<?php

namespace App\Mail;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Billing\DocumentPrint;
use App\Support\Billing\Money;
use App\Support\Mail\MailSender;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Send refund receipt" (owner's task of November 26), in the style of our receipt: the brand banner, the amount
 * refunded, the taxes in it, the way it went back (the card ····1234, cash, e-transfer), the date, the reason and the
 * invoice. Sent only on the button (Square sends the customer nothing itself).
 */
class RefundReceiptMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public int $paymentId;

    public int $companyId;

    public function __construct(Payment $refund)
    {
        $this->paymentId = $refund->id;
        $this->companyId = $refund->company_id;
    }

    public function refund(): Payment
    {
        return $this->inCompany(fn () => Payment::query()->with(['invoice' => fn ($q) => $q->withTrashed()->with('brand')])->findOrFail($this->paymentId));
    }

    public function envelope(): Envelope
    {
        return $this->inCompany(function () {
            $invoice = $this->refund()->invoice;
            $brand = $invoice?->brand;

            return new Envelope(
                from: MailSender::from($brand, $this->company()),
                replyTo: MailSender::replyTo($brand, $this->company()),
                subject: __('payments.refunds.receipt.subject', ['brand' => $brand?->name ?? $this->company()->name, 'number' => $invoice?->number]),
            );
        });
    }

    public function content(): Content
    {
        return $this->inCompany(function () {
            $refund = $this->refund();
            /** @var Invoice $invoice */
            $invoice = $refund->invoice;
            $locale = $this->company()->locale;
            $money = fn (int $minor) => Money::format($minor, $refund->currency, $locale);
            $taxes = collect((array) $refund->refund_taxes)->filter(fn (array $tax) => (int) $tax['amount'] > 0)
                ->map(fn (array $tax) => ['label' => trim($tax['name'].' '.($tax['rate'] ?? '').'%'), 'amount' => $money((int) $tax['amount'])])->values()->all();
            $total = abs((int) $refund->amount);

            return new Content(view: 'mail.refund-receipt', with: [
                'print' => DocumentPrint::data($invoice),
                'amount' => $money($total),
                'subtotal' => $money($total - (int) collect((array) $refund->refund_taxes)->sum('amount')),
                'taxes' => $taxes,
                'refundedOn' => DocumentPrint::date($refund->received_at),
                'method' => self::where($refund),
                'reason' => $refund->refund_reason,
            ]);
        });
    }

    /**
     * Where the money went back: "Card ····1234", "Cash", "E-transfer".
     */
    public static function where(Payment $refund): string
    {
        return $refund->card_last4 !== null
            ? __('payments.refunds.to_card', ['last4' => $refund->card_last4])
            : $refund->method->label();
    }

    private function company(): Company
    {
        return Company::query()->findOrFail($this->companyId);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function inCompany(callable $callback): mixed
    {
        return app(CurrentCompany::class)->runAs($this->company(), $callback(...));
    }
}
