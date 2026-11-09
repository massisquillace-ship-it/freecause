# Homepage rework

Implementation reference: [owner brief and gate clarification](specs/homepage-organizations-first.md).

## Data and visibility

- The only directory gate is `organizations.min_directory_count`, default 20 public verified/unclaimed organizations. Navigation, promotional claims, organization cards and causes share it. `public_launch` continues to govern organization indexing and application access only.
- Below the gate, the hero and primary header action promote personal petitions. Campaigns, victories, how it works, magazine and footer retain their independent visibility rules. Individual creation remains accessible above the gate too, including the mobile bar.
- Organization cards require at least three public records. Weekly ranking requires three qualifying organizations with at least 20 recent signatures; otherwise the total-signatures fallback is labeled “Most active”. Country-specific weekly headings use that country's organizations; the global fallback does not claim local growth.
- Campaigns require at least 50 total signatures and three eligible records. Use weekly counters, otherwise most signed campaigns created within the last year. Both personal and organization-attributed campaigns participate. Victories are newest first and disappear when there are none.
- Proof appears at 50 public organizations, the verified trust count at 10 verified organizations, and the claim banner at 20 unclaimed organizations in addition to the shared gate. Zero-count causes are omitted.

`RefreshHomepageMomentum` runs hourly as a unique queued job. It counts only confirmed, nonspam signatures dated within the previous seven days, using existing date indexes; it resets stale counters and updates petition/organization counters transactionally. Public requests never aggregate raw signatures. Homepage payloads are cached for ten minutes per locale/country with existing home/sitemap invalidation versions. Public author identity is loaded in one fresh batch per request so nickname changes and bulk account deletion cannot leave stale names in a cached campaign card.

## Editorial magazine

Magazine accounts remain separate from platform accounts and memberships. The existing WordPress database integration reads optional post custom fields:

| Custom field | Value |
| --- | --- |
| `freecause_locale` | Supported short locale, such as `en`, `it` or `fr`. Without it, the WordPress site `WPLANG` applies; empty `WPLANG` means English. |
| `related_petition_id` | Optional numeric platform petition ID. Show the related line only when that petition is public and has a translation in the current locale. |

The block requires three published posts in the current locale; there is no cross-language fallback. Existing card markup, image ratio, lazy loading and blur-up are retained. Add/edit these mappings through WordPress's existing post custom fields; no WordPress membership, account synchronization or new plugin is introduced.

## Validation — 9 November 2026

Empty, sparse and full fixtures cover thresholds, personal/organization campaigns, literal organization search, existing petition search, country context, weekly refresh, nickname changes, missing nicknames, account deletion and public-only magazine mappings. Browser checks cover IT/EN/FR at 375 and 1280 px. The full private fixture also passes serious/critical axe checks and has no horizontal overflow in all six combinations.

The initial full suite passed 1,210 tests / 6,561 assertions. The final integration run against main `9e26204d`, including subsequent authentication, recovery and deletion fixes plus cached-author deletion, passed **1,223 tests / 6,711 assertions**. JavaScript: 27 tests passed; language parity, scoped Pint, design-system lint and Vite build passed. Official Playwright-container screenshots must be reviewed/refreshed in the homepage PR before merge; do not widen tolerances or skip them.

The private preview retains prior test records and adds explicitly fictional layout fixtures. No production examples, real signatures, organization imports or test emails are created. The earlier 17-point organization review is live through PR #290; this homepage is a separate branch/release.

### Performance evidence

Lighthouse 13.6.0 mobile defaults: 412 × 823 viewport, 4× CPU slowdown, simulated 150 ms RTT / 1,638.4 Kbit/s. Full-fixture private PHP preview, persistent file cache, gzip and static `Last-Modified`/`ETag` behavior matched to headers observed on the live Nginx origin through a temporary loopback-only test proxy. No production Nginx configuration was changed.

| Run | Performance | LCP | CLS |
| --- | --- | --- | --- |
| Cold | 100 | 1.150 s | 0 |
| Retained browser storage / warm application | 100 | 1.145 s | 0 |

The cold <2.5 s goal passes. The stricter warm <1.0 s goal is **not demonstrated by this preview measurement**; do not claim it passed. Recheck on the production FPM/CDN path after publication and optimize further if necessary. These are private-fixture lab measurements, not live-user field data. The existing Inter Latin font is preloaded on the homepage to avoid late font discovery and layout shifts; no new font or visual library is added.

The standalone `/en/moderation-policy` URL currently returns 404. The trust-row moderation link therefore uses the existing terms-of-service page rather than introducing a broken URL or inventing a new legal policy.

## Release

Use normal CI/Deployer, including its recovery snapshot before the additive two-column migration. Do not alter production application source or schema manually. After deployment, verify `/version`, the single gate, anonymous application behavior and existing transactional email settings. Leave normal mail delivery/retries enabled; the owner revoked the earlier SMTP pause. Only the private preview has outbound email/ads disabled.

The hourly scheduler refreshes momentum counters. An initial queued `RefreshHomepageMomentum` may be dispatched after release to populate them immediately; it sends no email and changes no signature records. Refresh relevant public caches through the existing purge mechanism.
