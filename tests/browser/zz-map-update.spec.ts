import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * Owner's task of October 24 on a 390 px phone: the Map tab in the bottom bar, the day's jobs with their card
 * (Navigate, Call, Open job; Move for the office), a technician's own jobs only, and the "New version available"
 * banner that waits for an unsaved form.
 */
test.use({ viewport: { width: 390, height: 844 } });

async function noSideScroll(page: Page) {
    const width = await page.evaluate(() => ({
        page: document.documentElement.scrollWidth,
        viewport: innerWidth,
    }));
    expect(width.page).toBeLessThanOrEqual(width.viewport + 1);
}

test('Map is a tab of the bottom bar and shows the day with a card per job', async ({
    page,
}, info) => {
    await page.goto('/my-jobs');
    const nav = page.getByRole('navigation', { name: 'Main sections' });
    await expect(nav.locator('[data-test="bottom-nav-map"]')).toBeVisible();
    await nav.locator('[data-test="bottom-nav-map"]').click();
    await expect(page).toHaveURL(/\/map/);
    await expect(page.locator('[data-test="map-page"]')).toBeVisible();
    await expect(page.locator('[data-test="map-date"]')).toHaveText('Today');
    await expect(page.locator('[data-test="map-actions"]')).toBeVisible();

    // The Calendar keeps its own Map view; More has no Map item.
    await nav.locator('[data-test="bottom-nav-more"]').click();
    const more = page.locator('[data-test="more-sheet"]');
    await expect(more.getByText('Map', { exact: true })).toHaveCount(0);
    await page.keyboard.press('Escape');

    const list = page.locator('[data-test="map-list"] > li');
    await expect(list.first()).toBeVisible();
    await page
        .locator('[data-test="map-list"] button')
        .filter({ hasText: 'Jane Browser' })
        .first()
        .click();
    const card = page.locator('[data-test="map-card"]');
    await expect(card).toBeVisible();
    for (const label of ['Navigate', 'Call', 'Open job']) {
        await expect(card.getByText(label, { exact: true })).toBeVisible();
    }
    await noSideScroll(page);
    await page.screenshot({ path: info.outputPath('390-map-card.png') });
    await card.locator('[data-test="map-open-job"]').click();
    await expect(page).toHaveURL(/\/jobs\/\d+/);
});

test.describe('technician', () => {
    test.use({ storageState: 'test-results/auth-tech.json' });

    test('a technician sees only their own jobs on the map', async ({
        page,
    }, info) => {
        await page.goto('/map');
        await expect(page.locator('[data-test="map-page"]')).toBeVisible();
        // The owner's unassigned job of the morning is not theirs.
        await expect(page.locator('[data-test="map-list"] > li')).toHaveCount(
            1,
        );
        await expect(page.locator('[data-test="map-list"]')).toContainText(
            'Browser Technician',
        );
        await expect(
            page.getByRole('group', { name: 'Technician' }),
        ).toHaveCount(0);
        await noSideScroll(page);
        await page.screenshot({ path: info.outputPath('390-map-tech.png') });
    });
});

test('after a deploy the app offers Update, and waits while a form has changes', async ({
    page,
}, info) => {
    await page.route('**/app-version', (route) =>
        route.fulfill({ json: { version: 'a-newer-version' } }),
    );
    await page.goto('/my-jobs');
    const banner = page.locator('[data-test="app-update"]');
    await expect(banner).toBeVisible();
    await expect(banner).toContainText('New version available');
    await page.screenshot({ path: info.outputPath('390-update-banner.png') });

    // A task being typed holds the banner back until the sheet is closed.
    await page.locator('[data-test="create-fab"]').click();
    await page.locator('[data-test="create-task"]').click();
    await page.locator('#task-title').fill('Call the parts supplier');
    await expect(banner).toBeHidden();
    await page.keyboard.press('Escape');
    await expect(page.locator('[data-test="task-sheet"]')).toBeHidden();
    await expect(banner).toBeVisible();

    // Update loads the new version.
    await page.evaluate(() => {
        (window as unknown as { beforeUpdate: boolean }).beforeUpdate = true;
    });
    await Promise.all([
        page.waitForEvent('load'),
        page.locator('[data-test="app-update-button"]').click(),
    ]);
    expect(
        await page.evaluate(
            () =>
                (window as unknown as { beforeUpdate?: boolean }).beforeUpdate,
        ),
    ).toBeUndefined();
});
