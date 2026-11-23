<?php

namespace App\Support\Reviews;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\User;

/**
 * The Google review request text (owner's task of November 23): "Hey {first_name}, would you take 30 seconds…" when
 * the customer has a usable first name, "Thanks for choosing {brand}! Would you take 30 seconds…" when not, signed
 * with the company owner's first name. Only the words between <b> and </b> are bold in the email (html()).
 */
class ReviewText
{
    /** Placeholder names people type instead of a real name. */
    private const NOT_NAMES = ['test', 'tester', 'testing', 'customer', 'client', 'unknown', 'none', 'null', 'guest', 'walkin', 'noname', 'name'];

    /**
     * The first word of a name, "Josh" from "josh miller" or "JOSH"; null when it is shorter than two letters, has
     * digits or symbols, or is a placeholder such as "test".
     */
    public static function firstName(?string $name): ?string
    {
        $word = preg_split('/\s+/u', trim((string) $name))[0] ?? '';

        if (preg_match('/^\p{L}{2,}$/u', $word) !== 1 || in_array(mb_strtolower($word), self::NOT_NAMES, true)) {
            return null;
        }

        return mb_strtoupper(mb_substr($word, 0, 1)).mb_strtolower(mb_substr($word, 1));
    }

    /**
     * The customer's own first name (a business's name is never used as one).
     */
    public static function customerFirstName(?Customer $customer): ?string
    {
        if ($customer === null || $customer->is_walk_in) {
            return null;
        }

        return self::firstName($customer->first_name)
            ?? (blank($customer->company_name) && blank($customer->first_name) ? self::firstName($customer->last_name) : null);
    }

    /**
     * The first name of the company's owner (the first active Owner), who signs the request.
     */
    public static function signer(Company $company): ?string
    {
        $userId = Membership::withoutCompanyScope()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->where('role', UserRole::Owner->value)
            ->orderBy('id')
            ->value('user_id');

        return $userId === null ? null : self::firstName(User::query()->whereKey($userId)->value('name'));
    }

    public static function render(?string $firstName, string $brand, ?string $signer, ?string $link): string
    {
        $replace = ['{first_name}' => (string) $firstName, '{brand}' => $brand, '{signer}' => (string) $signer, '{link}' => (string) $link];
        $text = __($firstName !== null ? 'reviews.request.named' : 'reviews.request.unnamed');
        $sign = __(match (true) {
            $signer === null => 'reviews.request.sign_brand',
            $firstName !== null => 'reviews.request.sign_named',
            default => 'reviews.request.sign_unnamed',
        });

        return trim((string) preg_replace('/[ \t]{2,}/', ' ', strtr($text, $replace)))."\n".strtr($sign, $replace);
    }

    /**
     * The text for an email: escaped, links clickable, line breaks kept, and only <b>…</b> pairs bold.
     */
    public static function html(string $text, string $color): string
    {
        $html = preg_replace('~(https?://(?:(?!&lt;|&gt;)[^\s<])+)~', '<a href="$1" style="color:'.e($color).';">$1</a>', e($text));

        return nl2br((string) preg_replace('~&lt;b&gt;(.+?)&lt;/b&gt;~s', '<b>$1</b>', (string) $html), false);
    }
}
