import AxeBuilder from '@axe-core/playwright';
import { test, expect, PETITION } from './helpers.js';
import { ADMIN_STATE } from './env.js';

const PUBLIC = [
    '/en',
    '/en/petitions',
    PETITION,
    '/en/create-petition',
    '/en/login',
    '/en/register',
    '/__components',
    '/de/login',
    '/el/petitions',
    '/ru/create-petition',
    '/en/forgot-password',
    `${PETITION}/thanks`,
    '/en/organizations',
    '/en/organizations/explore',
    '/en/organizations/terms',
];
const ENGLISH_LABELS = ['Pagination', 'Previous page', 'Next page', 'Breadcrumb', 'Dismiss', 'Close'];
const ADMIN = [
    '/admin/dashboard',
    '/admin/options/global',
    '/admin/options/language',
    '/admin/ads',
    '/admin/users',
    '/admin/reports',
    '/admin/petitions',
    '/admin/petitions?select=1&locale=en',
    '/admin/categories',
    '/admin/signatures',
    '/admin/pages',
    '/admin/keywords',
    '/admin/spam',
    '/admin/stats',
    '/admin/logs',
    '/admin/languages',
    '/admin/system/user-info',
    '/admin/system/user-levels',
    '/admin/system/permissions',
];
const WIDTHS = [375, 1280];

async function check(page, path, width) {
    await page.setViewportSize({ width, height: 800 });
    const res = await page.goto(path);
    expect(res.status(), `status of ${path}`).toBe(200);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow, `horizontal overflow on ${path} at ${width}px`).toBeLessThanOrEqual(0);
    const { violations } = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
    const blocking = violations
        .filter((v) => ['serious', 'critical'].includes(v.impact))
        .map((v) => `${v.id}: ${v.nodes.slice(0, 3).map((n) => n.target.join(' ')).join(' , ')}`);
    expect(blocking, `${path} at ${width}px`).toEqual([]);
    if (/^\/(de|el|ru)\//.test(path)) {
        const english = await page.evaluate(
            (labels) => [...document.querySelectorAll('[aria-label]')].map((el) => el.getAttribute('aria-label')).filter((l) => labels.includes(l)),
            ENGLISH_LABELS,
        );
        expect(english, `English aria-label on ${path}`).toEqual([]);
    }
}

for (const width of WIDTHS) {
    for (const path of PUBLIC) {
        test(`public ${path} at ${width}px`, async ({ page }) => check(page, path, width));
    }
}

test.describe('admin pages', () => {
    test.use({ storageState: ADMIN_STATE });
    for (const width of WIDTHS) {
        for (const path of ADMIN) {
            test(`admin ${path} at ${width}px`, async ({ page }) => check(page, path, width));
        }
    }
});
