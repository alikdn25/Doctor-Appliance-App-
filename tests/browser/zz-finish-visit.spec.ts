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
    // The bottom bar follows the status: Start job, then Complete (or Part needed).
    await expect(
        tech.getByRole('link', { name: 'Part needed', exact: true }),
    ).toBeVisible();
    // Complete opens the Close job window (owner's task of November 26); the Finish visit screen stays at its address.
    await tech.getByRole('button', { name: 'Complete', exact: true }).click();
    await expect(tech.locator('[data-test="close-job-sheet"]')).toBeVisible();
    await tech.keyboard.press('Escape');
    const parts = await tech
        .getByRole('link', { name: 'Part needed', exact: true })
        .getAttribute('href');
    await tech.goto(`http://127.0.0.1:8000${parts!.split('?')[0]}`);
    await expect(tech).toHaveURL(/\/visits\/\d+\/finish$/);
    await expect(tech.getByText('Jane Browser').first()).toBeVisible();
    await tech
        .getByLabel(/Work completed \/ Notes/)
        .fill('Replaced drain pump. Tested, no leaks.');
    await expect(
        tech.getByRole('radio', { name: /Completed/ }).first(),
    ).toHaveAttribute('aria-checked', 'true');
    await tech.screenshot({
        path: info.outputPath('finish-visit.png'),
        fullPage: true,
    });
    await tech.getByRole('button', { name: 'Finish Job', exact: true }).click();
    await expect(tech).toHaveURL(new RegExp(`/jobs/${fixture.job_id}`));
    await expect(
        tech.getByText('Replaced drain pump. Tested, no leaks.').first(),
    ).toBeVisible();
    await context.close();
});
