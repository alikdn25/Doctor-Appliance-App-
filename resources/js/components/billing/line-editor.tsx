import { BookPlus, ChevronDown, History, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { currencyDecimals, fromMinor, toMinor } from './money';
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
    optional: boolean;
    selected: boolean;
    kind: LineKind;
    service_id: number | null;
    part_number: string;
    supplier: string;
    unit: string;
    unit_cost: string;
    /** Supplier tax paid, by tax rate id (major units as typed). */
    supplier_taxes: Record<string, string>;
    bill_to_customer: boolean;
    warranty_value: string;
    warranty_unit: string;
    /** The price / warranty were typed by hand: stop filling them in automatically. */
    price_touched: boolean;
    warranty_touched: boolean;
};

type Tier = { up_to: number | null; multiplier: number };
type Length = { value: number; unit: string };

export type LineSetup = {
    costs_visible: boolean;
    markup: { part: Tier[]; material: Tier[] };
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
    optional: false,
    selected: false,
    kind: 'service',
    service_id: null,
    part_number: '',
    supplier: '',
    unit: '',
    unit_cost: '',
    supplier_taxes: {},
    bill_to_customer: true,
    warranty_value: '',
    warranty_unit: 'days',
    price_touched: false,
    warranty_touched: false,
    ...patch,
});

/** Multiplier of the markup scale for a cost (minor units). */
export function markupFor(scale: Tier[], cost: number): number {
    return (
        scale.find((tier) => tier.up_to === null || cost <= tier.up_to)
            ?.multiplier ?? 1
    );
}

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
 * parts and materials add part number, supplier, cost (price from the markup scale), unit, supplier tax.
 * Cost, warranty and "bill to customer" are under "Cost & warranty".
 */
export function LineEditor({
    line,
    index,
    currency,
    symbol,
    total,
    setup,
    services,
    estimate,
    canRemove,
    errors,
    money,
    onChange,
    onRemove,
}: {
    line: Line;
    index: number;
    currency: string;
    symbol: string;
    total: number;
    setup: LineSetup;
    services: ServiceOption[];
    estimate: boolean;
    canRemove: boolean;
    errors: Record<string, string | undefined>;
    money: (minor: number, currency?: string) => string;
    onChange: (patch: Partial<Line>) => void;
    onRemove: () => void;
}) {
    const t = useTrans();
    const time = useCompanyTime();
    const goods = line.kind !== 'service';
    const [open, setOpen] = useState(goods || !line.bill_to_customer);
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
    const err = (field: string) => errors[`items.${index}.${field}`];
    const costMinor = toMinor(line.unit_cost, currency);
    const priceMinor = toMinor(line.unit_price, currency);
    const multiplier =
        goods && line.unit_cost !== ''
            ? markupFor(
                  setup.markup[line.kind === 'material' ? 'material' : 'part'],
                  costMinor,
              )
            : null;
    const margin =
        setup.costs_visible && line.unit_cost !== '' && priceMinor > 0
            ? Math.round(((priceMinor - costMinor) / priceMinor) * 100)
            : null;
    const unitLabel = line.unit || t('billing.units.pcs');

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

        if (!goods || !setup.costs_visible || term.length < 2) {
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
    }, [line.part_number, line.description, goods, setup.costs_visible]);

    const setCost = (value: string) => {
        const patch: Partial<Line> = { unit_cost: value };
        const cost = toMinor(value, currency);

        if (!line.price_touched && value !== '' && goods) {
            const m = markupFor(
                setup.markup[line.kind === 'material' ? 'material' : 'part'],
                cost,
            );
            patch.unit_price = fromMinor(Math.round(cost * m), currency);
        }

        onChange(patch);
    };

    const pickService = (id: string) => {
        const service = services.find((s) => String(s.id) === id);

        if (!service) {
            return;
        }

        onChange({
            service_id: service.id,
            kind: (service.kind as LineKind) ?? 'service',
            description: [service.name, service.description]
                .filter(Boolean)
                .join(' — '),
            taxable: service.taxable,
            part_number: service.part_number ?? '',
            unit: service.unit ?? '',
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
                unit_cost: line.unit_cost || null,
                unit_price: line.unit_price || null,
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
            <div className="grid grid-cols-3 gap-1">
                {(['service', 'part', 'material'] as const).map((kind) => (
                    <Button
                        key={kind}
                        type="button"
                        size="sm"
                        variant={line.kind === kind ? 'default' : 'outline'}
                        onClick={() => {
                            onChange({ kind });

                            if (kind !== 'service') {
                                setOpen(true);
                            }
                        }}
                    >
                        {t(`billing.kinds.${kind}`)}
                    </Button>
                ))}
            </div>

            {services.length > 0 && (
                <NativeSelect
                    aria-label={t('billing.pick_service')}
                    value=""
                    onChange={(e) => pickService(e.target.value)}
                >
                    <option value="">{t('billing.pick_service')}</option>
                    {[...new Set(services.map((service) => service.category))]
                        .sort((a, b) => (a ?? '').localeCompare(b ?? ''))
                        .map((category) => (
                            <optgroup
                                key={category ?? ''}
                                label={category ?? t('services.uncategorized')}
                            >
                                {services
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
                    {setup.costs_visible && (
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

            {goods && setup.costs_visible && history.length > 0 && (
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

            <div className="grid grid-cols-[5rem_1fr] gap-2 sm:grid-cols-[5rem_8rem_1fr]">
                <div>
                    <Input
                        aria-label={t('billing.fields.quantity')}
                        inputMode="decimal"
                        value={line.quantity}
                        onChange={(e) => onChange({ quantity: e.target.value })}
                    />
                    <InputError message={err('quantity')} />
                </div>
                {line.kind === 'material' ? (
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
                ) : (
                    <span className="hidden sm:block" />
                )}
                <div className="col-span-2 flex items-center justify-between gap-3 sm:col-span-1">
                    <label className="flex min-h-10 items-center gap-2 text-sm">
                        <Checkbox
                            checked={line.taxable}
                            onCheckedChange={(c) =>
                                onChange({ taxable: c === true })
                            }
                        />
                        {t('billing.taxable')}
                    </label>
                    <span
                        className={
                            !line.bill_to_customer ||
                            (line.optional && !line.selected)
                                ? 'text-muted-foreground tabular-nums line-through'
                                : 'font-medium tabular-nums'
                        }
                    >
                        {money(total)}
                    </span>
                </div>
            </div>
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

            <div className="grid grid-cols-2 gap-2">
                {goods && setup.costs_visible && (
                    <div>
                        <label className="text-xs text-muted-foreground">
                            {line.kind === 'material'
                                ? t('billing.line.cost_per_unit', {
                                      unit: unitLabel,
                                  })
                                : t('billing.line.cost')}
                        </label>
                        <MoneyInput
                            symbol={symbol}
                            currency={currency}
                            value={line.unit_cost}
                            label={t('billing.line.cost')}
                            onChange={setCost}
                        />
                        <InputError message={err('unit_cost')} />
                    </div>
                )}
                <div
                    className={goods && setup.costs_visible ? '' : 'col-span-2'}
                >
                    <label className="text-xs text-muted-foreground">
                        {line.kind === 'material'
                            ? t('billing.line.price_per_unit', {
                                  unit: unitLabel,
                              })
                            : t('billing.fields.unit_price')}
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
                    <InputError message={err('unit_price')} />
                </div>
            </div>
            {multiplier !== null && !line.price_touched && (
                <p className="text-xs text-muted-foreground">
                    {t('billing.line.markup_hint', { multiplier })}
                </p>
            )}
            {margin !== null && (
                <p className="text-xs text-muted-foreground">
                    {t('billing.line.margin', { percent: margin })}
                </p>
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
                        setup.costs_visible &&
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
                                                                      Number(
                                                                          line.quantity ||
                                                                              0,
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
                            {!line.bill_to_customer && (
                                <span className="block text-xs text-muted-foreground">
                                    {t('billing.line.internal_hint')}
                                </span>
                            )}
                        </span>
                    </label>

                    {goods &&
                        setup.costs_visible &&
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
