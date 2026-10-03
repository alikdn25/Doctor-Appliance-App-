import { Check } from 'lucide-react';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export function AccountSetupSteps({ current }: { current: 1 | 2 | 3 }) {
    const t = useTrans();
    const labels = [
        t('onboarding.steps.account'),
        t('onboarding.steps.email'),
        t('onboarding.steps.company'),
    ];

    return (
        <ol
            aria-label={t('onboarding.steps.label')}
            className="grid grid-cols-3 gap-2"
        >
            {labels.map((label, index) => {
                const step = index + 1;
                return (
                    <li
                        key={label}
                        aria-current={step === current ? 'step' : undefined}
                        className="flex flex-col items-center gap-2 text-center"
                    >
                        <span
                            className={cn(
                                'flex size-8 items-center justify-center rounded-full border text-sm font-semibold',
                                step <= current
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'border-border text-muted-foreground',
                            )}
                        >
                            {step < current ? (
                                <Check className="size-4" aria-hidden="true" />
                            ) : (
                                step
                            )}
                        </span>
                        <span
                            className={cn(
                                'text-xs',
                                step === current
                                    ? 'font-semibold text-foreground'
                                    : 'text-muted-foreground',
                            )}
                        >
                            {label}
                        </span>
                    </li>
                );
            })}
        </ol>
    );
}
