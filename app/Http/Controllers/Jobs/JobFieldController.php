<?php

namespace App\Http\Controllers\Jobs;

use App\Actions\Customers\SaveAppliance;
use App\Enums\PhotoKind;
use App\Http\Controllers\Controller;
use App\Models\Appliance;
use App\Models\JobChecklistItem;
use App\Models\JobPhoto;
use App\Models\JobVisit;
use App\Models\ServiceJob;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Work on site from the technician's phone: photos, rating plate, checklist and customer signature.
 * Uploads answer with JSON: the phone's upload queue sends them with fetch and retries on bad signal.
 */
class JobFieldController extends Controller
{
    private const IMAGE_RULES = ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic,heif', 'max:15360'];

    /**
     * Before/after photo. Idempotent on client_uuid, so a retry after a lost response does not duplicate it.
     */
    public function storePhoto(Request $request, ServiceJob $job): JsonResponse
    {
        Gate::authorize('work', $job);

        $validated = $request->validate([
            'photo' => self::IMAGE_RULES,
            'kind' => ['required', Rule::enum(PhotoKind::class)],
            'client_uuid' => ['required', 'uuid'],
            'visit_id' => ['nullable', 'integer'],
            'taken_at' => ['nullable', 'date'],
        ]);

        $existing = JobPhoto::query()->where('client_uuid', $validated['client_uuid'])->first();

        if ($existing !== null) {
            abort_unless($existing->service_job_id === $job->id, 409);

            return response()->json(['photo' => ['id' => $existing->id]]);
        }

        $visitId = isset($validated['visit_id'])
            ? JobVisit::query()->where('service_job_id', $job->id)->whereKey($validated['visit_id'])->value('id')
            : null;

        $file = $request->file('photo');
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'jpg');
        $path = $file->storeAs(
            "companies/{$job->company_id}/jobs/{$job->id}/photos",
            "{$validated['client_uuid']}.{$extension}",
            config('fieldservice.media_disk'),
        );

        $photo = new JobPhoto([
            'kind' => $validated['kind'],
            'job_visit_id' => $visitId,
            'client_uuid' => $validated['client_uuid'],
            'taken_at' => $validated['taken_at'] ?? now(),
        ]);
        $photo->service_job_id = $job->id;
        $photo->user_id = $request->user()->id;
        $photo->path = $path;
        $photo->size = $file->getSize();

        try {
            $photo->save();
        } catch (UniqueConstraintViolationException) {
            // Two attempts of the same upload arrived at once; the other one won.
            return response()->json(['photo' => ['id' => JobPhoto::query()->where('client_uuid', $photo->client_uuid)->value('id')]]);
        }

        return response()->json(['photo' => ['id' => $photo->id]], 201);
    }

    public function showPhoto(ServiceJob $job, JobPhoto $photo): StreamedResponse
    {
        Gate::authorize('view', $job);
        abort_unless($photo->service_job_id === $job->id, 404);

        return $this->file($photo->path);
    }

    /**
     * The person who took the photo, or the office, can remove it.
     */
    public function destroyPhoto(Request $request, ServiceJob $job, JobPhoto $photo): RedirectResponse
    {
        abort_unless($photo->service_job_id === $job->id, 404);
        abort_unless(
            Gate::allows('update', $job) || (Gate::allows('work', $job) && $photo->user_id === $request->user()->id),
            403,
        );

        $photo->delete();

        return back();
    }

    /**
     * Rating plate photo of an appliance on the job.
     */
    public function ratingPlate(Request $request, ServiceJob $job, Appliance $appliance, SaveAppliance $save): JsonResponse
    {
        Gate::authorize('work', $job);
        abort_unless($job->appliances()->whereKey($appliance->id)->exists(), 404);

        $request->validate(['rating_plate' => self::IMAGE_RULES]);

        $save->handle($appliance->property()->firstOrFail(), $appliance, [], $request->file('rating_plate'));

        return response()->json(['appliance' => ['id' => $appliance->id, 'rating_plate_url' => $appliance->fresh()->rating_plate_url]]);
    }

    public function toggleChecklistItem(Request $request, ServiceJob $job, JobChecklistItem $item): RedirectResponse
    {
        Gate::authorize('work', $job);
        abort_unless($item->service_job_id === $job->id, 404);

        $done = $request->validate(['is_done' => ['required', 'boolean']])['is_done'];

        $item->update([
            'is_done' => $done,
            'done_by' => $done ? $request->user()->id : null,
            'done_at' => $done ? now() : null,
        ]);

        return back();
    }

    /**
     * Customer signature drawn on the phone (PNG). A new signature replaces the previous one.
     */
    public function storeSignature(Request $request, ServiceJob $job): JsonResponse
    {
        Gate::authorize('work', $job);

        $validated = $request->validate([
            'signature' => ['required', 'file', 'mimes:png', 'max:1024'],
            'signer_name' => ['required', 'string', 'max:100'],
        ]);

        $disk = Storage::disk(config('fieldservice.media_disk'));
        $old = $job->signature_path;

        $path = $request->file('signature')->storeAs(
            "companies/{$job->company_id}/jobs/{$job->id}",
            'signature-'.Str::uuid().'.png',
            config('fieldservice.media_disk'),
        );

        DB::transaction(fn () => $job->forceFill([
            'signature_path' => $path,
            'signature_name' => $validated['signer_name'],
            'signed_at' => now(),
            'signed_by' => $request->user()->id,
        ])->save());

        if ($old !== null) {
            $disk->delete($old);
        }

        return response()->json(['signed_at' => $job->signed_at?->toIso8601String()], 201);
    }

    public function showSignature(ServiceJob $job): StreamedResponse
    {
        Gate::authorize('view', $job);
        abort_if($job->signature_path === null, 404);

        return $this->file($job->signature_path);
    }

    private function file(string $path): StreamedResponse
    {
        $disk = Storage::disk(config('fieldservice.media_disk'));
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, ['Cache-Control' => 'private, max-age=86400']);
    }
}
