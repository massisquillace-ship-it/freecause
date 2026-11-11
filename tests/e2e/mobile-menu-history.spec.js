import { test, expect } from './helpers.js';

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
