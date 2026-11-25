import { usePage } from "@inertiajs/react";
import { Mic, Square, X } from "lucide-react";
import { useEffect, useRef, useSyncExternalStore } from "react";
import type { DictationLanguage } from "@/hooks/use-dictation";
import { useDictation } from "@/hooks/use-dictation";
import { sendJson } from "@/lib/http";
import { useTrans } from "@/lib/i18n";
import { cn } from "@/lib/utils";

/**
 * The microphone's language, one for every field on the screen (and saved for the person on the server, so it is the
 * same on every device). RU / EN next to any microphone changes it everywhere.
 */
let chosen: DictationLanguage | null = null;
const listeners = new Set<() => void>();
const subscribe = (listener: () => void) => {
    listeners.add(listener);

    return () => listeners.delete(listener);
};

export function setDictationLanguage(language: DictationLanguage): void {
    chosen = language;
    listeners.forEach((listener) => listener());
    void sendJson("/settings/dictation-language", "PUT", { language }).catch(
        () => undefined,
    );
}

export function useDictationLanguage(): DictationLanguage {
    const { auth } = usePage().props;
    const saved = auth.user?.dictation_language === "ru" ? "ru" : "en";

    return useSyncExternalStore(
        subscribe,
        () => chosen ?? saved,
        () => saved,
    );
}

/**
 * A home-screen shortcut ("By voice", "Quick booking") opens its page with ?mic=1: the microphone starts by itself.
 */
export function micFromShortcut(): boolean {
    return (
        typeof window !== "undefined" &&
        new URLSearchParams(window.location.search).get("mic") === "1"
    );
}

/**
 * The microphone next to Send, as in WhatsApp: tap to record (the button turns red and pulses), tap again to stop.
 * Words are added to the field as they are recognised; nothing is sent by itself. EN / RU next to it picks the
 * language, saved for the person (the company's default at first). Not shown when the browser cannot recognise speech.
 */
export function MicButton({
    value,
    onChange,
    disabled = false,
    className,
    autoStart = false,
    selection,
}: {
    value: string;
    onChange: (value: string) => void;
    disabled?: boolean;
    className?: string;
    /** Start listening at once (a home-screen shortcut opened the page with ?mic=1). */
    autoStart?: boolean;
    /** The field's cursor: words go in there. */
    selection?: () => [number, number] | null;
}) {
    const t = useTrans();
    const language = useDictationLanguage();
    const dictation = useDictation({ value, onChange, language, selection });
    const started = useRef(false);
    useEffect(() => {
        if (autoStart && dictation.supported && !started.current) {
            started.current = true;
            dictation.start();
        }
    }, [autoStart, dictation]);

    if (!dictation.supported) return null;

    const switchLanguage = () => {
        const next: DictationLanguage = language === "en" ? "ru" : "en";
        if (dictation.listening) dictation.stop();
        setDictationLanguage(next);
    };

    return (
        <div
            className={cn("relative flex shrink-0 items-end gap-1", className)}
        >
            {dictation.error && (
                <div
                    role="alert"
                    className="absolute right-0 bottom-full z-10 mb-2 flex w-72 max-w-[calc(100vw-2rem)] items-start gap-2 rounded-2xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 shadow-lg dark:border-amber-800 dark:bg-amber-950 dark:text-amber-50"
                    data-test="mic-error"
                >
                    <p className="flex-1">
                        {dictation.error === "denied"
                            ? t("ui.dictation.denied")
                            : t("ui.dictation.failed")}
                    </p>
                    <button
                        type="button"
                        className="-m-1 inline-flex size-8 shrink-0 items-center justify-center rounded-full"
                        aria-label={t("ui.dictation.close")}
                        onClick={dictation.clearError}
                    >
                        <X className="size-4" />
                    </button>
                </div>
            )}
            <button
                type="button"
                onClick={switchLanguage}
                className="da-press inline-flex h-11 min-w-9 items-center justify-center rounded-xl px-1.5 text-xs font-bold text-muted-foreground"
                aria-label={t("ui.dictation.language", {
                    language: language.toUpperCase(),
                })}
                title={t("ui.dictation.language", {
                    language: language.toUpperCase(),
                })}
                data-test="mic-language"
            >
                {language.toUpperCase()}
            </button>
            <button
                type="button"
                onClick={dictation.toggle}
                disabled={disabled && !dictation.listening}
                aria-pressed={dictation.listening}
                aria-label={
                    dictation.listening
                        ? t("ui.dictation.stop")
                        : t("ui.dictation.start")
                }
                title={
                    dictation.listening
                        ? t("ui.dictation.stop")
                        : t("ui.dictation.start")
                }
                className={cn(
                    "da-press relative inline-flex size-11 shrink-0 items-center justify-center rounded-[14px] outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50 [&_svg]:size-5",
                    dictation.listening
                        ? "bg-red-600 text-white shadow-[0_0_0_4px_rgb(220_38_38/0.25)]"
                        : "da-soft text-navy",
                )}
                data-test="mic-button"
                data-listening={dictation.listening ? "true" : undefined}
            >
                {dictation.listening && (
                    <span
                        aria-hidden="true"
                        className="absolute inset-0 animate-ping rounded-[14px] bg-red-500/50 motion-reduce:hidden"
                    />
                )}
                {dictation.listening ? (
                    <Square className="relative fill-current" />
                ) : (
                    <Mic className="relative" />
                )}
            </button>
        </div>
    );
}
