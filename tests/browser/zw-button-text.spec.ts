import { expect, test, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';

const fixture = JSON.parse(
    readFileSync('storage/framework/testing/browser-fixture.json', 'utf8'),
);

/**
 * Every visible button keeps its label inside: nothing sticks out to the side or below.
 */
async function overflowingButtons(page: Page): Promise<string[]> {
    return page.evaluate(() =>
        Array.from(
            document.querySelectorAll<HTMLElement>(
                '[data-slot="button"], button',
            ),
        )
            // Icon buttons whose only text is for screen readers (the dialog's Close) have no label to fit.
            .filter(
                (el) =>
                    el.offsetParent !== null &&
                    el.innerText.trim() &&
                    !el.querySelector('.sr-only'),
            )
            .filter((el) => {
                const box = el.getBoundingClientRect();
                const range = document.createRange();
                range.selectNodeContents(el);
                const text = range.getBoundingClientRect();

                return (
                    el.scrollWidth > el.clientWidth + 1 ||
                    el.scrollHeight > el.clientHeight + 1 ||
                    text.left < box.left - 1 ||
                    text.right > box.right + 1 ||
                    text.top < box.top - 1 ||
                    text.bottom > box.bottom + 1
                );
            })
            .map((el) => el.innerText.trim()),
    );
}

// A 360 px Android phone, with the default font and with the system font at 130 %.
test.use({ viewport: { width: 360, height: 780 } });

for (const scale of [100, 130]) {
    test(`Close job buttons keep their labels inside at 360 px with a ${scale} % font`, async ({
        page,
    }, info) => {
        test.skip(info.project.name !== 'mobile', 'Phone layout only');
        await page.addInitScript((size) => {
            document.addEventListener('DOMContentLoaded', () => {
                document.documentElement.style.fontSize = size;
            });
        }, `${scale}%`);

        await page.goto(`/jobs/${fixture.job_id}`);
        expect(await overflowingButtons(page)).toEqual([]);

        await page.locator('[data-test="action-sheet-button"]').click();
        await page
            .locator('[data-test="action-sheet"]')
            .getByRole('button', { name: /Close job/ })
            .click();
        const dialog = page.getByRole('dialog');
        const declined = dialog.getByRole('button', {
            name: 'Customer declined repair',
        });
        const unable = dialog.getByRole('button', { name: 'Unable to repair' });
        await expect(declined).toBeVisible();

        expect(await overflowingButtons(page)).toEqual([]);
        // Two buttons sharing a row are the same height.
        const [a, b] = await Promise.all([
            declined.boundingBox(),
            unable.boundingBox(),
        ]);
        if (Math.round(a!.y) === Math.round(b!.y)) {
            expect(Math.round(a!.height)).toBe(Math.round(b!.height));
        }

        await page.screenshot({
            path: info.outputPath(`close-job-${scale}.png`),
        });
    });

    test(`My Jobs card buttons keep their labels inside at 360 px with a ${scale} % font`, async ({
        browser,
    }, info) => {
        test.skip(info.project.name !== 'mobile', 'Phone layout only');
        const context = await browser.newContext({
            storageState: 'test-results/auth-tech.json',
            viewport: { width: 360, height: 780 },
        });
        const tech = await context.newPage();
        await tech.addInitScript((size) => {
            document.addEventListener('DOMContentLoaded', () => {
                document.documentElement.style.fontSize = size;
            });
        }, `${scale}%`);
        await tech.goto('/today');
        await expect(tech.getByText('Jane Browser').first()).toBeVisible();
        expect(await overflowingButtons(tech)).toEqual([]);
        await tech.screenshot({
            path: info.outputPath(`my-jobs-${scale}.png`),
        });
        await context.close();
    });
}
