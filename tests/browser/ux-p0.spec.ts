import { expect, test } from '@playwright/test';
import type { Page, TestInfo } from '@playwright/test';
import { readFileSync } from 'node:fs';

// UX redesign phase 1 (P0): no service text, fitting rows, honest hints. Screenshots for review.
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

/** No text of the screen mentions environment variables, docs, localhost or roadmap promises. */
async function expectNoServiceText(page: Page) {
    const text = await page.locator('body').innerText();
    expect(text).not.toMatch(
        /GOOGLE_MAPS|DEPLOYMENT\.md|127\.0\.0\.1|localhost|comes later|comes with the price book/,
    );
}

test('login offers password recovery under the password field', async ({
    browser,
}, info) => {
    const context = await browser.newContext({
        storageState: { cookies: [], origins: [] },
    });
    const page = await context.newPage();
    await page.goto('/login');
    await expect(
        page.getByRole('link', { name: 'Forgot your password?' }),
    ).toBeVisible();
    await screenshot(page, info, 'login');
    await context.close();
});

test('map without a key says so in plain words', async ({ page }, info) => {
    await page.goto(`/calendar?view=map&date=${fixture.today}`);
    await expect(
        page.getByText(
            'Map is unavailable right now. Jobs and directions are listed below.',
        ),
    ).toBeVisible();
    await expectNoServiceText(page);
    await screenshot(page, info, 'calendar-map');
});

test('invoice copies the customer link instead of printing it', async ({
    page,
}, info) => {
    await page.goto(`/invoices/${fixture.invoice_id}`);
    await expectNoServiceText(page);
    await screenshot(page, info, 'invoice');
    // Send ▾ holds Email, SMS and Copy link (UX-REDESIGN §10.2).
    await page.locator('[data-test="send-menu"]').click();
    await expect(
        page.getByRole('button', { name: 'Copy customer link' }),
    ).toBeVisible();
});

test('team members are one row each with actions under ⋯', async ({
    page,
}, info) => {
    await page.goto('/company/team');
    await page
        .getByRole('button', { name: /^Actions for Browser Technician/ })
        .click();
    await expect(
        page.getByRole('menuitem', { name: 'Transfer jobs' }),
    ).toBeVisible();
    await expect(page.getByRole('menuitem', { name: 'Remove' })).toBeVisible();
    await page.keyboard.press('Escape');
    await screenshot(page, info, 'team');
});

test('cash rows keep the name, amount and action apart', async ({
    page,
}, info) => {
    await page.goto('/cash');
    const action = page
        .getByRole('button', { name: 'Record cash deposit' })
        .first();
    await expect(action).toBeVisible();
    // The name never runs under the amount or the button.
    const row = action.locator('xpath=ancestor::li[1]');
    // Name above the amount (UX-REDESIGN §11.3), both left of the button.
    const name = await row.getByRole('link').boundingBox();
    const amount = await row.locator('span.tabular-nums').boundingBox();
    const button = await action.boundingBox();
    expect(name!.y + name!.height).toBeLessThanOrEqual(amount!.y + 1);
    expect(name!.x + name!.width).toBeLessThanOrEqual(button!.x + 1);
    expect(amount!.x + amount!.width).toBeLessThanOrEqual(button!.x + 1);
    await screenshot(page, info, 'cash');
});

test('company settings speak plainly about payments and cash', async ({
    page,
}, info) => {
    await page.goto('/company/settings/payments');
    await expect(
        page.getByText('Technicians can record cash payments.'),
    ).toBeVisible();
    await expect(page.getByText(/Stripe is coming next/)).toHaveCount(0);
    await expectNoServiceText(page);
    await screenshot(page, info, 'company-settings');
});

test('new invoice shows a grey hint, not an error, before Save', async ({
    page,
}, info) => {
    await page.goto(`/jobs/${fixture.job_id}/invoices/create`);
    await expect(page.getByRole('alert')).toHaveCount(0);
    await screenshot(page, info, 'new-invoice');
});

test('the unfinished-jobs bar is on Jobs too', async ({ page }, info) => {
    await page.goto('/jobs');
    await expect(
        page.getByRole('navigation', { name: 'Not completed jobs' }),
    ).toBeVisible();
    await screenshot(page, info, 'jobs');
});
