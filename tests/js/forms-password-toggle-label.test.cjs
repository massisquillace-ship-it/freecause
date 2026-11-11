const { test } = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const { pathToFileURL } = require('node:url');

const sourcePath = path.join(process.cwd(), 'resources/js/public/forms.js');

async function run(bodyDataset) {
    const attrs = {};
    let click;
    const btn = {
        className: '',
        setAttribute(k, v) { attrs[k] = v; },
        addEventListener(type, fn) { if (type === 'click') click = fn; },
    };
    const wrapper = { className: '', appendChild() {} };
    const input = {
        type: 'password',
        dataset: {},
        hasAttribute: () => false,
        parentElement: {
            classList: { contains: () => true },
        },
    };
    input.parentElement.classList.contains = (c) => c === 'fcui-pwwrap';
    input.parentElement.appendChild = () => {};
    const origDoc = globalThis.document;
    const origWin = globalThis.window;
    globalThis.document = {
        body: { dataset: bodyDataset },
        documentElement: { dataset: {}, addEventListener() {} },
        addEventListener() {},
        createElement: () => btn,
        querySelectorAll: (sel) => (sel === 'input[type="password"]' ? [input] : []),
        querySelector: () => null,
        getElementById: () => null,
    };
    globalThis.window = { setTimeout, addEventListener() {} };
    try {
        const mod = await import(pathToFileURL(sourcePath).href + '?t=' + Date.now() + Math.random());
        mod.init();
        const initial = attrs['aria-label'];
        click();
        const shown = attrs['aria-label'];
        click();
        return [initial, shown, attrs['aria-label']];
    } finally {
        if (origDoc === undefined) delete globalThis.document; else globalThis.document = origDoc;
        if (origWin === undefined) delete globalThis.window; else globalThis.window = origWin;
    }
}

test('toggle uses body data attributes for both states', async () => {
    assert.deepEqual(await run({ pwShow: 'Passwort anzeigen', pwHide: 'Passwort verbergen' }), ['Passwort anzeigen', 'Passwort verbergen', 'Passwort anzeigen']);
});

test('toggle falls back to English without data attributes', async () => {
    assert.deepEqual(await run({}), ['Show password', 'Hide password', 'Show password']);
});
