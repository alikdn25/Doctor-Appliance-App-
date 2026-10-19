import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { readFileSync } from 'node:fs';

/**
 * Owner's fixes of October 19 on a 380 px phone: the job page as one list, the technician in one tap with the
 * day's load, Transfer in the calendar and on the Team card, compact estimate lines. Every change made to the
 * fixture job is undone, so later scenarios see it as before.
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

test('every main screen fits a 380 px phone', async ({ page }, info) => {
    for (const url of [
        '/jobs',
        '/my-jobs',
        '/calendar',
        '/customers',
        `/customers/${fixture.customer_id}`,
        `/customers/${fixture.customer_id}/edit`,
        `/jobs/${fixture.job_id}`,
        '/jobs/create?book=1',
        '/invoices',
        `/invoices/${fixture.invoice_id}`,
        `/jobs/${fixture.job_id}/estimates/create`,
        `/jobs/${fixture.job_id}/invoices/create`,
        '/company/team',
        '/company/settings/jobs',
        '/messages',
    ]) {
        await page.goto(url);
        await expect(page.locator('h1').first()).toBeVisible();
        await noSideScroll(page);
        await page.screenshot({
            path: info.outputPath(
                `380-${url.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '')}.png`,
            ),
            fullPage: true,
        });
    }
});

test('the job page is one list with Navigate, Call and Text, and the technician in one tap', async ({
    page,
}) => {
    await page.goto(`/jobs/${fixture.job_id}`);
    await expect(page.locator('[data-test="job-anchors"]')).toHaveCount(0);

    // Customer → technician → appliances → photos → Work done → estimates and invoices → Activity.
    const top = async (selector: string) =>
        (await page.locator(selector).first().boundingBox())!.y;
    const order = [
        await top('[data-test="job-customer"]'),
        await top('[data-test="job-technician"]'),
        await top('h2:text-is("Appliances")'),
        await top('h2:text-is("Photos")'),
        await top('h2:text-is("Work done")'),
        await top('#documents'),
        await top('[data-test="job-activity"]'),
    ];
    expect([...order].sort((a, b) => a - b)).toEqual(order);

    const actions = page.locator('[data-test="job-contact-actions"] > *');
    await expect(actions).toHaveCount(3);
    await expect(actions.nth(0)).toHaveAttribute('data-variant', 'sky');
    await expect(actions.nth(1)).toHaveAttribute('data-variant', 'success');
    await expect(actions.nth(2)).toHaveAttribute('data-variant', 'outline');
    const boxes = await actions.evaluateAll((els) =>
        els.map((el) => el.getBoundingClientRect().toJSON()),
    );
    expect(new Set(boxes.map((b) => Math.round(b.width))).size).toBe(1);
    expect(new Set(boxes.map((b) => Math.round(b.top))).size).toBe(1);

    // Technician: a tap lists everyone with their jobs that day and free / busy for this window.
    const row = page.locator('[data-test="job-technician"]');
    await expect(row).toContainText('Browser Technician');
    await row.getByRole('button').click();
    const picker = page.locator('[data-test="technician-picker"]');
    await expect(picker.getByText('Browser Owner')).toBeVisible();
    await expect(picker).toContainText(/1 job/);
    await expect(picker).toContainText(/free/);
    await noSideScroll(page);
    await picker.getByRole('button', { name: /Browser Owner/ }).click();
    await expect(row).toContainText('Browser Owner');

    // The name in the header opens the same choice.
    await page.locator('[data-test="header-technician"]').click();
    await page
        .locator('[data-test="technician-picker"]')
        .getByRole('button', { name: /Browser Technician/ })
        .click();
    await expect(row).toContainText('Browser Technician');
});

test('Transfer in the calendar moves the ticked jobs to another person', async ({
    page,
}) => {
    await page.goto(`/calendar?view=day&date=${fixture.today}`);
    const move = async (from: string, to: string) => {
        await page
            .locator('[data-drop-lane]')
            .locator('..')
            .filter({ hasText: from })
            .locator('[data-test="lane-transfer"]')
            .click();
        await page.locator('[data-test="transfer-tick"]').first().click();
        await expect(page.locator('[data-test="transfer-status"]')).toHaveText(
            '1 selected',
        );
        await page.locator('[data-test="transfer-move"]').click();
        const picker = page.locator('[data-test="technician-picker"]');
        await expect(picker.getByText(from, { exact: true })).toHaveCount(0);
        await picker.getByRole('button', { name: new RegExp(to) }).click();
        await expect(page.locator('[data-sonner-toast]').last()).toContainText(
            '1 job moved',
        );
        await page.getByRole('button', { name: 'Done', exact: true }).click();
    };

    await move('Browser Technician', 'Browser Owner');
    await move('Browser Owner', 'Browser Technician');
    await noSideScroll(page);
});

test('Transfer on the Team card gives ticked jobs to another person', async ({
    page,
}) => {
    await page.goto('/company/team');
    const card = (name: string) =>
        page.getByRole('listitem').filter({ hasText: name });
    const sheet = page.locator('[data-test="transfer-sheet"]');

    await card('Browser Technician')
        .locator('[data-test="member-transfer"]')
        .click();
    await sheet.getByRole('checkbox').first().click();
    await sheet.locator('[data-test="transfer-move"]').click();
    await page
        .locator('[data-test="technician-picker"]')
        .getByRole('button', { name: /Browser Owner/ })
        .click();
    await expect(sheet.getByRole('checkbox')).toHaveCount(0);
    await noSideScroll(page);
    await sheet.getByRole('button', { name: 'Done', exact: true }).click();

    // And back.
    await card('Browser Owner')
        .locator('[data-test="member-transfer"]')
        .click();
    await sheet.getByRole('checkbox').first().click();
    await sheet.locator('[data-test="transfer-move"]').click();
    await page
        .locator('[data-test="technician-picker"]')
        .getByRole('button', { name: /Browser Technician/ })
        .click();
    await expect(sheet.getByRole('checkbox')).toHaveCount(0);
});

test('an estimate: Labour · Part · Material on top, priced items added as one row, no Good / Better / Best', async ({
    page,
    context,
}, info) => {
    const name = `Door gasket ${info.project.name}`;
    const xsrf = (await context.cookies()).find((c) => c.name === 'XSRF-TOKEN');
    await page.goto(`/jobs/${fixture.job_id}/estimates/create`);
    const response = await page.request.post('/pricebook', {
        headers: {
            Accept: 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(xsrf?.value ?? ''),
        },
        data: { kind: 'part', name, unit_price: '45.00', taxable: true },
    });
    expect(response.status()).toBe(201);
    await page.reload();

    await page.getByRole('button', { name: 'Add item' }).click();
    const sheet = page.getByRole('dialog');
    const custom = await sheet
        .locator('[data-test="add-custom"]')
        .boundingBox();
    const search = await sheet.getByRole('searchbox').boundingBox();
    expect(custom!.y).toBeLessThan(search!.y);
    await expect(
        sheet.getByRole('button', { name: 'Labour', exact: true }),
    ).toBeVisible();
    await sheet.getByRole('searchbox').fill(name);
    await sheet.getByRole('button', { name: new RegExp(name) }).click();

    // A price book item with a price is a finished row: no form opens.
    await expect(page.locator('[data-test="line-sheet"]')).toHaveCount(0);
    const line = page
        .locator('[data-test="line-row"]')
        .filter({ hasText: name });
    await expect(line).toContainText('1 × $45.00');
    expect((await line.boundingBox())!.height).toBeLessThan(60);

    // Own item: the small sheet; taxes and warranty only under More.
    await page.getByRole('button', { name: 'Add item' }).click();
    await page
        .getByRole('dialog')
        .getByRole('button', { name: 'Labour', exact: true })
        .click();
    const editor = page.locator('[data-test="line-sheet"]');
    await editor.getByLabel('Description', { exact: true }).fill('Labour');
    await editor.getByLabel('Price', { exact: true }).fill('90');
    await expect(editor.getByRole('checkbox', { name: /GST/ })).toHaveCount(0);
    await noSideScroll(page);
    await editor.getByRole('button', { name: 'Done' }).click();

    await expect(page.getByText(/Good|Better|Best|All options/)).toHaveCount(0);
    await expect(page.locator('[data-test="document-warranty"]')).toBeVisible();
    await page.getByRole('button', { name: /Save · / }).click();
    await expect(page).toHaveURL(/\/estimates\/\d+$/);
    await expect(page.getByText(name)).toBeVisible();
});
