import { test, expect, uniq, db, newUser, createPetition, guestSign, petitionRow, userToken, setRequireVerification } from './helpers.js';

test.describe.configure({ mode: 'serial' });

let petition;

test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    await setRequireVerification(page, true);
    await newUser(page, 'signown');
    petition = await createPetition(page, uniq('E2E Sign '));
    await page.close();
});

test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    await setRequireVerification(page, false);
    await page.close();
});

test('guest signature stays pending until the email is verified, then counts', async ({ page }) => {
    const email = `${uniq('guest')}@e2e.test`;
    await guestSign(page, petition, { name: 'Gus Guest', email });
    await expect(page).toHaveURL(/\/thanks/);
    expect(db(`select confirmed from signatures where email='${email}'`)).toBe('0');
    expect(petitionRow(petition)).toBe('1');

    await page.goto(`/en/verify/${userToken(email)}`);
    await page.getByRole('button', { name: /verify email/i }).click();
    expect(db(`select confirmed from signatures where email='${email}'`)).toBe('1');
    expect(petitionRow(petition)).toBe('2');
});

test('signing twice with the same email gives the same response and one signature', async ({ page }) => {
    const email = `${uniq('dup')}@e2e.test`;
    await guestSign(page, petition, { name: 'Dup Guest', email });
    await expect(page).toHaveURL(/\/thanks/);
    await guestSign(page, petition, { name: 'Dup Guest', email });
    await expect(page).toHaveURL(/\/thanks/);
    expect(db(`select count(*) from signatures where email='${email}'`)).toBe('1');
});

test('a logged-in user signs with one click-through and is counted', async ({ page }) => {
    const before = Number(petitionRow(petition));
    const email = await newUser(page, 'authsign');
    await page.goto(petition);
    await page.locator('[data-sign-cta]:visible').first().click();
    const form = page.locator('form[data-sign-busy]').first();
    for (const n of ['agree1', 'agree2', 'agree3']) await form.locator(`input[name=${n}]:visible`).first().check();
    await form.locator('[type=submit]:visible').first().click();
    await page.getByRole('button', { name: /^sign/i }).first().click();
    await expect(page).toHaveURL(/\/thanks/);
    expect(db(`select confirmed from signatures where email='${email}'`)).toBe('1');
    expect(petitionRow(petition)).toBe(String(before + 1));
});
