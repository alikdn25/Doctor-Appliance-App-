<?php

namespace App\Services;

use App\Models\Company;
use App\Models\ImpersonationLog;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Lets a super-admin act as a company user for support. Every session is logged
 * in impersonation_logs and in the audit log.
 */
class Impersonation
{
    private const KEY = 'impersonation';

    public function __construct(private readonly Session $session) {}

    public function isActive(): bool
    {
        return $this->session->has(self::KEY.'.log_id');
    }

    public function impersonatorId(): ?int
    {
        return $this->session->get(self::KEY.'.impersonator_id');
    }

    public function companyId(): ?int
    {
        return $this->session->get(self::KEY.'.company_id');
    }

    public function start(User $superAdmin, Company $company, User $target, ?string $reason = null): ImpersonationLog
    {
        if (! $superAdmin->isSuperAdmin()) {
            throw new InvalidArgumentException('Only super-admins can impersonate.');
        }

        if ($target->isSuperAdmin() || $target->membershipFor($company) === null) {
            throw new InvalidArgumentException('The user is not a member of this company.');
        }

        $log = ImpersonationLog::create([
            'super_admin_id' => $superAdmin->id,
            'company_id' => $company->id,
            'impersonated_user_id' => $target->id,
            'reason' => $reason,
            'started_at' => now(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        $this->session->put(self::KEY, [
            'log_id' => $log->id,
            'impersonator_id' => $superAdmin->id,
            'company_id' => $company->id,
        ]);

        Auth::guard('web')->login($target);
        $this->session->regenerate();

        app(AuditLogger::class)->record('impersonation.started', $target, [
            'reason' => $reason,
        ], $company->id);

        return $log;
    }

    /**
     * Ends impersonation and logs the super-admin back in. Returns the super-admin,
     * or null if they no longer exist / are no longer a super-admin.
     */
    public function stop(): ?User
    {
        if (! $this->isActive()) {
            return null;
        }

        $data = $this->session->get(self::KEY);

        $this->finish();

        $superAdmin = User::find($data['impersonator_id']);

        if ($superAdmin === null || ! $superAdmin->isSuperAdmin() || ! $superAdmin->is_active) {
            Auth::guard('web')->logout();
            $this->session->invalidate();

            return null;
        }

        Auth::guard('web')->login($superAdmin);
        $this->session->regenerate();

        return $superAdmin;
    }

    /** Close recorded support access on Return to admin or an ordinary logout. */
    public function finish(): void
    {
        if (! $this->isActive()) {
            return;
        }

        $data = $this->session->get(self::KEY);
        app(AuditLogger::class)->record('impersonation.stopped', Auth::user(), [], $data['company_id']);
        ImpersonationLog::whereKey($data['log_id'])->whereNull('ended_at')->update(['ended_at' => now()]);
        $this->session->forget(self::KEY);
    }
}
