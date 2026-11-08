# Organizations MVP v6 — review entry point

Baseline: [approved v6 specification](../organizations-spec-v6.md). This review addendum, supplied by the owner on 8 November 2026, supersedes the baseline where they differ. Implement in one PR; phase-four newsletters/followers remain excluded.

Apply to /{locale}/organizations, /{locale}/organizations/explore, /{locale}/organizations/terms and shared layout. One PR. Reference: docs/specs/organizations-mvp-v6.md.
1. Header: a bare petitions URL is rendered as plain text next to the logo. Remove the stray output. Check the Blade partial for an unescaped {{ route(...) }} outside an anchor.
2. Footer: "Cookies" renders as text, not a link. Wire it to the existing cookie-policy route or remove the label.
3. Sticky mobile bar: on /organizations* routes replace "Start Free" (petition creation) with "Claim your organization" → /{locale}/organizations/claim-search (route added in §3). Keep "Start Free" on all other routes.
4. Mobile menu: remove the duplicate "Create Your Account" entry on organization routes or ensure it points to the same target as the header CTA.
5. Add <meta name="robots" content="noindex,follow"> to landing, explore and terms while config('organizations.public_launch') === false. Default false. Exclude these routes from the sitemap under the same flag.
6. Add config('organizations.min_directory_count') (default 20). When the count of verified + unclaimed organizations is below it: hide "For organizations" from header nav, mobile menu and footer; explore returns the landing page content with the search box only (no cause/country selects, no empty-state text, no "Order by").
7. Explore empty state when gate is passed but a filter returns nothing: keep "No organizations match these filters." and add a reset-filters link.
8. Hero primary CTA: a single search form GET /{locale}/organizations/claim-search?q=. Label: "Find your organization". Placeholder: "Organization name". Secondary CTA: "Not listed? Create your page" → /{locale}/organizations/apply. Remove "Explore organizations" and "Organization terms" from the hero; keep both in the page footer area.
9. New route GET /{locale}/organizations/claim-search: name substring search over status IN (unclaimed, verified), parameterized LIKE, wildcards escaped, max 20 results. Result row: logo/placeholder, name, country, status label; unclaimed → button "Claim this page" → /{locale}/org/{slug}/claim; verified → link "View page". Zero results: "Not listed? Create your page" → apply. Reserve slug claim-search.
10. Replace "Applications are not open yet" with the same gate as §2.6: when public_launch is false, the apply route returns 404 to unauthenticated users and the landing hides the apply CTA; no status notice text.
11. Remove the three-bullet block; replace with three steps (one line each, locale keys): claim or create your page · launch a campaign under your name · deliver signatures and report the outcome.
12. Verification block copy → locale keys organizations.verification.title, organizations.verification.body (max 60 words), organizations.verification.founding (max 30 words). Write body in first person plural, no mention of "administrator". Provide en/fr/it.
13. Add unclaimed to organizations.status; add claimed_at nullable timestamp, claimed_by_user_id nullable FK, source enum (application, petition_import, admin) default application. Migration must not alter existing rows.
14. Public profile for unclaimed: render name, country, causes, linked public petitions, signature total, victory count; label "Unclaimed" (locale key); disclaimer line (locale key) with link to report mailto using subject "Remove unclaimed profile"; CTA "Is this your organization? Claim it" → /{locale}/org/{slug}/claim. No badge, logo upload, description, links, donation, tax code, share-to-owner actions.
15. Route GET/POST /{locale}/org/{slug}/claim (auth required): same validation as apply for type, verification reference, representation declaration, terms. On success: status = pending, set claimed_by_user_id, owner_user_id, claimed_at; notify admin (phase 3 mail or log). Only one pending claim per organization: a second claimant sees "A claim is under review." Admin reject on a claimed record returns status to unclaimed, clears owner and claim fields.
16. Admin: "Create unclaimed profile" form (name, country, causes, petition IDs). Artisan command organizations:import-unclaimed {csv} reading admin-prepared CSV (name,country,causes,petition_ids); idempotent on normalized name + country; dry-run flag; report created/skipped. No automatic creation from petition text.
17. Directory counts, sorting and sitemap include unclaimed; badge.svg and donation remain verified-only; unclaimed profiles are noindex until public_launch is true.
- Stray URL absent from header markup; Cookies is an anchor or absent.
- Robots meta and sitemap exclusion toggle with public_launch.
- Nav/footer/mobile links hidden below min_directory_count; explore degrades to search-only.
- Claim-search: substring, wildcard escape, status filter, result cap, zero-state CTA.
- Claim flow: unclaimed → pending → verified / back to unclaimed; single pending claim; auth required; 404 for apply when gate closed.
- Unclaimed profile renders only §4.14 fields; no badge/donation/upload controls.
- Import command: idempotency, dry-run, malformed row handling.
- Existing phase 1 suite still green.
