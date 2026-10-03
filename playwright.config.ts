import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/browser',
    globalSetup: './tests/browser/auth.setup.ts',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 45000,
    reporter: [['list'], ['html', { open: 'never' }]],
    use: {
        baseURL: 'http://127.0.0.1:8000',
        storageState: 'test-results/auth-owner.json',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        {
            name: 'desktop',
            use: {
                ...devices['Desktop Chrome'],
                viewport: { width: 1440, height: 900 },
            },
        },
        {
            name: 'mobile',
            use: { ...devices['iPhone 13'], defaultBrowserType: 'chromium' },
        },
    ],
    webServer: [
        {
            command: 'node tests/browser/sms-stub.mjs',
            url: 'http://127.0.0.1:9001/health',
            reuseExistingServer: false,
        },
        {
            command: 'php artisan serve --host=127.0.0.1 --port=8000',
            url: 'http://127.0.0.1:8000/up',
            reuseExistingServer: false,
            // Keep CSRF protection active for real HTTP requests; only seeding uses testing mode.
            env: { APP_ENV: 'local' },
        },
    ],
});
