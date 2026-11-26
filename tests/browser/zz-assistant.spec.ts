import { expect, test } from '@playwright/test';

/**
 * The AI assistant (owner's task of November 15, PR 2) on a 390 px phone: More → Assistant opens a chat like the SMS
 * screen with the composer at the bottom. CI has no ANTHROPIC_API_KEY, so it says the AI is not set up and an answer
 * fails with a message, never silently.
 */
test.use({ viewport: { width: 390, height: 844 } });

test('More has Assistant; the chat says when the AI is not set up', async ({
    page,
}) => {
    await page.goto('/today');
    await page.locator('[data-test="bottom-nav-more"]').click();
    const more = page.locator('[data-test="more-sheet"]');
    await more.getByRole('link', { name: 'Assistant', exact: true }).click();
    await expect(page).toHaveURL(/\/assistant$/);

    await expect(page.locator('[data-test="assistant-error"]')).toBeVisible();
    const question = page.locator('[data-test="assistant-question"]');
    await expect(question).toBeVisible();
    await question.fill('Which invoices are overdue?');
    await page.locator('[data-test="assistant-send"]').click();
    // The question stays in the chat and the error is said.
    await expect(
        page
            .locator('[data-test="assistant-thread"] [data-from="user"]')
            .last(),
    ).toHaveText('Which invoices are overdue?');
    await expect(page.locator('[data-test="assistant-error"]')).toBeVisible();

    // Find problems runs only on this button; without an AI key it says so.
    await page.locator('[data-test="assistant-find-problems"]').click();
    await expect(
        page
            .locator(
                '[data-test="assistant-error"], [data-test="assistant-problems"]',
            )
            .first(),
    ).toBeVisible();

    const width = await page.evaluate(() => ({
        page: document.documentElement.scrollWidth,
        viewport: innerWidth,
    }));
    expect(width.page).toBeLessThanOrEqual(width.viewport + 1);
});
