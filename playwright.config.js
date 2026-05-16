import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/e2e',
    timeout: 30_000,
    workers: 1,
    expect: {
        timeout: 10_000,
    },
    webServer: {
        command: 'scripts/prepare-e2e.sh && php artisan serve --env=testing --host=127.0.0.1 --port=8002 --no-reload',
        url: 'http://127.0.0.1:8002/up',
        reuseExistingServer: false,
        timeout: 120_000,
    },
    use: {
        baseURL: process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8002',
        trace: 'on-first-retry',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
