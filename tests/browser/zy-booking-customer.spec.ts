import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { readFileSync } from 'node:fs';

/**
 * Owner's update of October 24 (PR 1) on a 380 px phone: Book customer (calendar in the page, payer with email,
 * occupant switch, Paste from message, Send Booking Confirmation) and the customer card with tabs and swipes.
 */
test.use({ viewport: { width: 380, height: 800 } });

const fixture = JSON.parse(
    readFileSync('storage/framework/testing/browser-fixture.json', 'utf8'),
);

async function noSideScroll(page: Page) {
    const width = await page.evaluate(() => ({
        page: document.documentElement.scrollWidth,
        viewport: innerWidth,
    }));
    expect(width.page).toBeLessThanOrEqual(width.viewport + 1);
}

/** A swipe with a finger over an element (synthetic touch events). */
async function swipe(page: Page, selector: string, dx: number) {
    await page.locator(selector).evaluate((el, dx) => {
        const box = el.getBoundingClientRect();
        const x = box.left + box.width / 2;
        const y = box.top + 40;
        const touch = (cx: number) =>
            new Touch({ identifier: 1, target: el, clientX: cx, clientY: y });
        el.dispatchEvent(
            new TouchEvent('touchstart', {
                bubbles: true,
                touches: [touch(x)],
                changedTouches: [touch(x)],
            }),
        );
        el.dispatchEvent(
            new TouchEvent('touchend', {
                bubbles: true,
                touches: [],
                changedTouches: [touch(x + dx)],
            }),
        );
    }, dx);
}

test('Pick date opens a calendar in the page under the days, never over them', async ({
    page,
}, info) => {
    await page.goto('/jobs/create?book=1');
    await expect(page.locator('#booking-name')).toBeVisible();
    await expect(page.locator('#booking-last-name')).toBeVisible();
    await expect(page.locator('#booking-email')).toBeVisible();

    await page.locator('[data-test="booking-pick-date"]').click();
    const calendar = page.locator('[data-test="month-calendar"]');
    await expect(calendar).toBeVisible();
    const chips = (await page
        .locator('[data-slot="date-chips"]')
        .boundingBox())!;
    const box = (await calendar.boundingBox())!;
    expect(box.y).toBeGreaterThanOrEqual(chips.y + chips.height - 1);
    await noSideScroll(page);
    await page.screenshot({
        path: info.outputPath('380-book-calendar.png'),
        fullPage: true,
    });

    // A day in the calendar picks it and folds the calendar. Next month's last day is always past the 7 day chips.
    await calendar.getByRole('button', { name: 'Next month' }).click();
    await calendar.locator('[role="radio"]:not([disabled])').last().click();
    await expect(calendar).toHaveCount(0);
    await expect(
        page.locator('[data-test="booking-pick-date"]'),
    ).not.toContainText('Pick date');
});

test('Paste from message fills the form; a booking with an occupant asks to send the confirmation', async ({
    page,
}, info) => {
    const digits = String(Date.now()).slice(-4);
    await page.goto('/jobs/create?book=1');
    await page.locator('[data-test="booking-paste"]').click();
    const sheet = page.locator('[data-test="booking-paste-sheet"]');
    await sheet
        .locator('textarea')
        .fill(
            `Peter Owner\n604 555 ${digits}\npeter.owner${digits}@example.com\n` +
                `77 Rental Rd, Surrey, BC V3T 1A1\nTenant Tina 604 555 0177\nDryer not heating`,
        );
    await sheet.locator('[data-test="booking-paste-fill"]').click();
    await expect(sheet).toHaveCount(0);

    await expect(page.locator('#booking-name')).toHaveValue('Peter');
    await expect(page.locator('#booking-last-name')).toHaveValue('Owner');
    await expect(page.locator('#booking-email')).toHaveValue(
        `peter.owner${digits}@example.com`,
    );
    await expect(page.locator('#booking-address')).toHaveValue('77 Rental Rd');
    await expect(page.locator('#booking-address-city')).toHaveValue('Surrey');
    await expect(page.locator('#booking-problem')).toHaveValue(
        'Dryer not heating',
    );
    await expect(
        page.locator('[data-test="booking-occupant-toggle"]'),
    ).toHaveAttribute('aria-checked', 'true');
    await expect(page.locator('#booking-occupant-name')).toHaveValue('Tina');
    await expect(page.locator('#booking-billing')).toBeVisible();
    await noSideScroll(page);
    await page.screenshot({
        path: info.outputPath('380-book-pasted.png'),
        fullPage: true,
    });

    await page
        .getByRole('button', { name: 'Finish booking', exact: true })
        .click();
    // Finish booking sends the confirmation by itself (owner's task, Oct 24): the sheet says how it went.
    const confirmation = page.locator('[data-test="booking-confirmation"]');
    await expect(confirmation).toBeVisible();
    await expect(confirmation).toContainText(/Booked #\d+/);
    await expect(
        confirmation.locator('[data-test="booking-confirmation-result"]'),
    ).toContainText(
        new RegExp(
            `Confirmation (texted to|will be texted to|emailed to peter\\.owner${digits}@example\\.com)`,
        ),
    );
    await page.screenshot({
        path: info.outputPath('380-booking-confirmation.png'),
    });
    await confirmation.getByRole('button', { name: 'Done' }).click();
    await expect(confirmation).toHaveCount(0);
});

test('an existing customer picks one of their addresses or a new one, with its occupant filled in', async ({
    page,
}) => {
    await page.goto(`/jobs/create?book=1&customer_id=${fixture.customer_id}`);
    await expect(page.locator('[data-test="booking-customer"]')).toBeVisible();
    await expect(
        page.locator('[data-test="booking-properties"]'),
    ).toBeVisible();
    await page.locator('[data-test="booking-new-address"]').click();
    await expect(page.locator('#booking-address')).toBeVisible();
    await expect(
        page.locator('[data-test="booking-occupant-toggle"]'),
    ).toHaveAttribute('aria-checked', 'false');
    await page.locator('[data-test="booking-occupant-toggle"]').click();
    await expect(page.locator('#booking-occupant-phone')).toBeVisible();
    await noSideScroll(page);
});

test('the customer card: main block, then Client / Work / Notes / Files by tap and swipe', async ({
    page,
}, info) => {
    await page.goto(`/customers/${fixture.customer_id}`);
    const main = page.locator('[data-test="customer-main"]');
    await expect(main.locator('[data-test="customer-balance"]')).toBeVisible();
    await expect(
        main.locator('[data-test="customer-actions"] > *'),
    ).toHaveCount(2);

    const tab = (name: string) =>
        page.getByRole('tab', { name: new RegExp(name) });
    await expect(tab('Client')).toHaveAttribute('aria-selected', 'true');
    await expect(
        page.locator('[data-test="customer-billing"]').first(),
    ).toBeVisible();
    await expect(
        page.locator('[data-test="customer-properties"]'),
    ).toBeVisible();
    await noSideScroll(page);
    await page.screenshot({
        path: info.outputPath('380-customer-client.png'),
        fullPage: true,
    });

    await swipe(page, '[role="tabpanel"]', -150);
    await expect(tab('Work')).toHaveAttribute('aria-selected', 'true');
    await expect(page.locator('[data-test="customer-tab-work"]')).toBeVisible();
    await swipe(page, '[role="tabpanel"]', -150);
    await expect(tab('Notes')).toHaveAttribute('aria-selected', 'true');
    await swipe(page, '[role="tabpanel"]', 150);
    await expect(tab('Work')).toHaveAttribute('aria-selected', 'true');

    await tab('Files').click();
    await expect(
        page.locator('[data-test="customer-tab-files"]'),
    ).toBeVisible();
    await tab('Notes').click();
    await expect(page.locator('#customer-notes')).toBeVisible();
    await noSideScroll(page);
});
