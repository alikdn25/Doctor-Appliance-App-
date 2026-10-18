import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';

// UX redesign phase 12: wide screens get tables and two columns; phones keep the lists.
const fixture = JSON.parse(
    readFileSync('storage/framework/testing/browser-fixture.json', 'utf8'),
);

test('lists are sortable tables on a computer and cards on a phone', async ({
    page,
}, info) => {
    const desktop = info.project.name === 'desktop';

    await page.goto('/invoices?status=all');
    const table = page.locator('[data-slot="data-table"]');
    if (desktop) {
        await expect(table).toBeVisible();
        await table.getByRole('button', { name: /Total/ }).click();
        await expect(page).toHaveURL(/sort=total/);
        await expect(
            table.getByRole('columnheader', { name: /Total/ }),
        ).toHaveAttribute('aria-sort', 'ascending');
    } else {
        await expect(table).toBeHidden();
        await expect(page.locator('[data-test="invoice-list"]')).toBeVisible();
    }

    await page.goto('/customers');
    await expect(page.locator('[data-slot="data-table"]')).toBeVisible({
        visible: desktop,
    });
    await page.goto('/jobs?tab=all');
    await expect(page.locator('[data-slot="data-table"]')).toBeVisible({
        visible: desktop,
    });
    await page.screenshot({
        path: info.outputPath('jobs-table.png'),
        fullPage: true,
    });
});

test('the job page puts status, money and history in a right column on a computer', async ({
    page,
}, info) => {
    await page.goto(`/jobs/${fixture.job_id}`);
    const aside = page.locator('[data-test="job-aside"]');
    const customer = page.locator('[data-test="job-customer"]');
    const activity = page.locator('[data-test="job-activity"]');
    await expect(customer).toBeVisible();
    await expect(activity).toBeVisible();
    const left = (await customer.boundingBox())!;
    const right = (await activity.boundingBox())!;

    if (info.project.name === 'desktop') {
        await expect(aside).toBeVisible();
        expect(right.x).toBeGreaterThan(left.x + left.width - 1);
    } else {
        // One column: Activity below the customer.
        expect(right.y).toBeGreaterThan(left.y);
    }
    await page.screenshot({
        path: info.outputPath('job-two-columns.png'),
        fullPage: true,
    });
});

test('N opens Book customer and / jumps to the search', async ({ page }) => {
    await page.goto('/customers');
    await page.locator('body').click({ position: { x: 5, y: 300 } });
    await page.keyboard.press('/');
    await expect(page.locator('input[type="search"]').first()).toBeFocused();
    await page.keyboard.press('Escape');
    await page.locator('input[type="search"]').first().blur();
    await page.keyboard.press('n');
    await expect(page).toHaveURL(/\/jobs\/create\?book=1/);
});
