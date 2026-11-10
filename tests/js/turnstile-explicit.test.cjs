const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const SRC = fs.readFileSync('resources/js/public/widgets.js', 'utf8')
    .replace('export function init', 'function init');

function container({ sitekey, visible, explicit = true }) {
    return {
        offsetParent: visible ? {} : null,
        dataset: explicit ? { sitekey, render: 'explicit' } : { sitekey },
    };
}

function setup(containers, { turnstile = true } = {}) {
    const renderCalls = [];
    let tabHandler = null;
    const document = {
        addEventListener(type, fn) {
            if (type === 'shown.bs.tab') tabHandler = fn;
        },
        querySelectorAll(sel) {
            if (sel === '.cf-turnstile[data-render="explicit"]') {
                return containers.filter((c) => c.dataset.render === 'explicit');
            }
            return [];
        },
        querySelector() {
            return null;
        },
    };
    const window = {
        addEventListener() {},
        requestAnimationFrame() {},
        location: { assign() {} },
    };
    if (turnstile) {
        window.turnstile = {
            render(el, options) {
                renderCalls.push({ el, options });
            },
        };
    }
    const sandbox = { document, window, Intl };
    vm.createContext(sandbox);
    vm.runInContext(`${SRC}\n;globalThis.__init = init;`, sandbox);
    sandbox.__init({ announce() {}, prefersReducedMotion: true });
    return {
        renderCalls,
        onload: () => sandbox.window.fcTurnstileOnload(),
        shown: () => tabHandler(),
    };
}

test('visible opt-in container renders once with its sitekey', () => {
    const el = container({ sitekey: 'key-abc', visible: true });
    const s = setup([el]);
    assert.equal(s.renderCalls.length, 1);
    assert.equal(s.renderCalls[0].el, el);
    assert.equal(s.renderCalls[0].options.sitekey, 'key-abc');
});

test('hidden container renders on shown.bs.tab once it is visible', () => {
    const el = container({ sitekey: 'key-abc', visible: false });
    const s = setup([el]);
    assert.equal(s.renderCalls.length, 0);
    el.offsetParent = {};
    s.shown();
    assert.equal(s.renderCalls.length, 1);
    assert.equal(s.renderCalls[0].options.sitekey, 'key-abc');
});

test('a second call does not render again', () => {
    const el = container({ sitekey: 'key-abc', visible: true });
    const s = setup([el]);
    s.onload();
    s.shown();
    assert.equal(s.renderCalls.length, 1);
});

test('a container without data-render="explicit" is never rendered', () => {
    const el = container({ sitekey: 'key-abc', visible: true, explicit: false });
    const s = setup([el]);
    s.onload();
    s.shown();
    assert.equal(s.renderCalls.length, 0);
    assert.equal(SRC.includes('.cf-turnstile[data-render="explicit"]'), true);
});

test('no-op when window.turnstile is absent', () => {
    const el = container({ sitekey: 'key-abc', visible: true });
    const s = setup([el], { turnstile: false });
    s.onload();
    s.shown();
    assert.equal(s.renderCalls.length, 0);
});
