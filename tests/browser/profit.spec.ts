import { expect, test } from '@playwright/test';

test('statistics open on this month with the period chips and volumetric blocks', async ({
    page,
}) => {
    await page.goto('/statistics');
    await expect(page.locator('h1').first()).toContainText('Statistics');
    await expect(
        page.getByRole('button', { name: 'This month', exact: true }),
    ).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('[data-test="stats-per-day"]')).toBeVisible();
    await expect(page.locator('[data-test="stats-money"]')).toBeVisible();
    await expect(page.locator('[data-test="stats-months"]')).toBeVisible();

    await page.getByRole('button', { name: 'Last year', exact: true }).click();
    await expect(
        page.getByRole('button', { name: 'Last year', exact: true }),
    ).toHaveAttribute('aria-pressed', 'true');
    await page.getByRole('button', { name: 'Custom', exact: true }).click();
    await expect(page.getByLabel('From')).toBeVisible();

    // The earlier reports are below: the profit breakdown opens on tap.
    await page.locator('summary', { hasText: 'Profit breakdown' }).click();
    await expect(
        page.getByText('Revenue of closed jobs (without taxes)'),
    ).toBeVisible();

    const widths = await page.evaluate(() => ({
        page: document.documentElement.scrollWidth,
        viewport: window.innerWidth,
    }));
    expect(widths.page).toBeLessThanOrEqual(widths.viewport);
});
