import { chromium, expect } from '@playwright/test';
import { createHmac } from 'node:crypto';
import { mkdir } from 'node:fs/promises';

function totp(): string {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    const secretChars = 'JBSWY3DPEHPK3PXP'.split('');
    const bits = secretChars
        .map((char) => alphabet.indexOf(char).toString(2).padStart(5, '0'))
        .join('');
    const secret = Buffer.from(
        bits.match(/.{8}/g)!.map((byte) => parseInt(byte, 2)),
    );
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
    const digest = createHmac('sha1', secret).update(counter).digest();
    const offset = digest[digest.length - 1] & 15;
    return String(
        (digest.readUInt32BE(offset) & 0x7fffffff) % 1000000,
    ).padStart(6, '0');
}

export default async function setup() {
    await mkdir('test-results', { recursive: true });
    const browser = await chromium.launch();
    for (const role of ['owner', 'tech']) {
        const context = await browser.newContext();
        const page = await context.newPage();
        await page.goto('http://127.0.0.1:8000/login');
        await page.locator('#email').fill(`browser-${role}@example.com`);
        await page.locator('#password').fill('password');
        await page.locator('[data-test="login-button"]').click();
        if (role === 'owner') {
            await expect(page).toHaveURL(/two-factor-challenge/);
            await page.locator('input[name="code"]').fill(totp());
            await page
                .getByRole('button', { name: 'Continue', exact: true })
                .click();
        }
        await expect(page).toHaveURL(
            role === 'owner' ? /dashboard/ : /my-jobs/,
        );
        await expect(page.locator('h1')).toBeVisible();
        await context.storageState({ path: `test-results/auth-${role}.json` });
        await context.close();
    }
    await browser.close();
}
