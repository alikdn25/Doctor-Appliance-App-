import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';

const fixture = JSON.parse(
    readFileSync('storage/framework/testing/browser-fixture.json', 'utf8'),
);

test.afterEach(async ({ page }, info) => {
    if (info.status === 'passed') {
        const width = await page.evaluate(() => ({
            page: document.documentElement.scrollWidth,
            viewport: innerWidth,
        }));
        expect(width.page).toBeLessThanOrEqual(width.viewport + 1);
    }
});

test('the technician sees the visit buttons above everything and no signature request on the job', async ({
    page,
    browser,
}, info) => {
    const context = await browser.newContext({
        storageState: 'test-results/auth-tech.json',
        viewport: page.viewportSize()!,
    });
    const tech = await context.newPage();
    await tech.goto(`http://127.0.0.1:8000/jobs/${fixture.job_id}`);
    const onMyWay = tech.getByRole('button', {
        name: 'On my way',
        exact: true,
    });
    await expect(onMyWay).toBeVisible();
    // Nothing covers the button: the element at its centre is the button itself.
    const box = (await onMyWay.boundingBox())!;
    expect(box.y + box.height).toBeLessThanOrEqual(page.viewportSize()!.height);
    const onTop = await onMyWay.evaluate((button) => {
        const rect = button.getBoundingClientRect();
        const hit = document.elementFromPoint(
            rect.x + rect.width / 2,
            rect.y + rect.height / 2,
        );
        return hit !== null && button.contains(hit);
    });
    expect(onTop).toBe(true);
    if (info.project.name === 'mobile') {
        await expect(
            tech.getByRole('navigation', { name: 'Main sections' }),
        ).toBeHidden();
    }
    await expect(
        tech.getByRole('button', { name: /Get signature|Sign again/ }),
    ).toHaveCount(0);
    await tech.screenshot({ path: info.outputPath('job-page-tech.png') });
    await context.close();
});

test('editing a booked job changes the customer name and appliances are picked from image tiles', async ({
    page,
}, info) => {
    await page.goto(`/jobs/${fixture.job_id}/edit`);
    const first = page.locator('#ce-first_name');
    await expect(first).toHaveValue('Jane');
    await expect(page.locator('#ce-phone')).not.toHaveValue('');
    await first.fill('Janet');
    await page.getByRole('button', { name: 'Add appliance' }).click();
    const tiles = page.getByRole('group', { name: 'Appliance type' });
    await tiles.getByRole('button', { name: 'Wine cooler' }).click();
    await expect(
        tiles.getByRole('button', { name: 'Wine cooler' }),
    ).toHaveAttribute('aria-pressed', 'true');
    await page.screenshot({
        path: info.outputPath('job-edit-customer.png'),
        fullPage: true,
    });
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/jobs/${fixture.job_id}$`));
    await expect(page.getByText('Janet Browser').first()).toBeVisible();
    await expect(page.getByText('Wine cooler').first()).toBeVisible();

    // Put the fixture name back for the other scenarios.
    await page.goto(`/jobs/${fixture.job_id}/edit`);
    await page.locator('#ce-first_name').fill('Jane');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByText('Jane Browser').first()).toBeVisible();
});

test('the menu shows the everyday sections and opens money and settings on tap', async ({
    page,
}, info) => {
    await page.goto('/jobs');
    if (info.project.name === 'mobile') {
        await page.getByRole('button', { name: 'Menu', exact: true }).click();
    }
    const sidebar = page.locator('[data-sidebar="content"]').last();
    await expect(
        sidebar.getByRole('link', { name: 'Jobs', exact: true }),
    ).toBeVisible();
    await expect(
        sidebar.getByRole('link', { name: 'Reports', exact: true }),
    ).toBeHidden();
    await sidebar
        .getByRole('button', { name: 'Money and reports', exact: true })
        .click();
    await expect(
        sidebar.getByRole('link', { name: 'Reports', exact: true }),
    ).toBeVisible();
    await page.screenshot({ path: info.outputPath('menu.png') });
});
