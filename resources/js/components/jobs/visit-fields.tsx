import { FormField } from '@/components/form-field';
import InputError from '@/components/input-error';
import type { Assignable } from '@/components/jobs/types';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { useTrans } from '@/lib/i18n';

export type VisitFormValue = {
    date: string;
    start_time: string;
    end_time: string;
    estimated_duration_minutes: string;
    assignee_ids: number[];
};

/**
 * Date, arrival window, duration and assigned people of a visit (job form and visit dialog).
 */
export function VisitFields({
    value,
    onChange,
    errors,
    errorPrefix = '',
    assignableUsers,
}: {
    value: VisitFormValue;
    onChange: (patch: Partial<VisitFormValue>) => void;
    errors: Record<string, string | undefined>;
    errorPrefix?: string;
    assignableUsers: Assignable[];
}) {
    const t = useTrans();
    const err = (field: string) =>
        errors[`${errorPrefix}${field}`] ??
        Object.entries(errors).find(([k]) =>
            k.startsWith(`${errorPrefix}${field}.`),
        )?.[1];

    return (
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <FormField
                id={`${errorPrefix}date`}
                label={t('jobs.visit_fields.date')}
                error={err('date')}
                className="col-span-2 sm:col-span-1"
            >
                <Input
                    id={`${errorPrefix}date`}
                    type="date"
                    value={value.date}
                    onChange={(e) => onChange({ date: e.target.value })}
                />
            </FormField>
            <FormField
                id={`${errorPrefix}start_time`}
                label={t('jobs.visit_fields.start_time')}
                error={err('start_time')}
            >
                <Input
                    id={`${errorPrefix}start_time`}
                    type="time"
                    step={900}
                    value={value.start_time}
                    onChange={(e) => onChange({ start_time: e.target.value })}
                />
            </FormField>
            <FormField
                id={`${errorPrefix}end_time`}
                label={t('jobs.visit_fields.end_time')}
                error={err('end_time')}
            >
                <Input
                    id={`${errorPrefix}end_time`}
                    type="time"
                    step={900}
                    value={value.end_time}
                    onChange={(e) => onChange({ end_time: e.target.value })}
                />
            </FormField>
            <FormField
                id={`${errorPrefix}estimated_duration_minutes`}
                label={t('jobs.visit_fields.estimated_duration_minutes')}
                error={err('estimated_duration_minutes')}
                className="col-span-2 sm:col-span-1"
            >
                <Input
                    id={`${errorPrefix}estimated_duration_minutes`}
                    type="number"
                    inputMode="numeric"
                    min={5}
                    step={15}
                    value={value.estimated_duration_minutes}
                    onChange={(e) =>
                        onChange({
                            estimated_duration_minutes: e.target.value,
                        })
                    }
                />
            </FormField>
            <fieldset className="col-span-2 space-y-2 sm:col-span-4">
                <legend className="mb-2 text-sm font-medium">
                    {t('jobs.visit_fields.assignee_ids')}
                </legend>
                <div className="grid gap-2 sm:grid-cols-2">
                    {assignableUsers.map((u) => (
                        <label
                            key={u.id}
                            className="flex min-h-11 items-center gap-3 rounded-md border px-3 py-2 text-sm"
                        >
                            <Checkbox
                                checked={value.assignee_ids.includes(u.id)}
                                onCheckedChange={(c) =>
                                    onChange({
                                        assignee_ids:
                                            c === true
                                                ? [...value.assignee_ids, u.id]
                                                : value.assignee_ids.filter(
                                                      (id) => id !== u.id,
                                                  ),
                                    })
                                }
                            />
                            <span>
                                {u.name}
                                <span className="text-muted-foreground">
                                    {' · '}
                                    {u.role}
                                </span>
                            </span>
                        </label>
                    ))}
                </div>
                <InputError message={err('assignee_ids')} />
            </fieldset>
        </div>
    );
}
