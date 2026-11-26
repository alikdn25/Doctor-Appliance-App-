import { expect, test } from '@playwright/test';

/**
 * Booking from photo on a 390 px phone (owner's task of November 23). Opened by a link (?photo=1), Quick
 * booking shows a big "Choose photo" instead of opening the picker by itself. The paperclip next to the microphone adds photos, their previews sit above the text field
 * with ✕; photos alone can be parsed, and the filled form shows them above the fields (each removable) with the
 * serial number read from the plate.
 */
test.use({ viewport: { width: 390, height: 844 } });

/** A tiny PNG standing in for a screenshot of the customer's SMS. */
const PNG = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
    'base64',
);
const file = (name: string) => ({ name, mimeType: 'image/png', buffer: PNG });

const parsed = {
    customer: null,
    matched_by: null,
    photos: [{ id: 'photo-1.png', url: '/favicon.ico' }],
    fields: {
        first_name: 'Maria',
        last_name: 'Lopez',
        phone: null,
        email: null,
        address: null,
        unit: null,
        buzzer: null,
        appliance_type: 'dishwasher',
        brand: 'Bosch',
        model: 'SHX878WD5N',
        serial_number: 'FD980100123',
        problem: 'Dishwasher leaking',
        date: null,
        arrival_now: false,
        arrival_start: null,
        arrival_end: null,
        notes: null,
        uncertain_fields: ['phone'],
    },
};

test('a link to ?photo=1 shows "Choose photo"; photos alone fill the form and show above it', async ({
    page,
}) => {
    await page.goto('/jobs/quick-booking?photo=1');
    const choose = page.locator('[data-test="quick-booking-choose-photo"]');
    await expect(choose).toBeVisible();
    await expect(choose).toHaveText('Choose photo');
    // The picker never opens by itself.
    const chooser = page.waitForEvent('filechooser');
    await choose.click();
    await (await chooser).setFiles([file('sms.png'), file('plate.png')]);
    await expect(choose).toHaveCount(0);

    const previews = page.locator('[data-test="quick-booking-photos"] img');
    await expect(previews).toHaveCount(2);
    await expect(
        page.locator('[data-test="quick-booking-attach"]'),
    ).toBeVisible();
    // Photos only: Parse is active without any text.
    await expect(page.locator('[data-test="quick-booking-text"]')).toHaveValue(
        '',
    );
    await expect(
        page.locator('[data-test="quick-booking-parse"]'),
    ).toBeEnabled();
    await page
        .locator('[data-test="quick-booking-photo-remove"]')
        .first()
        .click();
    await expect(previews).toHaveCount(1);

    let sent = '';
    await page.route('**/jobs/quick-booking/parse', async (route) => {
        sent = route.request().postData() ?? '';
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify(parsed),
        });
    });
    await page.locator('[data-test="quick-booking-parse"]').click();
    await expect(page).toHaveURL(/\/jobs\/create\?book=1&quick=1/);
    expect(sent).toContain('name="photos[]"');

    await expect(page.locator('[data-test="booking-photos"] img')).toHaveCount(
        1,
    );
    await expect(page.locator('#booking-name')).toHaveValue('Maria');
    await expect(
        page.locator('[data-test="booking-appliance-serial"]'),
    ).toHaveValue('FD980100123');
    await page.locator('[data-test="booking-photo-remove"]').click();
    await expect(page.locator('[data-test="booking-photos"]')).toHaveCount(0);

    const width = await page.evaluate(() => ({
        page: document.documentElement.scrollWidth,
        viewport: innerWidth,
    }));
    expect(width.page).toBeLessThanOrEqual(width.viewport + 1);
});
