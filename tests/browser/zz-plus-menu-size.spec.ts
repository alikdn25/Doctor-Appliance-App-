import { expect, test } from '@playwright/test';

/**
 * The "+" menu on a 360 px phone (owner's task of November 23): icons and tap areas 30% larger than the 48 px
 * standard (rows 62 px, icons 26 px); no label is cut, wraps out of its button or overlaps another.
 */
test.use({ viewport: { width: 360, height: 740 } });

test('the "+" menu has larger icons and tap areas; labels fit at 360 px', async ({
    page,
}) => {
    await page.goto('/today/summary');
    await page.locator('[data-test="create-fab"]').click();
    const menu = page.locator('[data-test="create-menu"]');
    await expect(menu).toBeVisible();

    const targets = menu.locator('button, a');
    const count = await targets.count();
    expect(count).toBeGreaterThan(1);

    const boxes = [];
    for (let i = 0; i < count; i++) {
        const target = targets.nth(i);
        const box = (await target.boundingBox())!;
        expect(box.height).toBeGreaterThanOrEqual(62);
        expect(box.x).toBeGreaterThanOrEqual(0);
        expect(box.x + box.width).toBeLessThanOrEqual(360);

        const icon = (await target.locator('svg').first().boundingBox())!;
        expect(Math.round(icon.width)).toBeGreaterThanOrEqual(26);

        // The text is not cut: nothing scrolls inside the button.
        const clipped = await target.evaluate(
            (el) =>
                el.scrollWidth > el.clientWidth + 1 ||
                el.scrollHeight > el.clientHeight + 1,
        );
        expect(clipped).toBe(false);
        boxes.push(box);
    }

    // No two items overlap.
    for (let a = 0; a < boxes.length; a++) {
        for (let b = a + 1; b < boxes.length; b++) {
            const [p, q] = [boxes[a], boxes[b]];
            const overlap =
                p.x < q.x + q.width - 1 &&
                q.x < p.x + p.width - 1 &&
                p.y < q.y + q.height - 1 &&
                q.y < p.y + p.height - 1;
            expect(overlap).toBe(false);
        }
    }

    await page.screenshot({ path: 'test-results/plus-menu-360.png' });
});
