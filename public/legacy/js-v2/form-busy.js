/** Busy feedback and network-failure handling for native form POSTs. */
(function () {
    'use strict';

    var offlineText = (document.body && document.body.getAttribute('data-offline-text')) || 'You appear to be offline. Reconnect and submit again.';
    var pending = new Map();

    function errorNode(form, selector) {
        var node = selector ? form.querySelector(selector) : null;

        if (!node) {
            node = form.querySelector('[data-network-error]');
        }

        if (!node) {
            node = document.createElement('p');
            node.className = 'fcui-field__error';
            node.setAttribute('role', 'alert');
            node.setAttribute('data-network-error', '');
            node.hidden = true;
            form.appendChild(node);
        }

        return node;
    }

    function messageFor(form, node) {
        return form.getAttribute('data-network-error-text')
            || (node ? node.textContent.trim() : '')
            || offlineText;
    }

    function showError(form, text) {
        if (window.clientToast && typeof window.clientToast.error === 'function') {
            var existing = form.querySelector('[data-sign-network-error]') || form.querySelector('[data-network-error]');
            window.clientToast.error(text || messageFor(form, existing));
            return;
        }
        var node = errorNode(form, '[data-sign-network-error]');
        node.textContent = text || messageFor(form, node);
        node.hidden = false;
    }

    function hideError(form) {
        var nodes = form.querySelectorAll('[data-network-error], [data-sign-network-error]');
        nodes.forEach(function (node) {
            node.hidden = true;
        });
    }

    function restore() {
        pending.forEach(function (state, form) {
            state.button.disabled = false;
            state.button.removeAttribute('aria-busy');
            state.button.style.opacity = state.opacity;
            if (state.spinner) {
                state.spinner.remove();
            }
            form.removeAttribute('aria-busy');
        });
        pending.clear();
    }

    function isEligible(form) {
        if (!form || form.tagName !== 'FORM') {
            return false;
        }
        if (form.getAttribute('target') === '_blank') {
            return false;
        }
        if (form.hasAttribute('data-no-busy') || form.hasAttribute('data-busy-ignore')) {
            return false;
        }
        return form.method.toLowerCase() === 'post';
    }

    // Bubble after the form's guest validation handler has had a chance to cancel.
    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (!isEligible(form) || event.defaultPrevented) {
            return;
        }
        if (pending.has(form)) {
            event.preventDefault();
            return;
        }

        var button = event.submitter || form.querySelector('button[type="submit"]');
        if (!button || button.form !== form || button.disabled) {
            return;
        }

        if (navigator.onLine === false) {
            event.preventDefault();
            showError(form);
            return;
        }

        hideError(form);

        var spinner = null;
        if (!button.querySelector('.fcui-btn__spinner, .fcui-spinner')) {
            spinner = document.createElement('span');
            spinner.className = 'fc-sign-spinner';
            spinner.setAttribute('aria-hidden', 'true');
            button.prepend(spinner);
        }
        pending.set(form, { button: button, spinner: spinner, opacity: button.style.opacity });
        button.style.opacity = '0.65';
        button.setAttribute('aria-busy', 'true');
        form.setAttribute('aria-busy', 'true');
        button.disabled = true;
        // Leave the native POST intact. Do not unlock on a timer: a slow request
        // could still succeed, making an automatic retry a duplicate signature.
    });

    window.addEventListener('offline', function () {
        document.querySelectorAll('form[method="post"]').forEach(function (form) {
            if (form.hasAttribute('data-busy-ignore')) {
                return;
            }
            showError(form);
        });
    });

    window.addEventListener('online', function () {
        document.querySelectorAll('form[method="post"]').forEach(hideError);
    });

    // Back/forward cache restores the original document, including disabled buttons.
    window.addEventListener('pageshow', restore);
})();
