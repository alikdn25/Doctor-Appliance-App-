import { ChevronDown } from 'lucide-react';
import { useRef } from 'react';
import { Textarea } from '@/components/ui/textarea';
import { useTrans } from '@/lib/i18n';

const PLACEHOLDERS = [
    'customer_first_name',
    'customer_name',
    'brand',
    'tech_name',
    'visit_date',
    'arrival_window',
    'address',
    'number',
    'amount',
    'link',
    'review_link',
] as const;

// GSM-7 characters: a text with any other character (e.g. an emoji) is sent as UCS-2 with shorter parts.
const GSM =
    '@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà^{}\\[~]|€';

/** Characters and SMS parts of a text (160 / 153 per part, or 70 / 67 when it has non-GSM characters). */
export function smsParts(text: string): { chars: number; parts: number } {
    // UCS-2 parts are counted in UTF-16 units, as carriers do.
    const chars = text.length;
    let gsm = true;

    for (const c of text) {
        if (!GSM.includes(c)) {
            gsm = false;
            break;
        }
    }

    const single = gsm ? 160 : 70;
    const multi = gsm ? 153 : 67;

    return {
        chars,
        parts: chars === 0 ? 0 : chars <= single ? 1 : Math.ceil(chars / multi),
    };
}

/**
 * One message template (UX-REDESIGN §12.6): opens on tap; chips put a placeholder where the cursor is; a preview
 * shows it with sample details; the length and number of SMS parts are counted.
 */
export function TemplateEditor({
    id,
    label,
    hint,
    value,
    fallback,
    error,
    sample,
    onChange,
}: {
    id: string;
    label: string;
    hint?: string;
    value: string;
    /** The default text, used when the field is empty. */
    fallback: string;
    error?: string;
    sample: Record<string, string>;
    onChange: (value: string) => void;
}) {
    const t = useTrans();
    const field = useRef<HTMLTextAreaElement>(null);
    const text = value || fallback;
    const preview = text.replace(/\{([a-z_]+)\}/g, (match, key: string) =>
        key in sample ? sample[key] : match,
    );
    const { chars, parts } = smsParts(preview);

    const insert = (key: string) => {
        const area = field.current;
        const token = `{${key}}`;
        const current = value || fallback;
        const start = area?.selectionStart ?? current.length;
        const end = area?.selectionEnd ?? current.length;
        const next = current.slice(0, start) + token + current.slice(end);
        onChange(next);
        requestAnimationFrame(() => {
            area?.focus();
            area?.setSelectionRange(start + token.length, start + token.length);
        });
    };

    return (
        <details className="da-card group overflow-hidden" data-test={id}>
            <summary className="flex min-h-14 cursor-pointer list-none items-center gap-3 px-4 py-2 [&::-webkit-details-marker]:hidden">
                <span className="min-w-0 flex-1">
                    <span className="block font-semibold">{label}</span>
                    <span className="block truncate text-sm text-muted-foreground">
                        {preview}
                    </span>
                </span>
                <ChevronDown className="size-5 shrink-0 transition-transform group-open:rotate-180" />
            </summary>
            <div className="space-y-3 border-t p-4">
                <label htmlFor={id} className="sr-only">
                    {label}
                </label>
                <Textarea
                    ref={field}
                    id={id}
                    rows={4}
                    placeholder={fallback}
                    value={value}
                    aria-invalid={error ? true : undefined}
                    onChange={(e) => onChange(e.target.value)}
                />
                {error && <p className="text-sm text-danger">{error}</p>}
                {hint && (
                    <p className="text-xs text-muted-foreground">{hint}</p>
                )}
                <div
                    className="flex flex-wrap gap-1.5"
                    role="group"
                    aria-label={t('messages.placeholders_label')}
                >
                    {PLACEHOLDERS.map((key) => (
                        <button
                            key={key}
                            type="button"
                            className="da-chip da-press inline-flex h-8 items-center rounded-full px-2.5 font-mono text-xs"
                            onClick={() => insert(key)}
                        >
                            {`{${key}}`}
                        </button>
                    ))}
                </div>
                <div className="rounded-2xl bg-muted/60 p-3 text-sm">
                    <p className="mb-1 text-xs font-medium text-muted-foreground">
                        {t('messages.preview')}
                    </p>
                    <p
                        className="whitespace-pre-line"
                        data-test="template-preview"
                    >
                        {preview}
                    </p>
                </div>
                <p className="text-xs text-muted-foreground tabular-nums">
                    {t('messages.sms_count', { chars, parts })}
                </p>
            </div>
        </details>
    );
}
