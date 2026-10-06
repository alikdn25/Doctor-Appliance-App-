import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';

const fixture = JSON.parse(
    readFileSync('storage/framework/testing/browser-fixture.json', 'utf8'),
);

test('labor, part and material are separate lines whose prices add up', async ({
    page,
}) => {
    await page.goto(`/jobs/${fixture.job_id}/invoices/create`);
    const prices = page.getByLabel('Price', { exact: true });
    const descriptions = page.getByLabel('Description', { exact: true });

    await page
        .getByRole('button', { name: 'Labor', exact: true })
        .last()
        .click();
    await descriptions.nth(0).fill('Labor');
    await prices.nth(0).fill('100');

    // Each type button adds a new, empty line: the labor price is not carried over or replaced.
    await page
        .getByRole('button', { name: 'Part', exact: true })
        .last()
        .click();
    await expect(prices).toHaveCount(2);
    await expect(prices.nth(1)).toHaveValue('');
    await descriptions.nth(1).fill('Drain pump');
    await prices.nth(1).fill('150');

    await page
        .getByRole('button', { name: 'Material', exact: true })
        .last()
        .click();
    await expect(prices).toHaveCount(3);
    await expect(prices.nth(2)).toHaveValue('');
    await descriptions.nth(2).fill('Hose clamp');
    await prices.nth(2).fill('20');

    await expect(prices.nth(0)).toHaveValue('100');
    await expect(
        page.getByText('Subtotal', { exact: true }).locator('..'),
    ).toContainText('$270.00');
});
