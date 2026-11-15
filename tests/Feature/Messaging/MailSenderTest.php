<?php

use App\Models\Brand;
use App\Models\Company;
use App\Models\User;
use App\Support\Mail\MailSender;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;

/**
 * Every outgoing email: From "<company name>" <MAIL_FROM_ADDRESS>, Reply-To the company's email from its
 * settings, else MAIL_REPLY_TO_ADDRESS.
 */
beforeEach(function () {
    config([
        'mail.from' => ['address' => 'no-reply@sender.example.com', 'name' => 'Platform Name'],
        'mail.fallback_reply_to.address' => 'info@sender.example.com',
    ]);
    app('mail.manager')->forgetMailers();
    $this->company = Company::factory()->create(['name' => 'Acme Repairs Group']);
});

/**
 * @return list<Email>
 */
function sentEmails(): array
{
    return Mail::mailer('array')->getSymfonyTransport()->messages()
        ->map(fn ($sent) => $sent->getOriginalMessage())->values()->all();
}

/**
 * @param  list<Address>  $addresses
 * @return list<string>
 */
function addressList(array $addresses): array
{
    return array_map(fn (Address $address) => $address->address, $addresses);
}

test('mail is sent from the platform address with the brand or company name', function () {
    $brand = Brand::factory()->create(['company_id' => $this->company->id, 'name' => 'Acme Appliance', 'sender_name' => null]);

    expect(MailSender::from($brand, $this->company))
        ->address->toBe('no-reply@sender.example.com')->name->toBe('Acme Appliance');

    $brand->update(['sender_name' => 'Acme Billing']);
    expect(MailSender::from($brand->fresh(), $this->company)->name)->toBe('Acme Billing')
        ->and(MailSender::from(null, $this->company)->name)->toBe('Acme Repairs Group')
        ->and(MailSender::from(null)->name)->toBe('Platform Name');
});

test('replies go to the brand sender email, then its contact email, then MAIL_REPLY_TO_ADDRESS', function () {
    $brand = Brand::factory()->create([
        'company_id' => $this->company->id, 'sender_email' => 'billing@acme.example.com', 'email' => 'office@acme.example.com',
    ]);
    expect(addressList(MailSender::replyTo($brand, $this->company)))->toBe(['billing@acme.example.com']);

    $brand->update(['sender_email' => null]);
    expect(addressList(MailSender::replyTo($brand->fresh(), $this->company)))->toBe(['office@acme.example.com']);

    $brand->update(['email' => null]);
    expect(addressList(MailSender::replyTo($brand->fresh(), $this->company)))->toBe(['info@sender.example.com']);

    config(['mail.fallback_reply_to.address' => null]);
    expect(MailSender::replyTo($brand->fresh(), $this->company))->toBe([]);
});

test('without a brand, replies go to the email in the company settings, never another company', function () {
    $other = Company::factory()->create();
    Brand::factory()->create(['company_id' => $other->id, 'email' => 'other@elsewhere.example.com']);
    expect(addressList(MailSender::replyTo(null, $this->company)))->toBe(['info@sender.example.com']);

    Brand::factory()->create(['company_id' => $this->company->id, 'email' => 'office@acme.example.com']);
    expect(addressList(MailSender::replyTo(null, $this->company)))->toBe(['office@acme.example.com']);
});

test('password reset and other notifications get the sender name and the fallback reply-to', function () {
    $user = User::factory()->create(['email' => 'owner@example.com']);

    $user->notify(new ResetPassword('reset-token'));

    $emails = sentEmails();
    expect($emails)->toHaveCount(1);
    expect($emails[0]->getFrom()[0]->getAddress())->toBe('no-reply@sender.example.com')
        ->and($emails[0]->getFrom()[0]->getName())->toBe('Platform Name')
        ->and(array_map(fn ($a) => $a->getAddress(), $emails[0]->getReplyTo()))->toBe(['info@sender.example.com']);
});

test('mail that sets its own reply-to keeps only that one', function () {
    Mail::raw('Hello', fn ($message) => $message->to('someone@example.com')->replyTo('office@acme.example.com'));

    expect(array_map(fn ($a) => $a->getAddress(), sentEmails()[0]->getReplyTo()))->toBe(['office@acme.example.com']);
});

test('mail:test sends one plain email and prints OK', function () {
    $this->artisan('mail:test', ['to' => 'info@example.com'])
        ->expectsOutput('OK')
        ->assertSuccessful();

    $emails = sentEmails();
    expect($emails)->toHaveCount(1)
        ->and($emails[0]->getTo()[0]->getAddress())->toBe('info@example.com')
        ->and($emails[0]->getSubject())->toBe('Test email from Platform Name')
        ->and($emails[0]->getTextBody())->toContain('outgoing email works')
        ->and(array_map(fn ($a) => $a->getAddress(), $emails[0]->getReplyTo()))->toBe(['info@sender.example.com']);
});

test('mail:test rejects an invalid address without sending', function () {
    $this->artisan('mail:test', ['to' => 'not-an-email'])
        ->expectsOutput('Not a valid email address: not-an-email')
        ->assertFailed();

    expect(sentEmails())->toBe([]);
});

test('mail:test prints the error without the SMTP login or password', function () {
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp' => [
            'transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 2,
            'username' => 'smtp-login-never-print', 'password' => 'smtp-key-never-print',
        ],
    ]);
    app('mail.manager')->forgetMailers();

    $this->artisan('mail:test', ['to' => 'info@example.com'])
        ->doesntExpectOutputToContain('smtp-login-never-print')
        ->doesntExpectOutputToContain('smtp-key-never-print')
        ->doesntExpectOutput('OK')
        ->assertFailed();
});
