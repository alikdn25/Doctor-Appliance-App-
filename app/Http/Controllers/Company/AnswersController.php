<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Support\Billing\DebtorImport;
use App\Support\Billing\PaymentReminders;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company → Prices and answers (Owner; owner's task of November 19): what the AI may say in reply drafts (diagnostic
 * fee, service areas, business hours, notes — it never names a price that is not here), Russian → English before
 * sending, clarifying questions sent automatically (off by default), the soft payment reminder text and the outside
 * technicians a job can be handed to.
 */
class AnswersController extends Controller
{
    public function edit(): Response
    {
        $company = currentCompany();
        Gate::authorize('update', $company);
        $decimals = $company->currencyDecimals();

        return Inertia::render('company/answers', [
            'settings' => [
                'diagnostic_fee' => $company->diagnostic_fee === null ? '' : number_format($company->diagnostic_fee / (10 ** $decimals), $decimals, '.', ''),
                'service_areas' => (string) $company->service_areas,
                'hours_text' => (string) $company->hours_text,
                'answer_notes' => (string) $company->answer_notes,
                'translate_to_english' => $company->translate_to_english,
                'auto_clarify' => $company->auto_clarify,
                'payment_reminder_template' => PaymentReminders::template($company),
                'outside_technicians' => array_map(fn (array $t) => [
                    'name' => $t['name'], 'phone' => PhoneNumber::display($t['phone'], $company->country),
                ], (array) $company->outside_technicians),
            ],
            'currency' => $company->currency,
        ]);
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $company = currentCompany();
        Gate::authorize('update', $company);

        $data = $request->validate([
            'diagnostic_fee' => ['nullable', 'string', 'max:20'],
            'service_areas' => ['nullable', 'string', 'max:1000'],
            'hours_text' => ['nullable', 'string', 'max:500'],
            'answer_notes' => ['nullable', 'string', 'max:3000'],
            'translate_to_english' => ['required', 'boolean'],
            'auto_clarify' => ['required', 'boolean'],
            'payment_reminder_template' => ['nullable', 'string', 'max:480'],
            'outside_technicians' => ['nullable', 'array', 'max:20'],
            'outside_technicians.*.name' => ['required', 'string', 'max:100'],
            'outside_technicians.*.phone' => ['required', 'string', 'max:40'],
        ]);

        $fee = trim((string) ($data['diagnostic_fee'] ?? ''));
        $minor = $fee === '' ? null : DebtorImport::amount($fee, $company->currencyDecimals());
        if ($fee !== '' && $minor === null) {
            throw ValidationException::withMessages(['diagnostic_fee' => __('validation.numeric', ['attribute' => __('answers.settings.diagnostic_fee')])]);
        }

        $technicians = [];
        foreach ($data['outside_technicians'] ?? [] as $i => $tech) {
            if (! PhoneNumber::isPossible($tech['phone'], $company->country)) {
                throw ValidationException::withMessages(["outside_technicians.{$i}.phone" => __('answers.settings.bad_phone')]);
            }
            $technicians[] = ['name' => trim($tech['name']), 'phone' => PhoneNumber::normalize($tech['phone'], $company->country)];
        }

        $template = trim((string) ($data['payment_reminder_template'] ?? ''));
        $company->fill([
            'diagnostic_fee' => $minor,
            'service_areas' => $data['service_areas'] ?? null,
            'hours_text' => $data['hours_text'] ?? null,
            'answer_notes' => $data['answer_notes'] ?? null,
            'translate_to_english' => $data['translate_to_english'],
            'auto_clarify' => $data['auto_clarify'],
            // The default text is kept as "no own text".
            'payment_reminder_template' => $template === '' || $template === __('debtors.reminder.default') ? null : $template,
            'outside_technicians' => $technicians,
        ]);
        $changes = array_keys($company->getDirty());
        $company->save();
        if ($changes !== []) {
            $audit->record('company.answers_updated', $company, ['fields' => $changes]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('answers.settings.saved')]);

        return to_route('company.answers.edit');
    }
}
