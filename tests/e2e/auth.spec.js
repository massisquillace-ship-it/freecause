import { test, expect, uniq, userToken, db, register, login, setRequireVerification, PASSWORD } from './helpers.js';

test.describe.configure({ mode: 'serial' });

test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    await setRequireVerification(page, true);
    await page.close();
});

test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    await setRequireVerification(page, false);
    await page.close();
});

test('register requires email verification, then verify link logs in', async ({ page }) => {
    const email = `${uniq('reg')}@e2e.test`;
    await register(page, { email });
    await expect(page).toHaveURL(/\/verify-email/);
    expect(db(`select verified from users where email='${email}'`)).toBe('0');

    await login(page, email);
    await expect(page).not.toHaveURL(/\/profile|\/en\/?$/);

    await page.goto(`/en/verify/${userToken(email)}`);
    await page.getByRole('button', { name: /verify email/i }).click();
    expect(db(`select verified from users where email='${email}'`)).toBe('1');
});

test('login with wrong password shows an error', async ({ page }) => {
    await login(page, 'owner@e2e.test', 'definitely-wrong');
    await expect(page).toHaveURL(/\/login/);
});

test('profile saves country, preferred language and gender without touching the password fields', async ({ page }) => {
    const email = `${uniq('prof')}@e2e.test`;
    await register(page, { email });
    await page.goto(`/en/verify/${userToken(email)}`);
    await page.getByRole('button', { name: /verify email/i }).click();
    await login(page, email);
    await page.goto('/en/profile');
    await expect(page.locator('#locale')).toHaveValue('');
    await page.locator('#country').selectOption({ index: 1 });
    await page.locator('#gender').selectOption({ index: 1 });
    await page.locator('#locale').selectOption('en');
    await page.locator('form[action$="/en/profile"] [type=submit]:visible').first().click();
    await expect.poll(() => db(`select country from users where email='${email}'`)).not.toBe('');
    expect(db(`select locale from users where email='${email}'`)).toBe('en_US');
    expect(db(`select preferred_language_confirmed_at from users where email='${email}'`)).not.toBe('');
});
