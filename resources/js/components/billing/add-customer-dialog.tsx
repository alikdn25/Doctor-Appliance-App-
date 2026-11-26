import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import {
    CustomerFields,
    emptyCustomer,
} from '@/components/payments/customer-fields';
import type { CustomerDetails } from '@/components/payments/customer-fields';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useTrans } from '@/lib/i18n';
import { addCustomer } from '@/routes/invoices';
import { lookup } from '@/routes/jobs';

type Found = { id: number; display_name: string; phone?: string | null };

/**
 * "Add customer" on a Walk-in invoice (owner's task of November 26): find the customer in the database, or type the
 * name, phone, email and address; the invoice and its job move to them.
 */
export function AddCustomerDialog({
    open,
    onOpenChange,
    invoiceId,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    invoiceId: number;
}) {
    const t = useTrans();
    const country = usePage().props.auth.company?.country;
    const [search, setSearch] = useState('');
    const [found, setFound] = useState<Found[]>([]);
    const [details, setDetails] = useState<CustomerDetails>(emptyCustomer);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (!open) return;
        setSearch('');
        setFound([]);
        setDetails(emptyCustomer());
    }, [open]);

    useEffect(() => {
        if (search.trim().length < 2) {
            setFound([]);

            return;
        }
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            fetch(lookup({ query: { search } }).url, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((response) => (response.ok ? response.json() : null))
                .then((data: { customers: Found[] } | null) =>
                    setFound(data?.customers ?? []),
                )
                .catch(() => undefined);
        }, 250);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [search]);

    const save = (body: Record<string, unknown>) => {
        setBusy(true);
        router.post(addCustomer(invoiceId).url, body as never, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
            onFinish: () => setBusy(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="max-h-[90svh] overflow-y-auto sm:max-w-md"
                data-test="add-customer-dialog"
            >
                <DialogHeader>
                    <DialogTitle>
                        {t('invoices.add_customer.title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('invoices.add_customer.hint')}
                    </DialogDescription>
                </DialogHeader>
                <Input
                    type="search"
                    placeholder={t('invoices.add_customer.find')}
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    data-test="add-customer-search"
                />
                {found.length > 0 && (
                    <ul className="da-card divide-y overflow-hidden">
                        {found.map((customer) => (
                            <li key={customer.id}>
                                <button
                                    type="button"
                                    disabled={busy}
                                    className="da-press flex min-h-12 w-full items-center px-3 text-left text-sm font-semibold"
                                    onClick={() =>
                                        save({ customer_id: customer.id })
                                    }
                                >
                                    {customer.display_name}
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
                <p className="pt-2 text-sm font-semibold">
                    {t('invoices.add_customer.or_new')}
                </p>
                <CustomerFields
                    value={details}
                    onChange={setDetails}
                    country={country}
                />
                <Button
                    size="lg"
                    className="w-full"
                    loading={busy}
                    disabled={busy}
                    onClick={() =>
                        save({
                            first_name: details.first_name,
                            last_name: details.last_name,
                            phone: details.phone,
                            email: details.email,
                            property: details.property,
                        })
                    }
                    data-test="add-customer-save"
                >
                    {t('invoices.add_customer.save')}
                </Button>
            </DialogContent>
        </Dialog>
    );
}
