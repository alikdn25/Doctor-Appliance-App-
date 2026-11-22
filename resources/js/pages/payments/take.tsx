import { Head, Link } from '@inertiajs/react';
import { TakePayment } from '@/components/payments/take-payment';
import type { ChargeScreen } from '@/components/payments/take-payment';
import { ScreenHeader } from '@/components/screen-header';
import { useTrans } from '@/lib/i18n';

type Props = ChargeScreen & {
    invoice: {
        id: number;
        number: string;
        customer: string | null;
        balance: number;
        balance_input: string;
        balance_formatted: string;
        currency: string;
        url: string;
    };
};

/**
 * Take payment for an invoice (from the invoice, the job page or after a quick invoice): the balance on top, which
 * can be changed for a partial payment, then Charge reader / Cash / E-transfer.
 */
export default function TakePaymentPage(props: Props) {
    const t = useTrans();
    const { invoice } = props;

    return (
        <>
            <Head title={t('charges.take.title')} />
            <ScreenHeader
                title={t('charges.take.title')}
                subtitle={[
                    t('charges.take.invoice', { number: invoice.number }),
                    invoice.customer,
                ]
                    .filter(Boolean)
                    .join(' · ')}
                back={invoice.url}
            />
            <div className="mx-auto w-full max-w-md p-4 pb-24 sm:p-6">
                <TakePayment
                    screen={props}
                    amount={invoice.balance_input}
                    amountLabel={invoice.balance_formatted}
                    editable
                    payload={(method, amount) => ({
                        method,
                        invoice_id: invoice.id,
                        amount,
                    })}
                />
                <Link
                    href={`${invoice.url}?pay=1`}
                    className="mt-6 block text-center text-sm text-link"
                    data-test="take-payment-other"
                >
                    {t('charges.take.other_ways')}
                </Link>
            </div>
        </>
    );
}
