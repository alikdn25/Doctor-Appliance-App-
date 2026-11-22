import { Capacitor, registerPlugin } from '@capacitor/core';

/**
 * Voice input in the Android app (owner's task of November 22): Android's own speech recognition through the
 * SpeechInput plugin (android/…/SpeechInputPlugin.kt), shaped like the browser's SpeechRecognition so the
 * microphone buttons (use-dictation.ts) work the same way in both. One phrase per start(); use-dictation starts
 * again after each phrase until the second tap. Not used in a browser.
 */
type SpeechInputPlugin = {
    start(options: { language: string }): Promise<void>;
    stop(): Promise<void>;
    abort(): Promise<void>;
    addListener(
        event: 'partial' | 'final',
        callback: (data: { text: string }) => void,
    ): Promise<unknown>;
    addListener(
        event: 'error',
        callback: (data: { code: string }) => void,
    ): Promise<unknown>;
    addListener(event: 'end', callback: () => void): Promise<unknown>;
};

const SpeechInput = registerPlugin<SpeechInputPlugin>('SpeechInput');

export function hasNativeSpeech(): boolean {
    try {
        return (
            Capacitor.isNativePlatform() &&
            Capacitor.isPluginAvailable('SpeechInput')
        );
    } catch {
        return false;
    }
}

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

// Only one microphone listens at a time: the plugin's events go to it.
let active: NativeSpeechRecognition | null = null;
let listening = false;

function activate(recognition: NativeSpeechRecognition | null): void {
    active = recognition;
}

function listen(): void {
    if (listening) return;
    listening = true;
    void SpeechInput.addListener('partial', ({ text }) =>
        active?.emit(text, false),
    );
    void SpeechInput.addListener('final', ({ text }) =>
        active?.emit(text, true),
    );
    void SpeechInput.addListener('error', ({ code }) =>
        active?.onerror?.({ error: code }),
    );
    void SpeechInput.addListener('end', () => active?.ended());
}

export class NativeSpeechRecognition {
    lang = 'en-US';
    continuous = true;
    interimResults = true;
    maxAlternatives = 1;
    onresult: ((event: ResultEvent) => void) | null = null;
    onerror: ((event: { error: string }) => void) | null = null;
    onend: (() => void) | null = null;

    emit(text: string, isFinal: boolean): void {
        const result: Result = { isFinal, length: 1, 0: { transcript: text } };
        this.onresult?.({ resultIndex: 0, results: { length: 1, 0: result } });
    }

    ended(): void {
        if (active === this) activate(null);
        this.onend?.();
    }

    start(): void {
        listen();
        activate(this);
        SpeechInput.start({ language: this.lang }).catch(
            (e: { code?: string }) => {
                this.onerror?.({
                    error: e?.code === 'DENIED' ? 'not-allowed' : 'failed',
                });
                this.ended();
            },
        );
    }

    stop(): void {
        void SpeechInput.stop().catch(() => {});
    }

    abort(): void {
        if (active === this) activate(null);
        void SpeechInput.abort().catch(() => {});
    }
}
