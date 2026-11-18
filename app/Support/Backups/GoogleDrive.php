<?php

namespace App\Support\Backups;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The few Google Drive calls the nightly backup needs (owner's task of November 19), over plain HTTP: OAuth with the
 * drive.file scope (only the files and folders the app created are visible to it), a folder, an upload, a delete.
 */
class GoogleDrive
{
    public const SCOPE = 'https://www.googleapis.com/auth/drive.file';

    private const AUTH = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN = 'https://oauth2.googleapis.com/token';

    private const API = 'https://www.googleapis.com/drive/v3';

    private const UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';

    public static function configured(): bool
    {
        return filled(config('services.google_drive.client_id')) && filled(config('services.google_drive.client_secret'));
    }

    public static function redirectUri(): string
    {
        return route('google-drive.callback');
    }

    public static function authUrl(string $state): string
    {
        return self::AUTH.'?'.http_build_query([
            'client_id' => config('services.google_drive.client_id'),
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPE.' email',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'false',
            'state' => $state,
        ]);
    }

    /**
     * The code from the callback → the refresh token and the account's email.
     *
     * @return array{refresh_token: string, email: string|null}
     */
    public function exchange(string $code): array
    {
        $response = Http::asForm()->timeout(20)->post(self::TOKEN, [
            'code' => $code,
            'client_id' => config('services.google_drive.client_id'),
            'client_secret' => config('services.google_drive.client_secret'),
            'redirect_uri' => self::redirectUri(),
            'grant_type' => 'authorization_code',
        ]);

        if (! $response->ok() || ! filled($response->json('refresh_token'))) {
            throw new RuntimeException('Google did not give access: '.($response->json('error_description') ?? $response->json('error') ?? $response->status()));
        }

        $email = null;
        $idToken = (string) $response->json('id_token');
        if ($idToken !== '' && count($parts = explode('.', $idToken)) === 3) {
            $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);
            $email = is_array($claims) ? ($claims['email'] ?? null) : null;
        }

        return ['refresh_token' => (string) $response->json('refresh_token'), 'email' => $email];
    }

    public function accessToken(string $refreshToken): string
    {
        $response = Http::asForm()->timeout(20)->post(self::TOKEN, [
            'refresh_token' => $refreshToken,
            'client_id' => config('services.google_drive.client_id'),
            'client_secret' => config('services.google_drive.client_secret'),
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->ok() || ! filled($response->json('access_token'))) {
            throw new RuntimeException('Google Drive access was refused: '.($response->json('error_description') ?? $response->json('error') ?? $response->status()));
        }

        return (string) $response->json('access_token');
    }

    /**
     * The backup folder: the one created earlier while it is still there (not in the trash), else a new one.
     */
    public function folder(string $token, ?string $id, string $name): string
    {
        if ($id !== null) {
            $found = Http::withToken($token)->timeout(20)->get(self::API."/files/{$id}", ['fields' => 'id,trashed']);
            if ($found->ok() && $found->json('trashed') !== true) {
                return $id;
            }
        }

        $created = Http::withToken($token)->timeout(20)->post(self::API.'/files', [
            'name' => $name, 'mimeType' => 'application/vnd.google-apps.folder',
        ]);
        if (! $created->ok() || ! filled($created->json('id'))) {
            throw new RuntimeException('The backup folder could not be created: '.($created->json('error.message') ?? $created->status()));
        }

        return (string) $created->json('id');
    }

    /**
     * Uploads one CSV file into the folder; returns its id.
     */
    public function upload(string $token, string $folderId, string $name, string $contents): string
    {
        $boundary = 'backup'.bin2hex(random_bytes(8));
        $body = "--{$boundary}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n"
            .json_encode(['name' => $name, 'parents' => [$folderId], 'mimeType' => 'text/csv'])
            ."\r\n--{$boundary}\r\nContent-Type: text/csv; charset=UTF-8\r\n\r\n{$contents}\r\n--{$boundary}--";

        $response = Http::withToken($token)->timeout(60)
            ->withBody($body, "multipart/related; boundary={$boundary}")
            ->post(self::UPLOAD.'?uploadType=multipart&fields=id');

        if (! $response->ok() || ! filled($response->json('id'))) {
            throw new RuntimeException("{$name} was not uploaded: ".($response->json('error.message') ?? $response->status()));
        }

        return (string) $response->json('id');
    }

    /**
     * Deletes a file the app created (an old copy). A file already gone counts as deleted.
     */
    public function delete(string $token, string $id): void
    {
        $response = Http::withToken($token)->timeout(20)->delete(self::API."/files/{$id}");

        if (! $response->successful() && $response->status() !== 404) {
            throw new RuntimeException("Old copy {$id} was not deleted: ".($response->json('error.message') ?? $response->status()));
        }
    }
}
