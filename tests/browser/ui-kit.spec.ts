import { expect, test } from '@playwright/test';

// UX redesign phase 2: the UI kit page (local only) shows every component; screenshots for review.
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

test('the UI kit shows the four button levels, one status dictionary and every component', async ({
    page,
}, info) => {
    await page.goto('/dev/ui');
    await expect(page.getByRole('heading', { name: 'UI kit' })).toBeVisible();

    // Only Primary glows: its shadow is blue; Secondary has none of that blue.
    const primary = page.locator('[data-variant="default"]').first();
    const secondary = page.locator('[data-variant="outline"]').first();
    expect(
        await primary.evaluate((el) => getComputedStyle(el).boxShadow),
    ).toContain('rgba(5, 103, 245, 0.6)');
    expect(
        await secondary.evaluate((el) => getComputedStyle(el).boxShadow),
    ).not.toContain('rgba(5, 103, 245');

    // Buttons are 48 px high on a phone and 40 on a desktop.
    const height = await primary.evaluate(
        (el) => el.getBoundingClientRect().height,
    );
    expect(height).toBe(info.project.name === 'mobile' ? 48 : 40);

    // Every job status has its own colour, icon and text.
    const badges = page.locator('[data-status]');
    expect(await badges.count()).toBeGreaterThan(20);
    const newColour = await page
        .locator('[data-status="new"]')
        .first()
        .evaluate((el) => getComputedStyle(el).color);
    const paidColour = await page
        .locator('[data-status="paid"]')
        .first()
        .evaluate((el) => getComputedStyle(el).color);
    expect(newColour).not.toBe(paidColour);
    await expect(
        page.locator('[data-test="overdue-flag"]').first(),
    ).toBeVisible();

    await page.screenshot({
        path: info.outputPath('ui-kit.png'),
        fullPage: true,
    });

    // Confirmation: the only place with a filled red button.
    await page.getByRole('button', { name: 'Open confirmation' }).click();
    await expect(page.getByRole('dialog')).toContainText('Delete job #1042?');
    await page.locator('[data-test="confirm-dialog-confirm"]').click();
    await expect(page.getByRole('dialog')).toBeHidden();

    // Toast with Undo.
    await page.getByRole('button', { name: 'Show toast with Undo' }).click();
    await expect(
        page.locator('[data-sonner-toast] button', { hasText: 'Undo' }),
    ).toBeVisible();

    // Bottom sheet with rounded top.
    await page.getByRole('button', { name: 'Open sheet' }).click();
    await expect(page.getByRole('dialog')).toContainText('Change status');
    await page.screenshot({ path: info.outputPath('ui-kit-sheet.png') });
});
