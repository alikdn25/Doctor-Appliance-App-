import { expect, test } from '@playwright/test';

// UX redesign phase 3: one frame, bottom bar by role and chosen menu, ← on child screens, More as a sheet.
let pageErrors: string[];

test.beforeEach(async ({ page }) => {
    pageErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));
});
test.afterEach(async ({ page }) => {
    const width = await page.evaluate(() => ({
        page: document.documentElement.scrollWidth,
        viewport: window.innerWidth,
    }));
    expect(width.page, 'Screen must fit the viewport').toBeLessThanOrEqual(
        width.viewport + 1,
    );
    expect(pageErrors, 'No uncaught JavaScript errors').toEqual([]);
});

test('the owner gets Today, Calendar, Book, Jobs and More; Field swaps Jobs for Invoices', async ({
    page,
}, info) => {
    test.skip(info.project.name !== 'mobile', 'The bottom bar is for phones');
    await page.goto('/my-jobs');

    const nav = page.getByRole('navigation', { name: 'Main sections' });
    await expect(nav.locator('[data-test="bottom-nav-today"]')).toHaveAttribute(
        'aria-current',
        'page',
    );
    // Start from the default Office menu (an earlier run may have left Field on).
    if ((await nav.locator('[data-test="bottom-nav-jobs"]').count()) === 0) {
        await nav.locator('[data-test="bottom-nav-more"]').click();
        await page
            .locator('[data-test="more-sheet"]')
            .getByRole('radio', { name: 'Office' })
            .click();
        await page.keyboard.press('Escape');
    }
    for (const key of ['today', 'calendar', 'book', 'jobs', 'more']) {
        await expect(
            nav.locator(`[data-test="bottom-nav-${key}"]`),
        ).toBeVisible();
    }
    // Root screen: no back arrow, no menu button.
    await expect(page.locator('[data-test="back-button"]:visible')).toHaveCount(
        0,
    );
    await expect(
        page.getByRole('button', { name: 'Menu', exact: true }),
    ).toHaveCount(0);
    await page.screenshot({ path: info.outputPath('today.png') });

    // More: My menu first, search, groups open for the Owner, then the person.
    await nav.locator('[data-test="bottom-nav-more"]').click();
    const more = page.locator('[data-test="more-sheet"]');
    await expect(more.locator('[data-test="menu-mode"]')).toBeVisible();
    await expect(
        more.getByRole('link', { name: 'Customers', exact: true }),
    ).toBeVisible();
    // Items already in the bottom bar are not repeated.
    await expect(
        more.getByRole('link', { name: 'Jobs', exact: true }),
    ).toHaveCount(0);
    await page.screenshot({ path: info.outputPath('more.png') });

    await more.getByRole('searchbox').fill('rep');
    await expect(
        more.getByRole('link', { name: 'Reports', exact: true }),
    ).toBeVisible();
    await expect(
        more.getByRole('link', { name: 'Customers', exact: true }),
    ).toHaveCount(0);
    await more.getByRole('searchbox').fill('');

    // The sheet hides the rest of the screen from assistive tech: close it before checking the bar.
    await more.getByRole('radio', { name: 'Field' }).click();
    await expect(more.getByRole('radio', { name: 'Field' })).toBeChecked();
    // Field changes the menu itself: mileage in, the office screens (Reports, Team) out.
    await expect(
        more.getByRole('link', { name: 'Mileage', exact: true }),
    ).toBeVisible();
    await expect(
        more.getByRole('link', { name: 'Reports', exact: true }),
    ).toHaveCount(0);
    await expect(
        more.getByRole('link', { name: 'Team', exact: true }),
    ).toHaveCount(0);
    await page.keyboard.press('Escape');
    await expect(
        nav.locator('[data-test="bottom-nav-invoices"]'),
    ).toBeVisible();
    await expect(nav.locator('[data-test="bottom-nav-jobs"]')).toHaveCount(0);
    // Back to Office for the other scenarios.
    await nav.locator('[data-test="bottom-nav-more"]').click();
    await more.getByRole('radio', { name: 'Office' }).click();
    await expect(more.getByRole('radio', { name: 'Office' })).toBeChecked();
    await page.keyboard.press('Escape');
    await expect(nav.locator('[data-test="bottom-nav-jobs"]')).toBeVisible();
});

test('child screens have ← that goes back; the unfinished-jobs strip shows counters', async ({
    page,
}, info) => {
    await page.goto('/my-jobs');
    await expect(page.locator('[data-test="unfinished-total"]')).toBeVisible();

    await page.goto('/customers');
    const back = page.locator('[data-test="back-button"]:visible').first();
    await expect(back).toBeVisible();
    await back.click();
    await expect(page).toHaveURL(/\/my-jobs/);
    await page.screenshot({ path: info.outputPath('back.png') });
});

test('profile and security are in the same frame with ← and the bottom bar', async ({
    page,
}, info) => {
    test.skip(
        info.project.name !== 'mobile',
        'A desktop shows the sidebar and breadcrumbs instead',
    );
    await page.goto('/settings/profile');
    await expect(
        page.locator('[data-test="back-button"]:visible').first(),
    ).toBeVisible();
    await expect(
        page.getByRole('navigation', { name: 'Main sections' }),
    ).toBeVisible();
    await page.screenshot({ path: info.outputPath('profile.png') });
});

test.describe('technician', () => {
    test.use({ storageState: 'test-results/auth-tech.json' });

    test('a technician gets Today, Mileage, My expenses and More, without the menu choice', async ({
        page,
    }, info) => {
        test.skip(
            info.project.name !== 'mobile',
            'The bottom bar is for phones',
        );
        await page.goto('/my-jobs');
        const nav = page.getByRole('navigation', { name: 'Main sections' });
        for (const key of ['today', 'mileage', 'my-expenses', 'more']) {
            await expect(
                nav.locator(`[data-test="bottom-nav-${key}"]`),
            ).toBeVisible();
        }
        await expect(nav.locator('[data-test="bottom-nav-book"]')).toHaveCount(
            0,
        );

        await nav.locator('[data-test="bottom-nav-more"]').click();
        const more = page.locator('[data-test="more-sheet"]');
        await expect(more.locator('[data-test="menu-mode"]')).toHaveCount(0);
        await expect(more.locator('[data-test="more-logout"]')).toBeVisible();
        await page.screenshot({ path: info.outputPath('tech-more.png') });
    });
});
