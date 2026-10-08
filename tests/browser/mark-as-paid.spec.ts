import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';

const fixture = JSON.parse(
    readFileSync('storage/framework/testing/browser-fixture.json', 'utf8'),
);

test('cash on site: Mark as paid from the job, then the review question and Complete job', async ({
    page,
}, info) => {
    // An invoice of its own for this screen size, so the other scenarios keep theirs.
    await page.goto(`/jobs/${fixture.job_id}/invoices/create`);
    await page.getByRole('button', { name: 'Add item' }).click();
    await page
        .getByRole('dialog')
        .getByRole('button', { name: 'Labor', exact: true })
        .click();
    await page
        .getByLabel('Description', { exact: true })
        .fill(`Cash repair ${info.project.name}`);
    await page.getByLabel('Price', { exact: true }).fill('100');
    await page.getByRole('button', { name: /Save · / }).click();
    await expect(page).toHaveURL(/\/invoices\/\d+$/);
    const number = (
        await page.getByRole('heading', { name: /^Invoice / }).innerText()
    )
        .replace(/^Invoice\s*/, '')
        .trim();

    await page.goto(`/jobs/${fixture.job_id}`);
    await page
        .getByRole('button', { name: new RegExp(`Mark ${number} as paid`) })
        .click();
    const dialog = page.getByRole('dialog');
    await dialog.getByRole('radio', { name: 'Cash' }).click();
    // Half now: the rest stays owed and the button shows it.
    await dialog.getByLabel('Amount').fill('50');
    await dialog.getByRole('button', { name: /Mark as paid · / }).click();
    await expect(dialog).toHaveCount(0);
    await expect(
        page.getByRole('button', {
            name: new RegExp(`Mark ${number} as paid`),
        }),
    ).toContainText('$62.00');

    await page
        .getByRole('button', { name: new RegExp(`Mark ${number} as paid`) })
        .click();
    await dialog.getByRole('radio', { name: 'Cash' }).click();
    await dialog.getByRole('button', { name: /Mark as paid · / }).click();

    // Paid in full: the review question first, then Complete job.
    await expect(page.getByText('Send Google Review request?')).toBeVisible();
    await page.getByRole('button', { name: 'No', exact: true }).click();
    await expect(page.getByText('Paid in full')).toBeVisible();
    await expect(
        page.getByRole('button', { name: /Complete job|Finish job/ }),
    ).toBeVisible();
    await page.getByRole('button', { name: 'Not now' }).click();
    await expect(
        page.getByRole('button', {
            name: new RegExp(`Mark ${number} as paid`),
        }),
    ).toHaveCount(0);
});
