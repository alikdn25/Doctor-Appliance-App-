import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';

// UX redesign phase 13 (§15.5): the main screens have no WCAG A/AA violations (contrast, names, roles).
const fixture = JSON.parse(
    readFileSync('storage/framework/testing/browser-fixture.json', 'utf8'),
);

const screens: [string, string][] = [
    ['My Jobs', '/my-jobs'],
    ['Jobs', '/jobs'],
    ['Job page', `/jobs/${fixture.job_id}`],
    ['Book customer', '/jobs/create'],
    ['Calendar', '/calendar'],
    ['Customers', '/customers'],
    ['Customer', `/customers/${fixture.customer_id}`],
    ['Invoices', '/invoices?status=all'],
    ['Invoice', `/invoices/${fixture.invoice_id}`],
    ['Reports', '/reports'],
    ['Settings', '/company/settings'],
    ['Messages', '/messages'],
    ['Expenses & taxes', '/expenses-and-taxes'],
    ['Business expenses', '/business-expenses'],
    ['Tax reserve', '/expenses-and-taxes/tax-reserve'],
    ['Cash', '/cash'],
    ['Mileage', '/trips'],
    ['Team', '/company/team'],
    ['Profile', '/settings/profile'],
    ['Customer link', `/d/${fixture.public_token}`],
];

for (const [name, url] of screens) {
    test(`${name} has no accessibility violations`, async ({ page }) => {
        await page.goto(url);
        await page.waitForLoadState('networkidle');
        const results = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
            .analyze();
        const found = results.violations.map(
            (v) =>
                `${v.id} (${v.impact}): ${v.nodes
                    .slice(0, 3)
                    .map(
                        (n) =>
                            `${n.target.join(' ')} ${n.any
                                .map((c) =>
                                    c.data && typeof c.data === 'object'
                                        ? JSON.stringify(c.data)
                                        : '',
                                )
                                .join(
                                    '',
                                )}${n.all.map((c) => c.message).join(' ')}`,
                    )
                    .join(' | ')}`,
        );
        expect(found, found.join('\n')).toEqual([]);
    });
}
