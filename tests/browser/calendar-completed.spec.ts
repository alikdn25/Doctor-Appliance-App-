import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';

const fixture = JSON.parse(
    readFileSync('storage/framework/testing/browser-fixture.json', 'utf8'),
);

test('completed work is hidden from the calendar until "Show completed", then shown grey', async ({
    page,
}) => {
    await page.goto(`/calendar?view=day&date=${fixture.today}`);
    const toggle = page.getByRole('checkbox', { name: 'Show completed' });
    await expect(toggle).not.toBeChecked();

    const finished = page.getByRole('button', { name: /Jane Browser/ });
    const before = await finished.count();
    await toggle.check();
    await expect(finished).toHaveCount(before + 1);
    await expect(page.locator('.grayscale')).toHaveCount(1);

    // The choice is remembered on this device.
    await page.reload();
    await expect(
        page.getByRole('checkbox', { name: 'Show completed' }),
    ).toBeChecked();
    await page.getByRole('checkbox', { name: 'Show completed' }).uncheck();
});

test('completed jobs are greyed out in the job history of the customer', async ({
    page,
}) => {
    await page.goto(`/customers/${fixture.customer_id}`);
    const card = page.locator(`a[href$="/jobs/${fixture.finished_job_id}"]`);
    await expect(card.first()).toBeVisible();
    await expect(
        page.getByRole('listitem').filter({ has: card.first() }),
    ).toHaveClass(/grayscale/);
});
