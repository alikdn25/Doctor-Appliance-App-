import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';

const fixture = JSON.parse(
    readFileSync('storage/framework/testing/browser-fixture.json', 'utf8'),
);

/**
 * Owner's task of November 25 on a 390 px phone: the job's buttons sit in one block at the bottom of the Job tab
 * (New estimate | New invoice, Take payment | Close by voice, Hand to technician), all of one size and style with
 * their icons, about 44 px high; the round "+" covers none of them once the page is scrolled to the end.
 */
test.use({ viewport: { width: 390, height: 844 } });

test('job page: one block of buttons of one size at the bottom of the Job tab, not under the "+"', async ({
    page,
}, info) => {
    await page.goto(`/jobs/${fixture.job_id}`);
    const block = page.locator('[data-test="job-buttons"]');
    await expect(block).toBeVisible();
    await expect(block.locator('[data-test="job-new-estimate"]')).toBeVisible();
    await expect(block.locator('[data-test="job-new-invoice"]')).toBeVisible();
    // The old "+ New estimate" / "+ New invoice" links are gone.
    await expect(
        page.getByRole('link', { name: '+ New estimate' }),
    ).toHaveCount(0);

    const buttons = block.locator('[data-slot="button"]');
    const count = await buttons.count();
    expect(count).toBeGreaterThanOrEqual(2);
    const heights = new Set<number>();
    for (let i = 0; i < count; i++) {
        const box = await buttons.nth(i).boundingBox();
        expect(box).not.toBeNull();
        heights.add(Math.round(box!.height));
        // Every button shows its icon.
        await expect(buttons.nth(i).locator('svg').first()).toBeVisible();
    }
    expect(heights.size).toBe(1);
    const [height] = [...heights];
    expect(height).toBeGreaterThanOrEqual(40);
    expect(height).toBeLessThanOrEqual(52);

    // Scrolled to the end: the round "+" is clear of the last button.
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    const fab = page.locator('[data-test="create-fab"]');
    if (await fab.isVisible()) {
        const fabBox = (await fab.boundingBox())!;
        const last = (await buttons.nth(count - 1).boundingBox())!;
        expect(last.y + last.height).toBeLessThanOrEqual(fabBox.y);
    }
    await page.screenshot({
        path: info.outputPath('390-job-buttons.png'),
        fullPage: true,
    });
});
