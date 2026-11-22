import { router } from '@inertiajs/react';
import { BatteryMedium, Bluetooth, Download, Settings2 } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { NativeSelect } from '@/components/ui/native-select';
import {
    ensureReaderAuthorized,
    hasCardReader,
    readerError,
    SquareReader,
} from '@/lib/card-reader';
import type { CardReader } from '@/lib/card-reader';
import { useTrans } from '@/lib/i18n';

type Location = { id: string; name: string; currency: string | null };

/**
 * Settings → Payments, under the connected Square account (owner's task of November 21): the Square location card
 * reader payments go to, and "Card reader" — Pair reader, the readers found (connected, battery), Forget reader.
 * Pairing works in the Android app; a browser shows the download link instead.
 */
export function SquareReaderSettings({ currentLocation }: { currentLocation: string | null }) {
    const t = useTrans();
    const native = hasCardReader();
    const [locations, setLocations] = useState<Location[] | null>(null);
    const [locationError, setLocationError] = useState<string | null>(null);
    const [location, setLocation] = useState('');
    const [readers, setReaders] = useState<CardReader[]>([]);
    const [pairing, setPairing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        fetch('/payment-providers/square/locations', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(async (r) => {
                const body = await r.json().catch(() => null);
                if (!r.ok) throw new Error(body?.message ?? r.statusText);
                const list = (body?.locations ?? []) as Location[];
                setLocations(list);
                setLocation(list.find((l) => l.name === currentLocation)?.id ?? '');
            })
            .catch((e: Error) => setLocationError(e.message));
    }, [currentLocation]);

    const refresh = useCallback(() => {
        if (!native) return;
        SquareReader.readers()
            .then((r) => setReaders(r.readers))
            .catch(() => {});
    }, [native]);

    useEffect(() => {
        if (!native) return;
        let remove: (() => void) | undefined;
        ensureReaderAuthorized('/payments/reader/session')
            .then(refresh)
            .catch((e) => setError(readerError(e).message));
        void SquareReader.addListener('readerChanged', (data) =>
            setReaders(data.readers),
        ).then((handle) => {
            remove = () => void handle.remove();
        });

        return () => remove?.();
    }, [native, refresh]);

    const pair = async () => {
        setError(null);
        setPairing(true);
        try {
            const { granted } = await SquareReader.requestReaderPermissions();
            if (!granted) {
                setError(t('charges.reader.permissions'));

                return;
            }
            await ensureReaderAuthorized('/payments/reader/session');
            await SquareReader.pair();
            refresh();
        } catch (e) {
            const { code, message } = readerError(e);
            if (code !== 'STOPPED') setError(message);
        } finally {
            setPairing(false);
        }
    };

    const status = (reader: CardReader) =>
        t(`charges.reader.${['ready', 'connecting', 'faulty'].includes(reader.status) ? reader.status : 'unavailable'}`);

    return (
        <div className="grid gap-4" data-test="square-reader-settings">
            <div className="grid gap-2 rounded-lg border p-3">
                <p className="text-sm font-medium">{t('charges.settings.location')}</p>
                <p className="text-xs text-muted-foreground">
                    {t('charges.settings.location_hint')}
                </p>
                {locationError && (
                    <p className="text-sm text-danger">{locationError}</p>
                )}
                {locations && (
                    <div className="flex flex-wrap items-center gap-2">
                        <NativeSelect
                            value={location}
                            onChange={(e) => setLocation(e.target.value)}
                            className="sm:max-w-xs"
                            data-test="square-location"
                        >
                            <option value="">{t('charges.settings.choose_location')}</option>
                            {locations.map((l) => (
                                <option key={l.id} value={l.id}>
                                    {l.name}
                                    {l.currency ? ` (${l.currency})` : ''}
                                </option>
                            ))}
                        </NativeSelect>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={location === ''}
                            onClick={() =>
                                router.put(
                                    '/payment-providers/square/location',
                                    { location_id: location },
                                    { preserveScroll: true },
                                )
                            }
                            data-test="square-location-save"
                        >
                            {t('charges.settings.save_location')}
                        </Button>
                    </div>
                )}
                <p className="text-xs text-muted-foreground">{t('charges.settings.reconnect')}</p>
            </div>

            <div className="grid gap-2 rounded-lg border p-3" data-test="card-reader">
                <p className="text-sm font-medium">{t('charges.reader.title')}</p>
                <p className="text-xs text-muted-foreground">{t('charges.reader.hint')}</p>
                {!native ? (
                    <div className="grid gap-2">
                        <p className="text-sm text-muted-foreground">{t('charges.reader.app_only')}</p>
                        <Button type="button" variant="outline" asChild className="sm:w-fit">
                            <a href="/download/android.apk">
                                <Download /> {t('charges.reader.download')}
                            </a>
                        </Button>
                    </div>
                ) : (
                    <>
                        {readers.length === 0 && !pairing && (
                            <p className="text-sm text-muted-foreground">{t('charges.reader.none')}</p>
                        )}
                        <ul className="grid gap-2">
                            {readers.map((reader) => (
                                <li
                                    key={reader.id}
                                    className="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-white/70 p-3 text-sm"
                                    data-test="card-reader-row"
                                >
                                    <span>
                                        <span className="block font-medium">
                                            {reader.name ?? reader.model}
                                            {reader.serial ? ` · ${reader.serial.slice(-4)}` : ''}
                                        </span>
                                        <span className="flex items-center gap-1 text-muted-foreground">
                                            <Bluetooth className="size-4" /> {status(reader)}
                                            {reader.battery !== null && (
                                                <>
                                                    {' · '}
                                                    <BatteryMedium className="size-4" />
                                                    {t('charges.reader.battery', { percent: reader.battery })}
                                                    {reader.charging ? ` (${t('charges.reader.charging')})` : ''}
                                                </>
                                            )}
                                        </span>
                                    </span>
                                    <Button
                                        type="button"
                                        variant="destructive"
                                        size="sm"
                                        onClick={() => {
                                            if (!window.confirm(t('charges.reader.confirm_forget'))) return;
                                            void SquareReader.forget({ id: reader.id }).then(refresh);
                                        }}
                                    >
                                        {t('charges.reader.forget')}
                                    </Button>
                                </li>
                            ))}
                        </ul>
                        {pairing && (
                            <p className="text-sm font-medium text-navy" aria-live="polite">
                                {t('charges.reader.pairing')}
                            </p>
                        )}
                        {error && <p className="text-sm text-danger">{error}</p>}
                        <div className="flex flex-wrap gap-2">
                            {pairing ? (
                                <Button type="button" variant="outline" onClick={() => void SquareReader.stopPairing()}>
                                    {t('charges.reader.stop')}
                                </Button>
                            ) : (
                                <Button type="button" onClick={() => void pair()} data-test="pair-reader">
                                    <Bluetooth /> {t('charges.reader.pair')}
                                </Button>
                            )}
                            <Button type="button" variant="tertiary" onClick={() => void SquareReader.showSettings()}>
                                <Settings2 /> {t('charges.reader.settings')}
                            </Button>
                        </div>
                    </>
                )}
            </div>
        </div>
    );
}
