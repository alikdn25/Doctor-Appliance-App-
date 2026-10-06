import { Upload } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useTrans } from '@/lib/i18n';

/**
 * A raised button for picking a file (photo or PDF) instead of the browser's own file field, whose text
 * follows the phone's language ("Choose file / No file chosen"). Shows the picked file's name.
 */
export function FilePicker({
    id,
    accept,
    capture,
    disabled,
    file,
    label,
    onChange,
}: {
    id: string;
    accept: string;
    capture?: 'environment' | 'user';
    disabled?: boolean;
    file: File | null;
    label?: string;
    onChange: (file: File | null) => void;
}) {
    const t = useTrans();

    return (
        <Button
            asChild
            variant="outline"
            className="min-h-11 cursor-pointer justify-start"
            aria-disabled={disabled}
        >
            <label htmlFor={id}>
                <Upload aria-hidden="true" />
                <span className="truncate">
                    {file?.name ?? label ?? t('common.choose_file')}
                </span>
                <input
                    id={id}
                    type="file"
                    accept={accept}
                    capture={capture}
                    disabled={disabled}
                    className="sr-only"
                    onChange={(event) =>
                        onChange(event.target.files?.[0] ?? null)
                    }
                />
            </label>
        </Button>
    );
}
