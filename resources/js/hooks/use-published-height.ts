import { useEffect, useRef } from 'react';

/**
 * Publishes an element's height as a CSS variable on <html> (e.g. --app-header-h), so parts kept in view while
 * scrolling can sit below the sticky header and above the action bar. Removed again when the element goes.
 */
export function usePublishedHeight<T extends HTMLElement>(name: string) {
    const ref = useRef<T>(null);

    useEffect(() => {
        const element = ref.current;

        if (!element) {
            return;
        }

        const root = document.documentElement;
        const observer = new ResizeObserver(() =>
            root.style.setProperty(name, `${element.offsetHeight}px`),
        );
        observer.observe(element);

        return () => {
            observer.disconnect();
            root.style.removeProperty(name);
        };
    }, [name]);

    return ref;
}
