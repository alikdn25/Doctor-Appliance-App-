import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';

test.use({ storageState: { cookies: [], origins: [] } });

test('new owner registers, confirms email and creates a usable company without mandatory 2FA', async ({
    page,
}, info) => {
    const email = `signup-${info.project.name}-${Date.now()}@example.com`;
    await page.goto('/login');
    await page.getByRole('link', { name: 'Create account' }).click();
    await expect(page).toHaveURL(/\/register$/);
    await page.locator('#name').fill('New Browser Owner');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill('New-owner-password-123!');
    await page
        .locator('#password_confirmation')
        .fill('New-owner-password-123!');
    await page.screenshot({
        path: info.outputPath('registration.png'),
        fullPage: true,
    });
    await page.locator('[data-test="register-button"]').click();
    await expect(page).toHaveURL(/\/email\/verify$/);
    await expect(page.getByText(email, { exact: false })).toBeVisible();

    await page.getByRole('link', { name: 'Correct my email address' }).click();
    await expect(page).toHaveURL(/\/settings\/profile$/);
    const correctedEmail = `corrected-${email}`;
    await page.locator('#email').fill(correctedEmail);
    await page.locator('[data-test="update-profile-button"]').click();
    await expect(page).toHaveURL(/\/email\/verify$/);
    await expect(
        page.getByText(correctedEmail, { exact: false }),
    ).toBeVisible();

    // Isolated CI uses the log mailer and synchronous queue, never a real inbox.
    const mail = readFileSync('storage/logs/laravel.log', 'utf8')
        .replace(/=\r?\n/g, '')
        .replaceAll('=3D', '=');
    const links = mail.match(
        /http:\/\/127\.0\.0\.1:8000\/email\/verify\/[^\s"'<>]+/g,
    );
    expect(links?.length).toBeGreaterThan(0);
    const confirmation = links!.at(-1)!.replaceAll('&amp;', '&');
    await page.goto(confirmation);
    await expect(page).toHaveURL(/\/onboarding\/company$/);
    await page
        .locator('#company-name')
        .fill(`New Company ${info.project.name}`);
    await page.locator('#country').selectOption('GB');
    await page.locator('#timezone').selectOption('Europe/London');
    await page.screenshot({
        path: info.outputPath('company-setup.png'),
        fullPage: true,
    });
    await page.locator('[data-test="create-company-button"]').click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await expect(
        page.getByRole('heading', { name: 'Start here' }),
    ).toBeVisible();
    await expect(
        page.getByRole('link', { name: /Add a customer/ }),
    ).toBeVisible();
    await page.screenshot({
        path: info.outputPath('first-dashboard.png'),
        fullPage: true,
    });
    await page.getByRole('link', { name: /Add a customer/ }).click();
    await expect(page).toHaveURL(/\/customers\/create$/);
});
