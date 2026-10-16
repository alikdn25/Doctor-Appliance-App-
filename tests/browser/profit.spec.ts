import { expect, test } from '@playwright/test';

test('reports open on the month with four figures and the period in one tap', async ({
    page,
}) => {
    await page.goto('/reports');
    await expect(page.locator('[data-test="kpi-revenue"]')).toContainText(
        'invoices issued',
    );
    await expect(page.locator('[data-test="kpi-profit"]')).toContainText(
        'jobs closed',
    );
    await expect(
        page.getByRole('radio', { name: 'Month', exact: true }),
    ).toHaveAttribute('aria-checked', 'true');

    await page.getByRole('radio', { name: 'Year', exact: true }).click();
    await expect(
        page.getByRole('radio', { name: 'Year', exact: true }),
    ).toHaveAttribute('aria-checked', 'true');
    await page.getByRole('radio', { name: 'Day', exact: true }).click();
    await expect(
        page.getByRole('radio', { name: 'Day', exact: true }),
    ).toHaveAttribute('aria-checked', 'true');

    // ‹ moves the day back; Custom shows From / To.
    const range = await page.locator('[data-test="report-range"]').innerText();
    await page.getByRole('button', { name: 'Previous', exact: true }).click();
    await expect(page.locator('[data-test="report-range"]')).not.toHaveText(
        range,
    );
    await page.getByRole('radio', { name: 'Custom', exact: true }).click();
    await expect(page.getByLabel('From')).toBeVisible();

    // The profit breakdown opens on tap.
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
