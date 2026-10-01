<?php

namespace App\Actions\Members;

use App\Models\Brand;
use App\Models\Membership;
use Illuminate\Support\Facades\DB;

/**
 * Sets which brands a member works for in the membership's company.
 * Touches only brand_user rows of that company, never other tenants' rows.
 */
class SyncMemberBrands
{
    /**
     * @param  list<int>  $brandIds
     */
    public function handle(Membership $membership, array $brandIds): void
    {
        // Only brands of the current company are accepted (Brand is tenant-scoped).
        $brandIds = Brand::query()->whereKey($brandIds)->pluck('id')->all();

        DB::table('brand_user')
            ->where('company_id', $membership->company_id)
            ->where('user_id', $membership->user_id)
            ->whereNotIn('brand_id', $brandIds)
            ->delete();

        $existing = DB::table('brand_user')
            ->where('company_id', $membership->company_id)
            ->where('user_id', $membership->user_id)
            ->pluck('brand_id')
            ->all();

        $now = now();

        DB::table('brand_user')->insert(array_map(fn (int $brandId) => [
            'company_id' => $membership->company_id,
            'brand_id' => $brandId,
            'user_id' => $membership->user_id,
            'created_at' => $now,
            'updated_at' => $now,
        ], array_values(array_diff($brandIds, $existing))));
    }
}
