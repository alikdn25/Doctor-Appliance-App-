import { router } from '@inertiajs/react';
import { Camera } from 'lucide-react';
import { useRef } from 'react';
import { headerButtonClass } from '@/components/screen-header';
import { useTrans } from '@/lib/i18n';
import { handReceipt } from '@/lib/receipt-scan';
import { create } from '@/routes/expenses';

/**
 * The camera in Expenses and My expenses (owner's task of November 18): a photo of a receipt opens the new expense
 * filled in by the AI; nothing is saved until Save.
 */
export function ReceiptCameraButton() {
    const t = useTrans();
    const input = useRef<HTMLInputElement>(null);

    return (
        <>
            <button
                type="button"
                className={headerButtonClass}
                aria-label={t('automation.receipt.scan')}
                title={t('automation.receipt.scan')}
                onClick={() => input.current?.click()}
                data-test="receipt-camera"
            >
                <Camera className="size-5" />
            </button>
            <input
                ref={input}
                type="file"
                accept="image/*"
                capture="environment"
                className="hidden"
                onChange={(event) => {
                    const file = event.target.files?.[0];
                    event.target.value = '';
                    if (!file) return;
                    handReceipt(file);
                    router.visit(create({ query: { scan: 1 } }).url);
                }}
            />
        </>
    );
}
