<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Before / After are taken on the visit. Attachment is a file the job was booked from (a screenshot of the customer's
 * message, a photo sent to the assistant): it sits under Files → Attachments, never among the Before photos.
 */
enum PhotoKind: string
{
    use HasOptions;

    case Before = 'before';
    case After = 'after';
    case Attachment = 'attachment';

    public function label(): string
    {
        return __("jobs.photo_kinds.{$this->value}");
    }

    /**
     * The kinds a technician takes with the camera (the buttons on the job and on Finish visit).
     *
     * @return list<array{value: string, label: string}>
     */
    public static function takeable(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            [self::Before, self::After],
        );
    }
}
