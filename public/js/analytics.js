window.dataLayer = window.dataLayer || [];
window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };

function gtagInit() {
    if (window.FCAnalyticsAllowed === false) return;
    gtag('js', new Date());
    var config = { allow_google_signals: false, allow_ad_personalization_signals: false };
    var profile = location.pathname.match(/^\/[a-z]{2}\/org\/([a-z0-9-]{1,80})\/?$/);
    var badgeArrival = profile && new URLSearchParams(location.search).get('source') === 'org-badge';
    var referrerDomain = '';
    if (badgeArrival) {
        try { referrerDomain = new URL(document.referrer).hostname; } catch (error) { /* No referrer. */ }
        config.page_referrer = referrerDomain;
    }
    gtag('config', 'G-9CYCSY3PWD', config);
    if (badgeArrival) gtag('event', 'organization_badge_arrival', { organization_slug: profile[1], referrer_domain: referrerDomain });
    if (!window.fcOrganizationTrackingBound) {
        window.fcOrganizationTrackingBound = true;
        document.addEventListener('click', function (event) {
            var link = event.target.closest('[data-organization-donation]');
            if (link && window.FCAnalyticsAllowed === true) gtag('event', 'organization_donation_click', { organization_slug: link.getAttribute('data-organization-donation') });
        });
    }
}

if (document.prerendering) {
    document.addEventListener('prerenderingchange', gtagInit, { once: true });
} else {
    gtagInit();
}
