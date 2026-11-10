const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const SRC = fs.readFileSync('resources/js/public/widgets.js', 'utf8')
    .replace('export function init', 'function init');

function frame({ fluid }) {
    const contentWindow = {};
    return {
        contentWindow,
        style: {},
        hasAttribute(name) {
            return name === 'data-ad-fluid' && fluid;
        },
    };
}

function setup(frames, covers = []) {
    let errorHandler = null;
    const timers = new Map();
    let nextTimer = 0;
    let intersection = null;
    let messageHandler = null;
    const document = {
        addEventListener(type, handler, capture) {
            if (type === 'error') {
                assert.equal(capture, true);
                errorHandler = handler;
            }
        },
        querySelectorAll(sel) {
            if (sel === 'img[data-home-petition-cover]') return covers;
            return sel === '.fc-ad iframe' ? frames : [];
        },
        querySelector() {
            return null;
        },
    };
    const window = {
        setTimeout(fn) { timers.set(++nextTimer, fn); return nextTimer; },
        clearTimeout(id) { timers.delete(id); },
        IntersectionObserver: class {
            constructor(fn) { intersection = fn; }
            observe() {}
            unobserve() {}
        },
        addEventListener(type, fn) {
            if (type === 'message') messageHandler = fn;
        },
        requestAnimationFrame() {},
        location: { assign() {} },
    };
    const sandbox = { document, window, Intl };
    vm.createContext(sandbox);
    vm.runInContext(`${SRC}\n;globalThis.__init = init;`, sandbox);
    sandbox.__init({ announce() {}, prefersReducedMotion: true });
    return {
        revealCover(image) { intersection([{ isIntersecting: true, target: image }]); },
        expireCoverTimers() { for (const fn of timers.values()) fn(); timers.clear(); },
        failCover(image) { errorHandler({ target: image }); },
        fire(height) {
            messageHandler({ data: { fcAdHeight: height }, source: frames[0].contentWindow });
        },
    };
}

test('fluid ad frame caps its resized height at 320', () => {
    const fluid = frame({ fluid: true });
    const s = setup([fluid]);
    s.fire(1000);
    assert.equal(fluid.style.height, '320px');
    assert.equal(fluid.style.maxHeight, '320px');
});

test('non-fluid ad frame keeps the 1000 cap', () => {
    const banner = frame({ fluid: false });
    const s = setup([banner]);
    s.fire(5000);
    assert.equal(banner.style.height, '1000px');
    assert.equal(banner.style.maxHeight, '1000px');
});

test('widgets source has no adsense slot push code', () => {
    assert.equal(/data-adsense/.test(SRC), false);
    assert.equal(/adsbygoogle/.test(SRC), false);
});

function cover({ complete = false, naturalWidth = 0, relevant = true } = {}) {
    const attributes = { 'data-cover-fallback': '/legacy/images/demo-featured.jpg' };
    let replacements = 0;
    let source = 'https://broken.example.com/blog/';
    return { complete, naturalWidth,
        addEventListener(type, fn) { if (type === 'load') this.loaded = fn; },
        matches() { return relevant; },
        getAttribute(name) { return attributes[name] ?? null; },
        removeAttribute(name) { delete attributes[name]; },
        get src() { return source; },
        set src(value) { source = value; replacements += 1; },
        replacements() { return replacements; },
    };
}

test('failed homepage cover uses the local fallback once without a retry loop', () => {
    const image = cover();
    const s = setup([], [image]);
    assert.equal(image.replacements(), 0);
    s.failCover(image);
    assert.equal(image.src, '/legacy/images/demo-featured.jpg');
    assert.equal(image.replacements(), 1);
    s.failCover(image);
    assert.equal(image.replacements(), 1);
});

test('covers that failed before widgets initialized recover; loaded and unrelated images stay intact', () => {
    const early = cover({ complete: true });
    const loaded = cover({ complete: true, naturalWidth: 640 });
    const unrelated = cover({ relevant: false });
    const s = setup([], [early, loaded]);
    assert.equal(early.replacements(), 1);
    assert.equal(loaded.replacements(), 0);
    s.failCover(unrelated);
    assert.equal(unrelated.replacements(), 0);
});

test('offscreen lazy covers keep their source; a stalled visible cover falls back after its deadline', () => {
    const image = cover();
    const s = setup([], [image]);
    s.expireCoverTimers();
    assert.equal(image.replacements(), 0);
    s.revealCover(image);
    s.expireCoverTimers();
    assert.equal(image.replacements(), 1);
});

test('a cover that loads before its deadline keeps the original image', () => {
    const image = cover();
    const s = setup([], [image]);
    s.revealCover(image);
    image.complete = true;
    image.naturalWidth = 640;
    image.loaded();
    s.expireCoverTimers();
    assert.equal(image.replacements(), 0);
});
