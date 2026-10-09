import { expect, test } from '@playwright/test';

test('+ Trip → Enter by hand logs a parts store run and the month total follows', async ({
    page,
}, info) => {
    await page.goto('/trips');
    await expect(page.getByRole('heading', { name: 'Mileage' })).toBeVisible();

    await page.getByRole('button', { name: 'Trip', exact: true }).click();
    // + Trip opens the navigation sheet; a past trip is entered by hand from it.
    await page.getByRole('button', { name: 'Enter by hand' }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByRole('radio', { name: 'Parts store' }).click();
    await dialog.getByLabel('From', { exact: true }).fill('Shop');
    await dialog
        .getByLabel('To', { exact: true })
        .fill(`Reliable Parts ${info.project.name}`);
    await dialog.getByLabel(/Distance/).fill('12.5');
    await dialog.getByLabel('Purpose', { exact: true }).fill('Drain pump');
    await dialog.getByRole('button', { name: 'Save' }).click();
    await expect(dialog).toHaveCount(0);

    await expect(
        page.getByRole('button', {
            name: new RegExp(`Reliable Parts ${info.project.name}`),
        }),
    ).toContainText('12.5 km');
    await expect(page.getByText('This month').locator('..')).toContainText(
        'km',
    );

    const widths = await page.evaluate(() => ({
        page: document.documentElement.scrollWidth,
        viewport: window.innerWidth,
    }));
    expect(widths.page).toBeLessThanOrEqual(widths.viewport);
});
