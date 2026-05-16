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
    await expect(page.getByRole('button', { name: 'Select Binance' })).toBeEnabled();
    await expect(page.getByRole('button', { name: 'Select Bitget' })).toBeEnabled();
    await expect(page.getByRole('button', { name: 'Coming soon Bybit' })).toBeDisabled();
    await expect(page.getByRole('button', { name: 'Coming soon KuCoin' })).toBeDisabled();
    await expect(page.getByText('Coming soon')).toHaveCount(2);
    await expect(page.getByAltText('Bybit')).toHaveClass(/grayscale/);
    await expect(page.getByAltText('KuCoin')).toHaveClass(/grayscale/);
    await expect(page.getByAltText('Bitget')).not.toHaveClass(/grayscale/);
    await expect(page.getByRole('link', { name: 'Terms & Conditions' })).toHaveAttribute('href', 'https://kraite.test/terms-and-conditions');

    await page.getByRole('button', { name: 'Next' }).click();

    await expect(page).toHaveURL(new RegExp(`/register/${registrationUuid}$`));
    await expect(page.getByText('The password field is required.')).toHaveCount(1);
    await expect(page.locator('#password').locator('xpath=..')).toContainText('The password field is required.');
    await expect(page.getByText('The API key field is required.')).toHaveCount(1);
    await expect(page.getByText('The terms field must be accepted.')).toHaveCount(1);
    await expect(page.getByRole('button', { name: 'Next' })).toBeEnabled();

    await page.locator('#password').fill('password');
    await expect(page.locator('[data-password-strength-label]')).toHaveText('Weak');
    await expect(page.getByRole('progressbar', { name: 'Password strength' })).toHaveAttribute('aria-valuenow', '33');

    await page.getByRole('button', { name: 'Add API Keys' }).click();
    await expect(page.getByRole('dialog', { name: 'API keys' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Test connectivity' })).toBeDisabled();

    await page.locator('#api_key').fill('binance-key');
    await page.locator('#api_secret').fill('binance-secret');

    await expect(page.getByRole('button', { name: 'Test connectivity' })).toBeEnabled();
    await page.getByRole('button', { name: 'Done' }).click();
    await expect(page.getByRole('dialog', { name: 'API keys' })).toBeHidden();

    await page.locator('#password').fill('correct-password');
    await expect(page.locator('[data-password-strength-label]')).toHaveText('Strong enough');
    await expect(page.getByRole('progressbar', { name: 'Password strength' })).toHaveAttribute('aria-valuenow', '75');
    await page.locator('#password_confirmation').fill('correct-password');
    await page.getByLabel('I read the Terms & Conditions').check();
    await page.getByRole('button', { name: 'Next' }).click();

    await expect(page).toHaveURL(/\/dashboard$/);
    await expect(javascriptErrors).toEqual([]);
});
