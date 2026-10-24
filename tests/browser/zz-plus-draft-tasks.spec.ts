import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { readFileSync } from 'node:fs';

/**
 * Owner's task of October 24 (the "+" button, finishing a booking, Messages tab) on a 390 px phone: the round "+"
 * with Job · Expense · Trip · Task, Save as draft and Finish booking, the orange draft on the job page, a task in
 * My Jobs, and the job page's Notes / Messages tabs.
 */
test.use({ viewport: { width: 390, height: 844 } });

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

async function openPlus(page: Page) {
    await page.locator('[data-test="create-fab"]').click();
    await expect(page.locator('[data-test="create-menu"]')).toBeVisible();
}

test('the round "+" is on every screen and opens Job, Expense, Trip and Task', async ({
    page,
}, info) => {
    for (const path of ['/my-jobs', '/calendar', '/customers']) {
        await page.goto(path);
        await expect(page.locator('[data-test="create-fab"]')).toBeVisible();
    }
    await openPlus(page);
    // From the bottom up: Job nearest the thumb.
    const items = page.locator('[data-test="create-menu"] button');
    await expect(items).toHaveText(['Task', 'Trip', 'Expense', 'Job']);
    const job = (await page.locator('[data-test="create-job"]').boundingBox())!;
    const task = (await page
        .locator('[data-test="create-task"]')
        .boundingBox())!;
    expect(job.y).toBeGreaterThan(task.y);
    await noSideScroll(page);
    await page.screenshot({ path: info.outputPath('390-plus-menu.png') });

    await page.locator('[data-test="create-expense"]').click();
    await expect(page).toHaveURL(/\/business-expenses\/create/);
    // A form has its own pinned actions: no "+" over them.
    await expect(page.locator('[data-test="create-fab"]')).toBeHidden();

    await page.goto('/calendar');
    await openPlus(page);
    await page.locator('[data-test="create-trip"]').click();
    await expect(page).toHaveURL(/\/trips\?manual=1/);
    await expect(page.locator('#trip-distance')).toBeVisible();
    await expect(page.locator('#trip-from')).toBeVisible();
});

test('a task from "+" shows in My Jobs in its own colour and is ticked done', async ({
    page,
}, info) => {
    await page.goto('/my-jobs');
    await openPlus(page);
    await page.locator('[data-test="create-task"]').click();
    const sheet = page.locator('[data-test="task-sheet"]');
    await expect(sheet).toBeVisible();
    const title = `Pick up a pump ${Date.now()}`;
    await sheet.locator('#task-title').fill(title);
    await sheet.getByRole('radio', { name: 'All day' }).click();
    await expect(sheet.locator('#task-start')).toHaveCount(0);
    await sheet.locator('#task-note').fill('At Marcone');
    await noSideScroll(page);
    await page.screenshot({ path: info.outputPath('390-task-sheet.png') });
    await sheet.locator('[data-test="task-save"]').click();
    await expect(sheet).toHaveCount(0);

    const row = page
        .locator('[data-test="task-row"]')
        .filter({ hasText: title });
    await expect(row).toBeVisible();
    await expect(row).toContainText('All day');
    await row.locator('[data-test="task-toggle"]').click();
    await expect(row).toHaveAttribute('data-done', 'true');
    await page.screenshot({ path: info.outputPath('390-my-jobs-task.png') });
});

test('Save as draft leaves an orange draft; Finish booking on the job page sends the confirmation', async ({
    page,
}, info) => {
    await page.goto(`/jobs/create?book=1&customer_id=${fixture.customer_id}`);
    await expect(page.locator('[data-test="booking-customer"]')).toBeVisible();
    const finish = page.getByRole('button', {
        name: 'Finish booking',
        exact: true,
    });
    const draft = page.locator('[data-test="booking-save-draft"]');
    await expect(finish).toBeVisible();
    await expect(draft).toBeVisible();
    // Both buttons fit side by side on the phone.
    const a = (await draft.boundingBox())!;
    const b = (await finish.boundingBox())!;
    expect(Math.abs(a.y - b.y)).toBeLessThanOrEqual(1);
    await noSideScroll(page);
    await page.screenshot({ path: info.outputPath('390-booking-buttons.png') });

    if (await draft.isDisabled()) {
        await page.locator('#booking-problem').fill('Washer leaks');
    }
    await draft.click();
    const sheet = page.locator('[data-test="booking-confirmation"]');
    await expect(sheet).toContainText(/Draft #\d+/);
    await expect(
        sheet.locator('[data-test="booking-confirmation-result"]'),
    ).toContainText('No confirmation was sent');
    await sheet.locator('[data-test="confirmation-open-job"]').click();

    const banner = page.locator('[data-test="job-draft-banner"]');
    await expect(banner).toBeVisible();
    await expect(banner).toContainText('Booking not finished');
    await expect(page.locator('[data-test="job-main"]')).toContainText('Draft');
    await noSideScroll(page);
    await page.screenshot({
        path: info.outputPath('390-draft-job.png'),
        fullPage: true,
    });

    await banner.locator('[data-test="job-finish-booking"]').click();
    await expect(
        page
            .locator('[data-test="booking-confirmation"]')
            .locator('[data-test="booking-confirmation-result"]'),
    ).toContainText(/Confirmation|Not sent/);
    await page
        .locator('[data-test="booking-confirmation"]')
        .getByRole('button', { name: 'Done' })
        .click();
    await expect(banner).toHaveCount(0);

    // Resend is in "…" for a finished booking; the old Send booking confirmation link is gone.
    await expect(
        page.locator('[data-test="job-send-confirmation"]'),
    ).toHaveCount(0);
    await page.getByRole('button', { name: 'More actions' }).first().click();
    await expect(
        page.getByRole('button', { name: 'Resend confirmation' }),
    ).toBeVisible();
});

test('the job page has Job · Notes · Messages · Files · History; a team note is added on Notes', async ({
    page,
}, info) => {
    await page.goto(`/jobs/${fixture.job_id}`);
    const tabs = page.locator('#job-tabs [role="tab"]');
    await expect(tabs).toHaveText([
        /^Job/,
        /^Notes/,
        /^Messages/,
        /^Files/,
        /^History/,
    ]);
    // No tab label is cut off on the phone.
    for (const handle of await tabs.all()) {
        const clipped = await handle.evaluate(
            (el) => el.scrollWidth > el.clientWidth + 1,
        );
        expect(clipped).toBe(false);
    }

    await page.getByRole('tab', { name: /^Notes/ }).click();
    const notes = page.locator('[data-test="job-tab-notes"]');
    await expect(notes).toContainText('About the customer');
    await expect(notes.getByRole('button', { name: 'Send SMS' })).toHaveCount(
        0,
    );
    const text = `Gasket on order ${Date.now()}`;
    await notes.locator('#job-note').fill(text);
    await notes.locator('[data-test="job-note-add"]').click();
    await expect(notes.locator('[data-test="job-notes"]')).toContainText(text);

    await page.getByRole('tab', { name: /^Messages/ }).click();
    await expect(page.locator('[data-test="job-tab-messages"]')).toContainText(
        'Messages',
    );
    await noSideScroll(page);
    await page.screenshot({
        path: info.outputPath('390-job-tabs.png'),
        fullPage: true,
    });
});
