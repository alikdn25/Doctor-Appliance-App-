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
        // Phone: no menu button in the header; More in the bottom bar opens the menu as a sheet (Owner: groups open).
        await expect(
            page.getByRole('button', { name: 'Menu', exact: true }),
        ).toHaveCount(0);
        await page.locator('[data-test="bottom-nav-more"]').click();
        const more = page.locator('[data-test="more-sheet"]');
        await expect(
            more.getByRole('link', { name: 'Customers', exact: true }),
        ).toBeVisible();
        await expect(
            more.getByRole('link', { name: 'Reports', exact: true }),
        ).toBeVisible();
        await page.screenshot({ path: info.outputPath('menu.png') });

        return;
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

test('my jobs: Day | Completed with ‹ date ›, the day summary, + Trip button and cards with Navigate, Call and On my way', async ({
    page,
    browser,
}, info) => {
    const context = await browser.newContext({
        storageState: 'test-results/auth-tech.json',
        viewport: page.viewportSize()!,
    });
    const tech = await context.newPage();
    await tech.goto('http://127.0.0.1:8000/my-jobs');
    await expect(tech.getByRole('radio', { name: 'Day' })).toHaveAttribute(
        'aria-checked',
        'true',
    );
    await expect(
        tech.getByRole('radio', { name: 'Completed', exact: true }),
    ).toBeVisible();
    await expect(tech.locator('[data-test="trip-fab"]')).toBeVisible();
    await expect(tech.locator('[data-test="day-summary"]')).toContainText(
        /\d+ jobs?/,
    );
    const label = tech.locator('[data-test="day-label"]');
    await expect(label).toContainText('Today');

    // The arrows move one day; the label becomes the date, and back again.
    await tech.getByRole('button', { name: 'Next day' }).click();
    await expect(label).not.toContainText('Today');
    await tech.getByRole('button', { name: 'Previous day' }).click();
    await expect(label).toContainText('Today');

    const widths = await tech.evaluate(() => ({
        page: document.documentElement.scrollWidth,
        viewport: window.innerWidth,
    }));
    expect(widths.page).toBeLessThanOrEqual(widths.viewport);

    const card = tech
        .locator('[data-test="job-card"]', { hasText: 'Jane Browser' })
        .first();
    await expect(card.getByRole('link', { name: /Navigate/ })).toBeVisible();
    await expect(card.getByRole('link', { name: /Call/ })).toBeVisible();
    await expect(card.getByRole('button', { name: /On my way/ })).toBeVisible();
    await expect(card.getByRole('link', { name: /View job/ })).toHaveCount(0);
    // The three buttons are the same size.
    const sizes = await card
        .locator('a[data-slot="button"], button[data-slot="button"]')
        .evaluateAll((buttons) =>
            buttons.map((b) => Math.round(b.getBoundingClientRect().width)),
        );
    expect(sizes).toHaveLength(3);
    expect(Math.max(...sizes) - Math.min(...sizes)).toBeLessThanOrEqual(2);
    await expect(
        tech.getByRole('button', { name: /Get signature|Checklist/ }),
    ).toHaveCount(0);
    await tech.screenshot({
        path: info.outputPath('my-jobs.png'),
        fullPage: true,
    });

    await tech.getByRole('radio', { name: 'Completed', exact: true }).click();
    await expect(tech.locator('[data-test="day-summary"]')).toContainText(
        'Last 30 days',
    );

    // Tapping the card opens the job.
    await tech.getByRole('radio', { name: 'Day' }).click();
    await card.getByText('Jane Browser').click();
    await expect(tech).toHaveURL(/\/jobs\/\d+$/);
    await expect(tech.getByText('Checklist', { exact: true })).toHaveCount(0);
    await context.close();
});

test('job page: ⋯ holds the rare actions, Work done saves by itself, Activity is folded', async ({
    page,
}, info) => {
    await page.goto(`/jobs/${fixture.job_id}`);
    await page.locator('[data-test="action-sheet-button"]').click();
    const sheet = page.locator('[data-test="action-sheet"]');
    await expect(
        sheet.getByRole('button', { name: /Close job/ }),
    ).toBeVisible();
    await expect(
        sheet.getByRole('button', { name: 'Change status' }),
    ).toBeVisible();
    await expect(sheet.getByRole('button', { name: 'Edit job' })).toBeVisible();
    await expect(
        sheet.getByRole('button', { name: 'Delete job' }),
    ).toBeVisible();
    await page.screenshot({ path: info.outputPath('job-more.png') });
    await page.keyboard.press('Escape');

    const notes = page.getByRole('textbox', { name: 'Work done' });
    await notes.fill('Drain filter clogged with a sock.');
    await expect(page.locator('[data-test="autosave-state"]')).toHaveText(
        /Saved/,
    );
    await expect(
        page.getByRole('button', { name: 'Save', exact: true }),
    ).toHaveCount(0);
    await page.reload();
    await expect(page.getByRole('textbox', { name: 'Work done' })).toHaveValue(
        'Drain filter clogged with a sock.',
    );
    await page.getByRole('textbox', { name: 'Work done' }).fill('');
    await expect(page.locator('[data-test="autosave-state"]')).toHaveText(
        /Saved/,
    );

    const activity = page.locator('[data-test="job-activity"]');
    await expect(
        activity.getByRole('heading', { name: 'Status history' }),
    ).toBeHidden();
    await activity.getByText('Activity', { exact: true }).click();
    await expect(
        activity.getByRole('heading', { name: 'Status history' }),
    ).toBeVisible();
    await page.screenshot({
        path: info.outputPath('job-page-office.png'),
        fullPage: true,
    });
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
    const card = page
        .locator('[data-test="job-card"]', { hasText: 'Jane Browser' })
        .first();
    await expect(card.getByRole('link', { name: /Navigate/ })).toBeVisible();
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
