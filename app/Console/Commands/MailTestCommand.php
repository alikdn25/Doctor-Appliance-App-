<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Sends one plain email straight away (not queued) through the configured mailer, to check delivery after
 * changing mail settings. Prints OK or the error, with the SMTP login and password removed.
 */
#[Signature('mail:test {to : The address to send the test email to}')]
#[Description('Send one plain test email and print OK or the error')]
class MailTestCommand extends Command
{
    public function handle(): int
    {
        $to = trim((string) $this->argument('to'));

        if (Validator::make(['to' => $to], ['to' => ['required', 'email']])->fails()) {
            $this->error("Not a valid email address: {$to}");

            return self::FAILURE;
        }

        $name = (string) config('mail.from.name');

        try {
            Mail::raw(
                "This is a test email from {$name} (".config('app.url').").\n\nIf you can read this, outgoing email works.",
                fn ($message) => $message->to($to)->subject("Test email from {$name}"),
            );
        } catch (Throwable $e) {
            $this->error('ERROR '.self::redact($e->getMessage()));

            return self::FAILURE;
        }

        $this->line('OK');

        return self::SUCCESS;
    }

    /**
     * SMTP errors can quote the login; never print it or the password.
     */
    private static function redact(string $text): string
    {
        $mailer = (string) config('mail.default');
        foreach (['username', 'password'] as $key) {
            $secret = (string) config("mail.mailers.{$mailer}.{$key}");
            if ($secret !== '') {
                $text = str_replace($secret, '[hidden]', $text);
            }
        }

        return $text;
    }
}
