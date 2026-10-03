import { expect, test } from '@playwright/test';

test.use({ storageState: { cookies: [], origins: [] } });
test.skip(
    process.env.AUTH_EMAIL_DELIVERY_ENABLED !== 'false',
    'Runs in the dedicated mail-disabled browser pass.',
);

test('signup and technician access work while mail is unavailable without promising email delivery', async ({
    page,
}, info) => {
    const suffix = `${info.project.name}-${Date.now()}`;
    await page.goto('/register');
    await expect(page.getByText('Confirm email', { exact: true })).toHaveCount(
        0,
    );
    await page.locator('#name').fill('Mail Off Owner');
    await page.locator('#email').fill(`mail-off-${suffix}@example.com`);
    await page.locator('#password').fill('New-owner-password-123!');
    await page
        .locator('#password_confirmation')
        .fill('New-owner-password-123!');
    await page.locator('[data-test="register-button"]').click();
    await expect(page).toHaveURL(/\/onboarding\/company$/);
    await page.locator('#company-name').fill(`Mail Off ${suffix}`);
    await page.locator('[data-test="create-company-button"]').click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await page.goto('/company/team');
    await page
        .getByRole('button', { name: 'Add team member', exact: true })
        .click();
    const dialog = page.getByRole('dialog');
    await expect(
        dialog.getByText(/Email delivery is not available yet/),
    ).toBeVisible();
    await dialog.locator('#name').fill('Mail Off Technician');
    await dialog.locator('#email').fill(`mail-off-tech-${suffix}@example.com`);
    await dialog.locator('#password').fill('Technician-password-123!');
    await dialog
        .locator('#password_confirmation')
        .fill('Technician-password-123!');
    await page.screenshot({
        path: info.outputPath('manual-staff-access.png'),
        fullPage: true,
    });
    await dialog
        .getByRole('button', { name: 'Add team member', exact: true })
        .click();
    await expect(dialog).toBeHidden();
    await expect(
        page.getByText('Mail Off Technician', { exact: true }),
    ).toBeVisible();

    // Use the real CSRF-protected logout instead of clearing browser storage.
    await page.evaluate(async () => {
        const token = document.cookie
            .split('; ')
            .find((entry) => entry.startsWith('XSRF-TOKEN='))
            ?.split('=')
            .slice(1)
            .join('=');
        const response = await fetch('/logout', {
            method: 'POST',
            headers: {
                'X-XSRF-TOKEN': decodeURIComponent(token ?? ''),
                Accept: 'text/html',
            },
        });
        if (!response.ok) throw new Error(`Logout failed: ${response.status}`);
    });
    await page.goto('/login');
    await page.locator('#email').fill(`mail-off-tech-${suffix}@example.com`);
    await page.locator('#password').fill('Technician-password-123!');
    await page.locator('[data-test="login-button"]').click();
    await expect(page).toHaveURL(/\/my-jobs$/);
    await page.reload();
    await expect(page).toHaveURL(/\/my-jobs$/);
    await page.screenshot({
        path: info.outputPath('unverified-technician-workspace.png'),
        fullPage: true,
    });
});

test('password recovery clearly explains that mail is not connected', async ({
    page,
}, info) => {
    await page.goto('/forgot-password');
    await expect(
        page.getByText(/Email delivery is not available yet/),
    ).toBeVisible();
    await expect(
        page.locator('[data-test="email-password-reset-link-button"]'),
    ).toHaveCount(0);
    await page.screenshot({
        path: info.outputPath('mail-unavailable-recovery.png'),
        fullPage: true,
    });
});
