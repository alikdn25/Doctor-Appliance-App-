import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';

const fixture = JSON.parse(
    readFileSync('storage/framework/testing/browser-fixture.json', 'utf8'),
);

test('completed work is hidden from the calendar until "Show completed", then shown grey', async ({
    page,
}) => {
    await page.goto(`/calendar?view=day&date=${fixture.today}`);
    // "Show completed" is in the Filters sheet (UX-REDESIGN §9.1).
    const filters = async () => {
        await page.locator('[data-test="calendar-filters"]').click();
        return page
            .locator('[data-test="calendar-filters-sheet"]')
            .getByRole('checkbox', { name: /Show completed/ });
    };
    const finished = page.getByRole('button', { name: /Jane Browser/ });
    const before = await finished.count();
    const toggle = await filters();
    await expect(toggle).not.toBeChecked();
    await toggle.check();
    await page.keyboard.press('Escape');
    await expect(finished).toHaveCount(before + 1);
    await expect(page.locator('.grayscale')).toHaveCount(1);

    // The choice is remembered on this device.
    await page.reload();
    const again = await filters();
    await expect(again).toBeChecked();
    await again.uncheck();
    await page.keyboard.press('Escape');
});

test('completed jobs are greyed out in the jobs list', async ({ page }) => {
    await page.goto('/jobs?status=completed');
    // Phones show cards, wide screens a table: whichever is on screen greys the job out.
    const card = page
        .locator(`a[href$="/jobs/${fixture.finished_job_id}"]`)
        .filter({ visible: true })
        .first();
    await expect(card).toBeVisible();
    await expect(
        page
            .locator('li, tr')
            .filter({ has: card })
            .filter({ visible: true })
            .first(),
    ).toHaveClass(/grayscale/);
});

test('the customer card shows totals and the job history with payment status', async ({
    page,
}) => {
    await page.goto(`/customers/${fixture.customer_id}`);
    await page.getByRole('tab', { name: /Work/ }).click();
    const totals = page.getByLabel('Customer totals');
    await expect(totals).toContainText('Jobs');
    await expect(totals).toContainText('Owes');
    await expect(
        page.getByRole('heading', { name: 'Job history' }),
    ).toBeVisible();
    await expect(
        page.locator(`a[href$="/jobs/${fixture.finished_job_id}"]`),
    ).toContainText('Finished browser repair');
    const widths = await page.evaluate(() => ({
        page: document.documentElement.scrollWidth,
        viewport: window.innerWidth,
    }));
    expect(widths.page).toBeLessThanOrEqual(widths.viewport);
});
