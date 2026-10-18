import { expect, test } from '@playwright/test';
import type { Page, TestInfo } from '@playwright/test';
import { readFileSync } from 'node:fs';

// UX redesign phase 9: invoices list, invoice page, SMS inbox chat.
const fixture = JSON.parse(
    readFileSync('storage/framework/testing/browser-fixture.json', 'utf8'),
);
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

async function screenshot(page: Page, info: TestInfo, name: string) {
    await page.screenshot({
        path: info.outputPath(`${name}.png`),
        fullPage: true,
    });
}

test('invoices: totals on top, filter chips, rows with where each invoice stands', async ({
    page,
}, info) => {
    await page.goto('/invoices');
    await expect(
        page.locator('[data-test="metric-outstanding"]'),
    ).toBeVisible();
    await expect(page.locator('[data-test="metric-overdue"]')).toBeVisible();
    await screenshot(page, info, 'invoices');

    await page.getByRole('button', { name: 'All', exact: true }).click();
    await expect(page).toHaveURL(/status=all/);
    await expect(
        page.locator(
            info.project.name === 'mobile'
                ? '[data-test="invoice-list"]'
                : '[data-slot="data-table"]',
        ),
    ).toBeVisible();
});

test('invoice page: status in large type, Take payment, Send ▾ and the rare actions under ⋯', async ({
    page,
}, info) => {
    await page.goto(`/invoices/${fixture.invoice_id}`);
    await expect(page.locator('[data-test="document-headline"]')).toBeVisible();
    await screenshot(page, info, 'invoice-page');

    // The rare and dangerous actions are not on the page itself.
    await expect(
        page.getByRole('button', { name: 'Void invoice' }),
    ).toHaveCount(0);
    await page.locator('[data-test="action-sheet-button"]').click();
    await expect(
        page
            .locator('[data-test="action-sheet"]')
            .getByRole('button', { name: 'Edit invoice' }),
    ).toBeVisible();
    await page.keyboard.press('Escape');

    await page.locator('[data-test="send-menu"]').click();
    await expect(
        page.getByRole('button', { name: 'Send by email' }),
    ).toBeVisible();
    await page.keyboard.press('Escape');
});

test("Send by SMS asks first and sends nothing on Don't send", async ({
    page,
}, info) => {
    const sentCount = async () =>
        (await (await page.request.get('http://127.0.0.1:9001/sent')).json())
            .length as number;
    const before = await sentCount();

    await page.goto(`/invoices/${fixture.invoice_id}`);
    await page.locator('[data-test="send-menu"]').click();
    await page.getByRole('button', { name: 'Send by SMS' }).click();
    const confirm = page.locator('[data-test="sms-confirm"]');
    await expect(confirm).toBeVisible();
    await expect(
        confirm.getByRole('button', { name: 'Send', exact: true }),
    ).toBeVisible();
    await screenshot(page, info, 'sms-confirm');

    await confirm.getByRole('button', { name: "Don't send" }).click();
    await expect(confirm).not.toBeVisible();
    expect(await sentCount()).toBe(before);
});

test('inbox: the list, then a conversation as a chat with templates', async ({
    page,
}, info) => {
    await page.goto('/messages');
    await expect(page.locator('[data-test="thread-list"]')).toBeVisible();
    await screenshot(page, info, 'inbox-list');

    await page.locator('[data-test="thread-list"] a').first().click();
    await expect(page.locator('[data-test="chat-thread"]')).toBeVisible();
    await expect(page.locator('#sms-reply')).toBeVisible();
    await screenshot(page, info, 'inbox-chat');
});
