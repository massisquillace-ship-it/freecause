# FreeCause Organizations — MVP Specification v6

Date: 2026-11-07
Status: implementation reference — approved for Codex
Supersedes: v1–v5 of the organizations MVP brief.

## 1. Product direction

Adding likes and profiles is not the product. The product is a free public home for small associations: one shareable page with verified identity, live campaigns and a way to support the organization. It must be useful when the association has no website and when FreeCause has only ten organizations and one person handling development, verification and support.

Internal reference: **“Crunchbase for associations, with petitions.”** Profiles, structured data, discovery and a public track record. Petitions are the events that bring people to those profiles; the page updates itself from campaigns and never asks the association to post. Facebook and Instagram remain the association’s distribution channels; FreeCause is where their campaign links point. Do not build a general social network.

Keep the public claim:

> La tua associazione, le tue campagne, le persone che vogliono fare la differenza.

Initial service is free. No billing, pricing page, campaign quotas or promise of perpetual free access. Future changes to pricing or free-plan availability require at least 90 days’ notice under the organization terms. Final terms must be supplied and approved by the project owner before public applications open.

Start with approximately ten associations recruited and supported personally. The key validation is whether they point their campaign links (bio, posts, website) to their FreeCause profile and return to launch another campaign.

## 2. Delivery phases — mandatory implementation workflow

### First output: repository findings, before code

Inspect applicable repository instructions and existing implementation. Before writing code, present a concise list of:

- Framework/version and conventions for routes, locales, views, authentication, authorization, validation, tests, email, queues, uploads, analytics and auditing.
- Existing petition ownership, publication states, signature confirmation flow and denormalized signature counters.
- Existing categories and sitemap implementation.
- Proposed schema integration, assumptions, unresolved ambiguities and any required deviation from this specification.

Do not invent conventions where the repository already provides them. Resolve material ambiguities before dependent implementation. Use English identifiers, enum values, slugs, locale keys and commit messages; localized UI copy lives only in locale files.

### Phase 1 — organization foundation

Data model, seeded causes, application flow, manual verification/admin, founding recognition, owner reassignment, public profile with share action, external donation link with admin approval, Italian 5×1000 block, image processing, report link and basic management panel. Include only the schema needed by these features. The profile shows an empty campaign state until phase 2.

### Phase 2 — discovery and petition integration

Directory, efficient signature totals, publish-as selection, attaching/detaching existing petitions, petition-page attribution and cause tags, optional petition cause selection, organization opt-in and revocation.

### Phase 3 — track record and distribution

Petition outcomes, embeddable badge, transactional emails, organization sitemap, conditional homepage section, adoption instrumentation through existing analytics.

**Each phase is a separate PR with its tests from section 14 and a short validation summary. Do not start the next phase until the project owner explicitly approves the preceding phase. Do not combine phases.** Phase 1/2 previews are not a public launch: transactional emails and completed terms must be ready before real applications open.

Phase 4 (followers and updates) is defined in section 16 and is **not** part of this implementation. Do not create its tables, routes or UI.

## 3. Scope and learning goals

Included: organization landing, application and manual verification, profiles, logo/cover, controlled causes, country and optional activity area, external donation link, 5×1000 block, share action, directory, petition attribution, basic dashboard, founding badge, outcomes, opt-in/revocation and embeddable badge.

Excluded: social feed, posts, likes/reactions, chat, comments, groups, personal connections, follow button, follower lists, newsletter/update sending, contact exports, team invitations/editors, CRM, in-platform donations/payments, multi-organization campaigns, custom themes/HTML, custom domains, independent certification of victories. No disabled “coming soon” controls anywhere.

Metrics below are reference definitions, **not an admin-report UI deliverable**. Use simple queries and manual notes; do not build a reporting page.

| Metric | Definition |
|---|---|
| Applications and verification | Counts by state and time to verification |
| Activation | Verified organizations with a published petition |
| Repeat use | Organizations publishing a second new petition after verification |
| Time to first campaign | Verification to first new petition publication |
| Profile visits | Aggregate visits using existing analytics, no new visitor identifiers |
| Link adoption | Manual check: profile URL in Instagram/Facebook bio, on website, badge installed |
| Donation clicks | Aggregate outbound clicks on Donate if existing analytics supports it |
| Support effort | Founder’s manual notes on requests and time spent |

Distinguish newly published petitions from historical petitions attached later. Review after approximately three months of real use; do not encode success thresholds in code.

## 4. Technical and preservation constraints

Use existing Laravel, MySQL, Blade and frontend conventions. Preserve authentication, locale routing, petition URLs, editorial states, ranking, existing signature counting and existing consents except for the explicit additive changes below.

**Do not delete, replace or discard historical petitions, users, signatures, categories or consents.** Attaching an old petition must not create retroactive consent or change its personal author. New features must not add a mandatory step to existing petition creation or signing.

Do not deploy to production or seed fictional public organizations. Keep sample data local/test-only. Reuse existing services before adding dependencies.

## 5. Application, verification and administration

### Application

Requires an authenticated personal account. An account may represent an organization and continue publishing personally; do not add `users.account_type`.

Required: organization name, type, ISO country, one to three controlled causes, a website/public profile/other verification reference, representation declaration, acceptance of organization terms.

Optional: legal name, logo, cover, tagline, description, activity area, public website, public email, approved social links, external donation URL, tax code (see 6.2). The verification reference is private and distinct from public links.

Generate a unique slug from the name; editable before first verification. On submission create `pending`, record declarations/terms, redirect to the private dashboard with a visible pending banner. Application/admin emails are delivered in phase 3; until then the owner informs applicants personally.

### Verification and state rules

Verification means the administrator reviewed organizational identity and the representative’s authority on available evidence. It does not endorse campaigns or certify claims. Explain this beside the badge with accessible text. Record a private review note; no automatic document collection or external verification provider.

| State | Public profile/badge/directory/sitemap | Profile editing | New petition attribution |
|---|---|---|---|
| `pending` | No | Yes | No |
| `verified` | Yes | Yes | Yes |
| `rejected` | No | Yes; explicit resubmission | No |
| `suspended` | No | Read-only | No |

- Resubmission returns `rejected` to `pending` and notifies the administrator once phase 3 emails exist.
- Lock slug, name, legal name, type and country after first verification; administrator handles corrections.
- Logo, cover, tagline, description, activity area, causes, public links, donation URL and tax code remain editable, subject to moderation and (for donation URL) re-approval.
- Suspension removes public attribution; petitions stay subject to existing editorial rules, never auto-deleted or hidden.
- Rejection/suspension reasons shown privately to the owner. Admin may restore after review.
- Every mutation: server-side authorization, validation, existing CSRF mechanisms.

### Admin tools

Existing admin guard/prefix. Searchable, paginated list with state filter; detail with applicant, verification reference, private notes, terms acceptance, donation URL approval state and campaign count. Actions: verify, reject with reason, suspend with reason, restore, toggle founding status, approve donation URL, change owner.

**Owner reassignment:** admin replaces `owner_user_id` with an existing user from the detail page. Require confirmation, record old/new owner and admin in the audit trail, revoke old owner’s management access, grant the new owner access. Do not transfer personal petition authorship; existing petition policies still apply. Explain this limitation in the confirmation.

Use existing audit facilities or a minimal state-change log. One owner per organization; no membership pivot, invitations or editor role.

### Founding organizations

`founding_partner` boolean, default false, manual admin control. Display a distinct recognition on verified profiles and in the dashboard using locale key `organizations.founding_badge` (it: “Associazione fondatrice”, en: “Founding organization”, fr: “Association fondatrice”). It is not the verification badge and implies no economic promise. No counter, automatic assignment or scarcity messaging.

## 6. Public pages and layout

### 6.1 Landing — `/{locale}/organizations`

Public claim, concise explanation, three benefits (public identity, campaigns in one place, discovery by cause/country), free initial service and manual verification explanation. Primary CTA: create an organization page. Secondary CTA: explore organizations. Mention founding recognition without promising eligibility. Hide logo selections until enough real verified profiles exist. No guaranteed signatures, reach or outcomes.

### 6.2 Profile — `/{locale}/org/{slug}`

One shared responsive layout, in this order:

1. Cover with logo overlapping its lower edge.
2. Name, verification badge, optional founding recognition.
3. Tagline, organization type, country, optional activity area, causes.
4. Action row: **Donate** (only with approved external URL), **Share** (native share where available, copy-link fallback, no tracking parameters beyond existing analytics conventions).
5. Optional official website, approved social links, public email.
6. Description.
7. **5×1000 block** — only when `country = IT` and `tax_code` is set: localized heading “Destina il tuo 5×1000” with the tax code in large monospaced text and a copy button. No other country-specific blocks in this release.
8. Counters: **X petitions, Y signatures, Z victories**. Victories are promoter-reported; signatures are not unique supporters.
9. Public campaigns: `victory` first; within each group newest publication first, then ID descending. Before outcomes ship, publication date/ID only.
10. “Report this page” link.

Only verified profiles are public; other states return 404. Exclude drafts, private, removed or non-public petitions from grids and totals. Empty verified profile: useful empty state, never fabricated content. No follow button, no like, no follower counts.

Report link: `mailto:` to configured support email, URL-encoded subject with organization slug, profile URL in body. Never expose private verification references.

### 6.3 Logo and cover

- Logo: JPG/PNG/WebP, max 2 MB; server-side center-crop and re-encode to 400 × 400.
- Cover: JPG/PNG/WebP, max 3 MB; server-side crop and re-encode to **1500 × 500**.
- Cover focal point: exactly `top`, `center`, `bottom`, default `center`. Horizontal anchor always center. Crop source at 3:1 using the vertical anchor, then resize. No arbitrary coordinates, no client crop editor, no preview tool.
- Validate real file content and dimensions server-side, apply existing image-processing limits, strip metadata, reject SVG.
- Upload, replace, remove; show the saved rendering.
- Missing cover: deterministic color/pattern from slug with a controlled accessible palette, no external service. Missing logo: consistent placeholder.
- No overlap obscuring text/actions on small screens.
- Safely delete replaced files.

### 6.4 Directory — `/{locale}/organizations/explore`

Verified organizations only. Cards: logo, name, tagline, country, causes. Paginated; no map, infinite scroll or recommendations.

Controls: name search only (parameterized `LIKE %term%`, SQL wildcards escaped; no full-text index); one cause filter; one country filter; ordering alphabetical (default), most signatures, newest.

Combine with AND, preserve through pagination. Sort rules: alphabetical name then ID; newest `verified_at` desc then ID; most signatures desc then name and ID. Totals follow section 10; never aggregate raw signature rows per request.

### 6.5 Petition page

For a public petition whose linked organization is currently verified, beneath the title:

> Launched by [24px logo] Organization name [verification badge]

Name/logo link to the localized profile; placeholder if no logo. Hide the whole attribution when not verified; preserve existing personal-author UI. Verification badge visually distinct from founding recognition.

Show the petition’s optional causes as localized clickable tags pointing to `/{locale}/organizations/explore?cause={slug}`. No new cause requirement.

Render the organization opt-in below existing consent controls per section 11. Outcome display per section 12.

### 6.6 Homepage and navigation

Add “For organizations” navigation entry within the existing responsive layout. In phase 3 add a section with up to six most recently verified organizations only when at least three exist; “See all” links to the directory.

## 7. Private management

Dashboard: verification state/reason and next step, public profile link when verified, edit action, donation URL approval state, campaigns and signature totals, in phase 2 consent totals. Show linked petitions the owner may manage under existing rules.

Edit page: optional profile fields, donation URL, tax code, cover/logo upload and focal choice. Explain locked identity fields. No preview system.

### Publish as

Reuse existing petition creation. With exactly one verified owned organization, default **Publish as** to it and offer an explicit switch to **Myself**. With several, default to the most recently verified (ID desc tie-break), list the others and Myself; show the selection clearly before publication. With none, personal publication unchanged. Existing drafts keep their saved attribution.

Validate server-side: current verified status and current ownership. A crafted request must not attribute to an unrelated organization.

### Attach/detach

Owner may attach only own petitions with no organization; may detach linked petitions. Attribution only; never personal author or edit rights. Require confirmation. Suspended organizations cannot attach/detach. Update totals/caches after either action. No URL changes, retroactive opt-ins or signature data transfers.

## 8. Localization and accessibility

New UI copy in locale files; en/fr/it plus explicit fallback for other supported locales. Preserve locale routing. Tagline/description: current locale, then English, then first nonempty translation; do not require all translations.

Responsive layout, keyboard access, labelled fields, accessible errors. Badges and states not conveyed by color alone. Safe text rendering, escaped links. Outcome summary stays in its original language.

## 9. Data model and controlled taxonomy

### 9.1 `organizations`

| Field | Definition |
|---|---|
| `id` | Primary key |
| `owner_user_id` | FK users; sole current owner |
| `slug` | Unique, max 80; lowercase ASCII letters, digits, hyphens; immutable after first verification |
| `name` | Max 120 |
| `legal_name` | Nullable, max 200 |
| `type` | `ngo`, `association`, `foundation`, `committee`, `union`, `other` |
| `country` | Required ISO 3166-1 alpha-2 |
| `territory` | Nullable text max 160; UI label “Area of activity” |
| `tax_code` | Nullable string max 16, uppercase alphanumeric; UI label “Codice fiscale (5×1000)”; shown publicly only when `country = IT` |
| `verification_reference` | Required private text max 1000; escape on display |
| `website` | Nullable validated public HTTPS URL |
| `donation_url` | Nullable external HTTPS URL max 2048; no credentials/nonstandard ports |
| `donation_url_approved_at`, `donation_url_approved_by` | Nullable; reset to null on every `donation_url` change |
| `email_public` | Nullable validated email |
| `social_links` | Nullable JSON, strictly defined below |
| `logo_path`, `cover_path` | Nullable image paths |
| `cover_focal_point` | `top`, `center`, `bottom`; default `center` |
| `tagline` | Nullable localized JSON; max 200 per language |
| `description` | Nullable localized JSON; max 5000 per language |
| `status` | `pending`, `verified`, `rejected`, `suspended`; default `pending` |
| `verified_at`, `verified_by` | Nullable timestamp and admin FK |
| `verification_note` | Nullable private admin note |
| `rejection_reason`, `suspension_reason` | Nullable reasons shown privately to owner |
| `founding_partner` | Boolean default false; manual admin control |
| `representation_confirmed_at` | Required |
| `terms_accepted_at`, `terms_version` | Required |
| `created_at`, `updated_at` | Timestamps |

Use enums or validated strings per repository conventions. Add `signatures_total_cache` only if required by section 10.

`social_links` permits only `facebook`, `instagram`, `x`, `linkedin`, `youtube`, `tiktok`. Each value: HTTPS URL, max 2048, no embedded credentials or nonstandard ports. Drop empty values; reject unknown keys and nested JSON. Validate parsed hostname against the exact domain or a genuine dot-boundary subdomain, never substring matching:

| Key | Allowed domain roots |
|---|---|
| `facebook` | `facebook.com` |
| `instagram` | `instagram.com` |
| `x` | `x.com`, `twitter.com` |
| `linkedin` | `linkedin.com` |
| `youtube` | `youtube.com`, `youtu.be` |
| `tiktok` | `tiktok.com` |

Never fetch submitted links server-side (applies to `website` and `donation_url` too). Render external links with safe attributes per existing conventions.

### 9.2 `causes`

Seeded closed taxonomy: `id`, unique immutable `slug`, `name` JSON en/fr/it, `sort_order`. Idempotent seed, never deleting existing associations. No user-created causes or free text.

| slug | Italiano | English | Français |
|---|---|---|---|
| environment | Ambiente | Environment | Environnement |
| animals | Animali | Animals | Animaux |
| human-rights | Diritti umani | Human rights | Droits humains |
| health | Salute | Health | Santé |
| children | Infanzia | Children | Enfance |
| disability | Disabilità | Disability | Handicap |
| migration | Migrazioni | Migration | Migrations |
| work | Lavoro | Work | Travail |
| education | Istruzione | Education | Éducation |
| culture | Cultura | Culture | Culture |
| territory-urban-planning | Territorio e urbanistica | Territory and urban planning | Territoire et urbanisme |
| justice | Giustizia | Justice | Justice |
| gender-equality | Parità di genere | Gender equality | Égalité de genre |
| older-people | Anziani | Older people | Personnes âgées |
| poverty | Povertà | Poverty | Pauvreté |
| international-cooperation | Cooperazione internazionale | International cooperation | Coopération internationale |
| digital-privacy | Digitale e privacy | Digital and privacy | Numérique et vie privée |
| sport-leisure | Sport e tempo libero | Sport and leisure | Sport et loisirs |
| religion-spirituality | Religione e spiritualità | Religion and spirituality | Religion et spiritualité |
| other | Altro | Other | Autre |

Pivots `cause_organization` and `cause_petition`, unique pairs, indexed FKs. Organizations: one to three causes at application. Petitions: **zero to three, including new petitions**. Validate server-side. Existing petition categories remain intact and independent.

Territory: required `country` plus optional free-text `territory`. No region tables, no filter on free text.

### 9.3 Petitions and signatures

Add nullable indexed FK `petitions.organization_id`; one organization per petition. Preserve personal author and authorization. Outcome fields ship in phase 3 (section 12). Opt-in fields ship in phase 2 (section 11), nullable, no backfill.

## 10. Signature totals and performance

Use the existing denormalized **per-petition signature counter** wherever available. Organization public total = sum of those counters for its currently public linked petitions. Directory sorting operates on an aggregate/subquery of petition counters, no N+1.

**Never run `SUM`/`COUNT` against raw signature rows on directory, profile or badge requests.**

If no reliable per-petition counter exists, add `organizations.signatures_total_cache` (unsigned bigint, default 0), recalculated by a queued job after each confirmed signature affecting an organization. Dispatch after commit; jobs idempotent and serialized per organization. Full raw aggregation only in background recalculation.

Recalculate/invalidate after attach, detach, publication/visibility changes, signature invalidation and other existing counted-signature events. Initialize historical totals via a background command before relying on cached ordering. Status changes invalidate directory/profile/badge caches. Document the chosen strategy in the repository findings.

## 11. Organization opt-in and revocation

Add nullable fields to `signatures`, no backfill:

| Field | Meaning |
|---|---|
| `org_optin` | null: not presented; false: shown unchecked; true: checked |
| `org_optin_at` | Affirmative timestamp, only for true |
| `org_optin_text_version` | Presented version for true/false; null if not presented |
| `org_optin_organization_id` | Historical recipient FK; independent of later petition attribution |
| `org_optin_locale` | Language of presented text |
| `org_optin_revoke_token` | Opaque random token (min 32 bytes, stored hashed), generated only when `org_optin = true` |
| `org_optin_revoked_at` | Nullable; retain original evidence |

Optional, never prechecked checkbox below existing consents, only for petitions linked to a currently verified organization. Initial text:

> I agree to receive updates from {organization name} about this and future campaigns.

Version `2026-11`; retained en/fr/it texts; privacy notice naming recipient and purpose. Owner-approved texts required before public activation. This records permission for future communications; no sending or export in this release.

Signing works without opt-in. Server validates current recipient, state, version and locale; never trust client-provided organization IDs. If unavailable at submit time, store null. Unconfirmed/invalid signatures cannot produce usable consent. Reattachment never transfers or creates consent.

Dashboard: total and last-30-day affirmative, unrevoked consents from valid signatures for this historical recipient, labelled as consents. No public counts, exports or identity access.

Revocation: public route keyed by the opaque token (never the signature ID), signed; GET shows confirmation, POST records `org_optin_revoked_at`. Include the link in the signature confirmation when consent was given. **Any future organization email must carry the revocation link in its footer.**

## 12. Petition outcomes

Phase 3 fields on `petitions`: `outcome` (`open`, `delivered`, `victory`, `closed`, default `open`), `outcome_at` nullable date, `outcome_summary` nullable plain text max 500 in the petition’s language, `outcome_updated_at`, `outcome_updated_by`.

Only the promoter authorized by existing petition policies or admin may change it, through a small form in existing petition management. For `delivered`, `victory`, `closed`: nonfuture date and nonempty summary required. Returning to `open` requires confirmation and clears date/summary; keep audit history.

Outcomes never change editorial state or signing availability. Historical petitions get technical default `open`; never infer victories.

Show outcome, date and summary only on public petitions. Victory badge text: **“Victory reported by the organizer”** (localized). Profile victory count and ordering use only public linked petitions with `outcome = victory`.

## 13. Routes, email, sitemap and badge

### Routes

Existing locale conventions for all public/owner paths; logical routes, actual names follow repository conventions, safe mutation methods retained.

| Method | Path | Purpose |
|---|---|---|
| GET | `/organizations` | Landing |
| GET | `/organizations/explore` | Directory |
| GET/POST | `/organizations/apply` | Authenticated application |
| GET | `/organizations/terms` | Completed terms |
| GET | `/org/{slug}` | Verified public profile |
| GET | `/org/{slug}/manage` | Private dashboard |
| GET/POST | `/org/{slug}/manage/profile` | Edit profile |
| GET | `/org/{slug}/manage/petitions` | Linked campaign list |
| POST | `/org/{slug}/manage/petitions/attach` | Attach |
| POST | `/org/{slug}/manage/petitions/{petition}/detach` | Detach |
| POST | `/org/{slug}/manage/resubmit` | Resubmit rejected application |
| GET | `/org/{slug}/badge.svg` | Generated badge (phase 3) |
| GET/POST | `/organization-consent/{token}/revoke` | Opaque-token revocation; no identity disclosure |

Reuse existing petition create/edit routes for attribution, cause tags and outcomes. Admin routes under existing guard/prefix: list/detail and POST actions verify, reject, suspend, restore, toggle founding, approve donation URL, change owner. Reserved slugs: at least `apply`, `terms`, `explore`, `manage`, `admin`, `new`, `edit`.

### Transactional emails — phase 3

Existing layout, queue and user-locale conventions: application received, admin application/resubmission notice, verified (public URL/next steps), rejected (reason), suspended (reason/contact), restored, donation URL approved. After-commit dispatch; failed sending never leaves partial writes.

### SEO, sharing and sitemap

Profile `<title>`: `{name} — FreeCause`; meta description from tagline/description. OpenGraph/Twitter image fallback: cover, logo, FreeCause default; `og:title`, `og:description`, `og:url` canonical. Phase 3: organization sitemap with verified profiles only, referenced from the existing index; canonical/hreflang per existing conventions; management/application pages noindex.

### Embeddable badge — phase 3

Dashboard shows the saved badge and copyable HTML (no preview tool):

```html
<a href="https://www.freecause.com/it/org/SLUG?source=org-badge">
  <img src="https://www.freecause.com/it/org/SLUG/badge.svg"
       alt="Le nostre campagne su FreeCause" width="320" height="80" />
</a>
```

Generate canonical host, locale and escaped slug. Badge shows organization name, localized “Our campaigns on FreeCause” and “X signatures collected” from section 10 totals. Long names truncate safely. Fixed SVG template, system fonts, no uploaded SVG, JavaScript, iframe, external resource or cookie.

Verified organizations only; other states return a neutral “Profile unavailable” graphic without name, total or badge; profile stays 404. Public cache max 15 minutes, invalidate on state change.

Measure arrivals tagged `source=org-badge` with existing analytics; retain at most referrer domain; no new visitor identifiers.

## 14. Acceptance and tests by phase

Repository test framework. Each phase PR includes its automated checks and a concise responsive visual verification.

### Phase 1

- Application creates pending record with declarations and one to three causes.
- Idempotent cause seeding; closed IDs, max three, duplicate rejection.
- Owner/admin authorization; other users cannot view or mutate private data.
- Public profile only when verified; all state transitions and resubmission rules.
- Unique/reserved slugs; identity fields locked after verification.
- Social link key/host validation; arbitrary JSON/URLs rejected.
- Logo/cover validation, 1500 × 500 output, three focal choices, deterministic fallback.
- Founding recognition only on verified profiles; locale key, no hardcoded string.
- Donation URL: approval required, approval reset on change, button hidden when absent/unapproved/unverified, safe external navigation, no payment implied.
- 5×1000 block only with `country = IT` and tax code set; tax code format validated.
- Share action: native share with copy fallback; profile OpenGraph preview renders correctly in WhatsApp, LinkedIn and Facebook debuggers.
- **Performance:** public profile with cover and logo reaches LCP under 1.0 s on a simulated 4G mobile profile (Lighthouse mobile preset) with warm cache and under 2.5 s cold; document the measurement.
- Owner reassignment updates access, records audit, preserves personal petition ownership.
- Report mailto includes slug/URL only.
- Visual checks: mobile/desktop, long names, no images, no campaigns, each state.

### Phase 2

- Directory substring matching, literal wildcards, cause/country filters, deterministic orderings.
- Queries use denormalized counters/cache; refresh events covered.
- Publish-as defaults, explicit personal switch, drafts unchanged, forged attribution rejected.
- Zero to three causes on petitions; closed tags.
- Attach/detach ownership and state restrictions; private petitions never leak.
- Attribution and tags display only under specified conditions.
- Opt-in three states, recipient/version/locale, no retroactive consent, confirmation semantics, concurrent state changes.
- Revocation by opaque token: tampering rejected, GET does not mutate, POST revokes, historical recipient retained after reattachment, token not derivable from signature ID.
- Existing creation/signing flows and totals intact; no new mandatory step.

### Phase 3

- Outcome authorization, validation, independent editorial state, audit, public count/order, wording.
- Email localization, after-commit dispatch, revocation link in confirmation.
- Badge safety, localization, public totals, neutral nonverified rendering, cache invalidation, tagged links.
- Sitemap/homepage expose only verified organizations and public content.
- Responsive rendering of outcome badges, homepage cards and SVG.
- Regression: historical petitions, users, signatures, URLs, categories and consents preserved.

## 15. Deliverables and launch checklist

Three separately approved PRs: migrations/models/policies, seeded taxonomy, routes/controllers/validation, Blade UI, image processing, localizations en/fr/it with fallback, emails, sitemap, tests. Local/test seeds only.

`docs/organizations.md`: route/state rules, verification procedure, founding recognition, owner reassignment limits, donation approval procedure, admin/support configuration, counter/cache strategy, image settings, consent versions/revocation, outstanding launch requirements, reference metric queries. No report page.

Before public launch: owner-approved terms/privacy texts, configured support/admin contacts, working queues and transactional emails, initialized public totals, tested revocation, documented manual verification and donation-approval procedures. No placeholder terms accepted by real applicants.

## 16. Phase 4 — followers and updates (gated, not in scope)

Open phase 4 only if, 30 days after verification, **at least five of the first ten associations** show two of these three behaviors: profile URL in their Instagram/Facebook bio or website, next campaign launched on FreeCause, badge installed. Until then nothing from this section is built.

Design constraints already decided for phase 4, to keep phases 1–3 compatible:

- One subscription model, not two. `organization_followers` with `source` (`petition_signature`, `profile_form`), nullable `petition_id`, normalized email, locale, consent version, confirmation and unsubscribe timestamps, hashed single-use tokens. The section 11 checkbox becomes a producer of follower rows with `source = petition_signature`; section 11 fields remain as historical evidence.
- Follow ships only together with working delivery: double opt-in, unsubscribe in every email, suppression handling, one plain-text update per organization per 24 hours, admin hide with cancellation of unsent deliveries.
- Public follower count only above a threshold (initially 50); never show small numbers.
- No likes, reactions, comments or feed in any phase.

Later, on observed demand: team roles, shared campaigns, exports and integrations, paid professional tools.

The first release succeeds when associations put their FreeCause page where their campaign links used to go and come back for the next campaign.
