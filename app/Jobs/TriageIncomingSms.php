<?php

namespace App\Jobs;

use App\Enums\PhotoKind;
use App\Messaging\Clarifier;
use App\Messaging\IncomingSmsPush;
use App\Messaging\SmsThreads;
use App\Models\Company;
use App\Models\JobPhoto;
use App\Models\Message;
use App\Models\SmsAccount;
use App\Services\AI\AiService;
use App\Services\AI\AiUnavailable;
use App\Services\AI\Assistant\AssistantPhotos;
use App\Services\AI\Sms\PlateReader;
use App\Services\AI\Sms\SmsTriage;
use App\Sms\SmsProvider;
use App\Support\PrivateMedia;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * After an incoming text is stored: its MMS photos are downloaded (kept as private files, and as photos of the
 * customer's open job), the AI reads the text (urgency, proposed actions, a reply draft) and a photo that is a rating
 * plate (brand, model, serial). Everything waits in the chat for a person: nothing is sent or changed here.
 */
class TriageIncomingSms implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  list<array{url: string, content_type: string}>  $media
     */
    public function __construct(public int $messageId, public int $companyId, public array $media = []) {}

    public function handle(CurrentCompany $tenancy, SmsProvider $sms, AiService $ai, SmsTriage $triage, PlateReader $plates): void
    {
        $company = Company::query()->find($this->companyId);

        if ($company === null) {
            return;
        }

        $tenancy->runAs($company, function () use ($company, $sms, $ai, $triage, $plates) {
            $message = Message::query()->find($this->messageId);

            if ($message === null || $message->direction !== Message::INBOUND) {
                return;
            }

            $photos = $this->photos($message, $sms);
            $jobs = $message->customer_id ? SmsTriage::openJobs($message->customer_id) : collect();
            $job = $jobs->firstWhere('id', $message->service_job_id) ?? $jobs->first();

            if ($photos !== [] && $job !== null) {
                $this->attach($photos, $job->id);
            }

            $result = null;
            try {
                if (! $ai->configured()) {
                    throw new AiUnavailable('not configured');
                }

                if (trim($message->body) !== '') {
                    $result = $triage->triage($message, $company);
                }

                if ($photos !== []) {
                    $plate = $plates->read($photos, $company);
                    $result ??= ['urgency' => 'normal', 'actions' => [], 'reply_draft' => null];

                    if ($plate['is_plate'] && ($plate['model'] !== null || $plate['serial'] !== null)) {
                        $result['plate'] = [
                            'brand' => $plate['brand'], 'model' => $plate['model'], 'serial' => $plate['serial'],
                            'job_id' => $job?->id, 'job_number' => $job?->number, 'status' => 'pending',
                        ];
                    }
                }
            } catch (AiUnavailable $e) {
                Log::info('Incoming text not read by the AI', ['company' => $company->id, 'error' => $e->getMessage()]);
            }

            if ($result !== null) {
                $message->forceFill(['ai_triage' => $result])->save();
                SmsThreads::markUrgent((string) $message->from, $result['urgency'] === 'urgent');
                // Clarifying questions by themselves, when the company turned that on.
                app(Clarifier::class)->maybeSend($message, $result);
            }

            // A push to the office for every incoming text: one line from the AI and its buttons.
            IncomingSmsPush::send($message->refresh());
        });
    }

    /**
     * Downloads and keeps the MMS photos (at most 5).
     *
     * @return list<array{path: string, media_type: string}>
     */
    private function photos(Message $message, SmsProvider $sms): array
    {
        if ($this->media === [] || $message->media !== null) {
            return (array) $message->media;
        }

        $account = SmsAccount::query()->first();
        $photos = [];

        foreach (array_slice($this->media, 0, AssistantPhotos::MAX) as $item) {
            $type = in_array($item['content_type'], AssistantPhotos::MEDIA_TYPES, true) ? $item['content_type'] : null;

            if ($type === null || $account === null) {
                continue;
            }

            try {
                $path = "sms-media/{$this->companyId}/".Str::uuid().'.'.AssistantPhotos::extension($type);
                PrivateMedia::disk()->put($path, $sms->fetchMedia($account, $item['url']));
                $photos[] = ['path' => $path, 'media_type' => $type];
            } catch (Throwable $e) {
                Log::warning('MMS photo not downloaded', ['company' => $this->companyId, 'error' => $e->getMessage()]);
            }
        }

        $message->forceFill(['media' => $photos])->save();

        return $photos;
    }

    /**
     * The photos also go to the job's photos.
     *
     * @param  list<array{path: string, media_type: string}>  $photos
     */
    private function attach(array $photos, int $jobId): void
    {
        $disk = PrivateMedia::disk();

        foreach ($photos as $photo) {
            $uuid = (string) Str::uuid();
            $path = "companies/{$this->companyId}/jobs/{$jobId}/photos/{$uuid}.".AssistantPhotos::extension($photo['media_type']);

            if (! $disk->exists($photo['path']) || ! $disk->copy($photo['path'], $path)) {
                continue;
            }

            $record = new JobPhoto(['kind' => PhotoKind::Before, 'client_uuid' => $uuid, 'taken_at' => now()]);
            $record->service_job_id = $jobId;
            $record->path = $path;
            $record->size = $disk->size($path);
            $record->save();
        }
    }
}
