export function init(ctx) {
    const announce = ctx.announce;
    const prefersReducedMotion = ctx.prefersReducedMotion;

    const recoverPetitionCover = function (image) {
        if (!image?.matches?.('img[data-home-petition-cover]')) return;
        const fallback = image.getAttribute('data-cover-fallback');
        if (!fallback) return;
        // Clear first so an unavailable fallback can never create a retry loop.
        image.removeAttribute('data-cover-fallback');
        image.src = fallback;
    };
    document.addEventListener('error', (event) => recoverPetitionCover(event.target), true);
    document.querySelectorAll('img[data-home-petition-cover]').forEach(function (image) {
        if (image.complete && image.naturalWidth === 0) recoverPetitionCover(image);
    });

    document.addEventListener('click', function (event) {
        const trigger = event.target.closest('[data-close-details]');
        if (!trigger) return;
        const details = trigger.closest('details');
        if (details) details.removeAttribute('open');
    });

    document.addEventListener('click', function (event) {
        const anchorClose = event.target.closest('[data-ad-anchor-close]');
        if (anchorClose) {
            anchorClose.closest('#fc-ad-anchor')?.remove();
            document.querySelector('.fc-ad-anchor-spacer')?.remove();
        }
    });

    window.addEventListener('message', function (e) {
        const h = e.data?.fcAdHeight;
        if (typeof h !== 'number') return;
        document.querySelectorAll('.fc-ad iframe').forEach(function (frame) {
            if (frame.contentWindow !== e.source) return;
            const capped = Math.min(Math.max(h, 0), frame.hasAttribute('data-ad-fluid') ? 320 : 1000);
            frame.style.height = capped + 'px';
            frame.style.maxHeight = capped + 'px';
        });
    });

    document.querySelectorAll('[data-share-petition]').forEach(function (shareLink) {
        shareLink.addEventListener('click', async function (event) {
            if (typeof navigator.share !== 'function') {
                return;
            }

            event.preventDefault();

            try {
                await navigator.share({
                    title: shareLink.dataset.shareTitle,
                    url: shareLink.dataset.shareUrl,
                });
            } catch (error) {
                if (error?.name !== 'AbortError') {
                    window.location.assign(shareLink.href);
                }
            }
        });
    });

    const easeOut = function (t) {
        return 1 - Math.pow(1 - t, 3);
    };

    document.querySelectorAll('[data-countup]').forEach(function (el) {
        const target = Number(el.dataset.countupTo);
        if (!Number.isFinite(target)) return;
        const formatter = new Intl.NumberFormat();
        if (prefersReducedMotion) {
            el.textContent = formatter.format(target);
            return;
        }
        const duration = 900;
        let startTime = null;
        const step = function (now) {
            if (startTime === null) startTime = now;
            const progress = Math.min((now - startTime) / duration, 1);
            const value = Math.round(target * easeOut(progress));
            el.textContent = formatter.format(value);
            if (progress < 1) {
                window.requestAnimationFrame(step);
            } else {
                el.textContent = formatter.format(target);
            }
        };
        window.requestAnimationFrame(step);
    });

    document.querySelectorAll('[data-progress]').forEach(function (el) {
        if (el.dataset.fcProgress === '1') return;
        el.dataset.fcProgress = '1';
        const pct = parseFloat(el.dataset.pct || '0');
        if (prefersReducedMotion) return;
        el.style.width = '0%';
        window.requestAnimationFrame(function () {
            window.requestAnimationFrame(function () {
                el.style.width = pct + '%';
            });
        });
    });
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        document.querySelectorAll('[data-progress]').forEach(function (el) {
            el.style.transition = 'none';
            el.style.width = (parseFloat(el.dataset.pct || '0')) + '%';
        });
    });

    document.querySelectorAll('img[data-blurup]').forEach(function (img) {
        const markLoaded = function () {
            img.classList.add('is-loaded');
        };
        if (img.complete && img.naturalWidth > 0) {
            markLoaded();
        } else {
            img.addEventListener('load', markLoaded, { once: true });
            img.addEventListener('error', markLoaded, { once: true });
        }
    });

    const brandConfettiColors = ['#cc1f1f', '#ff4b4b', '#f6c343', '#2f9e44', '#1971c2', '#ffffff'];

    window.fcConfetti = function (opts) {
        if (prefersReducedMotion) return;
        const options = opts || {};
        const count = Math.max(20, Math.min(120, Number(options.count) || 60));
        const duration = Number(options.duration) || 1200;

        const canvas = document.createElement('canvas');
        canvas.style.position = 'fixed';
        canvas.style.top = '0';
        canvas.style.left = '0';
        canvas.style.width = '100%';
        canvas.style.height = '100%';
        canvas.style.pointerEvents = 'none';
        canvas.style.zIndex = '2147483001';
        const dpr = window.devicePixelRatio || 1;
        const resize = function () {
            canvas.width = window.innerWidth * dpr;
            canvas.height = window.innerHeight * dpr;
        };
        resize();
        document.body.appendChild(canvas);

        const ctx = canvas.getContext('2d');
        if (!ctx) {
            canvas.remove();
            return;
        }
        ctx.scale(dpr, dpr);

        const originX = window.innerWidth / 2;
        const originY = 0;
        const particles = [];
        for (let i = 0; i < count; i++) {
            const angle = (Math.PI / 2) + (Math.random() - 0.5) * Math.PI * 0.9;
            const speed = 4 + Math.random() * 6;
            particles.push({
                x: originX + (Math.random() - 0.5) * 120,
                y: originY,
                vx: Math.cos(angle) * speed,
                vy: Math.sin(angle) * speed + 2,
                size: 5 + Math.random() * 6,
                rot: Math.random() * Math.PI,
                vr: (Math.random() - 0.5) * 0.3,
                color: brandConfettiColors[Math.floor(Math.random() * brandConfettiColors.length)],
            });
        }

        let startTime = null;
        const gravity = 0.18;
        const step = function (now) {
            if (startTime === null) startTime = now;
            const elapsed = now - startTime;
            ctx.clearRect(0, 0, window.innerWidth, window.innerHeight);
            const fade = Math.max(0, 1 - elapsed / duration);
            particles.forEach(function (p) {
                p.vy += gravity;
                p.x += p.vx;
                p.y += p.vy;
                p.rot += p.vr;
                ctx.save();
                ctx.globalAlpha = fade;
                ctx.translate(p.x, p.y);
                ctx.rotate(p.rot);
                ctx.fillStyle = p.color;
                ctx.fillRect(-p.size / 2, -p.size / 2, p.size, p.size * 0.6);
                ctx.restore();
            });
            if (elapsed < duration) {
                window.requestAnimationFrame(step);
            } else {
                canvas.remove();
            }
        };
        window.requestAnimationFrame(step);
    };

    (function () {
        const trigger = document.querySelector('[data-confetti-on-load]');
        if (!trigger) return;
        if (trigger.dataset.fcConfettiFired === '1') return;
        const threshold = Number(trigger.dataset.confettiThreshold);
        if (Number.isFinite(threshold) && threshold <= 0) return;
        trigger.dataset.fcConfettiFired = '1';
        window.fcConfetti();
    })();

    document.querySelectorAll('[data-countup][data-aria-announce]').forEach(function (el) {
        const target = Number(el.dataset.countupTo);
        if (!Number.isFinite(target)) return;
        announce(new Intl.NumberFormat().format(target));
    });

    document.querySelectorAll('[data-skeleton-until-load]').forEach(function (container) {
        if (container.dataset.fcSkeleton === '1') return;
        container.dataset.fcSkeleton = '1';
        container.querySelectorAll('.fcui-skeleton').forEach(function (sk) {
            sk.remove();
        });
        container.querySelectorAll('[data-skeleton-content]').forEach(function (content) {
            content.hidden = false;
            content.removeAttribute('hidden');
        });
    });

    const turnstileContainers = document.querySelectorAll('.cf-turnstile[data-render="explicit"]');

    const renderTurnstile = function () {
        if (!turnstileContainers.length || !window.turnstile) return;
        turnstileContainers.forEach(function (container) {
            if (container.offsetParent === null) return;
            if (container.dataset.fcTurnstileRendered === '1') return;
            container.dataset.fcTurnstileRendered = '1';
            window.turnstile.render(container, { sitekey: container.dataset.sitekey });
        });
    };

    window.fcTurnstileOnload = renderTurnstile;
    document.addEventListener('shown.bs.tab', renderTurnstile);
    renderTurnstile();
}
