<?php

namespace App\Support\Reports;

use App\Enums\PhotoKind;
use App\Models\JobPhoto;
use App\Models\ReportPhoto;
use App\Models\ServiceJob;
use App\Models\User;
use App\Support\PrivateMedia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The photos of a report (invoice, estimate or job report; owner's task of November 25): new photos are saved among
 * the job's photos too; the report keeps which photos, their captions and order.
 */
class ServiceReport
{
    /** Photos a report can hold. */
    public const MAX_PHOTOS = 40;

    /**
     * New photos from the report's "+ Photo" (camera or gallery): shrunk, turned upright and saved as the job's
     * "After" photos.
     *
     * @param  list<UploadedFile>  $files
     * @return list<array{id: int, url: string}>
     */
    public static function upload(ServiceJob $job, array $files, User $user): array
    {
        $disk = PrivateMedia::disk();
        $saved = [];

        foreach ($files as $file) {
            $bytes = (string) file_get_contents($file->getRealPath());
            $jpeg = ReportImages::jpeg($bytes);
            $uuid = (string) Str::uuid();
            $extension = $jpeg !== null ? 'jpg' : strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'jpg');
            $path = "companies/{$job->company_id}/jobs/{$job->id}/photos/{$uuid}.{$extension}";
            $disk->put($path, $jpeg ?? $bytes);

            $photo = new JobPhoto(['kind' => PhotoKind::After, 'client_uuid' => $uuid, 'taken_at' => now()]);
            $photo->service_job_id = $job->id;
            $photo->user_id = $user->id;
            $photo->path = $path;
            $photo->size = strlen($jpeg ?? $bytes);
            $photo->save();

            $saved[] = ['id' => $photo->id, 'url' => route('jobs.photos.show', [$job, $photo])];
        }

        return $saved;
    }

    /**
     * Saves which of the job's photos are in the report, with captions, in the order given.
     *
     * @param  list<array{job_photo_id?: int|string|null, caption?: string|null}>  $photos
     */
    public static function sync(Model $reportable, int $jobId, array $photos): void
    {
        DB::transaction(function () use ($reportable, $jobId, $photos) {
            $type = $reportable::REPORT_TYPE;
            ReportPhoto::query()->where('reportable_type', $type)->where('reportable_id', $reportable->getKey())->delete();

            // Only photos of the same job (and company, by the scope).
            $ids = array_values(array_filter(array_map(fn ($p) => (int) ($p['job_photo_id'] ?? 0), $photos)));
            $allowed = JobPhoto::query()->where('service_job_id', $jobId)->whereIn('id', $ids)->pluck('id')->all();
            $position = 0;
            $seen = [];

            foreach (array_slice($photos, 0, self::MAX_PHOTOS) as $photo) {
                $id = (int) ($photo['job_photo_id'] ?? 0);
                if (! in_array($id, $allowed, true) || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                ReportPhoto::query()->create([
                    'reportable_type' => $type,
                    'reportable_id' => $reportable->getKey(),
                    'job_photo_id' => $id,
                    'caption' => filled($photo['caption'] ?? null) ? mb_substr(trim((string) $photo['caption']), 0, 300) : null,
                    'position' => $position++,
                ]);
            }
        });
    }

    /** Copies a report's photos to another record (an invoice made from an estimate). */
    public static function copy(Model $from, Model $to): void
    {
        foreach ($from->reportPhotos()->get() as $photo) {
            ReportPhoto::query()->create([
                'reportable_type' => $to::REPORT_TYPE,
                'reportable_id' => $to->getKey(),
                'job_photo_id' => $photo->job_photo_id,
                'caption' => $photo->caption,
                'position' => $photo->position,
            ]);
        }
    }

    /**
     * The report's photos for the form: id, caption, URL to show it.
     *
     * @return list<array{job_photo_id: int, caption: string, url: string}>
     */
    public static function forForm(Model $reportable, ServiceJob $job): array
    {
        return $reportable->reportPhotos()->get()->map(fn (ReportPhoto $p) => [
            'job_photo_id' => $p->job_photo_id,
            'caption' => (string) $p->caption,
            'url' => route('jobs.photos.show', [$job, $p->job_photo_id]),
        ])->values()->all();
    }

    /**
     * All photos of the job, for the "pick from the job's photos" list.
     *
     * @return list<array{id: int, url: string, kind: string}>
     */
    public static function jobPhotos(ServiceJob $job): array
    {
        return JobPhoto::query()->where('service_job_id', $job->id)->orderBy('taken_at')->orderBy('id')->get()
            ->map(fn (JobPhoto $p) => ['id' => $p->id, 'url' => route('jobs.photos.show', [$job, $p]), 'kind' => $p->kind->value])
            ->values()->all();
    }

    /**
     * The photos for a PDF: embedded JPEG copies with captions.
     *
     * @return list<array{src: string, caption: string|null}>
     */
    public static function forPdf(Model $reportable, int $maxSide, int $quality): array
    {
        $disk = PrivateMedia::disk();
        $photos = [];

        foreach ($reportable->reportPhotos()->with('photo')->get() as $item) {
            $path = $item->photo?->path;
            if ($path === null || ! $disk->exists($path)) {
                continue;
            }
            $src = ReportImages::dataUri((string) $disk->get($path), $maxSide, $quality);
            if ($src !== null) {
                $photos[] = ['src' => $src, 'caption' => $item->caption];
            }
        }

        return $photos;
    }
}
