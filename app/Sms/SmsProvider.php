<?php

namespace App\Sms;

use App\Models\Company;
use App\Models\SmsAccount;
use App\Models\SmsRegistration;
use Illuminate\Http\Request;

/**
 * The platform's SMS provider (SPEC §7.7): Twilio first. The platform holds the provider account; every company
 * gets its own subaccount and number, so companies never sign up with the provider themselves.
 * Credentials come from .env only. The provider is set in config/sms.php.
 */
interface SmsProvider
{
    public function key(): string;

    /**
     * Whether the platform credentials are set.
     */
    public function isConfigured(): bool;

    /**
     * Creates the company's subaccount and buys a local SMS number in its country.
     *
     * @throws SmsException
     */
    public function provision(Company $company): SmsAccount;

    /**
     * Sends a text from the company's number. Returns the provider's message ID.
     *
     * @throws SmsException
     */
    public function send(SmsAccount $account, string $to, string $body): string;

    /**
     * Checks a webhook's signature with the account's credentials.
     */
    public function verifyWebhook(Request $request, SmsAccount $account): bool;

    /**
     * The account a webhook belongs to (by its account ID), across companies.
     */
    public function accountForWebhook(Request $request): ?SmsAccount;

    /**
     * An inbound text from a webhook.
     *
     * @return array{from: string, to: string, body: string, message_id: string}
     */
    public function inbound(Request $request): array;

    /**
     * A delivery status update from a webhook.
     *
     * @return array{message_id: string, status: string, error: string|null}
     */
    public function statusUpdate(Request $request): array;

    /**
     * Asks the provider for the current A2P 10DLC registration status (approved / rejected / still pending).
     *
     * @return array{status: string, reason: string|null}|null Null when there is nothing to check yet
     */
    public function registrationStatus(SmsRegistration $registration, SmsAccount $account): ?array;
}
