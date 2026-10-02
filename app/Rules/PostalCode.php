<?php

namespace App\Rules;

use App\Support\Locale\Countries;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A ZIP / postal code in the format of the address's country (config/countries.php). The country is read
 * from the sibling "country" field (e.g. "property.postal_code" → "property.country"); countries without a
 * known format accept anything.
 */
class PostalCode implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        $country = data_get($this->data, preg_replace('/postal_code$/', 'country', $attribute));
        $format = Countries::addressFormat(is_string($country) ? $country : null);

        if ($format['postal_pattern'] !== null && preg_match($format['postal_pattern'], trim((string) $value)) !== 1) {
            $fail(__('properties.invalid_postal', ['label' => mb_strtolower(__("properties.postals.{$format['postal']}"))]));
        }
    }
}
