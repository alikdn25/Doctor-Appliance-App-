import { Plus, Trash2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { useTrans } from '@/lib/i18n';

/**
 * Rows edited in place (phones, emails, arrival windows, tax rates): each row is rendered by the caller, with a
 * Remove button; "Add" appends a new row.
 */
export function EditableList<T>({
    items,
    onChange,
    renderItem,
    newItem,
    addLabel,
    minItems = 0,
    itemKey,
}: {
    items: T[];
    onChange: (items: T[]) => void;
    renderItem: (item: T, update: (item: T) => void, index: number) => ReactNode;
    newItem: () => T;
    addLabel: string;
    minItems?: number;
    itemKey: (item: T, index: number) => string | number;
}) {
    const t = useTrans();

    return (
        <div className="space-y-2" data-slot="editable-list">
            {items.map((item, index) => (
                <div key={itemKey(item, index)} className="flex items-start gap-2">
                    <div className="min-w-0 flex-1">
                        {renderItem(
                            item,
                            (next) => onChange(items.map((it, i) => (i === index ? next : it))),
                            index,
                        )}
                    </div>
                    {items.length > minItems && (
                        <Button
                            type="button"
                            variant="destructive"
                            size="icon"
                            aria-label={t('ui.remove')}
                            onClick={() => onChange(items.filter((_, i) => i !== index))}
                        >
                            <Trash2 />
                        </Button>
                    )}
                </div>
            ))}
            <Button type="button" variant="tertiary" onClick={() => onChange([...items, newItem()])}>
                <Plus /> {addLabel}
            </Button>
        </div>
    );
}
