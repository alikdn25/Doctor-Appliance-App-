import { Head } from '@inertiajs/react';
import { Download, Smartphone } from 'lucide-react';
import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { downloadAndroidRelease, hasAppShell } from '@/lib/native-shell';

type Props = {
    release: {
        version_code: number;
        version_name: string | null;
        built_at: string | null;
        commit: string | null;
        size: number;
        url: string;
    } | null;
};

/**
 * The Android app's download page (owner's task of November 22; Owner only): version, build date, Download and how to
 * install it on a phone. Every merge into main builds a new APK (GitHub Actions → android).
 */
export default function AndroidPage({ release }: Props) {
    const t = useTrans();
    const time = useCompanyTime();
    const steps = [1, 2, 3, 4, 5].map((n) =>
        t(`android.install_steps.step_${n}`),
    );

    return (
        <>
            <Head title={t('android.title')} />
            <ScreenHeader title={t('android.title')} />
            <div className="mx-auto grid w-full max-w-md gap-4 p-4 pb-24 sm:p-6">
                <div
                    className="da-card grid gap-3 rounded-[18px] p-5 text-center"
                    data-test="android-release"
                >
                    <Smartphone className="mx-auto size-10 text-link" />
                    <p className="text-sm text-muted-foreground">
                        {t('android.subtitle')}
                    </p>
                    {release ? (
                        <>
                            <p
                                className="text-2xl font-bold tracking-tight"
                                data-test="android-version"
                            >
                                {t('android.version', {
                                    version:
                                        release.version_name ??
                                        String(release.version_code),
                                })}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {[
                                    release.built_at
                                        ? t('android.built', {
                                              date: time.dateTime(
                                                  release.built_at,
                                              ),
                                          })
                                        : null,
                                    t('android.size', {
                                        size: (
                                            release.size /
                                            1024 /
                                            1024
                                        ).toFixed(1),
                                    }),
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </p>
                            {hasAppShell() ? (
                                <Button
                                    size="lg"
                                    className="da-fab-glow h-14 text-lg"
                                    onClick={() =>
                                        downloadAndroidRelease(release.url)
                                    }
                                    data-test="android-download"
                                >
                                    <Download /> {t('android.download')}
                                </Button>
                            ) : (
                                <Button
                                    size="lg"
                                    className="da-fab-glow h-14 text-lg"
                                    asChild
                                >
                                    <a
                                        href={release.url}
                                        download="doctor-appliance.apk"
                                        data-test="android-download"
                                    >
                                        <Download /> {t('android.download')}
                                    </a>
                                </Button>
                            )}
                        </>
                    ) : (
                        <p
                            className="text-sm text-muted-foreground"
                            data-test="android-none"
                        >
                            {t('android.none')}
                        </p>
                    )}
                </div>

                <div className="da-card grid gap-2 rounded-[18px] p-5">
                    <p className="font-semibold">
                        {t('android.install_title')}
                    </p>
                    <ol className="grid list-decimal gap-1 pl-5 text-sm text-muted-foreground">
                        {steps.map((step) => (
                            <li key={step}>{step}</li>
                        ))}
                    </ol>
                </div>
            </div>
        </>
    );
}
