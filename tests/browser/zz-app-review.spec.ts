import { expect, test } from '@playwright/test';

/**
 * App review (owner's task of November 15, PR 5) on a 390 px phone: "Inconvenient" in More sends a note with the
 * screen, and Assistant → App review opens the period choice and the past reviews (the review itself needs the AI).
 */
test.use({ viewport: { width: 390, height: 844 } });

test('Inconvenient in More sends a note', async ({ page }) => {
    await page.goto('/today');
    await page.locator('[data-test="bottom-nav-more"]').click();
    await page.locator('[data-test="more-inconvenient"]').click();
    const sheet = page.locator('[data-test="inconvenient-sheet"]');
    await expect(sheet).toBeVisible();
    await sheet
        .locator('[data-test="inconvenient-text"]')
        .fill('Too many taps to find a customer');
    await sheet.locator('[data-test="inconvenient-send"]').click();
    await expect(page.getByText('Thank you.', { exact: false })).toBeVisible();
    await expect(sheet).toHaveCount(0);
});

test('Assistant → App review shows the periods and past reviews', async ({
    page,
}) => {
    await page.goto('/assistant');
    await page.locator('[data-test="assistant-app-review"]').click();
    await expect(page).toHaveURL(/\/assistant\/reviews$/);
    const run = page.locator('[data-test="review-run"]');
    await expect(
        run.getByRole('radio', { name: 'Since the last review' }),
    ).toHaveAttribute('aria-checked', 'true');
    for (const label of ['1 day', '3 days', '7 days', '30 days']) {
        await expect(run.getByRole('radio', { name: label })).toBeVisible();
    }
    const width = await page.evaluate(() => ({
        page: document.documentElement.scrollWidth,
        viewport: innerWidth,
    }));
    expect(width.page).toBeLessThanOrEqual(width.viewport + 1);
});
