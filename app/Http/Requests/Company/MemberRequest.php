<?php

namespace App\Http\Requests\Company;

use App\Enums\OfficePermission;
use App\Enums\UserRole;
use App\Models\Membership;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class MemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Membership|null $membership */
        $membership = $this->route('membership');

        return $membership
            ? $this->user()->can('update', $membership)
            : $this->user()->can('create', Membership::class);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('is_active')) {
            $this->merge(['is_active' => $this->boolean('is_active')]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $creating = $this->route('membership') === null;

        return [
            'name' => [Rule::requiredIf($creating), 'string', 'max:255'],
            'email' => [Rule::requiredIf($creating), 'email', 'max:255'],
            'password' => [$creating ? 'nullable' : 'exclude', 'string', 'confirmed', Password::defaults()],
            'role' => ['required', Rule::enum(UserRole::class)->only($this->user()->hasRole(UserRole::Owner) ? UserRole::assignable() : [UserRole::Technician])],
            'is_active' => [$creating ? 'exclude' : 'required', 'boolean'],
            // Office permissions are set by the Owner only.
            'permissions' => [$this->user()->hasRole(UserRole::Owner) ? 'sometimes' : 'prohibited', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(OfficePermission::values())],
            'brand_ids' => ['array'],
            'brand_ids.*' => [
                'integer',
                Rule::exists('brands', 'id')->where('company_id', currentCompany()->id)->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * The Office permissions to save: a list for an Office member when the Owner sent them, otherwise null
     * (null keeps everything on for a new Office member and is stored for other roles).
     *
     * @return list<string>|null
     */
    public function permissions(): ?array
    {
        if ($this->role() !== UserRole::Admin || ! $this->has('permissions')) {
            return null;
        }

        return array_values(array_intersect(OfficePermission::values(), $this->validated('permissions', [])));
    }

    public function role(): UserRole
    {
        return UserRole::from($this->validated('role'));
    }

    /**
     * @return list<int>
     */
    public function brandIds(): array
    {
        return array_map('intval', $this->validated('brand_ids', []));
    }
}
