import { expect, test } from '@playwright/test';

const registrationUuid = '11111111-1111-4111-8111-111111111111';

test('private beta registration validates inline and completes without page refresh', async ({ page }) => {
    const javascriptErrors = [];

    page.on('pageerror', (error) => {
        javascriptErrors.push(error.message);
    });

    await page.goto(`/register/${registrationUuid}`);

    await expect(page.getByRole('heading', { name: 'Welcome to Kraite!' })).toBeVisible();
    await expect(page.getByText('browser.registration@kraite.test')).toBeVisible();
    await expect(page.locator('form')).toHaveAttribute('novalidate', '');
    await expect(page.locator('#password')).not.toHaveAttribute('required', /.*/);
    await expect(page.getByRole('button', { name: 'Select Binance' })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Terms & Conditions' })).toHaveAttribute('href', 'https://kraite.test/terms-and-conditions');

    await page.getByRole('button', { name: 'Configure API keys' }).click();

    await expect(page).toHaveURL(new RegExp(`/register/${registrationUuid}$`));
    await expect(page.getByText('The password field is required.')).toHaveCount(1);
    await expect(page.locator('#password').locator('xpath=..')).toContainText('The password field is required.');
    await expect(page.getByText('The API key field is required.')).toHaveCount(0);
    await expect(page.getByText('The terms field must be accepted.')).toHaveCount(1);
    await expect(page.getByText('The risk acknowledgement field must be accepted.')).toHaveCount(1);
    await expect(page.getByRole('button', { name: 'Configure API keys' })).toBeEnabled();

    await page.locator('#password').fill('password');
    await expect(page.locator('[data-password-strength-label]')).toHaveText('Weak');
    await expect(page.getByRole('progressbar', { name: 'Password strength' })).toHaveAttribute('aria-valuenow', '33');

    await page.locator('#password').fill('correct-password');
    await expect(page.locator('[data-password-strength-label]')).toHaveText('Strong enough');
    await expect(page.getByRole('progressbar', { name: 'Password strength' })).toHaveAttribute('aria-valuenow', '75');
    await page.locator('#password_confirmation').fill('correct-password');
    await page.getByLabel('I read the Terms & Conditions').check();
    await page.getByLabel('I understand crypto trading is high-risk and I can lose some or all of my financial assets').check();
    await page.getByRole('button', { name: 'Configure API keys' }).click();

    await expect(page.getByRole('heading', { name: 'Trading exchange' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Select Binance' })).toBeEnabled();
    await expect(page.getByRole('button', { name: 'Select Bitget' })).toBeEnabled();
    await expect(page.getByRole('button', { name: 'Coming soon Bybit' })).toBeDisabled();
    await expect(page.getByRole('button', { name: 'Coming soon KuCoin' })).toBeDisabled();
    await expect(page.getByText('Coming soon')).toHaveCount(2);
    await expect(page.getByAltText('Bybit')).toHaveClass(/grayscale/);
    await expect(page.getByAltText('KuCoin')).toHaveClass(/grayscale/);
    await expect(page.getByAltText('Bitget')).not.toHaveClass(/grayscale/);

    await page.getByRole('button', { name: 'Create account' }).click();

    await expect(page.getByRole('heading', { name: 'Create account without API setup?' })).toBeVisible();
    await expect(page.getByText('API credentials are missing.')).toBeVisible();
    await expect(page.getByText('API connectivity has not been verified.')).toBeVisible();
    await page.getByRole('button', { name: 'Go back' }).click();
    await expect(page.getByRole('button', { name: 'Test connectivity' })).toBeDisabled();

    await page.getByRole('button', { name: 'Add API keys' }).click();
    await expect(page.getByRole('heading', { name: 'API keys' })).toBeVisible();
    await page.locator('#api_key').fill('binance-key');
    await page.locator('#api_secret').fill('binance-secret');
    await page.getByRole('button', { name: 'Save keys' }).click();

    await expect(page.getByRole('button', { name: 'Test connectivity' })).toBeEnabled();
    await expect(page.getByRole('button', { name: 'Test connectivity' })).toHaveClass(/animate-pulse/);
    await expect(page.getByRole('button', { name: 'Create account' })).toBeDisabled();
    await expect(page.getByText('Test connectivity before creating the account.')).toBeVisible();

    const connectivityResponses = [];

    await page.route(`**/register/${registrationUuid}/connectivity`, async (route) => {
        const response = connectivityResponses.shift();

        if (! response) {
            throw new Error('Unexpected registration connectivity request.');
        }

        if (response.waitForRelease) {
            await response.waitForRelease;
        }

        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify(response.body),
        });
    });

    connectivityResponses.push({
        body: {
            block_uuid: '33333333-3333-4333-8333-333333333333',
            is_complete: true,
            all_connected: false,
            connected_servers: 1,
            failed_servers: 1,
            total_servers: 2,
            servers: [
                { id: 1, name: 'Orion', status: 'connected' },
                { id: 2, name: 'Vega', status: 'not_connected' },
            ],
        },
    });

    await page.getByRole('button', { name: 'Test connectivity' }).click();
    await expect(page.getByText('Some servers could not connect. Add the IP addresses to your exchange account, or confirm below to create the account with trading disabled.')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Test connectivity' })).not.toHaveClass(/animate-pulse/);
    await expect(page.getByRole('button', { name: 'Create account' })).toBeDisabled();
    await page.getByLabel('I would like to create the account - Trading will be disabled until I add the IP addresses to my exchange account').check();
    await expect(page.getByRole('button', { name: 'Create account' })).toBeEnabled();

    let releaseConnectivity;
    const heldConnectivityResponse = new Promise((resolve) => {
        releaseConnectivity = resolve;
    });

    connectivityResponses.push({
        waitForRelease: heldConnectivityResponse,
        body: {
            block_uuid: '22222222-2222-4222-8222-222222222222',
            is_complete: true,
            all_connected: true,
            connected_servers: 2,
            failed_servers: 0,
            total_servers: 2,
            servers: [
                { id: 1, name: 'Orion', status: 'connected' },
                { id: 2, name: 'Vega', status: 'connected' },
            ],
        },
    });

    const connectivityRequest = page.waitForRequest(`**/register/${registrationUuid}/connectivity`);
    await page.getByRole('button', { name: 'Test connectivity' }).click();
    await connectivityRequest;
    await expect(page.getByRole('button', { name: 'Create account' })).toBeDisabled();
    await expect(page.getByRole('button', { name: 'Test connectivity' })).toHaveClass(/animate-pulse/);
    releaseConnectivity();

    await expect(page.getByText('Connectivity verified, all good!')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Test connectivity' })).not.toHaveClass(/animate-pulse/);
    await expect(page.getByRole('button', { name: 'Create account' })).toBeEnabled();

    await page.getByRole('button', { name: 'Create account' }).click();

    await expect(page.getByRole('heading', { name: 'Your bot is active' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Go to dashboard' })).toHaveAttribute('href', /\/dashboard$/);
    await expect(javascriptErrors).toEqual([]);
});
