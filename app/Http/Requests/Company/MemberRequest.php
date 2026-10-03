<?php

namespace App\Http\Requests\Company;

use App\Enums\UserRole;
use App\Models\Membership;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'role' => ['required', Rule::enum(UserRole::class)->only($this->user()->hasRole(UserRole::Owner) ? UserRole::assignable() : [UserRole::Technician])],
            'is_active' => [$creating ? 'exclude' : 'required', 'boolean'],
            'brand_ids' => ['array'],
            'brand_ids.*' => [
                'integer',
                Rule::exists('brands', 'id')->where('company_id', currentCompany()->id)->whereNull('deleted_at'),
            ],
        ];
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
