import { expect, test } from '@playwright/test';

const registrationHandoffToken = 'a'.repeat(64);

test('registration handoff opens the dashboard welcome without page errors', async ({ page }) => {
    const javascriptErrors = [];

    page.on('pageerror', (error) => {
        javascriptErrors.push(error.message);
    });

    await page.goto(`/register-handoff/${registrationHandoffToken}`);

    await expect(page).toHaveURL(/\/dashboard$/);
    await expect(page.getByRole('heading', { name: 'Welcome to your dashboard!' })).toBeVisible();
    await expect(page.getByText('Your 7-day free trial is running')).toBeVisible();
    await page.getByRole('button', { name: "Let's go" }).click();
    await expect(page.getByRole('heading', { name: 'Dashboard', exact: true })).toBeVisible();
    await expect(javascriptErrors).toEqual([]);
});
