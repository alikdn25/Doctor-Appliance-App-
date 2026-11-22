import { expect, test } from '@playwright/test';

/**
 * Owner's task of November 21 on a 390 px phone, in the browser (no Android app): Today without the booking buttons,
 * "+" → New charge, the amount with tax and the total from the server, Take payment without Charge reader (Cash and
 * E-transfer only), Cash → Paid with Send receipt (email only, Send waits for an email) and Request review; nothing is sent before Send.
 */
test.use({ viewport: { width: 390, height: 844 } });

test('New charge in the browser: Cash → Paid with Send receipt', async ({
    page,
}, info) => {
    // The charge makes a Completed job for today: run it once, last (phone project), so it does not change what the
    // other screens show.
    test.skip(info.project.name !== 'mobile', 'Runs once, on the phone');
    await page.goto('/my-jobs');
    await expect(page.locator('[data-test="start-quick-booking"]')).toHaveCount(
        0,
    );

    await page.locator('[data-test="create-fab"]').click();
    await page.locator('[data-test="create-charge"]').click();
    await expect(page).toHaveURL(/\/charges\/new/);

    await page.locator('[data-test="charge-what"]').fill('Dryer repair');
    await page.locator('[data-test="charge-amount"]').fill('112');
    await expect(page.locator('[data-test="charge-total"]')).toContainText(
        '112',
    );
    const width = await page.evaluate(() => ({
        page: document.documentElement.scrollWidth,
        viewport: innerWidth,
    }));
    expect(width.page).toBeLessThanOrEqual(width.viewport + 1);
    await page.screenshot({ path: info.outputPath('390-new-charge.png') });

    await page.locator('[data-test="charge-submit"]').click();
    await expect(page.locator('[data-test="take-payment"]')).toBeVisible();
    // A browser has no card reader: Charge reader is hidden.
    await expect(page.locator('[data-test="charge-reader"]')).toHaveCount(0);
    await expect(page.locator('[data-test="charge-transfer"]')).toBeVisible();
    await page.screenshot({ path: info.outputPath('390-take-payment.png') });

    await page.locator('[data-test="charge-cash"]').click();
    await page.getByRole('button', { name: 'Cash' }).last().click();

    await expect(page).toHaveURL(/\/payments\/\d+\/paid/);
    await expect(page.locator('[data-test="paid-amount"]')).toContainText(
        '112',
    );
    await expect(page.locator('[data-test="send-receipt"]')).toBeVisible();
    await expect(page.locator('[data-test="receipt-review"]')).toBeVisible();
    // Walk-in: the customer's details are asked right here.
    await expect(page.locator('[data-test="customer-phone"]')).toBeVisible();
    // The receipt goes by email only: Send waits for an email.
    await expect(page.locator('[data-test="receipt-sms"]')).toHaveCount(0);
    const send = page.locator('[data-test="receipt-send"]');
    await expect(send).toBeDisabled();
    await page
        .locator('[data-test="receipt-email"]')
        .fill('walkin@example.com');
    await expect(send).toBeEnabled();
    await page.screenshot({
        path: info.outputPath('390-paid.png'),
        fullPage: true,
    });
});
