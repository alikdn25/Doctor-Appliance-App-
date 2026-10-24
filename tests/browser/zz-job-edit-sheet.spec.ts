import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { readFileSync } from 'node:fs';

/**
 * Owner's update of October 24 on a 390 px phone: the job page's pencil sheet (no Schedule block, no "Change
 * address"), "Notify client" off by default, the day shown once, sheets riding above the keyboard, the More menu
 * scrolling to its last item, and "+" → Expense → Job.
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

test('the pencil on the customer card edits name, address, time and technician; nothing is texted unless ticked', async ({
    page,
}, info) => {
    await page.goto(`/jobs/${fixture.edit_job_id}`);
    const main = page.locator('[data-test="job-main"]');
    await expect(
        page.getByRole('heading', { name: 'Schedule', exact: true }),
    ).toHaveCount(0);
    await expect(page.locator('[data-test="job-change-address"]')).toHaveCount(
        0,
    );
    // An open job: no Costs & profit.
    await expect(page.getByText('Costs & profit')).toHaveCount(0);

    // "Tue, Oct 20 · 3:00 – 5:00 p.m.": the weekday and date once.
    const when = (await main.locator('[data-test="job-when"]').innerText())
        .split('\n')[0]
        .trim();
    expect(when).toMatch(/^\w{3}, \w{3} \d{1,2} · \d/);
    expect(when.match(/\b(Mon|Tue|Wed|Thu|Fri|Sat|Sun)\b/g)).toHaveLength(1);

    await main.locator('[data-test="job-edit"]').click();
    const sheet = page.locator('[data-test="job-edit-sheet"]');
    await expect(sheet).toBeVisible();
    for (const id of [
        '#edit-first-name',
        '#edit-address',
        '#edit-date',
        '#edit-start',
        '#edit-end',
    ]) {
        await expect(sheet.locator(id)).toBeVisible();
    }
    await expect(sheet.locator('#edit-date')).not.toHaveValue('');
    // "Remove time" moved here from the removed Schedule block.
    await expect(
        sheet.locator('[data-test="job-edit-remove-time"]'),
    ).toBeVisible();
    const notify = sheet.locator('#edit-notify');
    await expect(notify).toHaveAttribute('aria-checked', 'false');
    await noSideScroll(page);
    await page.screenshot({ path: info.outputPath('390-job-edit-sheet.png') });

    await sheet.locator('[data-test="job-edit-save"]').click();
    await expect(sheet).toHaveCount(0);
    await expect(page.getByText('Job updated.')).toBeVisible();
    // No "Notify client?" question any more.
    await expect(page.locator('[data-test="confirmation-send"]')).toHaveCount(
        0,
    );
});

test('a sheet rides above the keyboard and keeps the field being typed in visible', async ({
    page,
}) => {
    // A stand-in for the phone's visual viewport, which shrinks when the keyboard opens.
    await page.addInitScript(() => {
        const viewport = new EventTarget();
        let covered = 0;
        Object.defineProperties(viewport, {
            height: { get: () => window.innerHeight - covered },
            width: { get: () => window.innerWidth },
            offsetTop: { get: () => 0 },
            offsetLeft: { get: () => 0 },
            scale: { get: () => 1 },
        });
        Object.defineProperty(window, 'visualViewport', { value: viewport });
        (window as unknown as { keyboard: (px: number) => void }).keyboard = (
            px: number,
        ) => {
            covered = px;
            viewport.dispatchEvent(new Event('resize'));
        };
    });
    await page.goto(`/jobs/${fixture.edit_job_id}`);
    await page.locator('[data-test="job-edit"]').click();
    const sheet = page.locator('[data-test="job-edit-sheet"]');
    await expect(sheet).toBeVisible();

    await sheet.locator('#edit-end').focus();
    await page.evaluate(() =>
        (window as unknown as { keyboard: (px: number) => void }).keyboard(380),
    );
    await expect(sheet).toHaveAttribute('data-keyboard', 'open');
    // The sheet slides up and ends where the keyboard starts.
    await expect
        .poll(async () => {
            const box = (await sheet.boundingBox())!;
            return Math.round(box.y + box.height);
        })
        .toBeLessThanOrEqual(844 - 380 + 1);
    // The field being typed in is in the visible part of the sheet.
    await expect
        .poll(async () => {
            const box = (await sheet.boundingBox())!;
            const field = (await sheet.locator('#edit-end').boundingBox())!;
            return field.y >= box.y && field.y + field.height <= 844 - 380;
        })
        .toBe(true);

    await page.evaluate(() =>
        (window as unknown as { keyboard: (px: number) => void }).keyboard(0),
    );
    await expect(sheet).not.toHaveAttribute('data-keyboard', 'open');
});

test('More scrolls to its last item, and the menu hint names the bottom bar', async ({
    page,
}, info) => {
    await page.goto('/my-jobs');
    await page.locator('[data-test="bottom-nav-more"]').click();
    const more = page.locator('[data-test="more-sheet"]');
    await expect(more).toBeVisible();
    await expect(more.locator('[data-test="menu-mode"]')).toContainText(
        /Today · Calendar · Map · (Jobs|Invoices)/,
    );
    await expect(more.locator('[data-test="menu-mode"]')).not.toContainText(
        'Book',
    );

    // The last block (profile, notifications, sign out) is whole and reachable.
    const person = more.locator('[data-test="more-person"]');
    await person.scrollIntoViewIfNeeded();
    const sheetBox = (await more.boundingBox())!;
    const personBox = (await person.boundingBox())!;
    expect(personBox.height).toBeGreaterThan(100);
    expect(personBox.y + personBox.height).toBeLessThanOrEqual(
        sheetBox.y + sheetBox.height + 1,
    );
    await expect(
        person.getByRole('button', { name: 'Log out' }),
    ).toBeInViewport();
    await page.screenshot({ path: info.outputPath('390-more-bottom.png') });
});

test('"+" → Expense has an optional Job field', async ({ page }) => {
    await page.goto(`/business-expenses/create?job=${fixture.edit_job_id}`);
    const job = page.locator('#service_job_id');
    await expect(job).toHaveValue(String(fixture.edit_job_id));
    await expect(job.locator('option').first()).toHaveText(
        'No job: company expense',
    );
    await noSideScroll(page);
});
