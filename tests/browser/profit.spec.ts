import { expect, test } from '@playwright/test';

test('reports open on the profit of the month with day, week, month and year', async ({
    page,
}) => {
    await page.goto('/reports');
    const profit = page.getByLabel('Profit', { exact: true });
    await expect(profit).toContainText('Revenue (without taxes)');
    await expect(profit).toContainText('Margin');

    await page.getByRole('button', { name: 'Year', exact: true }).click();
    await expect(
        page.getByRole('button', { name: 'Year', exact: true }),
    ).toHaveAttribute('aria-pressed', 'true');
    await page.getByRole('button', { name: 'Day', exact: true }).click();
    await expect(
        page.getByRole('button', { name: 'Day', exact: true }),
    ).toHaveAttribute('aria-pressed', 'true');

    const widths = await page.evaluate(() => ({
        page: document.documentElement.scrollWidth,
        viewport: window.innerWidth,
    }));
    expect(widths.page).toBeLessThanOrEqual(widths.viewport);
});
