<?php

namespace App\Actions\Brands;

use App\Models\Brand;
use App\Models\BrandAddress;
use App\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Creates or updates a brand with its addresses and logo.
 * Must run in a tenant context.
 */
class SaveBrand
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $addresses
     */
    public function handle(
        ?Brand $brand,
        array $attributes,
        array $addresses,
        ?UploadedFile $logo = null,
        bool $removeLogo = false,
    ): Brand {
        $isNew = $brand === null;
        $brand ??= new Brand;

        $brand = DB::transaction(function () use ($brand, $attributes, $addresses, $isNew) {
            $brand->fill($attributes);

            if ($isNew) {
                $brand->slug = $this->uniqueSlug($attributes['name']);
            }

            $brand->save();
            $this->syncAddresses($brand, $addresses);

            return $brand;
        });

        if ($removeLogo || $logo !== null) {
            $this->deleteLogo($brand);
        }

        if ($logo !== null) {
            $brand->logo_path = $logo->store(
                "companies/{$brand->company_id}/brands/{$brand->id}",
                config('fieldservice.media_disk'),
            );
            $brand->save();
        }

        if ($isNew) {
            $this->audit->record('brand.created', $brand, ['name' => $brand->name]);
        }

        return $brand->load('addresses');
    }

    /**
     * @param  list<array<string, mixed>>  $addresses
     */
    private function syncAddresses(Brand $brand, array $addresses): void
    {
        $keepIds = [];
        $hasPrimary = collect($addresses)->contains(fn ($a) => ! empty($a['is_primary']));

        foreach (array_values($addresses) as $index => $data) {
            $address = isset($data['id'])
                ? $brand->addresses()->whereKey($data['id'])->first() ?? new BrandAddress
                : new BrandAddress;

            $address->fill(collect($data)->except('id')->all());
            $address->is_primary = $hasPrimary ? (bool) ($data['is_primary'] ?? false) : $index === 0;
            $address->brand()->associate($brand);
            $address->save();

            $keepIds[] = $address->id;
        }

        $brand->addresses()->whereKeyNot($keepIds)->delete();

        // Exactly one primary address.
        $primary = $brand->addresses()->where('is_primary', true)->orderBy('id')->first();
        if ($primary !== null) {
            $brand->addresses()->whereKeyNot($primary->id)->update(['is_primary' => false]);
        }
    }

    private function deleteLogo(Brand $brand): void
    {
        if ($brand->logo_path === null) {
            return;
        }

        Storage::disk(config('fieldservice.media_disk'))->delete($brand->logo_path);
        $brand->logo_path = null;
        $brand->save();
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'brand';
        $slug = $base;
        $i = 2;

        while (Brand::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
