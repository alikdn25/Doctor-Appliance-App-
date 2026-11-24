import { router } from '@inertiajs/react';
import { Pencil, Plus, Trash2, Wallet } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import {
    currencyDecimals,
    fromMinor,
    useMoney,
} from '@/components/billing/money';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTrans } from '@/lib/i18n';
import {
    destroy as destroyExpense,
    store as storeExpenses,
    update as updateExpense,
} from '@/routes/jobs/my-expenses';

export type JobMyExpenses = {
    currency: string;
    total: number;
    can_add: boolean;
    lines: {
        id: number;
        description: string;
        amount: number;
        currency: string;
        spent_on: string;
        by: string | null;
        can_edit: boolean;
    }[];
};

type Line = { description: string; amount: string };

const emptyLine = (): Line => ({ description: '', amount: '' });

/**
 * "My expenses" of a job (owner's task of November 24): what was paid for the job (a part, parking). One amount
 * without a name or several lines (name + amount); the total is added up. Each line is a business expense linked to
 * the job, also listed in Expenses & taxes. Never shown to the customer.
 */
export function JobMyExpensesSection({
    jobId,
    expenses,
}: {
    jobId: number;
    expenses: JobMyExpenses;
}) {
    const t = useTrans();
    const money = useMoney(expenses.currency);
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<number | null>(null);
    const [lines, setLines] = useState<Line[]>([emptyLine()]);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const close = () => {
        setAdding(false);
        setEditing(null);
        setLines([emptyLine()]);
        setError(null);
    };
    const setLine = (index: number, patch: Partial<Line>) =>
        setLines((all) =>
            all.map((line, i) => (i === index ? { ...line, ...patch } : line)),
        );
    const options = {
        preserveScroll: true,
        onStart: () => setBusy(true),
        onFinish: () => setBusy(false),
        onSuccess: close,
        onError: (errors: Record<string, string>) =>
            setError(String(Object.values(errors)[0] ?? '')),
    };
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (editing === null) {
            router.post(storeExpenses(jobId).url, { lines }, options);
        } else {
            router.put(
                updateExpense({ job: jobId, expense: editing }).url,
                lines[0],
                options,
            );
        }
    };
    const startEdit = (line: JobMyExpenses['lines'][number]) => {
        setEditing(line.id);
        setAdding(true);
        setError(null);
        setLines([
            {
                description: line.description,
                amount: fromMinor(line.amount, line.currency),
            },
        ]);
    };
    const remove = (line: JobMyExpenses['lines'][number]) => {
        if (
            !window.confirm(
                t('expenses.job_block.confirm_remove', {
                    name: line.description,
                }),
            )
        ) {
            return;
        }
        router.delete(destroyExpense({ job: jobId, expense: line.id }).url, {
            preserveScroll: true,
        });
    };
    const typed = lines.reduce((sum, line) => {
        const value = Number.parseFloat(
            line.amount.replace(/[^\d.,-]/g, '').replace(',', '.'),
        );

        return sum + (Number.isFinite(value) && value > 0 ? value : 0);
    }, 0);

    return (
        <div
            className="da-card space-y-2 p-3 text-sm"
            data-test="job-my-expenses"
        >
            <div className="flex items-center justify-between gap-2">
                <p className="flex items-center gap-2 font-semibold">
                    <Wallet className="size-4 text-primary" />
                    {t('expenses.job_block.title')}
                </p>
                {expenses.can_add && !adding && (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                            setLines([emptyLine()]);
                            setAdding(true);
                        }}
                        data-test="job-my-expenses-add"
                    >
                        <Plus /> {t('expenses.job_block.add')}
                    </Button>
                )}
            </div>
            <p className="text-xs text-muted-foreground">
                {t('expenses.job_block.hint')}
            </p>
            {expenses.lines.length === 0 && !adding ? (
                <p className="text-muted-foreground">
                    {t('expenses.job_block.empty')}
                </p>
            ) : (
                <ul className="divide-y">
                    {expenses.lines.map((line) => (
                        <li
                            key={line.id}
                            className="flex min-h-11 items-center gap-2 py-1"
                            data-test="job-my-expense"
                        >
                            <span className="min-w-0 flex-1">
                                {line.description}
                                {line.by && (
                                    <span className="block text-xs text-muted-foreground">
                                        {t('expenses.job_block.by', {
                                            name: line.by,
                                        })}
                                    </span>
                                )}
                            </span>
                            <span className="tabular-nums">
                                {money(line.amount, line.currency)}
                            </span>
                            {line.can_edit && (
                                <>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label={t(
                                            'expenses.job_block.edit',
                                        )}
                                        onClick={() => startEdit(line)}
                                    >
                                        <Pencil />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label={t(
                                            'expenses.job_block.remove',
                                        )}
                                        onClick={() => remove(line)}
                                    >
                                        <Trash2 />
                                    </Button>
                                </>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            {expenses.lines.length > 0 && (
                <p
                    className="flex justify-between gap-2 border-t pt-1 font-bold"
                    data-test="job-my-expenses-total"
                >
                    <span>{t('expenses.job_block.total')}</span>
                    <span className="tabular-nums">
                        {money(expenses.total)}
                    </span>
                </p>
            )}
            {adding && (
                <form onSubmit={submit} className="space-y-2">
                    {lines.map((line, i) => (
                        <div key={i} className="flex items-center gap-2">
                            <Input
                                value={line.description}
                                maxLength={255}
                                placeholder={t(
                                    'expenses.job_block.what_placeholder',
                                )}
                                aria-label={t('expenses.job_block.what')}
                                onChange={(e) =>
                                    setLine(i, { description: e.target.value })
                                }
                                className="min-w-0 flex-1"
                            />
                            <Input
                                value={line.amount}
                                inputMode="decimal"
                                placeholder="0.00"
                                aria-label={t('expenses.job_block.amount')}
                                onChange={(e) =>
                                    setLine(i, { amount: e.target.value })
                                }
                                className="w-28 text-right tabular-nums"
                                autoFocus={i === lines.length - 1}
                            />
                            {lines.length > 1 && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label={t('expenses.job_block.remove')}
                                    onClick={() =>
                                        setLines((all) =>
                                            all.filter((_, x) => x !== i),
                                        )
                                    }
                                >
                                    <Trash2 />
                                </Button>
                            )}
                        </div>
                    ))}
                    {editing === null && (
                        <div className="flex items-center justify-between gap-2">
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                    setLines((all) => [...all, emptyLine()])
                                }
                            >
                                {t('expenses.job_block.add_line')}
                            </Button>
                            {lines.length > 1 && (
                                <span className="font-semibold tabular-nums">
                                    {t('expenses.job_block.total')}:{' '}
                                    {money(
                                        Math.round(
                                            typed *
                                                10 **
                                                    currencyDecimals(
                                                        expenses.currency,
                                                    ),
                                        ),
                                    )}
                                </span>
                            )}
                        </div>
                    )}
                    <InputError message={error ?? undefined} />
                    <div className="grid grid-cols-2 gap-2">
                        <Button type="button" variant="outline" onClick={close}>
                            {t('expenses.job_block.cancel')}
                        </Button>
                        <Button
                            type="submit"
                            loading={busy}
                            data-test="job-my-expenses-save"
                        >
                            {t('expenses.job_block.save')}
                        </Button>
                    </div>
                </form>
            )}
        </div>
    );
}
