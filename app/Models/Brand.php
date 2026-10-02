<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\PhoneNumber;
use Database\Factories\BrandFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $slug
 * @property string|null $logo_path
 * @property string|null $primary_color
 * @property string|null $secondary_color
 * @property string|null $website
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $sender_name
 * @property string|null $sender_email
 * @property string|null $tax_number Tax registration (GST/HST, VAT, EIN …)
 * @property string|null $business_number
 * @property string|null $invoice_footer
 * @property string|null $invoice_terms
 * @property int|null $google_profile_id Default Google profile for review requests
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string|null $logo_url
 */
class Brand extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<BrandFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'primary_color',
        'secondary_color',
        'website',
        'email',
        'phone',
        'sender_name',
        'sender_email',
        'tax_number',
        'business_number',
        'invoice_footer',
        'invoice_terms',
        'google_profile_id',
        'is_active',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    protected $appends = ['logo_url'];

    protected static function booted(): void
    {
        static::saving(function (Brand $brand) {
            if (filled($brand->phone)) {
                $brand->phone = PhoneNumber::normalize($brand->phone);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<BrandAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(BrandAddress::class)->orderByDesc('is_primary')->orderBy('id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function logoUrl(): Attribute
    {
        return Attribute::get(fn () => $this->logo_path
            ? Storage::disk(config('fieldservice.media_disk'))->url($this->logo_path)
            : null);
    }
}
