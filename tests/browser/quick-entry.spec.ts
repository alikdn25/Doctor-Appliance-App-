import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { readFileSync } from 'node:fs';

/** Booking starts on the day the calendar shows ("+ → Job" on a phone, Book customer in the sidebar on a computer). */
async function bookShownDay(page: Page, mobile: boolean) {
    if (mobile) {
        await page.locator('[data-test="create-fab"]').click();
        await page.locator('[data-test="create-job"]').click();
    } else {
        await page
            .locator('[data-sidebar="content"]')
            .last()
            .getByRole('link', { name: 'Book customer', exact: true })
            .click();
    }
}

/** The address is required right after the name (owner's fixes, October 19); typed by hand without a Maps key. */
async function fillAddress(page: Page, street = '12 Quick Street') {
    await page.locator('#booking-address').fill(street);
    await page.getByRole('button', { name: 'Enter manually' }).click();
    await page.locator('#booking-address-city').fill('Vancouver');
}

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
    // One way in: Book customer; the full job form is under "⋯" of the booking screen.
    await page.goto('/jobs/create?book=1');
    await page.locator('[data-test="action-sheet-button"]').click();
    await page
        .getByRole('button', { name: 'Open full job form', exact: true })
        .click();
    await expect(page).toHaveURL(/\/jobs\/create\?draft=1$/);
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
    await bookShownDay(page, info.project.name === 'mobile');
    await page
        .locator('#booking-phone')
        .fill(
            info.project.name === 'desktop' ? '+16045550291' : '+16045550292',
        );
    await page.locator('#booking-name').fill(name);
    // Without the address Book says what is missing.
    await expect(page.locator('[data-test="booking-status"]')).toContainText(
        'address',
    );
    await fillAddress(page);
    await expect(page.locator('[data-test="booking-status"]')).toHaveText(
        'Ready',
    );
    await page.getByRole('button', { name: 'Custom time' }).click();
    await expect(page.locator('#booking-date')).toHaveValue(date);
    await page.locator('#booking-from').fill('10:00');
    await page.locator('#booking-to').fill('12:00');
    await page
        .getByRole('button', { name: 'Finish booking', exact: true })
        .click();
    // Back on the day, with "Booked #… · …" and Open job.
    await expect(page).toHaveURL(
        new RegExp(`/calendar\\?date=${date}&view=day`),
    );
    await page.locator('[data-test="confirmation-open-job"]').click();
    await expect(page).toHaveURL(/\/jobs\/\d+$/);
    await expect(page.getByRole('link', { name, exact: true })).toBeVisible();
    await expect(page.getByText(/12 Quick Street/).first()).toBeVisible();
    // Navigate (blue) · Call (green) · Text (white) · On my way (white), equal buttons.
    const actions = page.locator('[data-test="job-contact-actions"] > *');
    await expect(actions).toHaveCount(4);
    const widths = await actions.evaluateAll((els) =>
        els.map((el) => Math.round(el.getBoundingClientRect().width)),
    );
    expect(Math.max(...widths) - Math.min(...widths)).toBeLessThanOrEqual(1);
    await expect(
        page.getByRole('link', { name: 'Navigate', exact: true }),
    ).toBeVisible();
    await page.goto(`/calendar?date=${date}`);
    await expect(
        page.getByRole('button', {
            name: new RegExp(`10 a\\.m\\..*12 p\\.m\\. · ${name}`),
        }),
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
    const chosen = page.locator('[data-test="booking-customer"]');
    await expect(
        chosen.getByText('Jane Browser', { exact: true }),
    ).toBeVisible();
    await chosen.getByRole('button', { name: 'Change', exact: true }).click();
    // The magnifier in Name searches by name; the phone field finds a caller from 3 digits.
    await page.getByRole('button', { name: 'Find a customer by name' }).click();
    await page.locator('#booking-name').fill('Jane Browser');
    const matches = page.locator('[data-test="customer-matches"]');
    const use = matches.getByRole('button', { name: 'Use' }).first();
    await expect(use).toBeVisible();
    // Keyboard activation: the phone emulation in CI sometimes reports the list as off-screen while it scrolls.
    await use.press('Enter');
    await expect(
        chosen.getByText('Jane Browser', { exact: true }),
    ).toBeVisible();
    await expect(chosen).toContainText('About the customer');
    await expect(
        page.getByRole('button', { name: 'Finish booking', exact: true }),
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
    await fillAddress(page, '14 Invoice Road');
    await expect(page.locator('#booking-date')).toHaveCount(0);
    await page
        .getByRole('button', { name: 'Continue to invoice', exact: true })
        .click();
    await expect(page).toHaveURL(/\/jobs\/\d+\/invoices\/create$/);
    await expect(
        page.getByRole('heading', { name: 'New invoice', exact: true }),
    ).toBeVisible();
    await expect(page.getByRole('button', { name: 'Add item' })).toBeVisible();
    await page.screenshot({
        path: info.outputPath('invoice-entry.png'),
        fullPage: true,
    });
});

test('"Now" books a caller right away for today, before the technician\'s next job', async ({
    page,
}, info) => {
    await page.goto('/jobs/create?book=1');
    await page
        .locator('#booking-phone')
        .fill(info.project.name === 'desktop' ? '6045550295' : '6045550296');
    // The number is formatted as it is typed, in the company's country style.
    await expect(page.locator('#booking-phone')).toHaveValue(/\(604\) 555-02/);
    await page.locator('#booking-name').fill(`Now Caller ${info.project.name}`);
    await fillAddress(page, '16 Now Avenue');
    await page
        .locator('[data-slot="date-chips"]')
        .getByRole('radio', { name: 'Today' })
        .click();
    await page.getByRole('radio', { name: 'Now', exact: true }).click();
    await expect(page.getByText(/Goes in right away/)).toBeVisible();
    await page.screenshot({
        path: info.outputPath('booking-now.png'),
        fullPage: true,
    });
    await page
        .getByRole('button', { name: 'Finish booking', exact: true })
        .click();
    await expect(page).toHaveURL(/\/calendar\?date=.*&view=day/);
    await expect(
        page.locator('[data-test="booking-confirmation"]'),
    ).toContainText(/Booked #\d+ · Today/);
});

test('a tap on an empty calendar slot books there with the time and the person', async ({
    page,
}) => {
    await page.goto('/calendar?view=day&date=2030-01-20');
    const lane = page.locator('[data-drop-mode="time"]').first();
    await lane.click({ position: { x: 40, y: 56 * 2 + 10 } });
    await expect(page).toHaveURL(
        /book=1.*date=2030-01-20.*start=\d\d(%3A|:)\d\d.*technician=\d+/,
    );
    await expect(page.locator('[data-test="booking-form"]')).toBeVisible();
});

test('the day chips scroll with "Pick date" as the last one, nothing overlaps', async ({
    page,
}) => {
    await page.goto('/jobs/create?book=1');
    const row = page.locator('[data-slot="date-chips"]');
    const last = row.locator(':scope > *').last();
    await expect(last).toContainText('Pick date');
    // First and last name are two fields; the address follows them.
    await expect(page.locator('#booking-name')).toBeVisible();
    await expect(page.locator('#booking-last-name')).toBeVisible();
    await expect(page.locator('#booking-address')).toBeVisible();
    const boxes = await row
        .locator(':scope > *')
        .evaluateAll((els) =>
            els.map((el) => el.getBoundingClientRect().toJSON()),
        );
    for (let i = 0; i < boxes.length; i++) {
        for (let j = i + 1; j < boxes.length; j++) {
            const [a, b] = [boxes[i], boxes[j]];
            const overlap =
                a.left < b.right - 1 &&
                b.left < a.right - 1 &&
                a.top < b.bottom - 1 &&
                b.top < a.bottom - 1;
            expect(overlap).toBe(false);
        }
    }
});
