import { expect, test } from '@playwright/test';

/**
 * Quick booking (owner's task of November 15, PR 1) on a 390 px phone: the button on Today and in "+", the big text
 * field and Parse; without an AI key the message is shown and the text stays. The filled Book customer form marks the
 * AI-filled fields and offers free windows when the text named no time; nothing is saved until Save.
 */
test.use({ viewport: { width: 390, height: 844 } });

test('Quick booking keeps the text when the AI does not answer', async ({
    page,
}) => {
    await page.goto('/my-jobs');
    await page.locator('[data-test="today-quick-booking"]').click();
    await expect(page).toHaveURL(/\/jobs\/quick-booking/);

    const text = page.locator('[data-test="quick-booking-text"]');
    await text.fill('Anna Kim 604 555 0199, washer not draining, tomorrow');
    await page.locator('[data-test="quick-booking-parse"]').click();
    // CI has no ANTHROPIC_API_KEY: the message is shown and the text stays.
    await expect(
        page.locator('[data-test="quick-booking-error"]'),
    ).toBeVisible();
    await expect(text).toHaveValue(
        'Anna Kim 604 555 0199, washer not draining, tomorrow',
    );

    // "+" has Quick booking too.
    await page.goto('/calendar');
    await page.locator('[data-test="create-fab"]').click();
    await page.locator('[data-test="create-quick-booking"]').click();
    await expect(page).toHaveURL(/\/jobs\/quick-booking/);
    // The text is kept while going back and forth.
    await expect(text).toHaveValue(
        'Anna Kim 604 555 0199, washer not draining, tomorrow',
    );
});

test('the filled form marks the AI fields and offers free windows', async ({
    page,
}) => {
    await page.goto('/jobs/quick-booking');
    await page.evaluate(() =>
        sessionStorage.setItem(
            'quick-booking-result',
            JSON.stringify({
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
                },
            }),
        ),
    );
    await page.goto('/jobs/create?book=1&quick=1');

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
    // Nothing was saved: the form waits for Save.
    await expect(page).toHaveURL(/\/jobs\/create/);
    const width = await page.evaluate(() => ({
        page: document.documentElement.scrollWidth,
        viewport: innerWidth,
    }));
    expect(width.page).toBeLessThanOrEqual(width.viewport + 1);
});
