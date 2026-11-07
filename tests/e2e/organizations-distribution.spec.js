import AxeBuilder from '@axe-core/playwright';
import { test, expect, login, db } from './helpers.js';

for (const width of [375, 1280]) {
    test(`outcome management and public disclosure at ${width}px`, async ({ page }) => {
        try {
            await page.setViewportSize({ width, height: 900 });
            await login(page, 'owner@e2e.test', 'password');
            await page.goto('/en/petition/protect-the-city-park/1/outcome');
            await expect(page.getByRole('heading', { name: 'Update campaign outcome' })).toBeVisible();
            await page.getByLabel('Campaign outcome', { exact: true }).selectOption('victory');
            await page.getByLabel('Outcome date', { exact: true }).fill('2026-01-20');
            await page.getByLabel('Summary in the petition’s language (maximum 500 characters)').fill('The council accepted our request.');
            await page.getByRole('button', { name: 'Save outcome', exact: true }).click();
            expect(db('select outcome from petitions where id=1')).toBe('victory');
            expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(false);
            await page.goto('/en/petition/protect-the-city-park/1');
            await expect(page.getByText('Victory reported by the organizer', { exact: true })).toBeVisible();
            await expect(page.getByText('The council accepted our request.', { exact: true })).toBeVisible();
            await page.goto('/en/petition/protect-the-city-park/1/outcome');
            await page.getByLabel('Campaign outcome', { exact: true }).selectOption('open');
            await page.getByLabel('I confirm returning this campaign to Open and clearing its outcome date and summary.').check();
            await page.getByRole('button', { name: 'Save outcome', exact: true }).click();
            expect(db('select outcome from petitions where id=1')).toBe('open');
        } finally {
            db("update petitions set outcome='open',outcome_at=null,outcome_summary=null,outcome_updated_at=null,outcome_updated_by=null where id=1");
        }
    });
}

test('saved badge and copyable HTML fit a mobile management page', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 900 });
    await login(page, 'owner@e2e.test', 'password');
    await page.goto('/it/org/e2e-organization-verified/manage');
    const badge = page.locator('img[src$="/badge.svg"]');
    await expect(badge).toBeVisible();
    await expect.poll(() => badge.evaluate((img) => img.naturalWidth)).toBe(320);
    await expect(page.locator('#organization-embed')).toHaveValue(/source=org-badge/);
    expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(false);
});

test('homepage organization section appears only with three verified profiles', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 900 });
    await page.goto('/en');
    await expect(page.getByRole('heading', { name: 'Organizations making a difference', exact: true })).toHaveCount(0);
    db("update organizations set status='verified' where slug='e2e-organization-pending'");
    try {
        await page.goto('/en');
        const section = page.locator('section[aria-labelledby="home-organizations-title"]');
        await expect(section.getByRole('heading')).toBeVisible();
        await expect(section.getByRole('link', { name: 'See all organizations', exact: true })).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(false);
        const result = await new AxeBuilder({ page }).include('section[aria-labelledby="home-organizations-title"]').withTags(['wcag2a', 'wcag2aa']).analyze();
        expect(result.violations.filter((v) => ['serious', 'critical'].includes(v.impact))).toEqual([]);
    } finally {
        db("update organizations set status='pending' where slug='e2e-organization-pending'");
    }
});
