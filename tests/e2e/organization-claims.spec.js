import AxeBuilder from '@axe-core/playwright';
import { test, expect, login, adminLogin, db } from './helpers.js';

const copy = {
    en: ['Find your organization', 'Claim your organization'],
    it: ['Trova la tua organizzazione', 'Rivendica la tua organizzazione'],
    fr: ['Trouvez votre organisation', 'Revendiquez votre organisation'],
};
for (const width of [375, 1280]) {
    for (const [locale, [search, claim]] of Object.entries(copy)) {
        test(`organization claim landing ${locale} at ${width}px`, async ({ page }) => {
            await page.setViewportSize({ width, height: 900 });
            await page.goto(`/${locale}/organizations`);
            await expect(page.getByRole('searchbox', { name: search, exact: true })).toBeVisible();
            await expect(page.getByRole('button', { name: search, exact: true })).toBeVisible();
            if (width === 375) {
                const action = page.locator('[data-bottom-nav]').getByRole('link', { name: claim, exact: true });
                await expect(action).toBeVisible();
                await expect(action).toHaveAttribute('href', new RegExp(`/${locale}/organizations/claim-search$`));
                const widths = await page.locator('[data-bottom-nav] > a, [data-bottom-nav] > button').evaluateAll((items) => items.map((item) => item.getBoundingClientRect().width));
                expect(Math.max(...widths) - Math.min(...widths)).toBeLessThan(1.1);
            }
            expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(false);
            const result = await new AxeBuilder({ page }).include('section.container').withTags(['wcag2a', 'wcag2aa']).analyze();
            expect(result.violations.filter((v) => ['serious', 'critical'].includes(v.impact))).toEqual([]);
        });
    }
}

test('public search leads to an unclaimed page and protected claim form', async ({ page }) => {
    await page.goto('/en/organizations');
    await page.getByRole('searchbox', { name: 'Find your organization', exact: true }).fill('public test group');
    await page.getByRole('button', { name: 'Find your organization', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Unclaimed public test group', exact: true })).toBeVisible();
    await page.getByRole('link', { name: 'Claim this page', exact: true }).click();
    await expect(page).toHaveURL(/\/en\/login$/);
    await page.goto('/en/org/e2e-organization-unclaimed');
    await expect(page.locator('article')).toContainText('Unclaimed');
    await expect(page.locator('[data-organization-donation]')).toHaveCount(0);
    await expect(page.locator('[data-organization-share]')).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Is this your organization? Claim it', exact: true })).toBeVisible();
});

test('claim submits for review and admin rejection restores the unclaimed listing', async ({ page }) => {
    const orgId = db("select id from organizations where slug='e2e-organization-unclaimed'");
    try {
        await login(page, 'owner@e2e.test', 'password');
        await page.goto('/en/org/e2e-organization-unclaimed/claim');
        await page.getByLabel('Organization type', { exact: true }).selectOption('association');
        await page.getByLabel('Private verification reference', { exact: true }).fill('PRIVATE CLAIM BROWSER TEST');
        await page.locator('input[name=representation_confirmed]').check();
        await page.locator('input[name=terms_accepted]').check();
        await page.getByRole('button', { name: 'Claim this page', exact: true }).click();
        await expect(page).toHaveURL(/\/en\/org\/e2e-organization-unclaimed\/manage$/);
        expect(db(`select status from organizations where id=${orgId}`)).toBe('pending');
        expect(db(`select claimed_by_user_id=owner_user_id and claimed_at is not null from organizations where id=${orgId}`)).toBe('1');
        await adminLogin(page);
        await page.goto(`/admin/organizations/${orgId}`);
        const reject = page.locator(`form[action$='/${orgId}/reject']`);
        await reject.locator('textarea[name=reason]').fill('Insufficient browser-test evidence');
        await reject.locator('input[name=confirm]').check();
        await reject.getByRole('button', { name: 'Reject', exact: true }).click();
        expect(db(`select status from organizations where id=${orgId}`)).toBe('unclaimed');
        expect(db(`select owner_user_id is null and claimed_by_user_id is null and claimed_at is null from organizations where id=${orgId}`)).toBe('1');
        await page.goto('/en/org/e2e-organization-unclaimed');
        await expect(page.locator('article')).not.toContainText('PRIVATE CLAIM');
        await expect(page.locator('article')).toContainText('Unclaimed');
    } finally {
        db(`delete from organization_notifications where organization_id=${orgId}`);
        db(`delete from organization_events where organization_id=${orgId}`);
        db(`update organizations set status='unclaimed',owner_user_id=null,claimed_by_user_id=null,claimed_at=null,representation_confirmed_at=null,terms_accepted_at=null,terms_version=null,verification_reference='',verification_note=null,rejection_reason=null where id=${orgId}`);
    }
});
