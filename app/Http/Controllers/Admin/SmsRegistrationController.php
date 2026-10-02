<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\SmsRegistration;
use App\Services\AuditLogger;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Super-admin: the platform registers a company's US A2P 10DLC brand and campaign with the carriers (in the
 * Twilio console, from the details the company submitted) and records the IDs and the outcome here. The daily
 * sms:sync-registrations command then follows the status at Twilio.
 */
class SmsRegistrationController extends Controller
{
    public function update(Request $request, Company $company, CurrentCompany $tenancy, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([SmsRegistration::SUBMITTED, SmsRegistration::APPROVED, SmsRegistration::REJECTED])],
            'brand_registration_sid' => ['nullable', 'string', 'max:64'],
            'messaging_service_sid' => ['nullable', 'string', 'max:64'],
            'campaign_sid' => ['nullable', 'string', 'max:64'],
            'rejection_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $tenancy->runAs($company, function () use ($data) {
            $registration = SmsRegistration::query()->firstOrNew();
            $registration->fill([
                ...$data,
                'approved_at' => $data['status'] === SmsRegistration::APPROVED ? ($registration->approved_at ?? now()) : null,
                'submitted_at' => $registration->submitted_at ?? now(),
            ])->save();
        });

        $audit->record('sms.registration_status', $company, ['status' => $data['status']], $company->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.sms_registration_saved')]);

        return to_route('admin.companies.show', $company);
    }
}
