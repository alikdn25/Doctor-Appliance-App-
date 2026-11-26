import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';

/**
 * The Close job window (owner's task of November 26) on a 390 px phone: "⋯ → Close job" opens one window that fits
 * the screen, with big one-tap buttons in reach of the thumb. Nothing is pressed, so the fixture job stays open.
 */
const fixture = JSON.parse(
    readFileSync('storage/framework/testing/browser-fixture.json', 'utf8'),
);

test.use({ viewport: { width: 390, height: 844 } });

test('Close job fits a 390 px phone with every button in reach', async ({
    page,
}, info) => {
    await page.goto(`/jobs/${fixture.job_id}`);
    await page.locator('[data-test="action-sheet-button"]').click();
    await page.locator('[data-test="action-close"]').click();

    const sheet = page.locator('[data-test="close-job-sheet"]');
    await expect(sheet).toBeVisible();
    // The window slides up: measure once it has arrived.
    await expect
        .poll(async () => {
            const b = (await sheet.boundingBox())!;

            return b.y + b.height;
        })
        .toBeLessThanOrEqual(844);
    const box = (await sheet.boundingBox())!;
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(390);
    expect(box.y).toBeGreaterThanOrEqual(0);
    expect(box.y + box.height).toBeLessThanOrEqual(844);
    // Nothing scrolls sideways inside the window.
    expect(
        await sheet.evaluate((el) => el.scrollWidth <= el.clientWidth + 1),
    ).toBe(true);

    for (const key of [
        'close-cash',
        'close-transfer',
        'close-send-invoice',
        'close-no-charge',
    ]) {
        const button = page.locator(`[data-test="${key}"]`);
        await expect(button).toBeVisible();
        const b = (await button.boundingBox())!;
        // Big and in the lower part of the screen, inside it.
        expect(b.height).toBeGreaterThanOrEqual(52);
        expect(b.y + b.height).toBeLessThanOrEqual(844);
        expect(b.y).toBeGreaterThan(844 / 3);
    }
    await expect(page.locator('[data-test="close-line"]')).toHaveCount(1);
    await expect(page.locator('[data-test="close-note"]')).toBeVisible();
    await page.screenshot({ path: info.outputPath('390-close-job.png') });

    // No charge → "Receipt & review": Request review only, on by default; one Done.
    await page.locator('[data-test="close-no-charge"]').click();
    await expect(
        page.locator('[data-test="receipt-review-step"]'),
    ).toBeVisible();
    await expect(page.locator('[data-test="close-send-receipt"]')).toHaveCount(
        0,
    );
    await expect(
        page.locator('[data-test="close-request-review"]'),
    ).toHaveAttribute('aria-checked', 'true');
    const done = (await page
        .locator('[data-test="close-done"]')
        .boundingBox())!;
    expect(done.y + done.height).toBeLessThanOrEqual(844);
    await page.screenshot({ path: info.outputPath('390-receipt-review.png') });
});
