const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function setup(opts = {}) {
    const handlers = {};
    const error = { hidden: true, textContent: 'connection error' };
    const toastCalls = [];
    let appended = 0;
    const button = { disabled: false, style: { opacity: '' }, attrs: {}, children: [],
        setAttribute(k, v) { this.attrs[k] = v; },
        removeAttribute(k) { delete this.attrs[k]; },
        querySelector() { return null; },
        prepend(node) { this.children.push(node); } };
    const form = { tagName: 'FORM', method: 'post', attrs: {},
        hasAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attrs, name); },
        getAttribute(name) { return name in this.attrs ? this.attrs[name] : null; },
        setAttribute(k, v) { this.attrs[k] = v; },
        removeAttribute(k) { delete this.attrs[k]; },
        querySelector(selector) { return selector.includes('network-error') ? error : button; },
        querySelectorAll() { return [error]; },
        appendChild() { appended += 1; } };
    button.form = form;
    const navigator = { onLine: true };
    const clientToast = opts.toast ? { error(msg) { toastCalls.push(msg); } } : undefined;
    vm.runInNewContext(fs.readFileSync('public/legacy/js-v2/form-busy.js', 'utf8'), {
        Map, navigator,
        document: { body: { getAttribute() { return null; } }, addEventListener(k, fn) { handlers[k] = fn; },
            createElement() { return { setAttribute() {}, remove() { button.children.pop(); } }; },
            querySelectorAll() { return []; } },
        window: { addEventListener(k, fn) { handlers[k] = fn; }, clientToast },
    });
    function submit(defaultPrevented = false, submitter = button) {
        const event = { target: form, submitter, defaultPrevented,
            preventDefault() { this.defaultPrevented = true; } };
        handlers.submit(event);
        return event;
    }
    return { handlers, form, button, navigator, error, submit, toastCalls, appended: () => appended };
}

test('native POST proceeds once with spinner, dimming and busy semantics', () => {
    const s = setup();
    assert.equal(s.submit().defaultPrevented, false);
    assert.equal(s.button.disabled, true);
    assert.equal(s.button.children.length, 1);
    assert.equal(s.button.children[0].className, 'fc-sign-spinner');
    assert.equal(s.button.attrs['aria-busy'], 'true');
    assert.equal(s.button.style.opacity, '0.65');
    assert.equal(s.submit().defaultPrevented, true);
});
test('guest validation cancellation leaves the sign button usable', () => {
    const s = setup(); s.submit(true);
    assert.equal(s.button.disabled, false);
    assert.equal(s.button.children.length, 0);
});
test('unrelated forms and foreign submitters are unchanged', () => {
    const s = setup();

    s.form.method = 'get';
    s.submit();
    assert.equal(s.button.disabled, false);

    s.form.method = 'post';
    s.form.attrs['data-busy-ignore'] = '';
    s.submit();
    assert.equal(s.button.disabled, false);

    delete s.form.attrs['data-busy-ignore'];
    s.submit(false, { form: {} });
    assert.equal(s.button.disabled, false);
});
test('offline request stays on the form with error and can retry online', () => {
    const s = setup(); s.navigator.onLine = false;
    assert.equal(s.submit().defaultPrevented, true);
    assert.equal(s.button.disabled, false);
    assert.equal(s.error.hidden, false);
    s.navigator.onLine = true;
    assert.equal(s.submit().defaultPrevented, false);
    assert.equal(s.error.hidden, true);
});
test('localised error text is preserved and data-network-error-text still wins', () => {
    const s = setup();
    s.error.textContent = 'Baglanti hatasi. Yeniden deneyin.';
    s.navigator.onLine = false;
    s.submit();
    assert.equal(s.error.textContent, 'Baglanti hatasi. Yeniden deneyin.');

    s.form.attrs['data-network-error-text'] = 'Offline now.';
    s.submit();
    assert.equal(s.error.textContent, 'Offline now.');
});
test('offline POST with toast available fires one toast and keeps inline error hidden', () => {
    const s = setup({ toast: true });
    s.error.textContent = 'Baglanti hatasi. Yeniden deneyin.';
    s.navigator.onLine = false;
    assert.equal(s.submit().defaultPrevented, true);
    assert.deepEqual(s.toastCalls, ['Baglanti hatasi. Yeniden deneyin.']);
    assert.equal(s.error.hidden, true);
    assert.equal(s.appended(), 0);
});
test('back navigation restores busy button and supports keyboard submit', () => {
    const s = setup(); s.submit(); s.handlers.pageshow();
    assert.equal(s.button.disabled, false);
    assert.equal(s.button.children.length, 0);
    assert.equal(s.button.style.opacity, '');
    assert.equal(s.button.attrs['aria-busy'], undefined);
    assert.equal(s.submit(false, null).defaultPrevented, false);
    assert.equal(s.button.disabled, true);
});
