export function init() {
    const body = document.body;
    const sheets = Array.from(document.querySelectorAll('dialog[data-sheet]'));

    if (!sheets.length) {
        return;
    }

    let lastTrigger = null;
    let openSheet = null;
    let pushedEntry = false;

    Array.from(document.querySelectorAll('[data-sheet-open]')).forEach(function (opener) {
        const sheet = document.getElementById(opener.dataset.sheetOpen);
        if (!sheet) {
            return;
        }

        opener.setAttribute('aria-controls', sheet.id);
        if (!opener.hasAttribute('aria-expanded')) {
            opener.setAttribute('aria-expanded', 'false');
        }
    });

    function lockScroll() {
        body.classList.add('fc-sheet-open');
    }

    function unlockScroll() {
        if (!document.querySelector('dialog[data-sheet][open]')) {
            body.classList.remove('fc-sheet-open');
        }
    }

    function show(sheet, trigger) {
        if (!sheet || sheet.open) {
            return;
        }

        lastTrigger = trigger || null;

        if (typeof sheet.showModal === 'function') {
            sheet.showModal();
        } else {
            sheet.setAttribute('open', 'open');
        }

        openSheet = sheet;
        lockScroll();

        if (lastTrigger) {
            lastTrigger.setAttribute('aria-expanded', 'true');
        }

        if (!pushedEntry) {
            try {
                history.pushState({ fcSheet: sheet.id }, '', location.href);
                pushedEntry = true;
            } catch (error) {
                pushedEntry = false;
            }
        }

        const focusTarget = sheet.querySelector('[data-sheet-autofocus]') || sheet.querySelector('[data-sheet-close]');
        if (focusTarget) {
            focusTarget.focus({ preventScroll: true });
        }
    }

    function close(sheet, consumeHistory, returnFocus) {
        if (!sheet) {
            return;
        }

        const trigger = lastTrigger;

        if (sheet.open) {
            if (typeof sheet.close === 'function') {
                sheet.close();
            } else {
                sheet.removeAttribute('open');
            }
        }

        openSheet = null;
        unlockScroll();

        if (trigger) {
            trigger.setAttribute('aria-expanded', 'false');
        }

        if (consumeHistory && pushedEntry) {
            pushedEntry = false;
            history.back();
        }

        if (returnFocus !== false && trigger && document.contains(trigger)) {
            trigger.focus({ preventScroll: true });
        }
    }

    document.addEventListener('click', function (event) {
        const opener = event.target.closest('[data-sheet-open]');
        if (opener) {
            event.preventDefault();
            const sheet = document.getElementById(opener.dataset.sheetOpen);
            if (sheet && sheet !== openSheet) {
                if (openSheet) {
                    close(openSheet, false, false);
                }
                show(sheet, opener);
            }
            return;
        }

        const closer = event.target.closest('[data-sheet-close]');
        if (closer) {
            event.preventDefault();
            close(closer.closest('dialog[data-sheet]') || openSheet, true);
            return;
        }

        const link = event.target.closest('a[href]');
        if (event.defaultPrevented || !link || !pushedEntry || !link.closest('dialog[data-sheet][open]')) {
            return;
        }

        const url = new URL(link.href, location.href);
        const samePage = url.pathname + url.search === location.pathname + location.search;
        const plainClick = event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey;
        const sameTab = !link.target || link.target === '_self';

        if (url.origin !== location.origin || !plainClick || !sameTab || link.hasAttribute('download') || (samePage && url.hash)) {
            return;
        }

        event.preventDefault();
        pushedEntry = false;
        location.replace(url.href);
    });

    document.addEventListener('submit', function (event) {
        const form = event.target;
        if (event.defaultPrevented || !pushedEntry || !form.closest || !form.closest('dialog[data-sheet][open]')) {
            return;
        }

        event.preventDefault();
        pushedEntry = false;
        window.addEventListener('popstate', function () {
            form.submit();
        }, { once: true });
        history.back();
    });

    sheets.forEach(function (sheet) {
        sheet.addEventListener('click', function (event) {
            if (event.target === sheet) {
                close(sheet, true);
            }
        });

        sheet.addEventListener('close', function () {
            if (openSheet === sheet) {
                openSheet = null;
                unlockScroll();
            }
        });

        sheet.addEventListener('cancel', function (event) {
            event.preventDefault();
            close(sheet, true);
        });

        const grip = sheet.querySelector('[data-sheet-grip]');
        if (!grip) {
            return;
        }

        let startY = null;

        sheet.addEventListener('touchstart', function (event) {
            startY = event.touches[0].clientY;
        }, { passive: true });

        sheet.addEventListener('touchend', function (event) {
            if (startY === null) {
                return;
            }

            const delta = event.changedTouches[0].clientY - startY;
            startY = null;

            if (delta > 90) {
                close(sheet, true);
            }
        }, { passive: true });
    });

    window.addEventListener('pageshow', function (event) {
        if (event.persisted && openSheet) {
            close(openSheet, false, false);
        }
    });

    window.addEventListener('popstate', function () {
        pushedEntry = false;

        if (openSheet) {
            close(openSheet, false);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && openSheet) {
            close(openSheet, true);
        }
    });
}
