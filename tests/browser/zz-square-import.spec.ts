import { expect, test } from '@playwright/test';

/**
 * Import from Square (owner's task of November 15, PR 3) on a 390 px phone: the screen opens for the Owner and, with
 * Square not set up in the browser fixtures, says only that Square is not available and offers no import.
 */
test.use({ viewport: { width: 390, height: 844 } });

test('without Square on the installation the import screen only says it is not available', async ({
    page,
}) => {
    await page.goto('/square-import');
    await expect(
        page.getByRole('heading', { name: 'Import from Square' }),
    ).toBeVisible();
    // Square is not set up in the browser fixtures: only "not available", no "Connect Square first" and no button.
    await expect(
        page.getByText('Square is not available for your company yet.'),
    ).toBeVisible();
    await expect(page.getByText('Connect Square first')).toHaveCount(0);
    await expect(
        page.getByRole('link', { name: 'Connect Square' }),
    ).toHaveCount(0);
    await expect(page.locator('[data-test="square-import-start"]')).toHaveCount(
        0,
    );
    const width = await page.evaluate(() => ({
        page: document.documentElement.scrollWidth,
        viewport: innerWidth,
    }));
    expect(width.page).toBeLessThanOrEqual(width.viewport + 1);
});

test('Import from Square is always shown in payment settings', async ({
    page,
}) => {
    await page.goto('/company/settings/payments');
    const button = page.locator('[data-test="square-import-button"]');
    await expect(button).toBeVisible();
    await button.click();
    await expect(page).toHaveURL(/\/square-import$/);
});
