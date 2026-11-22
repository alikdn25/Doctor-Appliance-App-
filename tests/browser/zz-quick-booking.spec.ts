import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * Quick booking on a 390 px phone: the two booking buttons (violet Quick booking, white Book customer) on the
 * calendar and in "+" (no longer on Today); the big text field and Parse; without an AI key the message is shown and the text
 * stays. The filled Book customer form marks the AI-filled fields ("Check" on the ones the AI is unsure of) and offers
 * free windows when the text named no time; nothing is saved until Save. Closing either screen keeps nothing.
 */
test.use({ viewport: { width: 390, height: 844 } });

const TEXT = 'Anna Kim 604 555 0199, washer not draining, tomorrow';

/** What the AI would read from the text (CI has no AI key, so the answer is stubbed). */
async function stubParse(page: Page) {
    await page.route('**/jobs/quick-booking/parse', (route) =>
        route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
                customer: null,
                matched_by: null,
                fields: {
                    first_name: 'Anna',
                    last_name: 'Kim',
                    phone: '+16045550199',
                    email: null,
                    address: {
                        line1: '123 Main St',
                        city: 'Surrey',
                        region: 'BC',
                        postal_code: null,
                    },
                    unit: '4',
                    buzzer: '1234',
                    appliance_type: 'washer',
                    brand: 'LG',
                    model: 'WM3900',
                    problem: 'Washer not draining',
                    date: null,
                    arrival_now: false,
                    arrival_start: null,
                    arrival_end: null,
                    notes: null,
                    uncertain_fields: ['model'],
                },
            }),
        }),
    );
}

test('Quick booking keeps the text when the AI does not answer', async ({
    page,
}) => {
    // Today has no booking buttons any more (they stay in "+" and on the calendar).
    await page.goto('/my-jobs');
    await expect(page.locator('[data-test="create-fab"]')).toBeVisible();
    await expect(
        page.locator('[data-test="start-quick-booking"]'),
    ).toHaveCount(0);
    await page.goto('/calendar');
    const quick = page.locator('[data-test="start-quick-booking"]');
    const manual = page.locator('[data-test="start-book-customer"]');
    // Two buttons side by side: Quick booking (violet) and Book customer (white).
    await expect(quick).toBeVisible();
    await expect(manual).toBeVisible();
    const [a, b] = [
        (await quick.boundingBox())!,
        (await manual.boundingBox())!,
    ];
    expect(Math.abs(a.y - b.y)).toBeLessThanOrEqual(1);
    await quick.click();
    await expect(page).toHaveURL(/\/jobs\/quick-booking/);

    const text = page.locator('[data-test="quick-booking-text"]');
    await text.fill(TEXT);
    await page.locator('[data-test="quick-booking-parse"]').click();
    // CI has no ANTHROPIC_API_KEY: the message is shown and the text stays.
    await expect(
        page.locator('[data-test="quick-booking-error"]'),
    ).toBeVisible();
    await expect(text).toHaveValue(TEXT);

    // "+" has both buttons too.
    await page.goto('/my-jobs');
    await page.locator('[data-test="create-fab"]').click();
    await page.locator('[data-test="create-quick-booking"]').click();
    await expect(page).toHaveURL(/\/jobs\/quick-booking/);
});

test('closing Quick booking empties the text; it opens empty again', async ({
    page,
}) => {
    await page.goto('/calendar');
    await page.locator('[data-test="start-quick-booking"]').click();
    const text = page.locator('[data-test="quick-booking-text"]');
    await text.fill(TEXT);
    // Back (the header arrow or the phone's back): no "Are you sure?".
    let asked = false;
    page.on('dialog', (dialog) => {
        asked = true;
        void dialog.dismiss();
    });
    await page.goBack();
    await expect(page).toHaveURL(/\/calendar/);
    await page.locator('[data-test="start-quick-booking"]').click();
    await expect(text).toHaveValue('');
    expect(asked).toBe(false);
    // Nothing of it is in the browser's storage.
    const stored = await page.evaluate(() =>
        [sessionStorage, localStorage]
            .flatMap((store) =>
                Array.from({ length: store.length }, (_, i) =>
                    store.getItem(store.key(i) ?? ''),
                ),
            )
            .join(' '),
    );
    expect(stored).not.toContain('Anna Kim');
});

test('the filled form marks the AI fields and offers free windows', async ({
    page,
}) => {
    await stubParse(page);
    await page.goto('/jobs/quick-booking');
    await page.locator('[data-test="quick-booking-text"]').fill(TEXT);
    await page.locator('[data-test="quick-booking-parse"]').click();
    await expect(page).toHaveURL(/\/jobs\/create\?book=1&quick=1/);

    await expect(
        page.locator('[data-test="quick-booking-filled"]'),
    ).toBeVisible();
    await expect(page.locator('#booking-name')).toHaveValue('Anna');
    await expect(page.locator('#booking-last-name')).toHaveValue('Kim');
    await expect(page.locator('#booking-problem')).toHaveValue(
        'Washer not draining',
    );
    await expect(page.locator('#booking-appliance-model')).toHaveValue(
        'WM3900',
    );
    await expect(page.locator('#booking-team-notes')).toHaveValue(
        'Buzzer: 1234',
    );
    // No day or window in the text: free windows of the next days.
    await expect(
        page.locator('[data-test="quick-booking-free"]'),
    ).toBeVisible();
    // Finish booking is always active.
    await expect(page.locator('[data-test="booking-finish"]')).toBeEnabled();
    // Nothing was saved: the form waits for Save.
    await expect(page).toHaveURL(/\/jobs\/create/);
    const width = await page.evaluate(() => ({
        page: document.documentElement.scrollWidth,
        viewport: innerWidth,
    }));
    expect(width.page).toBeLessThanOrEqual(width.viewport + 1);

    // "Back to the text" keeps the text while the booking is open.
    await page.getByRole('link', { name: 'Back to the text' }).click();
    await expect(page.locator('[data-test="quick-booking-text"]')).toHaveValue(
        TEXT,
    );
});

test('closing Book customer empties the form; a new booking opens empty', async ({
    page,
}) => {
    await page.goto('/calendar');
    await page.locator('[data-test="start-book-customer"]').click();
    await expect(page).toHaveURL(/\/jobs\/create\?book=1/);
    await page.locator('#booking-phone').fill('+16045550311');
    await page.locator('#booking-name').fill('Closing Test');
    await page.locator('#booking-problem').fill('Dryer not heating');
    // ✕ closes at once, without "Are you sure?".
    let asked = false;
    page.on('dialog', (dialog) => {
        asked = true;
        void dialog.dismiss();
    });
    await page.locator('[data-test="booking-cancel"]').click();
    await expect(page).not.toHaveURL(/\/jobs\/create/);
    expect(asked).toBe(false);

    await page.goto('/calendar');
    await page.locator('[data-test="start-book-customer"]').click();
    await expect(page.locator('#booking-phone')).toHaveValue('');
    await expect(page.locator('#booking-name')).toHaveValue('');
    await expect(page.locator('#booking-problem')).toHaveValue('');
});

test('the technician row comes before When: Me first with a star, Unassigned last', async ({
    page,
}) => {
    await page.goto('/jobs/create?book=1');
    const cards = page.locator('[data-test="technician-cards"]');
    await expect(cards).toBeVisible();
    const when = page.getByRole('heading', { name: 'When', exact: true });
    expect((await cards.boundingBox())!.y).toBeLessThan(
        (await when.boundingBox())!.y,
    );
    const radios = cards.getByRole('radio');
    await expect(radios.first()).toHaveAttribute('data-test', 'technician-me');
    await expect(radios.first()).toContainText('Me');
    await expect(radios.last()).toHaveAttribute(
        'data-test',
        'technician-unassigned',
    );
    // Every person has a free (green) or busy (red) dot for the chosen window.
    await expect(
        cards.locator(
            '[data-test="technician-free"], [data-test="technician-busy"]',
        ),
    ).toHaveCount((await radios.count()) - 1);
    // "No time" saves without a visit: the dots go away.
    await page.getByRole('radio', { name: 'No time', exact: true }).click();
    await expect(cards.locator('[data-test="technician-free"]')).toHaveCount(0);
});
