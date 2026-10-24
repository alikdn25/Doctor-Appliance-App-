import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { readFileSync } from 'node:fs';

/**
 * Owner's update of October 24 (PR 2) on a 380 px phone: the job page's main block, Instructions, the Job / Notes /
 * Files / History tabs, and Solo / Team as the first row of Settings.
 */
test.use({ viewport: { width: 380, height: 800 } });

const fixture = JSON.parse(
    readFileSync('storage/framework/testing/browser-fixture.json', 'utf8'),
);

async function noSideScroll(page: Page) {
    const width = await page.evaluate(() => ({
        page: document.documentElement.scrollWidth,
        viewport: innerWidth,
    }));
    expect(width.page).toBeLessThanOrEqual(width.viewport + 1);
}

async function swipe(page: Page, selector: string, dx: number) {
    await page.locator(selector).evaluate((el, dx) => {
        const box = el.getBoundingClientRect();
        const x = box.left + box.width / 2;
        const y = box.top + 40;
        const touch = (cx: number) =>
            new Touch({ identifier: 1, target: el, clientX: cx, clientY: y });
        el.dispatchEvent(
            new TouchEvent('touchstart', {
                bubbles: true,
                touches: [touch(x)],
                changedTouches: [touch(x)],
            }),
        );
        el.dispatchEvent(
            new TouchEvent('touchend', {
                bubbles: true,
                touches: [],
                changedTouches: [touch(x + dx)],
            }),
        );
    }, dx);
}

test('the job page: main block on top, Instructions, then tabs by tap and swipe', async ({
    page,
}, info) => {
    await page.goto(`/jobs/${fixture.job_id}`);
    const main = page.locator('[data-test="job-main"]');
    await expect(main.locator('[data-test="job-customer"]')).toBeVisible();
    await expect(main.locator('[data-test="job-when"]')).toBeVisible();
    await expect(main.locator('[data-test="job-technician"]')).toBeVisible();

    // Navigate (light blue) · Call (green) · Text (white) · On my way (white), all one size.
    const actions = main.locator('[data-test="job-contact-actions"] > *');
    await expect(actions).toHaveCount(4);
    await expect(actions.nth(0)).toHaveAttribute('data-variant', 'sky');
    await expect(actions.nth(1)).toHaveAttribute('data-variant', 'success');
    await expect(actions.nth(2)).toHaveAttribute('data-variant', 'outline');
    await expect(actions.nth(3)).toHaveAttribute('data-variant', 'outline');
    await expect(actions.nth(3)).toContainText('On my way');
    const boxes = await actions.evaluateAll((els) =>
        els.map((el) => el.getBoundingClientRect().toJSON()),
    );
    for (const box of boxes) {
        expect(Math.abs(box.width - boxes[0].width)).toBeLessThanOrEqual(1);
        expect(Math.abs(box.height - boxes[0].height)).toBeLessThanOrEqual(1);
    }

    // Instructions right under the main block, then the tabs.
    const top = async (selector: string) =>
        (await page.locator(selector).first().boundingBox())!.y;
    const order = [
        await top('[data-test="job-main"]'),
        await top('[data-test="job-instructions"]'),
        await top('#job-tabs'),
    ];
    expect([...order].sort((a, b) => a - b)).toEqual(order);
    await noSideScroll(page);
    await page.screenshot({
        path: info.outputPath('380-job-page.png'),
        fullPage: true,
    });

    const tab = (name: string) =>
        page.getByRole('tab', { name: new RegExp(`^${name}`) });
    await expect(tab('Job')).toHaveAttribute('aria-selected', 'true');
    await expect(page.locator('[data-test="job-tab-job"]')).toBeVisible();
    await swipe(page, '#job-tabs [role="tabpanel"]', -150);
    await expect(tab('Notes')).toHaveAttribute('aria-selected', 'true');
    await swipe(page, '#job-tabs [role="tabpanel"]', -150);
    await expect(tab('Files')).toHaveAttribute('aria-selected', 'true');
    await expect(page.locator('[data-test="job-tab-files"]')).toBeVisible();
    await tab('History').click();
    await expect(page.locator('[data-test="job-activity"]')).toBeVisible();
    await swipe(page, '#job-tabs [role="tabpanel"]', 150);
    await expect(tab('Files')).toHaveAttribute('aria-selected', 'true');
    await noSideScroll(page);
});

test('the office writes Instructions and they stay after a reload', async ({
    page,
}) => {
    await page.goto(`/jobs/${fixture.job_id}`);
    const field = page
        .locator('[data-test="job-instructions"]')
        .locator('textarea');
    const text = `Bring a door gasket ${Date.now()}`;
    await field.fill(text);
    await expect(
        page
            .locator('[data-test="job-instructions"]')
            .locator('[data-test="autosave-state"]'),
    ).toContainText('Saved');
    await page.reload();
    await expect(
        page.locator('[data-test="job-instructions"]').locator('textarea'),
    ).toHaveValue(text);
});

test('Solo / Team is the first row of Settings for the Owner', async ({
    page,
}, info) => {
    await page.goto('/company/settings');
    const row = page.locator('[data-test="work-mode"]');
    await expect(row).toBeVisible();
    const rowTop = (await row.boundingBox())!.y;
    const hubTop = (await page
        .locator('[data-test="settings-hub"]')
        .boundingBox())!.y;
    expect(rowTop).toBeLessThan(hubTop);
    await expect(row.getByRole('radio', { name: 'Team' })).toBeChecked();
    await noSideScroll(page);
    await page.screenshot({ path: info.outputPath('380-settings.png') });
});
