import { useCallback, useEffect, useRef, useState } from 'react';
import { hasNativeSpeech, NativeSpeechRecognition } from '@/lib/native-speech';

export type DictationLanguage = 'en' | 'ru';

type Alternative = { transcript: string };
type Result = {
    isFinal: boolean;
    length: number;
    [index: number]: Alternative;
};
type ResultEvent = {
    resultIndex: number;
    results: { length: number; [index: number]: Result };
};
type ErrorEvent = { error: string };
type Recognition = {
    lang: string;
    continuous: boolean;
    interimResults: boolean;
    maxAlternatives: number;
    onresult: ((event: ResultEvent) => void) | null;
    onerror: ((event: ErrorEvent) => void) | null;
    onend: (() => void) | null;
    start: () => void;
    stop: () => void;
    abort: () => void;
};
type RecognitionClass = new () => Recognition;

/**
 * The speech recognition to use: Android's own in the Android app (its web view has no Web Speech API), else the
 * browser's (Web Speech API), or null when there is none.
 */
export function speechRecognitionClass(): RecognitionClass | null {
    if (typeof window === 'undefined') return null;
    if (hasNativeSpeech()) {
        return NativeSpeechRecognition as unknown as RecognitionClass;
    }
    const w = window as unknown as {
        SpeechRecognition?: RecognitionClass;
        webkitSpeechRecognition?: RecognitionClass;
    };

    return w.SpeechRecognition ?? w.webkitSpeechRecognition ?? null;
}

const LOCALES: Record<DictationLanguage, string> = {
    en: 'en-US',
    ru: 'ru-RU',
};

const join = (...parts: string[]) =>
    parts
        .map((part) => part.trim())
        .filter((part) => part !== '')
        .join(' ');

/**
 * Final phrases of one recognition session. Chrome on Android repeats earlier words in each new final result in
 * continuous mode; a phrase that starts with what was already heard replaces it instead of being added.
 */
function finalText(event: ResultEvent): { final: string; interim: string } {
    let final = '';
    let interim = '';

    for (let i = 0; i < event.results.length; i++) {
        const result = event.results[i];
        const text = (result[0]?.transcript ?? '').trim();
        if (text === '') continue;

        if (!result.isFinal) {
            interim = join(interim, text);
        } else if (
            final !== '' &&
            text.toLowerCase().startsWith(final.toLowerCase())
        ) {
            final = text;
        } else {
            final = join(final, text);
        }
    }

    return { final, interim };
}

/**
 * Dictation into a text field with the browser's own speech recognition (no paid service). Recording goes on
 * through pauses until stop() (continuous, restarted when the browser ends it by itself). Recognised words are added
 * to what was already typed, words still being recognised show at once. Nothing is sent: the person presses Send.
 */
export function useDictation({
    value,
    onChange,
    language,
}: {
    value: string;
    onChange: (value: string) => void;
    language: DictationLanguage;
}) {
    const [supported] = useState(() => speechRecognitionClass() !== null);
    const [listening, setListening] = useState(false);
    const [error, setError] = useState<'denied' | 'failed' | null>(null);

    const recognition = useRef<Recognition | null>(null);
    const wanted = useRef(false);
    // Text in the field when recording started, phrases of earlier sessions, and this session's phrases.
    const base = useRef('');
    const committed = useRef('');
    const session = useRef({ final: '', interim: '' });
    const failures = useRef(0);
    const latest = useRef({ value, onChange });
    latest.current = { value, onChange };

    const emit = useCallback(() => {
        latest.current.onChange(
            join(
                base.current,
                committed.current,
                session.current.final,
                session.current.interim,
            ),
        );
    }, []);

    const stop = useCallback(() => {
        wanted.current = false;
        recognition.current?.stop();
    }, []);

    const start = useCallback(() => {
        const Class = speechRecognitionClass();
        if (!Class || wanted.current) return;

        setError(null);
        wanted.current = true;
        failures.current = 0;
        base.current = latest.current.value;
        committed.current = '';
        session.current = { final: '', interim: '' };

        const rec = new Class();
        rec.lang = LOCALES[language];
        rec.continuous = true;
        rec.interimResults = true;
        rec.maxAlternatives = 1;
        rec.onresult = (event) => {
            failures.current = 0;
            session.current = finalText(event);
            emit();
        };
        rec.onerror = (event) => {
            if (
                event.error === 'not-allowed' ||
                event.error === 'service-not-allowed' ||
                event.error === 'audio-capture'
            ) {
                wanted.current = false;
                setError('denied');
            } else if (
                event.error !== 'no-speech' &&
                event.error !== 'aborted'
            ) {
                failures.current++;
            }
        };
        rec.onend = () => {
            // What this session heard stays; words not final yet are kept as they were shown.
            committed.current = join(
                committed.current,
                session.current.final,
                session.current.interim,
            );
            session.current = { final: '', interim: '' };

            // The browser stopped by itself (a pause, a time limit): go on until the second tap.
            if (wanted.current && failures.current < 5) {
                try {
                    rec.start();

                    return;
                } catch {
                    // Falls through to stopping.
                }
            }

            if (wanted.current) setError('failed');
            wanted.current = false;
            recognition.current = null;
            setListening(false);
        };

        try {
            rec.start();
            recognition.current = rec;
            setListening(true);
        } catch {
            wanted.current = false;
            setError('failed');
        }
    }, [emit, language]);

    // Leaving the screen stops the microphone.
    useEffect(
        () => () => {
            wanted.current = false;
            recognition.current?.abort();
        },
        [],
    );

    return {
        supported,
        listening,
        error,
        clearError: () => setError(null),
        start,
        stop,
        toggle: () => (wanted.current ? stop() : start()),
    };
}
