import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';

const fixture = JSON.parse(
    readFileSync('storage/framework/testing/browser-fixture.json', 'utf8'),
);

test.afterEach(async ({ page }, info) => {
    if (info.status === 'passed') {
        const width = await page.evaluate(() => ({
            page: document.documentElement.scrollWidth,
            viewport: innerWidth,
        }));
        expect(width.page).toBeLessThanOrEqual(width.viewport + 1);
    }
});

test('New job really opens by clicking and cannot save without a customer', async ({
    page,
}) => {
    await page.goto('/jobs');
    await page.getByRole('link', { name: 'New job', exact: true }).click();
    await expect(page).toHaveURL(/\/jobs\/create$/);
    await expect(
        page.getByRole('heading', { name: 'New job', exact: true }),
    ).toBeVisible();
    await expect(
        page.getByRole('button', { name: 'Save', exact: true }),
    ).toBeDisabled();
});

test('an empty calendar day books a new caller using only name phone and time', async ({
    page,
}, info) => {
    const name = `Quick Caller ${info.project.name}`;
    const date = info.project.name === 'desktop' ? '2030-01-15' : '2030-01-16';
    await page.goto(`/calendar?date=${date}`);
    await page
        .getByRole('link', { name: 'Book on this day', exact: true })
        .click();
    await expect(page.locator('#booking-date')).toHaveValue(date);
    await page.locator('#booking-name').fill(name);
    await page
        .locator('#booking-phone')
        .fill(
            info.project.name === 'desktop' ? '+16045550291' : '+16045550292',
        );
    await page.locator('#booking-from').fill('10:00');
    await page.locator('#booking-to').fill('12:00');
    await page
        .getByRole('button', { name: 'Save booking', exact: true })
        .click();
    await expect(page).toHaveURL(/\/jobs\/\d+$/);
    await expect(page.getByRole('link', { name, exact: true })).toBeVisible();
    await expect(
        page.getByText('Address not added yet', { exact: true }),
    ).toBeVisible();
    await expect(
        page.getByRole('link', { name: 'Navigate', exact: true }),
    ).toHaveCount(0);
    await page.goto(`/calendar?date=${date}`);
    await expect(
        page.getByRole('button', { name: new RegExp(`10:00–12:00.*${name}`) }),
    ).toBeVisible();
    await page.screenshot({
        path: info.outputPath('quick-calendar-booking.png'),
        fullPage: true,
    });
});

test('quick booking finds an existing customer and shows their preferences', async ({
    page,
}) => {
    await page.goto(`/jobs/create?book=1&customer_id=${fixture.customer_id}`);
    await expect(page.getByText('Jane Browser', { exact: true })).toBeVisible();
    await page
        .getByRole('button', { name: 'New customer', exact: true })
        .click();
    await page
        .getByRole('button', { name: 'Find existing customer', exact: true })
        .click();
    await page.locator('#booking-search').fill('Jane Browser');
    await page.getByRole('button', { name: /Jane Browser/ }).click();
    await expect(page.getByText('Jane Browser', { exact: true })).toBeVisible();
    await expect(
        page.getByRole('button', { name: 'Save booking', exact: true }),
    ).toBeEnabled();
});

test('New invoice opens from the list and a new caller proceeds directly to invoice prices', async ({
    page,
}, info) => {
    await page.goto('/invoices');
    await page.getByRole('link', { name: 'New invoice', exact: true }).click();
    await expect(page).toHaveURL(/\/invoices\/create$/);
    await page
        .getByRole('link', { name: 'New customer and job', exact: true })
        .click();
    await page
        .locator('#booking-name')
        .fill(`Invoice Caller ${info.project.name}`);
    await page
        .locator('#booking-phone')
        .fill(
            info.project.name === 'desktop' ? '+16045550293' : '+16045550294',
        );
    await expect(page.locator('#booking-date')).toHaveCount(0);
    await page
        .getByRole('button', { name: 'Continue to invoice', exact: true })
        .click();
    await expect(page).toHaveURL(/\/jobs\/\d+\/invoices\/create$/);
    await expect(
        page.getByRole('heading', { name: 'New invoice', exact: true }),
    ).toBeVisible();
    await expect(
        page.getByRole('textbox', { name: 'Price', exact: true }).first(),
    ).toBeVisible();
    await page.screenshot({
        path: info.outputPath('invoice-entry.png'),
        fullPage: true,
    });
});
