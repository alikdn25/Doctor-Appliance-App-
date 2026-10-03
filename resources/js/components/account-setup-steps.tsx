import { Check } from 'lucide-react';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export function AccountSetupSteps({
    current,
    confirmationRequired = true,
}: {
    current: 1 | 2 | 3;
    confirmationRequired?: boolean;
}) {
    const t = useTrans();
    const steps = [
        { id: 1, label: t('onboarding.steps.account') },
        ...(confirmationRequired
            ? [{ id: 2, label: t('onboarding.steps.email') }]
            : []),
        { id: 3, label: t('onboarding.steps.company') },
    ];

    return (
        <ol
            aria-label={t('onboarding.steps.label')}
            className={cn(
                'grid gap-2',
                confirmationRequired ? 'grid-cols-3' : 'grid-cols-2',
            )}
        >
            {steps.map(({ id: step, label }, index) => {
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
                                index + 1
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
