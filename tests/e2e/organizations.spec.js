import AxeBuilder from '@axe-core/playwright';
import { test, expect, login } from './helpers.js';
import { ADMIN_STATE } from './env.js';

for (const width of [375, 1280]) {
    for (const slug of ['verified', 'images']) {
        test(`public organization ${slug} at ${width}px`, async ({ page }) => {
            await page.setViewportSize({ width, height: 900 });
            const response = await page.goto(`/en/org/e2e-organization-${slug}`);
            expect(response.status()).toBe(200);
            await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
            await expect(page.getByText('No campaigns are linked to this profile yet.')).toBeVisible();
            await expect(page.locator('article')).not.toContainText('PRIVATE');
            expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(false);
            if (process.env.E2E_ORG_CAPTURE) await page.screenshot({ path: test.info().outputPath('profile.png'), fullPage: true });
            const result = await new AxeBuilder({ page }).include('article').withTags(['wcag2a', 'wcag2aa']).analyze();
            expect(result.violations.filter((v) => ['serious', 'critical'].includes(v.impact))).toEqual([]);
            if (slug === 'images') {
                const cover = page.getByAltText('Cover image');
                await expect(cover).toBeVisible();
                await expect(cover).toHaveAttribute('width', '1500');
                await expect(cover).toHaveAttribute('height', '500');
                expect(await cover.evaluate((img) => img.currentSrc)).toMatch(width === 375 ? /_400w\.webp$/ : /e2e-cover\.webp$/);
            }
        });
    }
}

test('copy fallback, social metadata and report contain only public details', async ({ page }) => {
    await page.addInitScript(() => {
        Object.defineProperty(navigator, 'clipboard', { value: { writeText: async () => { throw new Error('denied'); } } });
        Object.defineProperty(navigator, 'share', { value: undefined });
    });
    await page.goto('/it/org/e2e-organization-verified');
    await expect(page.locator('meta[property="og:title"]')).toHaveAttribute('content', /Test organization/);
    await expect(page.locator('meta[property="og:description"]')).toHaveAttribute('content', 'Presentazione di prova');
    await page.getByRole('button', { name: 'Condividi profilo' }).click();
    await expect(page.locator('[data-copy-fallback] input')).toBeVisible();
    await expect(page.locator('[data-copy-fallback] input')).toHaveValue(/\/it\/org\/e2e-organization-verified$/);
    const report = await page.getByRole('link', { name: 'Segnala questa pagina' }).getAttribute('href');
    expect(report).toContain('e2e-organization-verified');
    expect(report).not.toContain('PRIVATE');
    await expect(page.getByRole('link', { name: 'Sostieni questa organizzazione' })).toHaveAttribute('rel', /noopener/);
});

test('native share is preferred when supported', async ({ page }) => {
    await page.addInitScript(() => Object.defineProperty(navigator, 'share', { value: async (data) => { window.sharedProfile = data; } }));
    await page.goto('/en/org/e2e-organization-verified');
    await page.getByRole('button', { name: 'Share profile' }).click();
    expect(await page.evaluate(() => window.sharedProfile.url)).toContain('/en/org/e2e-organization-verified');
});

for (const state of ['pending', 'rejected', 'suspended']) {
    test(`${state} profile is unavailable publicly`, async ({ page }) => {
        const response = await page.request.get(`/en/org/e2e-organization-${state}`);
        expect(response.status()).toBe(404);
        expect(await response.text()).not.toContain('PRIVATE TEST EVIDENCE');
    });
}

test('public applications closed and organization navigation usable', async ({ page }) => {
    await page.goto('/en/organizations');
    await expect(page.getByText('Applications are not open yet.')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Organization terms', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Apply for an organization profile' })).toHaveCount(0);
});

test.describe('organization admin', () => {
    test.use({ storageState: ADMIN_STATE });
    test('list and private review render on mobile', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 900 });
        await page.goto('/admin/organizations?status=pending');
        await page.getByRole('link', { name: 'Test organization — pending' }).click();
        await expect(page.getByText('Private verification reference: PRIVATE TEST EVIDENCE')).toBeVisible();
        await expect(page.getByText('Personal petition authorship and editing rights do not transfer.', { exact: false })).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(false);
    });
});


test('smaller font preserves Greek and Cyrillic fallback', async ({ page }) => {
    await page.goto('/en/org/e2e-organization-images');
    const fullFont = page.waitForResponse((response) => response.url().endsWith('/fonts/Inter/InterVariable.woff2') && response.status() === 200);
    await page.getByRole('heading', { level: 1 }).evaluate((heading) => { heading.textContent = 'Ελληνικά Русский Polski Türkçe'; });
    await fullFont;
    await page.evaluate(() => document.fonts.ready);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Ελληνικά Русский Polski Türkçe');
});


for (const state of ['pending', 'rejected', 'suspended']) {
    test(`owner dashboard in ${state} state on mobile`, async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 900 });
        await login(page, 'owner@e2e.test', 'password');
        await page.goto(`/en/org/e2e-organization-${state}/manage`);
        await expect(page.getByRole('heading', { level: 1 })).toHaveText(`Test organization — ${state}`);
        if (state === 'suspended') await expect(page.getByRole('link', { name: 'Edit profile' })).toHaveCount(0);
        else await expect(page.getByRole('link', { name: 'Edit profile' })).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(false);
    });
}


test('public profile mobile menu and language controls work without legacy widgets', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 900 });
    await page.goto('/en/org/e2e-organization-images');
    await page.locator('[data-sheet-open="mobile-menu"]:visible').first().click();
    await expect(page.locator('#mobile-menu')).toBeVisible();
    await expect(page.locator('#mobile-menu').getByRole('link', { name: 'For organizations' })).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.locator('#mobile-menu')).not.toBeVisible();
    await page.locator('nav details:visible summary').first().click();
    await expect(page.locator('nav details[open]')).toBeVisible();
});
