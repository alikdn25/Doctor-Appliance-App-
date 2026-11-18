<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\DriveBackup;
use App\Services\AuditLogger;
use App\Support\Backups\GoogleDrive;
use App\Support\Jobs\JobPresenter;
use App\Support\Jobs\MorningSms;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * Company → Morning SMS & backup (Owner; owner's task of November 19): the morning SMS to the owner's own phone
 * (on/off, number, time, 06:30 by default; it replaces the Owners' morning push) and the nightly Google Drive backup
 * (Connect Google Drive, only the files the app creates).
 */
class MorningController extends Controller
{
    public function edit(): Response
    {
        $company = currentCompany();
        Gate::authorize('update', $company);
        $last = DriveBackup::query()->latest('id')->first();

        return Inertia::render('company/morning', [
            'settings' => [
                'morning_sms_enabled' => $company->morning_sms_enabled,
                'morning_sms_phone' => $company->morning_sms_phone ? PhoneNumber::display($company->morning_sms_phone, $company->country) : '',
                'morning_sms_time' => $company->morning_sms_time,
            ],
            'preview' => MorningSms::build($company, CarbonImmutable::now($company->timezone)),
            'folder' => __('automation.backup.folder', ['company' => $company->name]),
            'drive' => [
                'available' => GoogleDrive::configured(),
                'connected' => $company->google_drive_token !== null,
                'email' => $company->google_drive_email,
                'last' => $last ? ['status' => $last->status, 'at' => JobPresenter::iso($last->created_at), 'error' => $last->error] : null,
            ],
        ]);
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $company = currentCompany();
        Gate::authorize('update', $company);

        $data = $request->validate([
            'morning_sms_enabled' => ['required', 'boolean'],
            'morning_sms_phone' => ['nullable', 'string', 'max:40'],
            'morning_sms_time' => ['required', 'date_format:H:i'],
        ]);
        $phone = trim((string) ($data['morning_sms_phone'] ?? ''));
        $normalized = $phone === '' ? null : PhoneNumber::normalize($phone, $company->country);
        if ($phone !== '' && ! PhoneNumber::isPossible($phone, $company->country)) {
            throw ValidationException::withMessages(['morning_sms_phone' => __('automation.morning_sms.bad_phone')]);
        }
        if ($data['morning_sms_enabled'] && $normalized === null) {
            throw ValidationException::withMessages(['morning_sms_phone' => __('automation.morning_sms.phone_required')]);
        }

        $company->fill([...$data, 'morning_sms_phone' => $normalized]);
        $changes = array_keys($company->getDirty());
        $company->save();
        if ($changes !== []) {
            $audit->record('company.morning_sms_updated', $company, ['fields' => $changes]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('automation.settings.saved')]);

        return to_route('company.morning.edit');
    }

    /**
     * Connect Google Drive: off to Google's consent screen (drive.file scope).
     */
    public function connect(Request $request): HttpResponse
    {
        Gate::authorize('update', currentCompany());
        abort_unless(GoogleDrive::configured(), 404);

        $state = Str::random(40);
        $request->session()->put('google_drive_state', ['state' => $state, 'company' => currentCompany()->id]);

        return Inertia::location(GoogleDrive::authUrl($state));
    }

    public function callback(Request $request, GoogleDrive $drive, AuditLogger $audit): RedirectResponse
    {
        $company = currentCompany();
        Gate::authorize('update', $company);
        $saved = $request->session()->pull('google_drive_state');

        if (! is_array($saved) || ! hash_equals((string) $saved['state'], (string) $request->query('state')) || $saved['company'] !== $company->id) {
            abort(403);
        }

        try {
            if ($request->filled('error') || ! $request->filled('code')) {
                throw new RuntimeException((string) $request->query('error', 'no code'));
            }
            $tokens = $drive->exchange((string) $request->query('code'));
        } catch (Throwable $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('automation.backup.connect_failed')]);

            return to_route('company.morning.edit');
        }

        $company->forceFill(['google_drive_token' => $tokens['refresh_token'], 'google_drive_email' => $tokens['email']])->save();
        $audit->record('company.google_drive_connected', $company, []);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('automation.backup.connected')]);

        return to_route('company.morning.edit');
    }

    public function disconnect(AuditLogger $audit): RedirectResponse
    {
        $company = currentCompany();
        Gate::authorize('update', $company);
        $company->forceFill(['google_drive_token' => null, 'google_drive_email' => null, 'google_drive_folder_id' => null])->save();
        $audit->record('company.google_drive_disconnected', $company, []);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('automation.backup.disconnected')]);

        return to_route('company.morning.edit');
    }
}
