import { Link, router } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type Column<T> = {
    key: string;
    label: string;
    /** The server sort key; the column is not sortable without it. */
    sort?: string;
    align?: 'left' | 'right';
    className?: string;
    render: (row: T) => ReactNode;
};

/**
 * A table for wide screens (UX-REDESIGN §13): the header stays at the top while the page scrolls, a tap on a column
 * title sorts by it (on the server, ?sort= & ?dir=), and the whole row opens the record.
 */
export function DataTable<T>({
    rows,
    columns,
    rowKey,
    rowHref,
    rowClassName,
    sort,
    dir,
    sortUrl,
    query = {},
    label,
    className,
}: {
    rows: T[];
    columns: Column<T>[];
    rowKey: (row: T) => string | number;
    rowHref: (row: T) => NonNullable<InertiaLinkProps['href']>;
    rowClassName?: (row: T) => string | false | undefined;
    sort?: string;
    dir?: 'asc' | 'desc';
    /** The list's URL; sorting keeps the other filters in `query`. */
    sortUrl?: string;
    query?: Record<string, string | number | boolean | null | undefined>;
    label: string;
    className?: string;
}) {
    const sortBy = (key: string) => {
        if (!sortUrl) {
            return;
        }

        const nextDir = sort === key && dir === 'asc' ? 'desc' : 'asc';
        router.get(
            sortUrl,
            Object.fromEntries(
                Object.entries({ ...query, sort: key, dir: nextDir }).filter(
                    ([, value]) => value !== '' && value !== null && value !== undefined,
                ),
            ),
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <div
            className={cn('da-card overflow-x-clip', className)}
            data-slot="data-table"
        >
            <table className="w-full border-collapse text-sm" aria-label={label}>
                <thead className="sticky top-[var(--app-sticky-top)] z-10 bg-card">
                    <tr className="border-b text-xs text-muted-foreground">
                        {columns.map((column) => {
                            const active = column.sort !== undefined && sort === column.sort;
                            const Icon = active
                                ? dir === 'desc'
                                    ? ArrowDown
                                    : ArrowUp
                                : ArrowUpDown;

                            return (
                                <th
                                    key={column.key}
                                    scope="col"
                                    aria-sort={
                                        active
                                            ? dir === 'desc'
                                                ? 'descending'
                                                : 'ascending'
                                            : undefined
                                    }
                                    className={cn(
                                        'font-semibold whitespace-nowrap',
                                        !column.sort && 'px-3 py-2',
                                        column.align === 'right' ? 'text-right' : 'text-left',
                                        column.className,
                                    )}
                                >
                                    {column.sort ? (
                                        <button
                                            type="button"
                                            // The whole cell sorts, not only the title.
                                            className={cn(
                                                'inline-flex min-h-10 w-full items-center gap-1 px-3 py-2 hover:text-foreground',
                                                column.align === 'right' && 'justify-end',
                                                active && 'text-foreground',
                                            )}
                                            onClick={() => sortBy(column.sort!)}
                                        >
                                            {column.label}
                                            <Icon
                                                className={cn(
                                                    'size-3.5',
                                                    !active && 'opacity-40',
                                                )}
                                                aria-hidden="true"
                                            />
                                        </button>
                                    ) : (
                                        column.label
                                    )}
                                </th>
                            );
                        })}
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {rows.map((row) => (
                        <tr
                            key={rowKey(row)}
                            className={cn(
                                'cursor-pointer hover:bg-black/[0.03]',
                                rowClassName?.(row),
                            )}
                            onClick={(e) => {
                                // Links and buttons in a cell keep their own action.
                                if ((e.target as HTMLElement).closest('a,button')) {
                                    return;
                                }

                                router.visit(rowHref(row));
                            }}
                        >
                            {columns.map((column, index) => (
                                <td
                                    key={column.key}
                                    className={cn(
                                        'px-3 py-2.5 align-middle',
                                        column.align === 'right' && 'text-right tabular-nums',
                                        column.className,
                                    )}
                                >
                                    {index === 0 ? (
                                        <Link
                                            href={rowHref(row)}
                                            className="font-semibold hover:underline"
                                        >
                                            {column.render(row)}
                                        </Link>
                                    ) : (
                                        column.render(row)
                                    )}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
