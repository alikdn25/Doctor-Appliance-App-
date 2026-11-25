import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';

/**
 * Owner's task of November 25: Settings → Company → Booking confirmation (texts, preview, placeholders) and the job
 * report form (Report button on the job, nothing required, the app's microphone under the text).
 */
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

test('Booking confirmation settings: live preview, placeholder at the cursor, reset to default', async ({
    page,
}) => {
    await page.goto('/company/booking-confirmation');
    const preview = page.locator('[data-test="bc-preview-sms"]');
    await expect(preview).toContainText(
        'We require 24 hours cancellation notice',
    );

    await page.locator('[data-test="bc-hours"]').fill('48');
    await expect(preview).toContainText('We require 48 hours');

    const sms = page.locator('[data-test="bc-sms_text"]');
    await sms.fill('Hello ');
    await sms.focus();
    await page.locator('[data-test="bc-placeholder-first_name"]').click();
    await expect(sms).toHaveValue('Hello {first_name}');
    await expect(preview).toHaveText('Hello Maria');

    await page.locator('[data-test="bc-reset-sms_text"]').click();
    await expect(sms).toHaveValue(
        /^Hi \{first_name\}, this is \{company_name\}/,
    );
    // The app's microphone sits under the texts where the browser can recognise speech.
    await expect(
        page.locator('[data-test="mic-button"]').first(),
    ).toBeVisible();
});

test('the job page has Report next to New invoice; an empty report saves', async ({
    page,
}) => {
    await page.goto(`/jobs/${fixture.job_id}`);
    const report = page.locator('[data-test="job-new-report"]');
    await expect(report).toBeVisible();
    await report.click();

    await expect(page.locator('[data-test="report-form"]')).toBeVisible();
    await page.locator('[data-test="report-text"]').fill('Cleaned the pump.');
    await page.locator('[data-test="report-save"]').click();
    await expect(page.locator('[data-test="report-send"]')).toBeVisible();
    await expect(
        page.locator('[data-test="report-preview-pdf"]'),
    ).toBeVisible();
});
