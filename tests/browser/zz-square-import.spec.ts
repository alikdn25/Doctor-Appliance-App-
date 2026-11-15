import { expect, test } from '@playwright/test';

/**
 * Import from Square (owner's task of November 15, PR 3) on a 390 px phone: the screen opens for the Owner and, with
 * no Square account connected in the browser fixtures, says to connect Square first and offers no import.
 */
test.use({ viewport: { width: 390, height: 844 } });

test('the import screen asks to connect Square first', async ({ page }) => {
    await page.goto('/square-import');
    await expect(
        page.getByRole('heading', { name: 'Import from Square' }),
    ).toBeVisible();
    await expect(page.getByText('Connect Square first')).toBeVisible();
    await expect(page.locator('[data-test="square-import-start"]')).toHaveCount(
        0,
    );
    const width = await page.evaluate(() => ({
        page: document.documentElement.scrollWidth,
        viewport: innerWidth,
    }));
    expect(width.page).toBeLessThanOrEqual(width.viewport + 1);
});
