import { useForm } from '@inertiajs/react';
import { ArrowRightLeft, UserRoundPen } from 'lucide-react';
import { useState } from 'react';
import { FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useTrans } from '@/lib/i18n';
import { replace, transferJobs } from '@/routes/team';

type Member = { id: number; name: string; email: string; role: string; is_active: boolean };

export function MemberTransitions({ member, members }: { member: Member; members: Member[] }) {
    const t = useTrans();
    const [mode, setMode] = useState<'transfer' | 'replace' | null>(null);
    const form = useForm({ replacement_id: '', name: '', password: '', password_confirmation: '' });
    const show = (value: 'transfer' | 'replace') => { form.reset(); form.clearErrors(); setMode(value); };
    return <>
        <Button variant="outline" className="h-11" onClick={() => show('transfer')}><ArrowRightLeft />{t('team.transfer_jobs')}</Button>
        {member.role === 'technician' && !member.email.endsWith('@retired.invalid') && <Button variant="outline" className="h-11" onClick={() => show('replace')}><UserRoundPen />{t('team.replace_technician')}</Button>}
        <Dialog open={mode !== null} onOpenChange={(open) => { if (!open) { setMode(null); form.reset(); } }}>
            <DialogContent>
                <DialogHeader><DialogTitle>{t(mode === 'replace' ? 'team.replace_technician' : 'team.transfer_jobs')}</DialogTitle><DialogDescription>{t(mode === 'replace' ? 'team.replace_hint' : 'team.transfer_hint', { name: member.name })}</DialogDescription></DialogHeader>
                <form className="space-y-4" onSubmit={(event) => { event.preventDefault(); form.post(mode === 'replace' ? replace(member.id).url : transferJobs(member.id).url, { preserveScroll: true, onSuccess: () => { setMode(null); form.reset(); }, onFinish: () => form.reset('password', 'password_confirmation') }); }}>
                    {mode === 'replace' ? <>
                        <p className="text-sm">{t('team.login_kept', { email: member.email })}</p>
                        <FormField id={`replacement-name-${member.id}`} label={t('settings.profile.name')} error={form.errors.name}><Input id={`replacement-name-${member.id}`} required value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></FormField>
                        <FormField id={`replacement-password-${member.id}`} label={t('auth.fields.password')} error={form.errors.password}><Input id={`replacement-password-${member.id}`} type="password" autoComplete="new-password" required value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} /></FormField>
                        <FormField id={`replacement-confirmation-${member.id}`} label={t('auth.fields.password_confirmation')}><Input id={`replacement-confirmation-${member.id}`} type="password" autoComplete="new-password" required value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)} /></FormField>
                    </> : <FormField id={`replacement-id-${member.id}`} label={t('team.transfer_to')} error={form.errors.replacement_id}><NativeSelect id={`replacement-id-${member.id}`} required value={form.data.replacement_id} onChange={(e) => form.setData('replacement_id', e.target.value)}><option value="">{t('team.choose_replacement')}</option>{members.filter((target) => target.id !== member.id && target.is_active).map((target) => <option value={target.id} key={target.id}>{target.name}</option>)}</NativeSelect></FormField>}
                    <Button type="submit" disabled={form.processing} className="h-11 w-full">{t(mode === 'replace' ? 'team.replace_technician' : 'team.transfer_jobs')}</Button>
                </form>
            </DialogContent>
        </Dialog>
    </>;
}
