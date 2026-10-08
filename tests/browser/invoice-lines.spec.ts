import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';

const fixture = JSON.parse(
    readFileSync('storage/framework/testing/browser-fixture.json', 'utf8'),
);

test('one "Add item" sheet adds labor, part and material rows whose prices add up', async ({
    page,
}) => {
    await page.goto(`/jobs/${fixture.job_id}/invoices/create`);

    // The old type switch and the three "+ Labor / Part / Material" buttons are gone.
    await expect(page.getByRole('button', { name: 'Labor' })).toHaveCount(0);

    const addItem = async (kind: string, name: string, price: string) => {
        await page.getByRole('button', { name: 'Add item' }).click();
        const sheet = page.getByRole('dialog');
        await expect(sheet.getByText('Or custom item')).toBeVisible();
        await sheet.getByRole('button', { name: kind, exact: true }).click();
        await expect(sheet).toHaveCount(0);

        // The new line opens with the cursor in its name; the others stay folded.
        await expect(
            page.getByLabel('Description', { exact: true }),
        ).toHaveCount(1);
        await page.getByLabel('Description', { exact: true }).fill(name);
        await page.getByLabel('Price', { exact: true }).fill(price);
        // Materials carry no warranty.
        await expect(page.getByLabel('Warranty', { exact: true })).toHaveCount(
            kind === 'Material' ? 0 : 1,
        );
        await page.getByRole('button', { name: 'Done' }).click();
    };

    await addItem('Labor', 'Diagnosis and repair', '100');
    await addItem('Part', 'Drain pump', '150');
    await addItem('Material', 'Hose clamp', '10');

    // Folded rows: type letter, name, "qty × price" and the amount.
    await expect(page.getByLabel('Description', { exact: true })).toHaveCount(
        0,
    );
    const pump = page.getByRole('button', { name: /Drain pump/ });
    await expect(pump).toContainText('1 × $150.00');

    // A tap opens the row; the stepper doubles the quantity.
    await pump.click();
    await page.getByRole('button', { name: 'More', exact: true }).click();
    await expect(page.getByLabel('Qty', { exact: true })).toHaveValue('2');
    await page.getByRole('button', { name: 'Done' }).click();

    const totals = page.getByLabel('Totals');
    await expect(
        totals.getByText('Subtotal', { exact: true }).locator('..'),
    ).toContainText('$410.00');
    await expect(page.getByRole('button', { name: /Save · / })).toBeVisible();

    // Dates fold into one line and open on tap; the discount is a link.
    await expect(page.getByLabel('Date', { exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: /Edit$/ }).first().click();
    await expect(page.getByLabel('Date', { exact: true })).toBeVisible();
    await expect(
        page.getByRole('button', { name: '+ Add discount' }),
    ).toBeVisible();

    const widths = await page.evaluate(() => ({
        page: document.documentElement.scrollWidth,
        viewport: window.innerWidth,
    }));
    expect(widths.page).toBeLessThanOrEqual(widths.viewport);

    await page.getByRole('button', { name: /Save · / }).click();
    await expect(page).toHaveURL(/\/invoices\/\d+$/);
    await expect(page.getByText('Drain pump')).toBeVisible();
});

test('a tax set for parts and materials skips labor until ticked on the line', async ({
    page,
}, info) => {
    const name = `Parts tax ${info.project.name}`;
    await page.goto('/company/taxes');
    await page.getByRole('button', { name: 'Add tax', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await dialog.locator('#name').fill(name);
    await dialog.locator('#rate').fill('10');
    await dialog
        .getByRole('checkbox', { name: 'Labor', exact: true })
        .uncheck();
    await dialog
        .getByRole('checkbox', {
            name: 'Apply by default on new estimates and invoices',
        })
        .check();
    await dialog.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByText('on Part, Material').first()).toBeVisible();

    await page.goto(`/jobs/${fixture.job_id}/invoices/create`);
    for (const [kind, label] of [
        ['Labor', 'Repair labor'],
        ['Part', 'Door switch'],
    ]) {
        await page.getByRole('button', { name: 'Add item' }).click();
        await page
            .getByRole('dialog')
            .getByRole('button', { name: kind, exact: true })
            .click();
        await page.getByLabel('Description', { exact: true }).fill(label);
        await page.getByLabel('Price', { exact: true }).fill('100');
        await page.getByRole('button', { name: 'Done' }).click();
    }

    const totals = page.getByLabel('Totals');
    const taxRow = totals.getByText(`${name} 10%`).locator('..');
    await expect(taxRow).toContainText('$10.00');

    // Ticked on the labor line, the tax covers it too.
    await page.getByRole('button', { name: /Repair labor/ }).click();
    await page.getByRole('checkbox', { name: `${name} 10%` }).check();
    await expect(taxRow).toContainText('$20.00');

    // Switched off again so the other scenarios keep their totals.
    await page.goto('/company/taxes');
    await page
        .getByRole('listitem')
        .filter({ hasText: name })
        .getByRole('button', { name: 'Edit' })
        .click();
    await dialog
        .getByRole('checkbox', {
            name: 'Apply by default on new estimates and invoices',
        })
        .uncheck();
    await dialog
        .getByRole('checkbox', { name: 'Active', exact: true })
        .uncheck();
    await dialog.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(dialog).not.toBeVisible();
});
