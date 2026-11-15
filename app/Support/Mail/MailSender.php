<?php

namespace App\Support\Mail;

use App\Models\Brand;
use App\Models\Company;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Mailables\Address;
use Symfony\Component\Mime\Address as SymfonyAddress;
use Symfony\Component\Mime\Email;

/**
 * Who every outgoing email is from and where replies go. Mail always leaves from the platform's verified sender
 * address (MAIL_FROM_ADDRESS) with the company's name; replies go to the company's email from its settings
 * (the brand's sender email, else its contact email), else to MAIL_REPLY_TO_ADDRESS.
 */
class MailSender
{
    public static function from(?Brand $brand, ?Company $company = null): Address
    {
        return new Address((string) config('mail.from.address'), self::name($brand, $company));
    }

    /**
     * @return list<Address>
     */
    public static function replyTo(?Brand $brand, ?Company $company = null): array
    {
        $settings = $brand ?? self::companyBrand($company);
        $email = $settings?->sender_email ?: $settings?->email ?: self::fallbackReplyTo();

        return filled($email) ? [new Address($email, self::name($brand, $company))] : [];
    }

    public static function name(?Brand $brand, ?Company $company = null): string
    {
        return (string) ($brand?->sender_name ?: ($brand?->name ?: ($company?->name ?: config('mail.from.name'))));
    }

    public static function fallbackReplyTo(): ?string
    {
        $address = config('mail.fallback_reply_to.address');

        return filled($address) ? (string) $address : null;
    }

    /**
     * Mail that does not set its own sender (notifications, password reset, email confirmation, mail:test):
     * replies go to MAIL_REPLY_TO_ADDRESS and the sender gets the configured name.
     */
    public static function applyDefaults(MessageSending $event): void
    {
        $message = $event->message;
        if (! $message instanceof Email) {
            return;
        }

        $fromName = (string) config('mail.from.name');
        $from = $message->getFrom();
        if ($from === [] && filled(config('mail.from.address'))) {
            $message->from(new SymfonyAddress((string) config('mail.from.address'), $fromName));
        } elseif (count($from) === 1 && $from[0]->getName() === '') {
            $message->from(new SymfonyAddress($from[0]->getAddress(), $fromName));
        }

        $fallback = self::fallbackReplyTo();
        if ($message->getReplyTo() === [] && $fallback !== null) {
            $message->replyTo(new SymfonyAddress($fallback, ($message->getFrom()[0] ?? null)?->getName() ?? $fromName));
        }
    }

    /**
     * Without a brand on the job or document, replies go to the company's first active brand (its settings).
     */
    private static function companyBrand(?Company $company): ?Brand
    {
        if ($company === null) {
            return null;
        }

        return Brand::withoutCompanyScope()->where('company_id', $company->id)
            ->where('is_active', true)->orderBy('id')->first();
    }
}
