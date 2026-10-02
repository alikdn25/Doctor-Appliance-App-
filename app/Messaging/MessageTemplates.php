<?php

namespace App\Messaging;

use App\Enums\MessageKind;
use App\Models\Company;

/**
 * Company texts for customer messages (SPEC §7.7, §8): the company's own version or the English default
 * (lang/en/messages.php → templates). Placeholders are written as {name}; unknown ones are left as they are.
 */
class MessageTemplates
{
    public static function template(Company $company, MessageKind $kind): string
    {
        $own = trim((string) ($company->message_templates[$kind->value] ?? ''));

        return $own !== '' ? $own : (string) __("messages.templates.{$kind->value}");
    }

    /**
     * @param  array<string, string|null>  $variables
     */
    public static function render(Company $company, MessageKind $kind, array $variables): string
    {
        $replace = [];
        foreach ($variables as $name => $value) {
            $replace['{'.$name.'}'] = (string) $value;
        }

        // Collapse spaces left by empty placeholders.
        return trim((string) preg_replace('/[ \t]{2,}/', ' ', strtr(self::template($company, $kind), $replace)));
    }

    /**
     * All templates of the company, own text or empty (= default), with the defaults for the settings page.
     *
     * @return list<array{kind: string, label: string, text: string, default: string}>
     */
    public static function forSettings(Company $company): array
    {
        return array_map(fn (MessageKind $kind) => [
            'kind' => $kind->value,
            'label' => $kind->label(),
            'text' => (string) ($company->message_templates[$kind->value] ?? ''),
            'default' => (string) __("messages.templates.{$kind->value}"),
        ], MessageKind::templated());
    }
}
