<?php

namespace App\Http\Controllers\Company;

use App\Actions\Members\AddMember;
use App\Actions\Members\RemoveMember;
use App\Actions\Members\UpdateMember;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\MemberRequest;
use App\Models\Brand;
use App\Models\Membership;
use App\Notifications\MemberInvited;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Membership::class);

        $company = currentCompany();

        $brandLinks = DB::table('brand_user')
            ->where('company_id', $company->id)
            ->get(['user_id', 'brand_id'])
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('brand_id')->all());

        $members = Membership::query()
            ->with('user')
            ->get()
            ->sortBy(fn (Membership $m) => mb_strtolower($m->user->name))
            ->values()
            ->map(fn (Membership $m) => [
                'id' => $m->id,
                'user_id' => $m->user_id,
                'name' => $m->user->name,
                'email' => $m->user->email,
                'role' => $m->role->value,
                'role_label' => $m->role->label(),
                'is_active' => $m->is_active,
                'brand_ids' => $brandLinks->get($m->user_id, []),
                'invitation_pending' => $m->user->last_login_at === null,
                'is_self' => $m->user_id === $request->user()->id,
            ]);

        return Inertia::render('team/index', [
            'members' => $members,
            'roles' => UserRole::assignableOptions(),
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(MemberRequest $request, AddMember $addMember): RedirectResponse
    {
        $addMember->handle(
            currentCompany(),
            $request->validated('name'),
            $request->validated('email'),
            $request->role(),
            $request->brandIds(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('team.added')]);

        return to_route('team.index');
    }

    public function update(MemberRequest $request, Membership $membership, UpdateMember $update): RedirectResponse
    {
        $update->handle($membership, $request->role(), $request->boolean('is_active'), $request->brandIds());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('team.updated')]);

        return to_route('team.index');
    }

    public function destroy(Membership $membership, RemoveMember $remove): RedirectResponse
    {
        Gate::authorize('delete', $membership);

        $remove->handle($membership);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('team.removed')]);

        return to_route('team.index');
    }

    public function resendInvitation(Membership $membership): RedirectResponse
    {
        Gate::authorize('update', $membership);

        $user = $membership->user;

        abort_if($user->last_login_at !== null, 422);

        $user->notify(new MemberInvited(currentCompany()->name, Password::broker()->createToken($user)));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('team.invitation_resent')]);

        return to_route('team.index');
    }
}
