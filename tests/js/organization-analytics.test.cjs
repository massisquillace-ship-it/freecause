const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function run({ source = '', consent = true } = {}) {
    const calls = [];
    const listeners = {};
    const gtag = (...args) => calls.push(args);
    const window = { gtag, FCAnalyticsAllowed: consent };
    const context = { window, gtag, URL, URLSearchParams,
        location: { pathname: '/it/org/test-org', search: source },
        document: { prerendering: false, referrer: 'https://referrer.example/private/path?secret=value', addEventListener: (name, fn) => { listeners[name] = fn; } },
    };
    vm.runInNewContext(fs.readFileSync('public/js/analytics.js', 'utf8'), context);
    return { calls, listeners, window };
}

test('badge arrivals retain only a referrer hostname in config and event', () => {
    const { calls } = run({ source: '?source=org-badge' });
    assert.equal(calls.find((c) => c[0] === 'config')[2].page_referrer, 'referrer.example');
    const event = calls.find((c) => c[1] === 'organization_badge_arrival');
    assert.equal(event[2].referrer_domain, 'referrer.example');
    assert.equal(event[2].organization_slug, 'test-org');
    assert.equal(JSON.stringify(calls).includes('secret'), false);
});

test('donation events stop after analytics consent is withdrawn', () => {
    const { calls, listeners, window } = run();
    const target = { closest: () => ({ getAttribute: () => 'test-org' }) };
    listeners.click({ target });
    assert.equal(calls.filter((c) => c[1] === 'organization_donation_click').length, 1);
    window.FCAnalyticsAllowed = false;
    listeners.click({ target });
    assert.equal(calls.filter((c) => c[1] === 'organization_donation_click').length, 1);
    assert.equal(run({ consent: false, source: '?source=org-badge' }).calls.length, 0);
});
