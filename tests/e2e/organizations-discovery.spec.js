import AxeBuilder from '@axe-core/playwright';
import { test, expect, login, db } from './helpers.js';

for (const width of [375, 1280]) {
    test(`organization directory filters and layout at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 900 });
        await page.goto('/en/organizations/explore');
        await expect(page.getByRole('heading', { name: 'Explore organizations', exact: true })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Test organization — verified', exact: true })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Test organization — pending', exact: true })).toHaveCount(0);
        await page.getByLabel('Organization name', { exact: true }).fill('Test organization');
        await page.getByLabel('Country', { exact: true }).selectOption('IT');
        await page.getByRole('button', { name: 'Search', exact: true }).click();
        await expect(page).toHaveURL(/country=IT/);
        await expect(page.getByRole('link', { name: 'Test organization — verified', exact: true })).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(false);
        const result = await new AxeBuilder({ page }).include('section.container').withTags(['wcag2a', 'wcag2aa']).analyze();
        expect(result.violations.filter((v) => ['serious', 'critical'].includes(v.impact))).toEqual([]);
    });
}

test('owner links and unlinks a personal petition without changing its author', async ({ page }) => {
    const orgId = db("select id from organizations where slug='e2e-organization-verified'");
    const ownerId = db('select user_id from petitions where id=2');
    db('update petitions set organization_id=null where id=2');
    try {
        await login(page, 'owner@e2e.test', 'password');
        await page.goto('/en/org/e2e-organization-verified/manage/petitions');
        const attach = page.locator('form').filter({ has: page.locator('input[name="petition_id"][value="2"]') });
        await attach.locator('input[name=confirm]').check();
        await attach.getByRole('button', { name: 'Link a petition', exact: true }).click();
        expect(db('select organization_id from petitions where id=2')).toBe(orgId);
        expect(db('select user_id from petitions where id=2')).toBe(ownerId);
        await page.goto('/en/org/e2e-organization-verified');
        await expect(page.getByRole('link', { name: 'Clean the river', exact: true })).toBeVisible();
        await page.goto('/en/org/e2e-organization-verified/manage/petitions');
        const detach = page.locator('form[action$="/2/detach"]');
        await detach.locator('input[name=confirm]').check();
        await detach.getByRole('button', { name: 'Unlink petition', exact: true }).click();
        expect(db('select coalesce(organization_id,0) from petitions where id=2')).toBe('0');
    } finally {
        db('update petitions set organization_id=null where id=2');
    }
});

test('consent is optional and unchecked, with verified attribution and linked cause', async ({ page }) => {
    const orgId = db("select id from organizations where slug='e2e-organization-verified'");
    const causeId = db("select id from causes where slug='environment'");
    db(`update petitions set organization_id=${orgId} where id=2`);
    db(`insert or ignore into cause_petition(cause_id,petition_id) values(${causeId},2)`);
    try {
        await page.goto('/en/petition/clean-the-river/2');
        await expect(page.getByText('Launched by', { exact: false }).first()).toBeVisible();
        await expect(page.locator('a[href$="/organizations/explore?cause=environment"]')).toHaveCount(1);
        await page.goto('/en/petition/clean-the-river/2/sign/confirm');
        const checkbox = page.locator('input[name=organization_optin]').first();
        await expect(checkbox).toBeVisible();
        await expect(checkbox).not.toBeChecked();
        await expect(checkbox).not.toHaveAttribute('required');
        await expect(page.getByText('Test organization — verified is the recipient', { exact: false }).first()).toBeVisible();
    } finally {
        db('update petitions set organization_id=null where id=2');
        db(`delete from cause_petition where petition_id=2 and cause_id=${causeId}`);
    }
});

test('publish-as offers a clear personal option and preserves the browser draft choice', async ({ page }) => {
    await login(page, 'owner@e2e.test', 'password');
    await page.goto('/en/create-petition');
    const select = page.getByLabel('Publish as', { exact: true });
    await expect(select).toBeVisible();
    await select.selectOption('');
    await expect.poll(() => page.evaluate(() => Object.entries(localStorage).some(([key, value]) => key.startsWith('fc_petition_draft_') && JSON.parse(value).organization_id === ''))).toBe(true);
    await page.reload();
    await expect(select).toHaveValue('');
    await page.evaluate(() => Object.keys(localStorage).filter((key) => key.startsWith('fc_petition_draft_')).forEach((key) => localStorage.removeItem(key)));
});
