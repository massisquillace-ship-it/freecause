import AxeBuilder from '@axe-core/playwright';
import { test, expect } from './helpers.js';

const copy = {
    en: ['Search FreeCause', 'Start a petition', 'Claim your organization'],
    it: ['Cerca su FreeCause', 'Avvia una petizione', 'Rivendica la tua organizzazione'],
    fr: ['Rechercher sur FreeCause', 'Lancer une pétition', 'Revendiquez votre organisation'],
};

for (const width of [375, 1280]) {
    for (const [locale, [search, start, claim]] of Object.entries(copy)) {
        test(`homepage search and individual actions ${locale} at ${width}px`, async ({ page }) => {
            await page.setViewportSize({ width, height: 900 });
            await page.goto(`/${locale}`);
            await expect(page.locator('#home-hero-title')).toBeVisible();
            await expect(page.getByRole('searchbox', { name: search, exact: true })).toBeVisible();
            await expect(page.locator('main').getByRole('link', { name: start, exact: true }).first()).toBeVisible();
            await expect(page.locator('main').getByRole('link', { name: claim, exact: true }).first()).toBeVisible();
            const nav = width === 375 ? page.locator('[data-bottom-nav]') : page.locator('nav.navbar');
            await expect(nav.getByRole('link', { name: start, exact: true })).toBeVisible();
            await expect(nav.getByRole('link', { name: claim, exact: true })).toBeVisible();
            expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(false);
            const result = await new AxeBuilder({ page }).include('#fc-main').withTags(['wcag2a', 'wcag2aa']).analyze();
            expect(result.violations.filter((v) => ['serious', 'critical'].includes(v.impact))).toEqual([]);
        });
    }
}

test('homepage search opens organizations first and preserves the petition tab', async ({ page }) => {
    await page.goto('/en');
    await page.getByRole('searchbox', { name: 'Search FreeCause', exact: true }).fill('Test organization');
    await page.locator('main form[role=search]').getByRole('button', { name: 'Search', exact: true }).click();
    await expect(page).toHaveURL(/\/en\/search\?q=Test/);
    const tabs = page.locator('nav[aria-label="Search FreeCause"]');
    await expect(tabs.getByRole('link').first()).toContainText('Organizations');
    await expect(page.getByRole('heading', { name: 'Test organization — verified', exact: true })).toBeVisible();
    await tabs.getByRole('link', { name: /Petitions/ }).click();
    await expect(page).toHaveURL(/tab=petitions/);
    await expect(page.getByText('No results. Try another name or keyword.', { exact: true })).toBeVisible();
});
