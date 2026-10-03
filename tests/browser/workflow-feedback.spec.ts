import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';

const fixture = JSON.parse(
    readFileSync('storage/framework/testing/browser-fixture.json', 'utf8'),
);

test('visible menu and booking open the first visit directly from any working screen and calendar', async ({
    page,
}, info) => {
    await page.goto('/company/settings');
    const menu = page.getByRole('button', { name: 'Menu', exact: true });
    await expect(menu).toBeVisible();
    const bounds = await menu.boundingBox();
    expect(bounds!.height).toBeGreaterThanOrEqual(44);
    await menu.click();
    await expect(
        page.getByRole('link', { name: 'Book customer', exact: true }).last(),
    ).toBeVisible();
    await page.keyboard.press('Escape');
    await page
        .getByRole('link', { name: 'Book customer', exact: true })
        .first()
        .click();
    await expect(page).toHaveURL(/\/jobs\/create\?book=1$/);
    await expect(
        page.getByRole('heading', { name: 'Book customer', exact: true }),
    ).toBeVisible();
    await expect(
        page.getByRole('checkbox', { name: 'Schedule the first visit now' }),
    ).toBeChecked();
    await page.goto('/calendar?date=2026-10-12');
    await page
        .getByRole('link', { name: 'Book customer', exact: true })
        .last()
        .click();
    await expect(page).toHaveURL(/book=1.*date=2026-10-12/);
    await expect(page.locator('input[type="date"]').first()).toHaveValue(
        '2026-10-12',
    );
    await page.screenshot({
        path: info.outputPath('visible-menu-booking.png'),
        fullPage: true,
    });
});

test('time zone search uses familiar cities countries and UTC offsets and markup settings are absent', async ({
    page,
}, info) => {
    await page.goto('/company/settings');
    await page.locator('#timezone-search').fill('Vancouver');
    const option = page.locator('#timezone option[value="America/Vancouver"]');
    await expect(option).toHaveText(/UTC-0[78]:00.*Vancouver, Canada/);
    await page.locator('#timezone').selectOption('America/Vancouver');
    await expect(page.getByText('Parts markup', { exact: true })).toHaveCount(
        0,
    );
    await page.screenshot({
        path: info.outputPath('timezone-search.png'),
        fullPage: true,
    });
});

test('a technician types customer and private purchase prices independently and sees the difference', async ({
    browser,
}, info) => {
    const context = await browser.newContext({
        storageState: 'test-results/auth-tech.json',
        viewport: info.project.use.viewport,
    });
    const page = await context.newPage();
    await page.goto(`/jobs/${fixture.job_id}/estimates/create`);
    await page
        .locator('select')
        .filter({ has: page.locator('option[value="part"]') })
        .first()
        .selectOption('part');
    const price = page
        .getByRole('textbox', { name: 'Price', exact: true })
        .first();
    await price.fill('90.00');
    await page
        .getByRole('textbox', {
            name: 'Your purchase price — private',
            exact: true,
        })
        .first()
        .fill('40.00');
    await expect(price).toHaveValue('90.00');
    await expect(page.getByText(/Difference before tax:.*50/)).toBeVisible();
    await page.screenshot({
        path: info.outputPath('private-purchase-price.png'),
        fullPage: true,
    });
    await context.close();
});
