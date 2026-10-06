import { BookPlus, ChevronDown, History, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    currencyDecimals,
    fromMinor,
    normalizeNumber,
    toMinor,
    toNumber,
} from './money';
import { NumberHint } from './number-hint';
import type { ServiceOption } from './types';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import {
    history as historyRoute,
    store as storeToPriceBook,
} from '@/routes/pricebook';
import type { Option } from '@/types';

export type LineKind = 'service' | 'part' | 'material';

export type Line = {
    key: number;
    id: number | null;
    description: string;
    quantity: string;
    unit_price: string;
    taxable: boolean;
    tax_rate_ids: number[] | null;
    optional: boolean;
    selected: boolean;
    kind: LineKind;
    service_id: number | null;
    part_number: string;
    supplier: string;
    unit: string;
    unit_cost: string;
    costs_editable: boolean;
    /** Supplier tax paid, by tax rate id (major units as typed). */
    supplier_taxes: Record<string, string>;
    bill_to_customer: boolean;
    warranty_value: string;
    warranty_unit: string;
    /** The price / warranty were typed by hand: stop filling them in automatically. */
    price_touched: boolean;
    warranty_touched: boolean;
};

type Length = { value: number; unit: string };

export type LineSetup = {
    costs_visible: boolean;
    warranty: {
        labor: Length;
        parts: Length;
        parts_threshold: number | null;
        parts_above: Length | null;
    };
    warranty_units: Option[];
    units: Option[];
    supplier_taxes: {
        id: number;
        name: string;
        rate: string;
        recoverable: boolean;
    }[];
};

// Keys for React lists only; never sent to the server.
let lineKey = 0;

export const newLine = (patch: Partial<Line> = {}): Line => ({
    key: ++lineKey,
    id: null,
    description: '',
    quantity: '1',
    unit_price: '',
    taxable: true,
    tax_rate_ids: null,
    optional: false,
    selected: false,
    kind: 'service',
    service_id: null,
    part_number: '',
    supplier: '',
    unit: '',
    unit_cost: '',
    costs_editable: true,
    supplier_taxes: {},
    bill_to_customer: true,
    warranty_value: '',
    warranty_unit: 'days',
    price_touched: false,
    warranty_touched: false,
    ...patch,
});

/** The company's default warranty for a line (mirrors App\Support\Billing\Warranty::default). */
export function defaultWarranty(
    setup: LineSetup,
    kind: LineKind,
    unitPrice: number,
    service?: ServiceOption | null,
): Length {
    if (service && service.warranty_value !== null) {
        return {
            value: service.warranty_value,
            unit: service.warranty_unit ?? 'days',
        };
    }

    if (kind === 'part') {
        const w = setup.warranty;

        return w.parts_threshold !== null &&
            w.parts_above &&
            unitPrice > w.parts_threshold
            ? w.parts_above
            : w.parts;
    }

    return setup.warranty.labor;
}

/**
 * One line of an estimate or invoice: a card that works one-handed on a phone. Services keep the short form;
 * parts and materials add part number, supplier, private purchase price, unit, supplier tax.
 * Per-line taxes, cost, warranty and "bill to customer" are under "Taxes, cost & warranty".
 */
export function LineEditor({
    line,
    index,
    currency,
    symbol,
    total,
    setup,
    services,
    taxRates,
    estimate,
    canRemove,
    errors,
    money,
    onChange,
    onRemove,
    autoFocus = false,
}: {
    line: Line;
    index: number;
    currency: string;
    symbol: string;
    total: number;
    setup: LineSetup;
    services: ServiceOption[];
    taxRates: { tax_rate_id: number | null; name: string; rate: string }[];
    estimate: boolean;
    canRemove: boolean;
    errors: Record<string, string | undefined>;
    money: (minor: number, currency?: string) => string;
    onChange: (patch: Partial<Line>) => void;
    onRemove: () => void;
    /** A line just added with "+ Labor / Part / Material": the cursor goes to its description. */
    autoFocus?: boolean;
}) {
    const t = useTrans();
    const time = useCompanyTime();
    const goods = line.kind !== 'service';
    const [open, setOpen] = useState(!line.bill_to_customer);
    const [history, setHistory] = useState<
        {
            description: string;
            part_number: string | null;
            supplier: string | null;
            unit: string | null;
            unit_cost: number | null;
            date: string | null;
        }[]
    >([]);
    const [saved, setSaved] = useState(false);
    const [changingKind, setChangingKind] = useState(false);
    const err = (field: string) => errors[`items.${index}.${field}`];
    const costMinor = toMinor(line.unit_cost, currency);
    const priceMinor = toMinor(line.unit_price, currency);
    const privateCosts = setup.costs_visible && line.costs_editable;
    const difference =
        privateCosts && line.unit_cost !== '' && line.unit_price !== ''
            ? Math.round((priceMinor - costMinor) * toNumber(line.quantity))
            : null;
    // The price book offers items of this line's kind only: picking never switches a part into a service.
    const options = services.filter((s) => s.kind === line.kind);
    const fmt = (value: number) =>
        money(Math.round(value * 10 ** currencyDecimals(currency)));

    // Default warranty follows kind / price / price book item until set by hand.
    useEffect(() => {
        if (line.warranty_touched) {
            return;
        }

        const w = defaultWarranty(
            setup,
            line.kind,
            priceMinor,
            services.find((s) => s.id === line.service_id),
        );

        if (
            String(w.value) !== line.warranty_value ||
            w.unit !== line.warranty_unit
        ) {
            onChange({
                warranty_value: String(w.value),
                warranty_unit: w.unit,
            });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [line.kind, priceMinor, line.service_id, line.warranty_touched]);

    // Earlier costs of this part / material (as the part number or name is typed).
    useEffect(() => {
        const term = (line.part_number || line.description).trim();

        if (!goods || !privateCosts || term.length < 2) {
            setHistory([]);

            return;
        }

        const controller = new AbortController();
        const timer = setTimeout(() => {
            fetch(historyRoute({ query: { q: term } }).url, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((r) => (r.ok ? r.json() : { history: [] }))
                .then((data) => setHistory(data.history ?? []))
                .catch(() => undefined);
        }, 400);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [line.part_number, line.description, goods, privateCosts]);

    const setCost = (value: string) => onChange({ unit_cost: value });

    const pickService = (id: string) => {
        const service = services.find((s) => String(s.id) === id);

        if (!service) {
            return;
        }

        onChange({
            service_id: service.id,
            description: [service.name, service.description]
                .filter(Boolean)
                .join(' — '),
            taxable: service.taxable,
            part_number: service.part_number ?? line.part_number,
            unit: service.unit ?? line.unit,
            supplier: service.supplier ?? line.supplier,
            ...(service.unit_cost !== null && service.currency === currency
                ? { unit_cost: fromMinor(service.unit_cost, currency) }
                : {}),
            ...(service.unit_price !== null && service.currency === currency
                ? {
                      unit_price: fromMinor(service.unit_price, currency),
                      price_touched: true,
                  }
                : {}),
            warranty_touched: false,
        });
    };

    const saveToPriceBook = () => {
        void fetch(storeToPriceBook().url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
                ),
            },
            body: JSON.stringify({
                kind: line.kind,
                name: line.description.trim(),
                part_number: line.part_number || null,
                supplier: line.supplier || null,
                unit: line.unit || null,
                unit_cost: normalizeNumber(line.unit_cost) || null,
                unit_price: normalizeNumber(line.unit_price) || null,
                taxable: line.taxable,
                warranty_value: line.warranty_value || null,
                warranty_unit: line.warranty_unit,
            }),
        }).then((r) => {
            if (r.ok) {
                setSaved(true);
                void r.json().then((data) => onChange({ service_id: data.id }));
            }
        });
    };

    return (
        <li
            className={
                line.bill_to_customer
                    ? 'space-y-3 rounded-lg border p-3'
                    : 'space-y-3 rounded-lg border border-dashed bg-muted/40 p-3'
            }
        >
            {/* The type is picked when the line is added. A filled line shows its type and changes it only on
                request, so typing a part after labor never re-labels the labor line and its price. */}
            {changingKind ||
            (line.description === '' && line.unit_price === '') ? (
                <div className="grid grid-cols-3 gap-1">
                    {(['service', 'part', 'material'] as const).map((kind) => (
                        <Button
                            key={kind}
                            type="button"
                            size="sm"
                            variant={line.kind === kind ? 'default' : 'outline'}
                            onClick={() => {
                                const picked = services.find(
                                    (s) => s.id === line.service_id,
                                );
                                onChange({
                                    kind,
                                    ...(picked && picked.kind !== kind
                                        ? { service_id: null }
                                        : {}),
                                });
                                setChangingKind(false);
                            }}
                        >
                            {t(`billing.kinds.${kind}`)}
                        </Button>
                    ))}
                </div>
            ) : (
                <div className="flex items-center justify-between gap-2">
                    <span className="rounded-md bg-primary/10 px-2 py-0.5 text-xs font-semibold tracking-wide text-primary uppercase">
                        {t(`billing.kinds.${line.kind}`)}
                    </span>
                    <button
                        type="button"
                        className="h-8 text-xs text-muted-foreground underline"
                        onClick={() => setChangingKind(true)}
                    >
                        {t('billing.change_kind')}
                    </button>
                </div>
            )}

            {options.length > 0 && (
                <NativeSelect
                    aria-label={t('billing.pick_service')}
                    value={
                        options.some((s) => s.id === line.service_id)
                            ? String(line.service_id)
                            : ''
                    }
                    onChange={(e) => pickService(e.target.value)}
                >
                    <option value="">{t('billing.pick_service')}</option>
                    {[...new Set(options.map((service) => service.category))]
                        .sort((a, b) => (a ?? '').localeCompare(b ?? ''))
                        .map((category) => (
                            <optgroup
                                key={category ?? ''}
                                label={category ?? t('services.uncategorized')}
                            >
                                {options
                                    .filter(
                                        (service) =>
                                            service.category === category,
                                    )
                                    .map((service) => (
                                        <option
                                            key={service.id}
                                            value={service.id}
                                        >
                                            {service.unit_price !== null
                                                ? `${service.name} · ${money(service.unit_price, service.currency)}`
                                                : service.name}
                                        </option>
                                    ))}
                            </optgroup>
                        ))}
                </NativeSelect>
            )}

            <div className="flex items-start gap-2">
                <div className="flex-1">
                    <Textarea
                        autoFocus={autoFocus}
                        aria-label={t('billing.fields.description')}
                        placeholder={t('billing.fields.description')}
                        rows={2}
                        maxLength={500}
                        value={line.description}
                        onChange={(e) =>
                            onChange({ description: e.target.value })
                        }
                    />
                    <InputError message={err('description')} />
                </div>
                {canRemove && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-10"
                        aria-label={t('billing.remove_item')}
                        onClick={onRemove}
                    >
                        <Trash2 />
                    </Button>
                )}
            </div>

            {goods && (
                <div className="grid grid-cols-2 gap-2">
                    <Input
                        aria-label={t('billing.line.part_number')}
                        placeholder={t('billing.line.part_number')}
                        value={line.part_number}
                        maxLength={100}
                        onChange={(e) =>
                            onChange({
                                part_number: e.target.value.toUpperCase(),
                            })
                        }
                    />
                    {privateCosts && (
                        <Input
                            aria-label={t('billing.line.supplier')}
                            placeholder={t('billing.line.supplier')}
                            value={line.supplier}
                            maxLength={150}
                            onChange={(e) =>
                                onChange({ supplier: e.target.value })
                            }
                        />
                    )}
                </div>
            )}

            {goods && privateCosts && history.length > 0 && (
                <div className="rounded-md bg-muted/50 p-2 text-xs">
                    <p className="mb-1 flex items-center gap-1 font-medium">
                        <History className="size-3" />
                        {t('billing.line.cost_history')}
                    </p>
                    <ul className="space-y-0.5">
                        {history.slice(0, 5).map((h, i) => (
                            <li key={i}>
                                <button
                                    type="button"
                                    className="w-full text-left hover:underline"
                                    onClick={() => {
                                        onChange({
                                            supplier:
                                                h.supplier ?? line.supplier,
                                            part_number:
                                                line.part_number ||
                                                (h.part_number ?? ''),
                                        });

                                        if (h.unit_cost !== null) {
                                            setCost(
                                                fromMinor(
                                                    h.unit_cost,
                                                    currency,
                                                ),
                                            );
                                        }
                                    }}
                                >
                                    {[
                                        h.date ? time.date(h.date) : null,
                                        h.unit_cost !== null
                                            ? `${money(h.unit_cost)}${h.unit ? ` / ${h.unit}` : ''}`
                                            : null,
                                        h.supplier,
                                        h.part_number,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <div className="grid grid-cols-[4.5rem_1fr] gap-2">
                <div>
                    <label className="text-xs text-muted-foreground">
                        {t('billing.fields.quantity')}
                    </label>
                    <Input
                        aria-label={t('billing.fields.quantity')}
                        inputMode="decimal"
                        value={line.quantity}
                        onChange={(e) => onChange({ quantity: e.target.value })}
                    />
                    <NumberHint text={line.quantity} format={String} />
                    <InputError message={err('quantity')} />
                </div>
                <div>
                    <label className="text-xs text-muted-foreground">
                        {t('billing.line.customer_price')}
                    </label>
                    <MoneyInput
                        symbol={symbol}
                        currency={currency}
                        value={line.unit_price}
                        label={t('billing.fields.unit_price')}
                        onChange={(value) =>
                            onChange({ unit_price: value, price_touched: true })
                        }
                    />
                    <NumberHint text={line.unit_price} format={fmt} />
                    <InputError message={err('unit_price')} />
                </div>
            </div>
            {line.kind === 'material' && (
                <NativeSelect
                    aria-label={t('billing.line.unit')}
                    value={
                        setup.units.some((u) => u.value === line.unit) ||
                        line.unit === ''
                            ? line.unit
                            : '__custom'
                    }
                    onChange={(e) =>
                        onChange({
                            unit:
                                e.target.value === '__custom'
                                    ? ' '
                                    : e.target.value,
                        })
                    }
                >
                    <option value="">{t('billing.line.unit')}</option>
                    {setup.units.map((u) => (
                        <option key={u.value} value={u.value}>
                            {u.label}
                        </option>
                    ))}
                    <option value="__custom">
                        {t('billing.line.custom_unit')}
                    </option>
                </NativeSelect>
            )}
            {line.kind === 'material' &&
                line.unit !== '' &&
                !setup.units.some((u) => u.value === line.unit) && (
                    <Input
                        aria-label={t('billing.line.custom_unit')}
                        placeholder={t('billing.line.custom_unit')}
                        value={line.unit.trim()}
                        maxLength={20}
                        onChange={(e) =>
                            onChange({ unit: e.target.value || ' ' })
                        }
                    />
                )}
            <div className="flex min-h-10 items-center justify-between gap-3">
                {taxRates.length > 0 ? (
                    <label className="flex min-h-10 items-center gap-2 text-sm">
                        <Checkbox
                            checked={line.taxable}
                            onCheckedChange={(c) =>
                                onChange({ taxable: c === true })
                            }
                        />
                        {t('billing.taxable')}
                    </label>
                ) : (
                    <span />
                )}
                {line.optional && !line.selected ? (
                    <span className="text-sm text-muted-foreground tabular-nums">
                        {t('billing.line.optional_extra', {
                            amount: money(total),
                        })}
                    </span>
                ) : (
                    <span
                        className={
                            !line.bill_to_customer
                                ? 'text-muted-foreground tabular-nums line-through'
                                : 'font-medium tabular-nums'
                        }
                    >
                        {money(total)}
                    </span>
                )}
            </div>
            {goods && privateCosts && (
                <div className="rounded-xl border border-dashed bg-muted/40 p-3">
                    <label className="text-xs font-medium">
                        {t('billing.line.private_purchase_price')}
                    </label>
                    <p className="mb-2 text-xs text-muted-foreground">
                        {t('billing.line.private_cost_hint')}
                    </p>
                    <MoneyInput
                        symbol={symbol}
                        currency={currency}
                        value={line.unit_cost}
                        label={t('billing.line.private_purchase_price')}
                        onChange={setCost}
                    />
                    <NumberHint text={line.unit_cost} format={fmt} />
                    <InputError message={err('unit_cost')} />
                    {difference !== null && (
                        <p className="mt-2 text-sm font-medium" role="status">
                            {t('billing.line.difference', {
                                amount: money(difference),
                            })}
                        </p>
                    )}
                </div>
            )}

            {estimate && (
                <div className="flex flex-wrap gap-x-4">
                    <label className="flex min-h-10 items-center gap-2 text-sm">
                        <Checkbox
                            checked={line.optional}
                            onCheckedChange={(c) =>
                                onChange({
                                    optional: c === true,
                                    selected: false,
                                })
                            }
                        />
                        {t('estimates.optional')}
                        <span className="text-xs text-muted-foreground">
                            {t('estimates.optional_hint')}
                        </span>
                    </label>
                    {line.optional && (
                        <label className="flex min-h-10 items-center gap-2 text-sm">
                            <Checkbox
                                checked={line.selected}
                                onCheckedChange={(c) =>
                                    onChange({ selected: c === true })
                                }
                            />
                            {t('estimates.included')}
                        </label>
                    )}
                </div>
            )}

            <button
                type="button"
                className="flex min-h-9 items-center gap-1 text-sm text-muted-foreground"
                onClick={() => setOpen((o) => !o)}
            >
                <ChevronDown
                    className={open ? 'size-4 rotate-180' : 'size-4'}
                />
                {t('billing.line.more')}
                {!open && line.warranty_value !== '' && (
                    <span>
                        {' · '}
                        {Number(line.warranty_value) === 0
                            ? t('billing.no_warranty')
                            : `${line.warranty_value} ${t(`billing.warranty_units.${line.warranty_unit}`)}`}
                    </span>
                )}
            </button>

            {open && (
                <div className="space-y-3">
                    <div className="grid grid-cols-[1fr_2fr] gap-2">
                        <div>
                            <label className="text-xs text-muted-foreground">
                                {t('billing.warranty')}
                            </label>
                            <Input
                                aria-label={t('billing.warranty')}
                                inputMode="numeric"
                                value={line.warranty_value}
                                onChange={(e) =>
                                    onChange({
                                        warranty_value: e.target.value.replace(
                                            /\D/g,
                                            '',
                                        ),
                                        warranty_touched: true,
                                    })
                                }
                            />
                        </div>
                        <div>
                            <label className="text-xs text-muted-foreground">
                                &nbsp;
                            </label>
                            <NativeSelect
                                aria-label={t('billing.warranty')}
                                value={line.warranty_unit}
                                onChange={(e) =>
                                    onChange({
                                        warranty_unit: e.target.value,
                                        warranty_touched: true,
                                    })
                                }
                            >
                                {setup.warranty_units.map((u) => (
                                    <option key={u.value} value={u.value}>
                                        {u.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {Number(line.warranty_value) === 0
                            ? t('billing.no_warranty')
                            : null}
                    </p>

                    {goods &&
                        privateCosts &&
                        setup.supplier_taxes.length > 0 && (
                            <div className="space-y-1">
                                <p className="text-xs text-muted-foreground">
                                    {t('billing.line.supplier_tax')}
                                </p>
                                <div className="grid grid-cols-2 gap-2">
                                    {setup.supplier_taxes.map((tax) => (
                                        <div key={tax.id}>
                                            <label className="text-xs">
                                                {tax.name} {tax.rate}% ·{' '}
                                                {tax.recoverable
                                                    ? t(
                                                          'billing.line.recoverable',
                                                      )
                                                    : t(
                                                          'billing.line.not_recoverable',
                                                      )}
                                            </label>
                                            <MoneyInput
                                                symbol={symbol}
                                                currency={currency}
                                                label={tax.name}
                                                value={
                                                    line.supplier_taxes[
                                                        tax.id
                                                    ] ?? ''
                                                }
                                                placeholder={
                                                    line.unit_cost !== ''
                                                        ? fromMinor(
                                                              Math.round(
                                                                  (costMinor *
                                                                      toNumber(
                                                                          line.quantity,
                                                                      ) *
                                                                      Number(
                                                                          tax.rate,
                                                                      )) /
                                                                      100,
                                                              ),
                                                              currency,
                                                          )
                                                        : undefined
                                                }
                                                onChange={(value) =>
                                                    onChange({
                                                        supplier_taxes: {
                                                            ...line.supplier_taxes,
                                                            [tax.id]: value,
                                                        },
                                                    })
                                                }
                                            />
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}

                    {line.taxable && taxRates.length > 1 && (
                        <fieldset className="space-y-2 rounded-md border p-3">
                            <legend className="px-1 text-sm">
                                {t('billing.line.taxes')}
                            </legend>
                            <label className="flex min-h-10 items-center gap-2 text-sm">
                                <Checkbox
                                    checked={line.tax_rate_ids === null}
                                    onCheckedChange={(checked) =>
                                        onChange({
                                            tax_rate_ids:
                                                checked === true ? null : [],
                                        })
                                    }
                                />
                                {t('billing.line.document_taxes')}
                            </label>
                            <div className="flex flex-wrap gap-x-4 gap-y-1">
                                {taxRates.map(
                                    (tax) =>
                                        tax.tax_rate_id !== null && (
                                            <label
                                                key={tax.tax_rate_id}
                                                className="flex min-h-10 items-center gap-2 text-sm"
                                            >
                                                <Checkbox
                                                    checked={
                                                        line.tax_rate_ids ===
                                                            null ||
                                                        line.tax_rate_ids.includes(
                                                            tax.tax_rate_id,
                                                        )
                                                    }
                                                    onCheckedChange={(
                                                        checked,
                                                    ) => {
                                                        const ids =
                                                            line.tax_rate_ids ??
                                                            taxRates.flatMap(
                                                                (rate) =>
                                                                    rate.tax_rate_id ===
                                                                    null
                                                                        ? []
                                                                        : [
                                                                              rate.tax_rate_id,
                                                                          ],
                                                            );
                                                        onChange({
                                                            tax_rate_ids:
                                                                checked === true
                                                                    ? [
                                                                          ...ids,
                                                                          tax.tax_rate_id!,
                                                                      ]
                                                                    : ids.filter(
                                                                          (
                                                                              id,
                                                                          ) =>
                                                                              id !==
                                                                              tax.tax_rate_id,
                                                                      ),
                                                        });
                                                    }}
                                                />
                                                {tax.name} ({tax.rate}%)
                                            </label>
                                        ),
                                )}
                            </div>
                            {taxRates.length === 0 && (
                                <p className="text-xs text-muted-foreground">
                                    {t('billing.line.enable_taxes')}
                                </p>
                            )}
                            <InputError message={err('tax_rate_ids')} />
                        </fieldset>
                    )}

                    <label className="flex min-h-10 items-start gap-2 text-sm">
                        <Checkbox
                            className="mt-0.5"
                            checked={line.bill_to_customer}
                            onCheckedChange={(c) =>
                                onChange({ bill_to_customer: c === true })
                            }
                        />
                        <span>
                            {t('billing.line.bill_to_customer')}
                            <span className="block text-xs text-muted-foreground">
                                {line.bill_to_customer
                                    ? t('billing.line.bill_to_customer_hint')
                                    : t('billing.line.internal_hint')}
                            </span>
                        </span>
                    </label>

                    {goods &&
                        privateCosts &&
                        line.service_id === null &&
                        line.description.trim() !== '' && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={saved}
                                onClick={saveToPriceBook}
                            >
                                <BookPlus />
                                {saved
                                    ? t('billing.line.saved_to_pricebook')
                                    : t('billing.line.save_to_pricebook')}
                            </Button>
                        )}
                </div>
            )}
        </li>
    );
}

function MoneyInput({
    symbol,
    currency,
    value,
    label,
    placeholder,
    onChange,
}: {
    symbol: string;
    currency: string;
    value: string;
    label: string;
    placeholder?: string;
    onChange: (value: string) => void;
}) {
    return (
        <div className="relative">
            <span className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-muted-foreground">
                {symbol}
            </span>
            <Input
                aria-label={label}
                placeholder={placeholder ?? fromMinor(0, currency)}
                inputMode={
                    currencyDecimals(currency) > 0 ? 'decimal' : 'numeric'
                }
                style={{ paddingLeft: `${symbol.length * 0.6 + 1}rem` }}
                value={value}
                onChange={(e) => onChange(e.target.value)}
            />
        </div>
    );
}
