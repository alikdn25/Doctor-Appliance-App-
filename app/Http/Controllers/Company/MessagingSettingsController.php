<?php

namespace App\Http\Controllers\Company;

use App\Enums\MessageKind;
use App\Enums\SmsMode;
use App\Http\Controllers\Controller;
use App\Messaging\MessageTemplates;
use App\Models\SmsAccount;
use App\Models\SmsRegistration;
use App\Services\AuditLogger;
use App\Sms\SmsException;
use App\Sms\SmsProvider;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company → Messaging (Owner): SMS mode, quiet hours, message templates, review request settings, the company SMS
 * number (Automatic mode) and the US A2P 10DLC registration.
 */
class MessagingSettingsController extends Controller
{
    public function edit(SmsProvider $sms): Response
    {
        $company = currentCompany();
        Gate::authorize('update', $company);

        $account = SmsAccount::query()->first();
        $registration = SmsRegistration::query()->first();

        return Inertia::render('company/messaging', [
            'settings' => [
                'sms_mode' => $company->sms_mode->value,
                'quiet_hours_start' => $company->quiet_hours_start,
                'quiet_hours_end' => $company->quiet_hours_end,
                'review_requests_default' => $company->review_requests_default,
                'review_request_delay_hours' => $company->review_request_delay_hours,
                'review_request_cooldown_days' => $company->review_request_cooldown_days,
            ],
            'modes' => array_map(fn (SmsMode $mode) => [
                'value' => $mode->value, 'label' => $mode->label(), 'hint' => __("messages.mode_hints.{$mode->value}"),
            ], SmsMode::cases()),
            'templates' => MessageTemplates::forSettings($company),
            'account' => [
                'configured' => $sms->isConfigured(),
                'phone_number' => $account?->phone_number ? PhoneNumber::display($account->phone_number, $company->country) : null,
            ],
            // A2P 10DLC is a US carrier rule: shown to US companies (and to anyone texting US numbers from the US).
            'registration' => $company->country === 'US' ? [
                'status' => $registration->status ?? SmsRegistration::DRAFT,
                'status_label' => __('messages.registration.statuses.'.($registration->status ?? SmsRegistration::DRAFT)),
                'rejection_reason' => $registration?->rejection_reason,
                'business' => [
                    ...array_fill_keys(SmsRegistration::FIELDS, ''),
                    'legal_name' => $company->name,
                    'use_case_description' => __('messages.registration.default_use_case'),
                    'sample_message' => MessageTemplates::render($company, MessageKind::OnMyWay, [
                        'customer_first_name' => 'Maria', 'tech_name' => 'Tom', 'brand' => $company->name, 'arrival_window' => '9:00 AM – 11:00 AM',
                    ]),
                    ...array_filter((array) ($registration->business ?? []), fn ($v) => $v !== null),
                ],
                'business_types' => array_map(fn (string $type) => ['value' => $type, 'label' => __("messages.registration.business_types.{$type}")],
                    ['sole_proprietor', 'llc', 'corporation', 'partnership', 'non_profit']),
            ] : null,
        ]);
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $company = currentCompany();
        Gate::authorize('update', $company);

        $kinds = array_map(fn (MessageKind $k) => $k->value, MessageKind::templated());
        $data = $request->validate([
            'sms_mode' => ['required', Rule::enum(SmsMode::class)],
            'quiet_hours_start' => ['required', 'date_format:H:i'],
            'quiet_hours_end' => ['required', 'date_format:H:i'],
            'templates' => ['array:'.implode(',', $kinds)],
            'templates.*' => ['nullable', 'string', 'max:1000'],
        ]);

        $company->fill([
            ...collect($data)->except('templates')->all(),
            'message_templates' => array_filter(array_map(fn ($t) => trim((string) $t), $data['templates'] ?? []), fn ($t) => $t !== ''),
        ]);
        $changes = array_keys($company->getDirty());
        $company->save();

        if ($changes !== []) {
            $audit->record('company.messaging_updated', $company, ['fields' => $changes]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('messages.templates_saved')]);

        return to_route('company.messaging.edit');
    }

    /**
     * Gets the company its SMS subaccount and number at the platform's provider.
     */
    public function provision(SmsProvider $sms, AuditLogger $audit): RedirectResponse
    {
        $company = currentCompany();
        Gate::authorize('update', $company);

        try {
            $account = $sms->provision($company);
        } catch (SmsException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('messages.account.failed', ['error' => $e->getMessage()])]);

            return to_route('company.messaging.edit');
        }

        $audit->record('sms.number_provisioned', $company, ['number' => $account->phone_number]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('messages.account.ready')]);

        return to_route('company.messaging.edit');
    }

    /**
     * Saves the A2P 10DLC business details; with "submit" also sends them for registration.
     */
    public function registration(Request $request, AuditLogger $audit): RedirectResponse
    {
        $company = currentCompany();
        Gate::authorize('update', $company);
        abort_unless($company->country === 'US', 404);

        $submit = $request->boolean('submit');
        $required = $submit ? 'required' : 'nullable';
        $data = $request->validate([
            'business' => ['required', 'array:'.implode(',', SmsRegistration::FIELDS)],
            'business.legal_name' => [$required, 'string', 'max:255'],
            'business.business_type' => [$required, Rule::in(['sole_proprietor', 'llc', 'corporation', 'partnership', 'non_profit'])],
            'business.ein' => [$submit && $request->input('business.business_type') !== 'sole_proprietor' ? 'required' : 'nullable', 'string', 'regex:/^\d{2}-?\d{7}$/'],
            'business.website' => ['nullable', 'url', 'max:255'],
            'business.street' => [$required, 'string', 'max:255'],
            'business.city' => [$required, 'string', 'max:100'],
            'business.region' => [$required, 'string', 'size:2'],
            'business.postal_code' => [$required, 'string', 'regex:/^\d{5}(-\d{4})?$/'],
            'business.contact_first_name' => [$required, 'string', 'max:100'],
            'business.contact_last_name' => [$required, 'string', 'max:100'],
            'business.contact_email' => [$required, 'email', 'max:255'],
            'business.contact_phone' => [$required, 'string', 'max:32'],
            'business.use_case_description' => [$required, 'string', 'min:40', 'max:1000'],
            'business.sample_message' => [$required, 'string', 'min:20', 'max:1000'],
        ], [], collect(SmsRegistration::FIELDS)->mapWithKeys(fn ($f) => ["business.{$f}" => __("messages.registration.fields.{$f}")])->all());

        $registration = SmsRegistration::query()->firstOrNew();
        abort_if($registration->status === SmsRegistration::APPROVED, 422);

        $registration->business = $data['business'];
        if ($submit) {
            $registration->fill(['status' => SmsRegistration::SUBMITTED, 'submitted_at' => now(), 'rejection_reason' => null]);
        }
        $registration->save();

        $audit->record($submit ? 'sms.registration_submitted' : 'sms.registration_saved', $company, []);
        Inertia::flash('toast', ['type' => 'success', 'message' => __($submit ? 'messages.registration.submitted' : 'messages.registration.saved')]);

        return to_route('company.messaging.edit');
    }
}
