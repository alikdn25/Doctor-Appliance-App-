<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Job photos, signatures and rating plate photos used to be stored on the public media disk,
 * reachable by URL without a login. Move them to the private media disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->move(config('fieldservice.media_disk'), config('fieldservice.private_media_disk'));
    }

    public function down(): void
    {
        $this->move(config('fieldservice.private_media_disk'), config('fieldservice.media_disk'));
    }

    private function move(string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }

        $source = Storage::disk($from);
        $target = Storage::disk($to);

        $paths = DB::table('job_photos')->pluck('path')
            ->merge(DB::table('service_jobs')->whereNotNull('signature_path')->pluck('signature_path'))
            ->merge(DB::table('appliances')->whereNotNull('rating_plate_path')->pluck('rating_plate_path'));

        foreach ($paths as $path) {
            if (! $source->exists($path)) {
                continue;
            }

            $stream = $source->readStream($path);
            $target->writeStream($path, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            $source->delete($path);
        }
    }
};
