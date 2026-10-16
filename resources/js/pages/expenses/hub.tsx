import { Head, usePage } from '@inertiajs/react';
import { Banknote, Car, Landmark, Wallet } from 'lucide-react';
import { useMoney } from '@/components/billing/money';
import { ScreenHeader } from '@/components/screen-header';
import { ListRow } from '@/components/ui/list-row';
import { useTrans } from '@/lib/i18n';
import { index as cash } from '@/routes/cash';
import { index as expenses } from '@/routes/expenses';
import { expensesHub } from '@/routes';
import { index as taxReserve } from '@/routes/tax-reserve';
import { index as trips } from '@/routes/trips';

const tile =
    'da-soft inline-flex size-11 items-center justify-center rounded-[14px] [&_svg]:size-5';

/**
 * More → Expenses & taxes (Owner): expenses, mileage and cash on hand, each with this month's figure.
 */
export default function ExpensesHub({
    expenses: spent,
    distance,
    distanceUnit,
    cash: onHand,
    taxReserve: reserve = null,
}: {
    month: string;
    expenses: number;
    distance: number;
    distanceUnit: 'km' | 'mi';
    cash: number;
    taxReserve?: { month: string; left: number; total: number } | null;
}) {
    const t = useTrans();
    const money = useMoney();
    const { auth } = usePage().props;
    const monthName = (value: string) =>
        new Intl.DateTimeFormat(auth.company?.locale ?? 'en-US', {
            month: 'long',
            timeZone: 'UTC',
        }).format(new Date(`${value}T12:00:00Z`));

    return (
        <>
            <Head title={t('nav.expenses_and_taxes')} />

            <ScreenHeader title={t('nav.expenses_and_taxes')} />

            <div className="mx-auto w-full max-w-3xl space-y-4 p-4 sm:p-6">
                <div className="da-card divide-y overflow-hidden">
                    <ListRow
                        href={expenses()}
                        leading={
                            <span className={tile}>
                                <Wallet />
                            </span>
                        }
                        title={t('nav.business_expenses')}
                        subtitle={t('expenses.hub.this_month', {
                            value: money(spent),
                        })}
                        chevron
                    />
                    <ListRow
                        href={taxReserve()}
                        leading={
                            <span className={tile}>
                                <Landmark />
                            </span>
                        }
                        title={t('tax_reserve.title')}
                        subtitle={
                            reserve
                                ? t('expenses.hub.tax_reserve', {
                                      month: monthName(reserve.month),
                                      value: money(reserve.left),
                                  })
                                : t('tax_reserve.set_up')
                        }
                        chevron
                    />
                    <ListRow
                        href={trips()}
                        leading={
                            <span className={tile}>
                                <Car />
                            </span>
                        }
                        title={t('nav.mileage')}
                        subtitle={t('expenses.hub.this_month', {
                            value: `${distance} ${t(`trips.units.${distanceUnit}`)}`,
                        })}
                        chevron
                    />
                    <ListRow
                        href={cash()}
                        leading={
                            <span className={tile}>
                                <Banknote />
                            </span>
                        }
                        title={t('nav.cash')}
                        subtitle={t('expenses.hub.on_hand', {
                            value: money(onHand),
                        })}
                        chevron
                    />
                </div>
            </div>
        </>
    );
}

ExpensesHub.layout = {
    breadcrumbs: [{ title: 'nav.expenses_and_taxes', href: expensesHub() }],
};
