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

test('my jobs: day with arrows, Completed, + Trip, card with Navigate, Call and View job', async ({
    page,
    browser,
}, info) => {
    const context = await browser.newContext({
        storageState: 'test-results/auth-tech.json',
        viewport: page.viewportSize()!,
    });
    const tech = await context.newPage();
    await tech.goto('http://127.0.0.1:8000/my-jobs');
    const today = tech.getByRole('link', { name: 'Today', exact: true });
    await expect(today).toBeVisible();
    await expect(tech.getByRole('link', { name: 'Completed' })).toBeVisible();
    await expect(tech.getByRole('button', { name: '+ Trip' })).toBeVisible();
    await expect(tech.getByRole('link', { name: 'Upcoming' })).toHaveCount(0);

    // The arrows move one day; the label becomes the date, and back again.
    await tech.getByRole('link', { name: 'Next day' }).click();
    await expect(
        tech.getByRole('link', { name: 'Today', exact: true }),
    ).toHaveCount(0);
    await tech.getByRole('link', { name: 'Previous day' }).click();
    await expect(today).toBeVisible();

    const widths = await tech.evaluate(() => ({
        page: document.documentElement.scrollWidth,
        viewport: window.innerWidth,
    }));
    expect(widths.page).toBeLessThanOrEqual(widths.viewport);

    const card = tech.locator('li', { hasText: 'Jane Browser' }).first();
    await expect(card.getByRole('link', { name: /Navigate/ })).toBeVisible();
    await expect(card.getByRole('link', { name: /Call/ })).toBeVisible();
    await expect(card.getByRole('link', { name: /View job/ })).toBeVisible();
    await expect(
        tech.getByRole('button', { name: /Get signature|Checklist/ }),
    ).toHaveCount(0);
    await tech.screenshot({
        path: info.outputPath('my-jobs.png'),
        fullPage: true,
    });
    await card.getByRole('link', { name: /View job/ }).click();
    await expect(tech.getByText('Checklist', { exact: true })).toHaveCount(0);
    await context.close();
});

test('the address of a booked job can be corrected on Edit', async ({
    page,
}) => {
    await page.goto(`/jobs/${fixture.job_id}/edit`);
    const street = page.locator('#ae-line1');
    await expect(street).toHaveValue('123 Browser Street');
    await page.locator('#ae-unit').fill('5');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/jobs/${fixture.job_id}$`));
    await expect(page.getByText(/123 Browser Street/).first()).toBeVisible();
    await page.goto(`/jobs/${fixture.job_id}/edit`);
    await expect(page.locator('#ae-unit')).toHaveValue('5');
    await page.locator('#ae-unit').fill('');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/jobs/${fixture.job_id}$`));
});

test('the jobs list uses the mockup cards with count circles and folded filters', async ({
    page,
}, info) => {
    await page.goto('/jobs');
    await expect(page.getByRole('combobox')).toHaveCount(0);
    const card = page.locator('li', { hasText: 'Jane Browser' }).first();
    await expect(card.getByRole('link', { name: /View job/ })).toBeVisible();
    await page.screenshot({
        path: info.outputPath('jobs-cards.png'),
        fullPage: true,
    });
    const circle = page.getByRole('button', { name: /^Scheduled: \d+$/ });
    await expect(circle).toBeVisible();
    await circle.click();
    await expect(page).toHaveURL(/status=scheduled/);
    await page.getByRole('button', { name: 'Filters', exact: true }).click();
    await expect(page.getByRole('combobox').first()).toBeVisible();
    await page.screenshot({ path: info.outputPath('jobs-list.png') });
});
