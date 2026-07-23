import { expect, test } from '@playwright/test';

const signIn = async (page) => {
    await page.goto('/login');
    await page.getByLabel('Email').fill('browser.rail@kraite.test');
    await page.getByLabel('Password').fill('rail-password');
    await page.getByRole('button', { name: 'Sign in' }).click();
};

const expectAligned = async (highlight, link) => {
    const [linkBox, highlightBox] = await Promise.all([
        link.boundingBox(),
        highlight.boundingBox(),
    ]);

    expect(highlightBox).toEqual(linkBox);
};

test('keeps the active projection child highlight aligned after selecting Monthly', async ({ page }) => {
    await signIn(page);

    const projectionsButton = page.getByRole('button', { name: /Projections/ });
    await projectionsButton.click();

    const monthlyLink = page.getByRole('link', { name: 'Monthly', exact: true });
    await monthlyLink.click();
    await expect(page).toHaveURL(/\/projections$/);

    const highlight = page.locator('nav[data-rail] span[aria-hidden="true"]').first();
    await expect(highlight).toBeVisible();
    await page.waitForTimeout(500);
    await expectAligned(highlight, monthlyLink);

    await page.setViewportSize({ width: 1100, height: 600 });
    await page.waitForTimeout(500);
    await expectAligned(highlight, monthlyLink);

    await projectionsButton.click();
    await expect(highlight).toBeHidden();
    await projectionsButton.click();
    await page.waitForTimeout(800);
    await expectAligned(highlight, monthlyLink);

    await projectionsButton.click();
    await projectionsButton.click();
    await page.waitForTimeout(800);
    await expectAligned(highlight, monthlyLink);
});

test('reveals the active projection child in a short desktop rail', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 240 });
    await signIn(page);
    await page.goto('/projections/yearly');

    const railViewport = page.locator('[data-rail-scroll]');
    const yearlyLink = page.getByRole('link', { name: 'Yearly', exact: true });
    await expect(yearlyLink).toBeVisible();
    await page.waitForTimeout(500);

    const [viewportBox, linkBox] = await Promise.all([
        railViewport.boundingBox(),
        yearlyLink.boundingBox(),
    ]);

    expect(linkBox.y).toBeGreaterThanOrEqual(viewportBox.y - 1);
    expect(linkBox.y + linkBox.height).toBeLessThanOrEqual(viewportBox.y + viewportBox.height + 1);
});
