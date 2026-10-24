import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { readFileSync } from 'node:fs';

/** + Book of the menu books on the day the calendar shows (the bottom bar on a phone, the sidebar on a computer). */
async function bookShownDay(page: Page, mobile: boolean) {
    if (mobile) {
        await page.locator('[data-test="bottom-nav-book"]').click();
    } else {
        await page
            .locator('[data-sidebar="content"]')
            .last()
            .getByRole('link', { name: 'Book customer', exact: true })
            .click();
    }
}

const fixture = JSON.parse(
    readFileSync('storage/framework/testing/browser-fixture.json', 'utf8'),
);

test('visible menu and booking open the first visit directly from any working screen and calendar', async ({
    page,
}, info) => {
    await page.goto('/company/settings');
    // One menu: the sidebar on a computer, the bottom bar with "+ Book" and More on a phone (no menu button).
    await expect(
        page.getByRole('button', { name: 'Menu', exact: true }),
    ).toHaveCount(0);
    if (info.project.name === 'mobile') {
        const book = page.locator('[data-test="bottom-nav-book"]');
        const bounds = await book.boundingBox();
        expect(bounds!.height).toBeGreaterThanOrEqual(44);
        await book.click();
    } else {
        await page
            .locator('[data-sidebar="content"]')
            .last()
            .getByRole('link', { name: 'Book customer', exact: true })
            .click();
    }
    await expect(page).toHaveURL(/\/jobs\/create\?book=1$/);
    await expect(
        page.getByRole('heading', { name: 'Book customer', exact: true }),
    ).toBeVisible();
    await expect(page.locator('#booking-name')).toBeVisible();
    await expect(page.locator('#booking-phone')).toBeVisible();
    await expect(page.locator('[data-slot="date-chips"]')).toBeVisible();
    await expect(page.locator('[data-slot="time-window-chips"]')).toBeVisible();
    await expect(
        page.getByRole('button', { name: 'Book', exact: true }),
    ).toBeDisabled();
    await page.goto('/calendar?date=2026-10-12');
    await bookShownDay(page, info.project.name === 'mobile');
    await expect(page).toHaveURL(/book=1.*date=2026-10-12/);
    // The chosen day shows in the calendar under the day row (in the page, never over the days).
    await page.locator('[data-test="booking-pick-date"]').click();
    await expect(
        page.locator(
            '[data-test="month-calendar"] [role="radio"][aria-checked="true"]',
        ),
    ).toHaveText('12');
    await page.screenshot({
        path: info.outputPath('visible-menu-booking.png'),
        fullPage: true,
    });
});

test('time zone search uses familiar cities countries and UTC offsets and markup settings are absent', async ({
    page,
}, info) => {
    await page.goto('/company/settings/region');
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
    await page.getByRole('button', { name: 'Add item' }).click();
    await page
        .getByRole('dialog')
        .getByRole('button', { name: 'Part', exact: true })
        .click();
    const price = page
        .getByRole('textbox', { name: 'Price', exact: true })
        .first();
    await price.fill('90.00');
    // The private purchase price is under More in the line's sheet.
    await page
        .locator('[data-test="line-sheet"]')
        .getByRole('button', { name: 'More', exact: true })
        .click();
    await page
        .getByRole('textbox', {
            name: 'Your purchase price — private',
            exact: true,
        })
        .first()
        .fill('40.00');
    await expect(price).toHaveValue('90.00');
    await expect(page.getByText(/Price difference:.*50/)).toBeVisible();
    await page.screenshot({
        path: info.outputPath('private-purchase-price.png'),
        fullPage: true,
    });
    await context.close();
});
