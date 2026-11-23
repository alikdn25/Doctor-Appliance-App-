<?php

namespace App\Support\Jobs;

use App\Enums\PhotoKind;
use App\Models\JobPhoto;
use App\Models\ServiceJob;
use App\Models\User;
use App\Support\PrivateMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Photos a booking is read from (Quick booking, or the assistant's propose_booking): a screenshot of the customer's
 * message, a rating plate, a note. They wait as private files of the person who sent them until the booking is saved,
 * then become the new job's photos. An id is "<uuid>.<ext>"; it only ever resolves inside the sender's own folder
 * (company and user), so one person can never attach another's (or another company's) photos.
 */
class BookingPhotos
{
    public const MAX = 5;

    private const ID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(jpg|png|webp|gif)$/';

    private const MEDIA_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    /**
     * Stores the uploads (already shrunk on the phone) and returns them as image blocks for the AI, with their ids.
     *
     * @param  list<UploadedFile>  $files
     * @return list<array{type: 'image', path: string, media_type: string, id: string}>
     */
    public static function store(array $files, int $companyId, int $userId): array
    {
        $photos = [];

        foreach (array_slice($files, 0, self::MAX) as $file) {
            $type = array_key_exists((string) $file->getMimeType(), self::MEDIA_TYPES) ? (string) $file->getMimeType() : 'image/jpeg';
            $id = Str::uuid()->toString().'.'.self::MEDIA_TYPES[$type];
            PrivateMedia::disk()->put(self::path($companyId, $userId, $id), (string) file_get_contents($file->getRealPath()));
            $photos[] = ['type' => 'image', 'path' => self::path($companyId, $userId, $id), 'media_type' => $type, 'id' => $id];
        }

        return $photos;
    }

    /**
     * Copies stored photos (e.g. the assistant's) into the sender's booking folder and returns their ids.
     *
     * @param  list<array{path: string, media_type: string}>  $photos
     * @return list<string>
     */
    public static function copy(array $photos, int $companyId, int $userId): array
    {
        $disk = PrivateMedia::disk();
        $ids = [];

        foreach (array_slice($photos, 0, self::MAX) as $photo) {
            if (! $disk->exists($photo['path'])) {
                continue;
            }
            $id = Str::uuid()->toString().'.'.(self::MEDIA_TYPES[$photo['media_type']] ?? 'jpg');
            $disk->copy($photo['path'], self::path($companyId, $userId, $id));
            $ids[] = $id;
        }

        return $ids;
    }

    public static function valid(string $id): bool
    {
        return preg_match(self::ID, $id) === 1;
    }

    /** The private file of a photo id in the sender's folder, or null when it is not theirs or gone. */
    public static function find(string $id, int $companyId, int $userId): ?string
    {
        if (! self::valid($id)) {
            return null;
        }

        $path = self::path($companyId, $userId, $id);

        return PrivateMedia::disk()->exists($path) ? $path : null;
    }

    /**
     * Moves the photos into the job's photo folder as its "before" photos. Ids that are not the person's own are
     * skipped silently.
     *
     * @param  list<string>  $ids
     */
    public static function attach(ServiceJob $job, array $ids, User $user): int
    {
        $disk = PrivateMedia::disk();
        $attached = 0;

        foreach (array_slice(array_values(array_unique($ids)), 0, self::MAX) as $id) {
            $from = self::find((string) $id, $job->company_id, $user->id);

            if ($from === null) {
                continue;
            }

            $uuid = Str::before((string) $id, '.');
            $to = "companies/{$job->company_id}/jobs/{$job->id}/photos/{$id}";
            $disk->move($from, $to);

            $photo = new JobPhoto(['kind' => PhotoKind::Before, 'client_uuid' => $uuid, 'taken_at' => now()]);
            $photo->service_job_id = $job->id;
            $photo->user_id = $user->id;
            $photo->path = $to;
            $photo->size = $disk->size($to);
            $photo->save();
            $attached++;
        }

        return $attached;
    }

    private static function path(int $companyId, int $userId, string $id): string
    {
        return "booking-photos/{$companyId}/{$userId}/{$id}";
    }
}
