import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * On a 390 px phone: the Today feed of jobs and to-dos with All / Jobs / To Do (owner's task of November 25) and the
 * Parts page with its two tabs. Nothing scrolls sideways.
 */
test.use({ viewport: { width: 390, height: 844 } });

async function noSideScroll(page: Page) {
    const width = await page.evaluate(() => ({
        page: document.documentElement.scrollWidth,
        viewport: innerWidth,
    }));
    expect(width.page).toBeLessThanOrEqual(width.viewport + 1);
}

test('Today: one feed of jobs and to-dos, All / Jobs / To Do with All on every open', async ({
    page,
}, info) => {
    await page.goto('/today');
    const all = page.getByRole('radio', { name: 'All', exact: true });
    const jobs = page.getByRole('radio', { name: 'Jobs', exact: true });
    const todo = page.getByRole('radio', { name: 'To Do', exact: true });
    await expect(all).toHaveAttribute('aria-checked', 'true');
    await expect(page.locator('[data-test="today-feed"]')).toBeVisible();
    // No two columns and no Jobs / To Do toggles in the header any more.
    await expect(page.locator('[data-test="today-panes"]')).toHaveCount(0);

    // To Do: only to-dos, with "+" and the microphone field.
    await todo.click();
    await expect(page.locator('[data-test="job-card"]')).toHaveCount(0);
    await expect(page.locator('[data-test="todo-add"]')).toBeVisible();
    await page.locator('[data-test="todo-add"]').click();
    await expect(page.locator('[data-test="task-sheet"]')).toBeVisible();
    // Three types, Personal by default.
    await expect(page.locator('[data-test="task-kind-home"]')).toHaveAttribute(
        'aria-checked',
        'true',
    );
    await expect(page.locator('[data-test="task-kind-parts"]')).toBeVisible();
    await expect(page.locator('[data-test="task-kind-call"]')).toBeVisible();
    await page.keyboard.press('Escape');

    // Jobs: no to-dos.
    await jobs.click();
    await expect(page.locator('[data-test="todo-card"]')).toHaveCount(0);
    await noSideScroll(page);

    // Back on the screen: All again.
    await page.goto('/today');
    await expect(
        page.getByRole('radio', { name: 'All', exact: true }),
    ).toHaveAttribute('aria-checked', 'true');
    await noSideScroll(page);
    await page.screenshot({ path: info.outputPath('390-today-feed.png') });
});

test('Parts: Order and Waiting tabs with counts, opened from More', async ({
    page,
}, info) => {
    await page.goto('/parts');
    await expect(
        page.getByRole('radio', { name: /^Order · \d+$/ }),
    ).toBeVisible();
    await expect(
        page.getByRole('radio', { name: /^Waiting · \d+$/ }),
    ).toBeVisible();
    await noSideScroll(page);
    await page.screenshot({ path: info.outputPath('390-parts.png') });

    await page.getByRole('radio', { name: /^Waiting/ }).click();
    await expect(page).toHaveURL(/\/parts\?tab=waiting/);

    await page.locator('[data-test="parts-add"]').click();
    await expect(page.locator('[data-test="add-part-sheet"]')).toBeVisible();
});
