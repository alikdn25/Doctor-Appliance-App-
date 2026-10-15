import { Head } from '@inertiajs/react';
import { Banknote, Car, Wallet } from 'lucide-react';
import { useMoney } from '@/components/billing/money';
import { PageHeader } from '@/components/page-header';
import { ListRow } from '@/components/ui/list-row';
import { useTrans } from '@/lib/i18n';
import { index as cash } from '@/routes/cash';
import { index as expenses } from '@/routes/expenses';
import { expensesHub } from '@/routes';
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
}: {
    month: string;
    expenses: number;
    distance: number;
    distanceUnit: 'km' | 'mi';
    cash: number;
}) {
    const t = useTrans();
    const money = useMoney();

    return (
        <>
            <Head title={t('nav.expenses_and_taxes')} />

            <div className="max-w-3xl space-y-4 p-4">
                <PageHeader title={t('nav.expenses_and_taxes')} />

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
