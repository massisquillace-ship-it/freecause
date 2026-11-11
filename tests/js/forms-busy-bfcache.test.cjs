const { test } = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const { pathToFileURL } = require('node:url');

const sourcePath = path.join(process.cwd(), 'resources/js/public/forms.js');

test('pageshow with persisted restores the busy button and a second submit re-applies busy state', async (t) => {
    const label = { textContent: 'Log in' };
    const attrs = {};
    const button = {
        dataset: { loadingText: 'Working...' },
        disabled: false,
        offsetParent: {},
        type: 'submit',
        querySelector: (sel) => (sel === '.fcui-btn__label' ? label : null),
        setAttribute(k, v) { attrs[k] = v; },
        removeAttribute(k) { delete attrs[k]; },
    };
    const handlers = {};
    const form = {
        dataset: {},
        querySelector: (sel) => (sel === '[data-loading]' ? button : null),
        querySelectorAll: (sel) => (sel === '[data-loading]' ? [button] : []),
        addEventListener(type, fn) { handlers[type] = fn; },
    };
    const pageshow = [];
    const origDoc = globalThis.document;
    const origWin = globalThis.window;
    globalThis.document = {
        documentElement: { dataset: {}, addEventListener() {} },
        addEventListener() {},
        querySelectorAll: (sel) => (sel === 'form' ? [form] : []),
        querySelector: () => null,
        getElementById: () => null,
    };
    globalThis.window = {
        setTimeout,
        addEventListener(type, fn) { if (type === 'pageshow') pageshow.push(fn); },
    };
    t.after(() => {
        if (origDoc === undefined) delete globalThis.document; else globalThis.document = origDoc;
        if (origWin === undefined) delete globalThis.window; else globalThis.window = origWin;
    });

    const mod = await import(pathToFileURL(sourcePath).href + '?t=' + Date.now());
    mod.init();

    const submit = async () => {
        handlers.submit({ defaultPrevented: false });
        await new Promise((r) => setTimeout(r, 5));
    };

    await submit();
    assert.equal(label.textContent, 'Working...');
    assert.equal(button.disabled, true);
    assert.equal(attrs['aria-busy'], 'true');

    pageshow.forEach((fn) => fn({ persisted: false }));
    assert.equal(label.textContent, 'Working...');

    pageshow.forEach((fn) => fn({ persisted: true }));
    assert.equal(label.textContent, 'Log in');
    assert.equal(button.disabled, false);
    assert.equal(attrs['aria-busy'], undefined);
    assert.equal(button.dataset.fcLoadingActive, undefined);

    await submit();
    assert.equal(label.textContent, 'Working...');
    assert.equal(button.disabled, true);
    assert.equal(attrs['aria-busy'], 'true');

    pageshow.forEach((fn) => fn({ persisted: true }));
    assert.equal(label.textContent, 'Log in');
});
