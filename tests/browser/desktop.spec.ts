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

test('the job side column and table headers stay in view below the app header', async ({
    page,
}, info) => {
    test.skip(
        info.project.name !== 'desktop',
        'two columns and tables are for wide screens',
    );
    // A short window, scrolled to the very bottom: the header is not covered by the unfinished-jobs strip, and the
    // top of the side column (status, Estimates & invoices) stays below the header.
    const layout = async () =>
        page.evaluate(() => {
            const rect = (selector: string) =>
                document.querySelector(selector)?.getBoundingClientRect();
            const bar = rect('.da-unfinished-bar');
            const header = rect('header.da-header')!;
            const aside = rect('[data-test="job-aside"]')!;
            const atBottom =
                window.scrollY + window.innerHeight >=
                document.documentElement.scrollHeight - 1;

            return atBottom
                ? Math.min(
                      header.top - (bar?.bottom ?? 0),
                      aside.top - header.bottom,
                  )
                : -1;
        });

    await page.setViewportSize({ width: 1440, height: 520 });
    await page.goto(`/jobs/${fixture.job_id}`);
    await expect(page.locator('[data-test="job-aside"]')).toBeVisible();
    await page.mouse.move(400, 300);
    await page.mouse.wheel(0, 5000);
    await expect.poll(layout).toBeGreaterThanOrEqual(0);
    await page.setViewportSize({ width: 1440, height: 900 });

    await page.goto('/jobs?tab=all');
    const table = page.locator('[data-slot="data-table"]');
    await table.getByRole('button', { name: 'Status' }).click();
    await expect(page).toHaveURL(/sort=status/);
    await expect(page).toHaveURL(/dir=asc/);
    await expect(
        table.getByRole('columnheader', { name: /Status/ }),
    ).toHaveAttribute('aria-sort', 'ascending');
    await table.getByRole('button', { name: 'Status' }).click();
    await expect(page).toHaveURL(/sort=status/);
    await expect(page).toHaveURL(/dir=desc/);
    // The whole header cell sorts, not only its title.
    const customer = table.getByRole('button', { name: 'Customer' });
    const box = (await customer.boundingBox())!;
    await customer.click({ position: { x: box.width - 4, y: box.height - 4 } });
    await expect(page).toHaveURL(/sort=customer/);
    await expect(page).toHaveURL(/dir=asc/);
});
