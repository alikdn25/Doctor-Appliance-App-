<?php

namespace App\Support\Backups;

use RuntimeException;
use SodiumException;

/**
 * Encrypts backup files before they leave the server and decrypts them for a restore: libsodium secretstream
 * (XChaCha20-Poly1305), in 1 MB chunks, so large dumps never sit in memory and any change to a file is detected.
 *
 * File format: "FSBK1" · 24-byte stream header · chunks of [4-byte length · ciphertext].
 */
class BackupEncryptor
{
    private const MAGIC = 'FSBK1';

    private const CHUNK = 1048576;

    private readonly string $key;

    public function __construct(?string $key = null)
    {
        $key ??= (string) config('backup.encryption_key');
        $raw = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : false;

        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('BACKUP_ENCRYPTION_KEY is missing or invalid. Create one with: php artisan backup:key');
        }

        $this->key = $raw;
    }

    public static function generateKey(): string
    {
        return 'base64:'.base64_encode(sodium_crypto_secretstream_xchacha20poly1305_keygen());
    }

    public function encryptFile(string $source, string $target): void
    {
        [$in, $out] = $this->open($source, $target);

        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->key);
            fwrite($out, self::MAGIC.$header);

            $chunk = fread($in, self::CHUNK);

            do {
                $next = feof($in) ? '' : (string) fread($in, self::CHUNK);
                $last = $next === '' && feof($in);
                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push(
                    $state,
                    (string) $chunk,
                    '',
                    $last ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,
                );
                fwrite($out, pack('N', strlen($cipher)).$cipher);
                $chunk = $next;
            } while (! $last);
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    public function decryptFile(string $source, string $target): void
    {
        [$in, $out] = $this->open($source, $target);

        try {
            if (fread($in, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new RuntimeException('Not a backup file of this application.');
            }

            $header = (string) fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);

            if (strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
                throw new RuntimeException('The backup file is incomplete.');
            }

            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $this->key);
            $finished = false;

            while (! $finished) {
                $length = fread($in, 4);

                if ($length === false || strlen($length) !== 4) {
                    throw new RuntimeException('The backup file is incomplete.');
                }

                $cipher = (string) fread($in, unpack('N', $length)[1]);
                try {
                    $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);
                } catch (SodiumException) {
                    $result = false;
                }

                if ($result === false) {
                    throw new RuntimeException('The backup file is damaged or was encrypted with another key.');
                }

                [$plain, $tag] = $result;
                fwrite($out, $plain);
                $finished = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /**
     * @return array{0: resource, 1: resource}
     */
    private function open(string $source, string $target): array
    {
        $in = @fopen($source, 'rb');
        $out = @fopen($target, 'wb');

        if ($in === false || $out === false) {
            throw new RuntimeException('Cannot open the backup file.');
        }

        return [$in, $out];
    }
}
