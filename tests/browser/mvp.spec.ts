import { expect, test } from '@playwright/test';
import type { Page, TestInfo } from '@playwright/test';
import { readFileSync } from 'node:fs';

const fixture = JSON.parse(
    readFileSync('storage/framework/testing/browser-fixture.json', 'utf8'),
);
const notes = 'Please text instead of calling. Baby naps after 1pm.';
const png = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
    'base64',
);
let pageErrors: string[];

test.beforeEach(async ({ page }) => {
    pageErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));
});
test.afterEach(async ({ page }, info) => {
    if (info.status === 'passed') {
        const width = await page.evaluate(() => ({
            page: document.documentElement.scrollWidth,
            viewport: window.innerWidth,
        }));
        expect(width.page, 'Screen must fit the viewport').toBeLessThanOrEqual(
            width.viewport + 1,
        );
        expect(pageErrors, 'No uncaught JavaScript errors').toEqual([]);
    }
});

async function screenshot(page: Page, info: TestInfo, name: string) {
    await page.screenshot({
        path: info.outputPath(`${name}.png`),
        fullPage: true,
    });
}

test('customer context and a manual icon persist and follow a newly booked job', async ({
    page,
}, info) => {
    await page.goto(`/customers/${fixture.customer_id}/edit`);
    await page
        .getByRole('radio', { name: 'Automatic (from first name)' })
        .click();
    await page.locator('#first_name').fill('Zzyxunknown');
    await expect(
        page.getByRole('img', { name: 'Neutral', exact: true }),
    ).toBeVisible();
    await page.locator('#first_name').fill('Jane');
    await page
        .locator('#notes')
        .fill(`${notes} Updated from ${info.project.name}.`);
    await page.getByRole('radio', { name: 'Woman', exact: true }).click();
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page).toHaveURL(
        new RegExp(`/customers/${fixture.customer_id}$`),
    );
    await expect(
        page.getByText(`${notes} Updated from ${info.project.name}.`, {
            exact: true,
        }),
    ).toBeVisible();
    await screenshot(page, info, 'customer-context');
    await page.goto(`/customers/${fixture.customer_id}/edit`);
    await expect(
        page.getByRole('radio', { name: 'Woman', exact: true }),
    ).toHaveAttribute('aria-checked', 'true');
    await expect(page.locator('#notes')).toHaveValue(
        `${notes} Updated from ${info.project.name}.`,
    );
    await page.goto('/jobs/create');
    await page.locator('#customer-search').fill('Jane Browser');
    await page.getByRole('button', { name: /Jane Browser/ }).click();
    await expect(
        page.getByText(`${notes} Updated from ${info.project.name}.`, {
            exact: true,
        }),
    ).toBeVisible();
    await page.locator('#description').fill(`Booked from ${info.project.name}`);
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page).toHaveURL(/\/jobs\/\d+$/);
    await expect(
        page.getByText(`${notes} Updated from ${info.project.name}.`, {
            exact: true,
        }),
    ).toBeVisible();
});

test('an old unfinished repair remains in the backlog and map fallback keeps visit details', async ({
    page,
}, info) => {
    await page.goto('/dashboard');
    await expect(
        page.getByRole('link', { name: /Not completed jobs/ }),
    ).toBeVisible();
    await page.getByRole('link', { name: /Not completed jobs/ }).click();
    await expect(
        page.locator(`a[href="/jobs/${fixture.waiting_job_id}"]`).first(),
    ).toBeVisible();
    await screenshot(page, info, 'unfinished-jobs');
    await page.goto(`/calendar?view=map&date=${fixture.today}`);
    await expect(
        page.getByText(/The map is off: no Google Maps key is set/),
    ).toBeVisible();
    await expect(
        page.getByRole('button', {
            name: /1:00 p\.m\. – 3:00 p\.m\..*Jane Browser/,
        }),
    ).toBeVisible();
    await page
        .getByRole('combobox', { name: 'Visits to show' })
        .selectOption({ label: 'Browser Technician' });
    await expect(
        page.getByRole('button', {
            name: /1:00 p\.m\. – 3:00 p\.m\..*Jane Browser/,
        }),
    ).toBeVisible();
    await screenshot(page, info, 'calendar-map');
});

test('business expenses save custom categories, actual named taxes and a private receipt', async ({
    page,
}, info) => {
    await page.goto('/business-expenses/create');
    await page.locator('#new_category').fill(`Fuel ${info.project.name}`);
    await page
        .locator('#description')
        .fill(`Browser fuel ${info.project.name}`);
    await page.locator('#amount').fill('25.00');
    await page.locator(`#tax-${fixture.gst_id}`).fill('1.22');
    await expect(page.locator(`#tax-${fixture.pst_id}`)).toHaveValue('1.75');
    await page.locator('#receipt').setInputFiles({
        name: 'browser-receipt.png',
        mimeType: 'image/png',
        buffer: png,
    });
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page).toHaveURL(/\/business-expenses\?from=/);
    await page.locator('#search').fill(`Browser fuel ${info.project.name}`);
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    await expect(page).toHaveURL(
        (url) =>
            url.searchParams.get('search') ===
            `Browser fuel ${info.project.name}`,
    );
    await expect(
        page
            .getByText(`Browser fuel ${info.project.name}`, { exact: true })
            .filter({ visible: true }),
    ).toBeVisible();
    const receipt = page
        .getByRole('link', { name: 'Open receipt' })
        .filter({ visible: true });
    await expect(receipt).toHaveCount(1);
    await expect(receipt).toBeVisible();
    const response = await page.request.get(
        (await receipt.getAttribute('href'))!,
    );
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toContain('image/png');
    await screenshot(page, info, 'business-expenses');
});

test('custom tax names and inactive state persist through the settings dialog', async ({
    page,
}, info) => {
    await page.goto('/company/taxes');
    await page.getByRole('button', { name: 'Add tax', exact: true }).click();
    await page
        .getByRole('dialog')
        .locator('#name')
        .fill(`Custom ${info.project.name}`);
    await page.getByRole('dialog').locator('#rate').fill('3.25');
    await page
        .getByRole('dialog')
        .getByRole('checkbox', { name: 'Active', exact: true })
        .uncheck();
    await page
        .getByRole('dialog')
        .getByRole('button', { name: 'Save', exact: true })
        .click();
    await expect(page.getByRole('dialog')).not.toBeVisible();
    await expect(
        page.getByText(`Custom ${info.project.name}`, { exact: true }),
    ).toBeVisible();
    await page.reload();
    await expect(
        page.getByText(`Custom ${info.project.name}`, { exact: true }),
    ).toBeVisible();
    await screenshot(page, info, 'custom-taxes');
});

test('inbox opens, acknowledges the visible reply and sends to the actual contact number', async ({
    page,
}, info) => {
    const phone =
        info.project.name === 'desktop' ? '+16045550143' : '+16045550142';
    await page.goto(`/messages?phone=${encodeURIComponent(phone)}`);
    await expect(
        page
            .getByRole('region', { name: 'Selected conversation' })
            .getByText('Can you text before arriving?', { exact: true }),
    ).toBeVisible();
    await page.locator('#sms-reply').fill(`Browser reply ${info.project.name}`);
    await page.getByRole('button', { name: 'Send SMS', exact: true }).click();
    await expect(page.locator('#sms-reply')).toHaveValue('');
    await expect(
        page
            .getByRole('region', { name: 'Selected conversation' })
            .getByText(`Browser reply ${info.project.name}`, { exact: true }),
    ).toBeVisible();
    const sent = await (
        await page.request.get('http://127.0.0.1:9001/sent')
    ).json();
    expect(sent).toContainEqual(
        expect.objectContaining({
            To: phone,
            Body: `Browser reply ${info.project.name}`,
        }),
    );
    await screenshot(page, info, 'sms-inbox');
    if (info.project.name === 'mobile') {
        await page
            .getByRole('link', { name: 'Conversations', exact: true })
            .click();
        await expect(
            page.getByRole('region', { name: 'Conversations' }),
        ).toBeVisible();
    }
});

test('invoice totals agree with item tax choices and the public PDF downloads', async ({
    page,
    browser,
}, info) => {
    await page.goto(`/invoices/${fixture.invoice_id}`);
    await expect(
        page.getByText('Labour GST only', { exact: true }),
    ).toBeVisible();
    await expect(
        page.getByText('Part GST and PST', { exact: true }),
    ).toBeVisible();
    await expect(page.getByText(/161\.00/).first()).toBeVisible();
    await screenshot(page, info, 'invoice');
    const guest = await browser.newContext({ viewport: page.viewportSize()! });
    const customer = await guest.newPage();
    await customer.goto(`http://127.0.0.1:8000/d/${fixture.public_token}`);
    await expect(customer.getByText(/161\.00/).first()).toBeVisible();
    const pdf = await guest.request.get(
        `http://127.0.0.1:8000/d/${fixture.public_token}/pdf`,
    );
    expect(pdf.status()).toBe(200);
    expect(pdf.headers()['content-type']).toContain('application/pdf');
    expect((await pdf.body()).subarray(0, 4).toString()).toBe('%PDF');
    await guest.close();
});

test('technician sees assigned customer context and own expenses while foreign customer URLs fail', async ({
    page,
    browser,
}, info) => {
    const context = await browser.newContext({
        storageState: 'test-results/auth-tech.json',
        viewport: page.viewportSize()!,
    });
    const tech = await context.newPage();
    await tech.goto(`http://127.0.0.1:8000/jobs/${fixture.job_id}`);
    await expect(
        tech.getByText(
            new RegExp(notes.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')),
        ),
    ).toBeVisible();
    await tech.goto('http://127.0.0.1:8000/business-expenses');
    await expect(
        tech
            .getByText('Technician drill', { exact: true })
            .filter({ visible: true }),
    ).toBeVisible();
    await expect(tech.getByText(/Browser fuel/)).toHaveCount(0);
    const forbidden = await tech.request.get('http://127.0.0.1:8000/messages');
    expect(forbidden.status()).toBe(403);
    await screenshot(tech, info, 'technician-expenses');
    await context.close();
    const foreign = await page.goto(
        `/customers/${fixture.foreign_customer_id}`,
    );
    expect(foreign?.status()).toBe(404);
});

test('dark mode customer and inbox screens fit the viewport', async ({
    page,
}, info) => {
    await page.goto('/settings/appearance');
    await page.getByRole('button', { name: 'Dark', exact: true }).click();
    await page.goto(`/customers/${fixture.customer_id}`);
    await expect(page.locator('html')).toHaveClass(/dark/);
    await screenshot(page, info, 'customer-dark');
    await page.goto('/messages');
    await screenshot(page, info, 'inbox-dark');
});

test('technician uploads a job photo; the customer signs on the invoice through the field queue', async ({
    page,
    browser,
}, info) => {
    const context = await browser.newContext({
        storageState: 'test-results/auth-tech.json',
        viewport: page.viewportSize()!,
    });
    const tech = await context.newPage();
    tech.on('pageerror', (error) => pageErrors.push(error.message));
    await tech.goto(`http://127.0.0.1:8000/jobs/${fixture.job_id}`);
    const [chooser] = await Promise.all([
        tech.waitForEvent('filechooser'),
        tech.getByRole('button', { name: 'Before photo', exact: true }).click(),
    ]);
    const uploaded = tech.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            /\/jobs\/\d+\/photos$/.test(response.url()),
    );
    await chooser.setFiles({
        name: 'before.png',
        mimeType: 'image/png',
        buffer: png,
    });
    expect((await uploaded).status()).toBeLessThan(400);
    await expect(
        tech.locator(`img[src*="/jobs/${fixture.job_id}/photos/"]`).first(),
    ).toBeVisible();
    // The job page no longer asks for a signature: it is taken with the invoice.
    await expect(
        tech.getByRole('button', { name: /Get signature|Sign again/ }),
    ).toHaveCount(0);
    const width = await tech.evaluate(() => ({
        page: document.documentElement.scrollWidth,
        viewport: window.innerWidth,
    }));
    expect(width.page).toBeLessThanOrEqual(width.viewport + 1);
    await screenshot(tech, info, 'field-photo');
    await context.close();

    await page.goto(`/invoices/${fixture.invoice_id}`);
    await page
        .getByRole('button', { name: /Get signature|Sign again/ })
        .click();
    const canvas = page.getByRole('dialog').locator('canvas');
    await expect(canvas).toBeVisible();
    const rect = (await canvas.boundingBox())!;
    await page.mouse.move(rect.x + 20, rect.y + 70);
    await page.mouse.down();
    await page.mouse.move(rect.x + 80, rect.y + 40, { steps: 5 });
    await page.mouse.move(rect.x + 150, rect.y + 90, { steps: 5 });
    await page.mouse.up();
    const signed = page.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            /\/jobs\/\d+\/signature$/.test(response.url()),
    );
    await page
        .getByRole('button', { name: 'Save signature', exact: true })
        .click();
    expect((await signed).status()).toBeLessThan(400);
    await expect(
        page.getByRole('img', { name: 'Customer signature', exact: true }),
    ).toBeVisible();
    await screenshot(page, info, 'invoice-signature');
    await page.goto('/dashboard');
});

test('office core screens load without runtime errors at both screen sizes', async ({
    page,
}, info) => {
    for (const path of [
        '/customers',
        '/jobs',
        '/invoices',
        '/reports',
        '/cash',
        '/company/services',
        '/company/settings',
    ]) {
        const response = await page.goto(path);
        expect(response?.status(), path).toBe(200);
        await expect(page.locator('h1').first()).toBeVisible();
        const width = await page.evaluate(() => ({
            page: document.documentElement.scrollWidth,
            viewport: window.innerWidth,
        }));
        expect(width.page, path).toBeLessThanOrEqual(width.viewport + 1);
    }
    await screenshot(page, info, 'company-settings');
});
