import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * Owner's task of November 19, part 4, on a 390 px phone: the Jobs / To Do buttons of Today (one always on, two
 * columns with both) and the Parts page with its two tabs. Nothing scrolls sideways.
 */
test.use({ viewport: { width: 390, height: 844 } });

async function noSideScroll(page: Page) {
    const width = await page.evaluate(() => ({
        page: document.documentElement.scrollWidth,
        viewport: innerWidth,
    }));
    expect(width.page).toBeLessThanOrEqual(width.viewport + 1);
}

test('Today: Jobs and To Do buttons, never both off, two columns with both', async ({
    page,
}, info) => {
    await page.goto('/my-jobs');
    const jobs = page.locator('[data-test="pane-jobs"]');
    const todo = page.locator('[data-test="pane-todo"]');
    await expect(jobs).toBeVisible();

    // Start from Jobs only.
    if ((await todo.getAttribute('aria-pressed')) === 'true') {
        await todo.click();
    }
    if ((await jobs.getAttribute('aria-pressed')) !== 'true') {
        await jobs.click();
    }
    await expect(jobs).toHaveAttribute('aria-pressed', 'true');
    await expect(todo).toHaveAttribute('aria-pressed', 'false');
    await expect(page.locator('[data-test="todo-pane"]')).toHaveCount(0);

    // The last one on stays on.
    await jobs.click();
    await expect(jobs).toHaveAttribute('aria-pressed', 'true');

    // Both: two columns, the To Do column with "+" and the microphone field.
    await todo.click();
    await expect(page.locator('[data-test="todo-pane"]')).toBeVisible();
    await expect(page.locator('[data-test="jobs-pane"]')).toBeVisible();
    await expect(page.locator('[data-test="todo-add"]')).toBeVisible();
    await noSideScroll(page);
    await page.screenshot({ path: info.outputPath('390-today-both.png') });

    // To Do only.
    await jobs.click();
    await expect(page.locator('[data-test="jobs-pane"]')).toHaveCount(0);
    await page.locator('[data-test="todo-add"]').click();
    await expect(page.locator('[data-test="task-sheet"]')).toBeVisible();
    await expect(page.locator('#task-kind')).toBeVisible();
    await page.keyboard.press('Escape');

    // Back to Jobs only for the other tests.
    await jobs.click();
    await todo.click();
    await expect(todo).toHaveAttribute('aria-pressed', 'false');
    await expect(jobs).toHaveAttribute('aria-pressed', 'true');
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
