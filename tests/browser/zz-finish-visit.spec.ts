import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';

const fixture = JSON.parse(
    readFileSync('storage/framework/testing/browser-fixture.json', 'utf8'),
);

// Runs last (it finishes the fixture visit), on the phone only.
test('the technician finishes a visit on the Finish visit screen with free-text work notes', async ({
    page,
    browser,
}, info) => {
    test.skip(info.project.name !== 'mobile', 'Finishes the shared visit once');
    const context = await browser.newContext({
        storageState: 'test-results/auth-tech.json',
        viewport: page.viewportSize()!,
    });
    const tech = await context.newPage();
    await tech.goto(`http://127.0.0.1:8000/jobs/${fixture.job_id}`);
    await tech.getByRole('button', { name: 'Start job', exact: true }).click();
    await tech.getByRole('link', { name: 'Finish visit', exact: true }).click();
    await expect(tech).toHaveURL(/\/visits\/\d+\/finish$/);
    await expect(tech.getByText('Jane Browser').first()).toBeVisible();
    await tech
        .getByLabel('Work completed / notes')
        .fill('Replaced drain pump. Tested, no leaks.');
    await expect(
        tech.getByRole('radio', { name: /Completed/ }).first(),
    ).toHaveAttribute('aria-checked', 'true');
    await tech.screenshot({
        path: info.outputPath('finish-visit.png'),
        fullPage: true,
    });
    await tech
        .getByRole('button', { name: 'Finish visit', exact: true })
        .click();
    await expect(tech).toHaveURL(new RegExp(`/jobs/${fixture.job_id}`));
    await expect(
        tech.getByText('Replaced drain pump. Tested, no leaks.').first(),
    ).toBeVisible();
    await context.close();
});
