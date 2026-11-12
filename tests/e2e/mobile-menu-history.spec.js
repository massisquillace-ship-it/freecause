import { test, expect, login } from './helpers.js';

test.use({ viewport: { width: 375, height: 800 } });

test('mobile menu link leaves no extra history entry', async ({ page }) => {
    await page.goto('/en');
    await page.locator('.fcui-bottom-nav__item[data-sheet-open="mobile-menu"]').click();
    await expect(page.locator('#mobile-menu')).toBeVisible();
    await page.locator('#mobile-menu a[href]').first().click();
    await page.waitForURL((url) => url.pathname !== '/en');
    await page.goBack();
    await expect(page).toHaveURL(/\/en$/);
    await page.goBack();
    await expect(page).not.toHaveURL(/\/en$/);
});

test('mobile menu logout leaves no extra history entry', async ({ page }) => {
    await login(page, 'owner@e2e.test', 'password');
    await expect(page).not.toHaveURL(/\/login$/);
    await page.goto('/en/profile');
    await page.goto('/en');
    const before = await page.evaluate(() => history.length);
    await page.locator('.fcui-bottom-nav__item[data-sheet-open="mobile-menu"]').click();
    await expect(page.locator('#mobile-menu')).toBeVisible();
    await page.locator('#mobile-menu form[action$="/logout"] button[type=submit]').click();
    await page.waitForLoadState('load');
    await expect(page.locator('.fcui-bottom-nav__item[data-sheet-open="mobile-menu"]')).toBeVisible();
    await expect(page.locator('#mobile-menu form[action$="/logout"]')).toHaveCount(0);
    const after = await page.evaluate(() => history.length);
    expect(after).toBe(before + 1);
    await page.goBack();
    await expect(page).toHaveURL(/\/en$/);
    await page.goBack();
    await expect(page).not.toHaveURL(/\/en$/);
});
