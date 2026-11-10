import { test as base, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { E2E_DB } from './env.js';

const ORIGIN = 'http://127.0.0.1:8099';
// The petition editor (Quill) is CDN-only; allow just that pinned path, block every other external host.
const QUILL_CDN = 'https://cdn.jsdelivr.net/npm/quill@1.3.7/';

export const test = base.extend({
    page: async ({ page }, use) => {
        // Ordinary workflows start after an explicit essential-only choice.
        // cookie-consent.spec.js clears this cookie to exercise first-visit consent.
        const chosenAt = new Date().toISOString();
        await page.context().addCookies([{
            name: 'fc_cookie_consent', url: ORIGIN,
            value: encodeURIComponent(JSON.stringify({
                categories: ['necessary'], revision: 1,
                data: null, consentTimestamp: chosenAt, lastConsentTimestamp: chosenAt,
                consentId: '11111111-1111-4111-8111-111111111111',
                services: { necessary: [], analytics: [], advertising: [] },
                languageCode: 'en',
            })),
            expires: Math.floor(Date.now() / 1000) + 86400,
        }]);
        const errors = [];
        await page.route('**/*', (route) =>
            [ORIGIN, 'data:', QUILL_CDN].some((allowed) => route.request().url().startsWith(allowed)) ? route.continue() : route.abort(),
        );
        page.on('pageerror', (e) => {
            if (!e.message.includes('ViewTransition')) errors.push(`pageerror: ${e.message}`);
        });
        page.on('console', (m) => {
            if (m.type() === 'error' && !m.text().includes('net::ERR_FAILED')) errors.push(`console: ${m.text()}`);
        });
        page.on('response', (r) => {
            if (r.url().startsWith(ORIGIN) && r.status() >= 500) errors.push(`${r.status()}: ${r.url()}`);
        });
        await use(page);
        expect(errors).toEqual([]);
    },
});

export { expect };

export const PETITION = '/en/petition/protect-the-city-park/1';

export async function adminLogin(page, login = 'admin@e2e.test') {
    await page.goto('/admin/login');
    await page.locator('[name=login]').fill(login);
    await page.locator('[name=password]').fill('e2e-password');
    await page.locator('[name=password]').press('Enter');
    await expect(page).toHaveURL(/\/admin(\/|$)/);
    await expect(page).not.toHaveURL(/login/);
}

export const PASSWORD = 'e2e-Password-1';

export const uniq = (prefix) => `${prefix}${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;

export const db = (sql) => execFileSync('sqlite3', ['-cmd', '.timeout 5000', E2E_DB, sql], { encoding: 'utf8' }).trim();

export const userToken = (email) => {
    if (!db(`select verification_token from users where email='${email}'`)) return '';
    const token = uniq('vt');
    const hash = createHash('sha256').update(token).digest('hex');
    db(`update users set verification_token='${hash}' where email='${email}'`);
    return token;
};

export async function setRequireVerification(page, on) {
    await adminLogin(page);
    await page.goto('/admin/options/global');
    await page.locator('input[type=checkbox][name=require_email_verification]').setChecked(on);
    await page.getByRole('button', { name: /^save/i }).click();
    await expect.poll(() => db("select value from settings where key='require_email_verification'")).toBe(on ? 'true' : 'false');
}

export async function register(page, { email, password = PASSWORD, name = 'Eve', surname = 'Tester' }) {
    await page.goto('/en/register');
    await page.locator('input[name=name]').fill(name);
    await page.locator('input[name=surname]').fill(surname);
    await page.locator('input[name=email]').fill(email);
    await page.locator('input[name=password]').fill(password);
    await page.locator('input[name=agree_terms]').check();
    await page.locator('form:has(input[name=agree_terms]) [type=submit]:visible').first().click();
}

export async function login(page, email, password = PASSWORD) {
    await page.goto('/en/login');
    if ((await page.locator('#login_email').count()) === 0) return;
    await page.locator('#login_email').fill(email);
    await page.locator('#login_password').fill(password);
    await page.locator('#login_password').press('Enter');
}

// Error alerts are turned into toasts and hidden, so wait for the server-rendered alert to be attached.
export async function expectLoginRefused(page, email, password = PASSWORD) {
    await login(page, email, password);
    await expect(page.locator('[data-alert-variant=error]').first()).toBeAttached();
    await expect(page).toHaveURL(/\/login/);
}

export async function newUser(page, prefix = 'u') {
    const email = `${uniq(prefix)}@e2e.test`;
    await register(page, { email });
    const token = userToken(email);
    if (token) {
        await page.goto(`/en/verify/${token}`);
        await page.getByRole('button', { name: /verify email/i }).click();
    }
    await login(page, email);
    await page.goto('/en/profile');
    await page.locator('#country').selectOption({ index: 1 });
    await page.locator('#gender').selectOption({ index: 1 });
    await page.locator('#locale').selectOption('en');
    await page.locator('form[action$="/en/profile"] [type=submit]:visible').first().click();
    await expect.poll(() => db(`select country from users where email='${email}'`)).not.toBe('');
    return email;
}

export async function createPetition(page, title) {
    await page.goto('/en/create-petition');
    await page.locator('input[name=title]').fill(title);
    const editor = page.locator('#petition_editor .ql-editor[contenteditable=true]');
    await expect(editor).toBeEditable();
    await editor.fill('E2E petition body. '.repeat(12));
    await expect(page.locator('#petition_description')).toHaveValue(/E2E petition body/);
    await page.locator('select[name=goal_signatures]').selectOption({ index: 1 });
    await page.locator('select[name=category_id]').selectOption({ index: 1 });
    await page.locator('form[data-petition-form] [type=submit]:visible').first().click();
    await expect(page).toHaveURL(/\/thanks/);
    return new URL(page.url()).pathname.replace(/\/thanks$/, '');
}

export async function guestSign(page, petitionPath, { name, email }) {
    await page.goto(petitionPath);
    const cta = page.locator('[data-sign-cta]:visible').first();
    if (await cta.count()) await cta.click();
    const form = page.locator('form.fc-sign-form');
    await form.locator('[name=name]:visible').first().fill(name);
    await form.locator('[name=email]:visible').first().fill(email);
    for (const n of ['agree1', 'agree2', 'agree3']) await form.locator(`input[type=checkbox][name=${n}]:visible`).first().check();
    await form.locator('[type=submit]:visible').first().click();
    await page.getByRole('button', { name: /^sign/i }).first().click();
}

export const petitionRow = (path) => db(`select signature_count from petitions where id=${path.split('/').pop()}`);

// Role query on purpose: it fails if the dialog is left inside an aria-hidden ancestor (e.g. with a drawer open).
export const confirmDialog = (page) => page.getByRole('alertdialog').getByRole('button', { name: 'Continue' });
