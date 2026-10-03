<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class BusinessExpenseCategory extends Model
{
    use BelongsToCompany;

    protected $fillable = ['name', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $category) {
            $category->name = trim($category->name);
            $category->normalized_name = mb_strtolower($category->name);
        });
    }
}
