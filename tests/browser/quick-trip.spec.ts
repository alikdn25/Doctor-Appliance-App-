import { expect, test } from '@playwright/test';

test('Trip in Mileage\'s "…": type the store, Navigate opens Google Maps and the trip is in the mileage log', async ({
    browser,
    page,
}, info) => {
    const context = await browser.newContext({
        storageState: 'test-results/auth-tech.json',
        viewport: page.viewportSize()!,
        // No GPS permission: the trip starts from the last point of the day.
    });
    // Google Maps is not loaded in the test; only the address it is opened with is checked.
    await context.route('https://www.google.com/**', (route) =>
        route.fulfill({ body: 'maps' }),
    );
    const tech = await context.newPage();
    // The trip with directions lives in Mileage's "…" (the "+" opens the form by hand).
    await tech.goto('http://127.0.0.1:8000/trips');
    await tech
        .locator('button[aria-label="More actions"]:visible')
        .first()
        .click();
    await tech.getByRole('button', { name: 'Trip', exact: true }).click();
    const sheet = tech.getByRole('dialog');
    // Enter by hand is on the sheet without scrolling.
    await expect(
        sheet.getByRole('button', { name: 'Enter by hand' }),
    ).toBeInViewport();
    await sheet
        .getByLabel('Store or address')
        .fill(`Reliable Parts ${info.project.name}`);
    const maps = context.waitForEvent('page');
    await sheet.getByRole('button', { name: 'Navigate' }).click();
    // The new tab may not have its address yet when it opens.
    const popup = await maps;
    await popup.waitForURL(/google\.com/);
    const mapsUrl = new URL(popup.url());
    expect(mapsUrl.pathname).toBe('/maps/dir/');
    expect(mapsUrl.searchParams.get('dir_action')).toBe('navigate');
    expect(mapsUrl.searchParams.get('destination')).toBe(
        `Reliable Parts ${info.project.name}`,
    );
    await expect(sheet).toHaveCount(0);

    await tech.goto('http://127.0.0.1:8000/trips');
    await expect(
        tech.getByRole('button', {
            name: new RegExp(`Reliable Parts ${info.project.name}`),
        }),
    ).toBeVisible();
    await context.close();
});
