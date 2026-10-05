<?php

namespace App\Console\Commands;

use App\Models\Membership;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;

/**
 * Server-side recovery when sign-in fails and email is not connected: sets a new password, switches the
 * account back on and signs out its other devices. Company memberships are only reported, never changed,
 * so an Owner's decision to deactivate someone in a company stays in force.
 */
#[Signature('app:reset-password {email} {--generate : Create a random temporary password and print it once} {--without-2fa : Also turn off two-factor sign-in}')]
#[Description('Set a new password for an account and make sure it can sign in')]
class ResetUserPassword extends Command
{
    public function handle(AuditLogger $audit): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $user = User::withTrashed()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No account with the email {$email}. This database has ".User::count().' account(s); check that the application uses the right database.');

            return self::FAILURE;
        }

        $generated = $this->option('generate') ? Str::password(16, symbols: false) : null;
        $password = $generated ?? password('New password', required: true, hint: 'At least 8 characters');

        $validator = Validator::make(['password' => $password], ['password' => ['required', Password::defaults()]]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $wasActive = $user->is_active;
        $wasDeleted = $user->trashed();

        DB::transaction(function () use ($user, $password, $audit, $wasActive, $wasDeleted) {
            if ($user->trashed()) {
                $user->restore();
            }

            $user->forceFill([
                'password' => $password,
                'is_active' => true,
                // Signs out "remember me" on other devices.
                'remember_token' => Str::random(60),
                ...($this->option('without-2fa') ? [
                    'two_factor_secret' => null,
                    'two_factor_recovery_codes' => null,
                    'two_factor_confirmed_at' => null,
                ] : []),
            ])->save();

            // Database sessions of this account end too.
            $sessions = config('session.table', 'sessions');
            if (Schema::hasTable($sessions)) {
                DB::table($sessions)->where('user_id', $user->id)->delete();
            }

            $audit->record('user.password_reset_cli', $user, [
                'reactivated' => ! $wasActive,
                'restored' => $wasDeleted,
                'two_factor_off' => (bool) $this->option('without-2fa'),
            ]);
        });

        $this->info("Password updated for {$user->email}.");
        if (! $wasActive || $wasDeleted) {
            $this->warn('The account was switched off and is active again.');
        }
        if ($generated !== null) {
            $this->line("Temporary password: {$generated}");
            $this->line('Give it to the person directly; they should change it in Settings → Security.');
        }

        $memberships = Membership::withoutGlobalScopes()->with('company')->where('user_id', $user->id)->get();
        if ($memberships->isEmpty() && ! $user->is_super_admin) {
            $this->warn('This account belongs to no company yet; after sign-in it will be asked to create one.');
        }
        foreach ($memberships as $membership) {
            $this->line(sprintf(
                '  %s — %s%s',
                $membership->company?->name ?? '#'.$membership->company_id,
                $membership->role->label(),
                $membership->is_active ? '' : ' (switched off in this company: the Owner can switch it on in Members)',
            ));
        }
        $this->line('If sign-in was tried many times, wait a minute before trying again.');

        return self::SUCCESS;
    }
}
