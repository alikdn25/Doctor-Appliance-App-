import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * The assistant's composer on a 390 px phone (owner's task of November 16): without the keyboard it sits right above
 * the bottom bar; with the keyboard open (the window shrinks, as with interactive-widget=resizes-content on Android)
 * the bar hides and the composer sits right on top of the keyboard. The paperclip shows photo thumbnails with a
 * remove cross; the microphone shows where the browser can recognise speech.
 */
test.use({ viewport: { width: 390, height: 844 } });

async function bottoms(page: Page) {
    return page.evaluate(() => {
        const dock = document
            .querySelector('[data-slot="chat-dock"]')!
            .getBoundingClientRect();
        const nav = document.querySelector('[data-slot="bottom-nav"]');
        const navBox = nav?.getBoundingClientRect();
        const navShown = nav ? getComputedStyle(nav).display !== 'none' : false;

        return {
            dockBottom: Math.round(dock.bottom),
            navTop: navShown && navBox ? Math.round(navBox.top) : null,
            viewport: Math.round(window.visualViewport?.height ?? innerHeight),
            keyboard: document.documentElement.dataset.keyboard ?? null,
        };
    });
}

test('the composer sits on the bottom bar, and right on the keyboard while typing', async ({
    page,
}) => {
    await page.goto('/assistant');
    const question = page.locator('[data-test="assistant-question"]');
    await expect(question).toBeVisible();

    const resting = await bottoms(page);
    expect(resting.navTop).not.toBeNull();
    expect(Math.abs(resting.dockBottom - resting.navTop!)).toBeLessThanOrEqual(
        1,
    );

    // The keyboard opens: the window gets shorter while the field has focus.
    await question.focus();
    await page.setViewportSize({ width: 390, height: 500 });
    await expect.poll(async () => (await bottoms(page)).keyboard).toBe('open');
    const typing = await bottoms(page);
    expect(typing.navTop).toBeNull();
    expect(Math.abs(typing.dockBottom - typing.viewport)).toBeLessThanOrEqual(
        1,
    );

    // The keyboard closes: the bar is back and the composer on top of it.
    await page.setViewportSize({ width: 390, height: 844 });
    await question.blur();
    await expect.poll(async () => (await bottoms(page)).keyboard).toBeNull();
    const back = await bottoms(page);
    expect(back.navTop).not.toBeNull();
    expect(Math.abs(back.dockBottom - back.navTop!)).toBeLessThanOrEqual(1);

    const width = await page.evaluate(() => ({
        page: document.documentElement.scrollWidth,
        viewport: innerWidth,
    }));
    expect(width.page).toBeLessThanOrEqual(width.viewport + 1);
});

test('photos attach as thumbnails with a remove cross; the microphone toggles EN / RU', async ({
    page,
}) => {
    await page.goto('/assistant');

    const png = Buffer.from(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        'base64',
    );
    await page.locator('[data-test="assistant-photo-input"]').setInputFiles([
        { name: 'one.png', mimeType: 'image/png', buffer: png },
        { name: 'two.png', mimeType: 'image/png', buffer: png },
    ]);
    const thumbs = page.locator('[data-test="assistant-photos"] img');
    await expect(thumbs).toHaveCount(2);
    await page.locator('[data-test="assistant-photo-remove"]').first().click();
    await expect(thumbs).toHaveCount(1);
    // A photo alone can be sent.
    await expect(page.locator('[data-test="assistant-send"]')).toBeEnabled();

    // Chromium has speech recognition: the microphone and its "RU | EN" switch are shown, the chosen half lit.
    const language = page.locator('[data-test="mic-language"]');
    await expect(page.locator('[data-test="mic-button"]')).toBeVisible();
    await expect(language).toHaveAttribute('data-language', 'en');
    await page.locator('[data-test="mic-language-ru"]').click();
    await expect(language).toHaveAttribute('data-language', 'ru');
    await page.reload();
    await expect(page.locator('[data-test="mic-language"]')).toHaveAttribute(
        'data-language',
        'ru',
    );
    await page.locator('[data-test="mic-language-en"]').click();
    await expect(page.locator('[data-test="mic-language"]')).toHaveAttribute(
        'data-language',
        'en',
    );
});
