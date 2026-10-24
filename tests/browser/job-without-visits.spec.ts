import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';

const fixture = JSON.parse(
    readFileSync('storage/framework/testing/browser-fixture.json', 'utf8'),
);

test('a job is its own booking: no Visits or Schedule block, the time is changed with the pencil', async ({
    page,
}) => {
    await page.goto(`/jobs/${fixture.job_id}`);
    await expect(page.getByRole('heading', { name: 'Visits' })).toHaveCount(0);
    await expect(page.getByText(/visit/i)).toHaveCount(0);
    await expect(
        page.getByRole('heading', { name: 'Schedule', exact: true }),
    ).toHaveCount(0);
    await expect(
        page.getByRole('button', { name: 'Edit job details' }),
    ).toBeVisible();

    // A job waiting for parts has no time yet: "Schedule next time" books it.
    await page.goto(`/jobs/${fixture.waiting_job_id}`);
    await expect(page.getByText(/visit/i)).toHaveCount(0);
    await page
        .getByRole('button', { name: 'Schedule next time', exact: true })
        .click();
    await expect(
        page.getByRole('dialog').getByRole('heading', { name: 'Schedule' }),
    ).toBeVisible();
});
