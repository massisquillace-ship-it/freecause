# Homepage rework

Implementation reference: [owner brief and gate clarification](specs/homepage-organizations-first.md).

## Data and visibility

- Owner override, 9 November: show even one real result. The shared `organizations.min_directory_count` defaults to **1**; there is no second homepage flag. `public_launch` still governs indexing/application access only.
- Organizations navigation remains visible in desktop, mobile and footer, even before any public organization exists. Below the shared gate the new hero uses petition-centric copy; no legacy homepage blocks return.
- Organization cards show any public verified/unclaimed records. Weekly ranking accepts one organization with recent signatures; otherwise use the honestly labeled total-signatures fallback.
- Recently signed petitions show up to six public petitions in the current locale, ordered by their latest confirmed nonspam signature, regardless of age or total signatures. The worker prepares this list across all dates; future timestamps, drafts and inactive petitions are excluded. No signer identities are displayed.
- Organization, recently signed and victory sections remain visible with translated empty states when no data exists. Victories are newest reported outcomes first; no fictional outcomes are added.
- Proof/trust counts show actual nonzero values. The claim banner accepts one unclaimed organization; zero-count causes are omitted.

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

## Latest launch-rule validation — 9 November 2026

After the owner approved showing sparse real data, the integrated suite against main `4ece3a46` passed **1,286 tests / 7,284 assertions**. Targeted homepage/claims/privacy checks passed 37 tests / 343 assertions. Language parity, design-system lint and Vite build passed. IT/EN/FR at 375 and 1280 px pass serious/critical axe checks without horizontal overflow; the separate zero-organization fixture confirms the petition-centric hero and visible empty-state sections. The recency worker additionally passes 14 focused tests / 80 assertions, including independent six-item limits per locale. Its read-only production query returned six IDs for 13 locales and two for Greek in 16.5 seconds; it runs hourly in the worker, never in the public request. A covering-index MAX is computed before translation joins and a window rank returns only the required IDs. Official screenshot checks remain required before merge.

## Visual alignment — 9 November 2026

The owner supplied the [organizations-first visual reference](homepage-organizations-reference.png). Align the homepage with its compact spacing, right-aligned section links, bordered cards and short status badges, inset claim banner, outlined secondary actions and numbered steps. The public empty state uses the same new layout and petition copy. The later owner override above removes the launch-day homepage minimums. Existing logos and locale-dependent content remain authoritative.

Private review data explicitly marks six campaigns, three victories and editorial placeholder cards as fictional; it never enters production. The preview magazine uses a separate SQLite fixture without WordPress users. Validate IT/EN/FR on mobile and desktop, review official-container screenshot changes, and purge both hostnames and slash variants after release.
