import { expect, test } from '@playwright/test';

/**
 * Assistant in the "+" menu (owner's task of November 23): the first item from the button, below Quick booking; a tap
 * opens the assistant with the cursor in the question field.
 */
test.use({ viewport: { width: 390, height: 844 } });

test('"+" → Assistant opens the assistant with the question field focused', async ({
    page,
}) => {
    await page.goto('/today/summary');
    await page.locator('[data-test="create-fab"]').click();
    const assistant = page.locator('[data-test="create-assistant"]');
    await expect(assistant).toBeVisible();
    await expect(assistant).toHaveText('Assistant');

    // Nearest the "+": below every other item, Quick booking included.
    const buttons = page.locator(
        '[data-test="create-menu"] :is(button, label, a)',
    );
    const count = await buttons.count();
    const assistantBox = (await assistant.boundingBox())!;
    for (let i = 0; i < count; i++) {
        const box = (await buttons.nth(i).boundingBox())!;
        if (box.y === assistantBox.y) continue;
        expect(box.y).toBeLessThan(assistantBox.y);
    }

    await assistant.click();
    await expect(page).toHaveURL(/\/assistant\?focus=1$/);
    await expect(
        page.locator('[data-test="assistant-question"]'),
    ).toBeFocused();
    // The hidden keyboard holder is gone.
    await expect(page.locator('#da-keyboard-holder')).toHaveCount(0);
});
