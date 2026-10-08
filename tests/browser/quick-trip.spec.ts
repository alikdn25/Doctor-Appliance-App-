import { expect, test } from '@playwright/test';

test('+ Trip on My Jobs: type the store, Save, and it is in the mileage log', async ({
    browser,
    page,
}, info) => {
    const context = await browser.newContext({
        storageState: 'test-results/auth-tech.json',
        viewport: page.viewportSize()!,
        // No GPS permission: the trip starts from the last point of the day.
    });
    const tech = await context.newPage();
    await tech.goto('http://127.0.0.1:8000/my-jobs');
    await tech.getByRole('button', { name: '+ Trip' }).click();
    const sheet = tech.getByRole('dialog');
    await sheet
        .getByLabel('Store or address')
        .fill(`Reliable Parts ${info.project.name}`);
    await sheet.getByRole('button', { name: 'Save trip' }).click();
    await expect(sheet).toHaveCount(0);

    await tech.goto('http://127.0.0.1:8000/trips');
    await expect(
        tech.getByRole('button', {
            name: new RegExp(`Reliable Parts ${info.project.name}`),
        }),
    ).toBeVisible();
    await context.close();
});
