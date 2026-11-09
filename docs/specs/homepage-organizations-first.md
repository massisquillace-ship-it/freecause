# Homepage implementation reference and owner clarification

The owner supplied the following brief and then clarified that there is only one organization-directory gate: `config('organizations.min_directory_count')`, default 20, counting public verified and unclaimed organizations. Below this threshold, hide global Organizations navigation, promotional claim actions, Trending organizations, Browse by cause and the claim banner. Use `home.hero.title_petitions` and `home.hero.subtitle_petitions`, and make Start a petition the header primary action. Campaigns, Victories, How it works, Magazine and Footer are independent of this directory gate and retain their own data thresholds. Above it, the complete organizations-first experience appears automatically. `public_launch` controls only organization indexing and application access, never a second homepage flag. Functional record-specific claims remain available.

The existing platform style tokens and logos take precedence over introducing any new palette. No connected design canvas was available in this workspace; implementation follows the supplied written brief and existing components. The editorial magazine remains separate from platform accounts and memberships.

# FreeCause Homepage — Implementation Brief v2 (organizations-first)

Date: 2026-11-08
Supersedes: homepage mini brief v1.
Depends on: Organizations spec v6 phases 1–2 and `organizations-phase1-fixes.md` §4 (`unclaimed` state, claim-search, import). Ships as **Phase 3b**, one PR, after phase 3 approval.
Design reference: canvas "FreeCause Homepage — preview" (artboards `Main.dc.html`, `Org.dc.html`).

## 1. Visual system — reuse existing tokens, add nothing new

Use the existing Tailwind theme and utilities already in `build/assets/app-*.css`. No new colors, fonts or component libraries.

| Role | Existing token / value |
|---|---|
| Page background | `#FAF9F7` (body) |
| Band background | `.bg-band` → `#F4F2EF` |
| Ink / headings | `.text-ink` → `#1F1E1C` |
| Body text | `#111111` |
| Muted text | `.text-muted` → `#6B655D` |
| Brand / primary action / links | `.text-brand` → `#CC1F1F`; hover `#A51919` |
| Borders | `#E6E3DD` |
| Dark footer | `#1F2024`; text `.text-on-dark` → `#F4F2EF` |
| Cards | white, `.shadow-elev-1` (`0 2px 4px rgba(0,0,0,.075)`), `rounded-lg` |
| Primary button | brand bg, white text, radius 3px, padding 10px 24px, 16px, weight 600 |
| Font | Inter (already loaded) |
| Container | existing `max-w-[1140px] xl` / `2xl:max-w-[1320px]` |

New semantic labels (locale keys, no new colors): verified badge = ink text on `#E4F3EA`; unclaimed label = `#7A4B00` on `#FFF1D6`; victory label = same as verified. Cause chips = ink on band.

## 2. Page — `/{locale}/`

**Individuals remain first-class.** Anyone can start a petition without an organization; personal petitions appear in every petition block on equal terms, with the existing personal-author line (avatar/name) where an organization line would be. No block, copy or CTA may imply that only organizations can launch petitions. "Start a petition" stays visible in header, hero and mobile bar.

Replace the current homepage template. Every block below has a hide rule; never render placeholder content. All copy in locale files (en/fr/it; fallback per existing convention).

### 2.1 Header (shared layout, all pages)
Logo (existing `/legacy/images/logo7.png`, 392×64, rendered at 130×21 as today; footer uses `/legacy/images/logo7footer.png` with the existing `brightness-0 invert` treatment; never redraw or replace the logo) · Organizations → `/organizations/explore` · Petitions → `/petitions` · Magazine → `/magazine` · Help · Login · **Claim your organization** (primary, brand button) → `/organizations/claim-search` · **Start a petition** (ghost) · language switcher. Mobile sticky bar: Home · Organizations · Claim · Login · Menu. Remove the stray petitions URL text.

### 2.2 Hero (white, border-bottom)
- H1 key `home.hero.title`: "Associations, committees and NGOs in {country}: who they are, what they fight for." `{country}` from the request country attribute; fallback "in Europe".
- Subtitle key `home.hero.subtitle`: "Every organization has a page with its campaigns, signatures and results. And anyone can start a petition, on their own."
- One search form `GET /{locale}/search?q=`: placeholder "Search an organization, a cause or a city"; results page shows organizations tab first, petitions tab second (reuse existing petition search; add organization name search per fixes §3.9 logic).
- CTAs: primary **Claim your organization** → claim-search; ghost **Start a petition**.
- Proof line: "{total_signatures} signatures · {organizations_count} organizations · since 2010". Counts from cached aggregates refreshed hourly; `organizations_count` = verified + unclaimed. Hide line if `organizations_count < 50`.

### 2.3 Trending organizations
Heading "Growing in {country} this week" / sub "Organizations by signatures received in the last 7 days". Up to 6 cards: logo or placeholder (initial on brand-neutral background from slug hash), name, status label (verified badge / unclaimed), two chips (first cause, country or city from `territory`), "Main campaign: {title}", "+{n} signatures this week" in brand color. Order by `signatures_7d_cache` desc, then total desc, then ID. Link "All organizations →" to explore. Hide block if fewer than 3 organizations have ≥ 20 signatures in 7 days; fallback heading "Most active organizations" ordered by total.

### 2.4 Campaigns gaining momentum
Heading "Campaigns gaining momentum" / sub "Petitions with most signatures in the last 7 days". Up to 6 public petitions in the user's country, then language, organizational and personal mixed, ordered only by `signatures_7d_cache`; card: title, author line — organization (24px logo + name + badge if verified) when linked to a verified or unclaimed org, otherwise the existing personal author presentation (avatar icon + "Started by {first name} {initial}.") — progress bar (brand), "{sig} signatures" / "goal {goal}". Sub-heading: "Petitions by organizations and citizens with the most signatures in the last 7 days". Hide any petition `< 50` total; hide block if fewer than 3 remain; fallback: most signed of the last 12 months.

### 2.5 Victories (white band)
Heading "Victories" / sub "Results reported by organizers". Up to 3 public petitions with `outcome = victory`, newest `outcome_at` first; card: victory label, title, `outcome_summary` truncated 140 chars, "{org} · {month year}". Hide if none.

### 2.6 Browse by cause
Heading "Explore by cause". Chips for the 20 seeded causes with count of verified + unclaimed organizations; hide zero-count chips; link to `/organizations/explore?cause={slug}`. Remove the old 22-category list from the homepage.

### 2.7 Claim banner (dark `#1F2024`)
"{unclaimed_count} organizations already have a page on FreeCause. Is yours one of them?" + sub "Pages are generated from petitions published over 15 years. Claim yours, verify your identity and start managing it." Button (brand) "Find your organization" → claim-search. Hide if `unclaimed_count < 20`.

### 2.8 How it works (white band)
Three numbered steps, one line + one sub-line each: Claim or create your page / Manual identity check, free · Launch a campaign / As an organization or as an individual · Deliver signatures and report the outcome / Results stay on your page.

### 2.9 Trust row
Centered inline: "Since 2010" · "{verified_count} verified organizations" (hide < 10) · Ethical Code · Moderation policy · Privacy.

### 2.10 Magazine — keep as today
Band background. Heading "Latest from Freecause magazine" / sub "Stay updated with our latest insights and news". 3 latest posts in current locale, existing card markup (16:9 featured image with blur-up, title, "Read More ›" in brand), plus one new line "Related campaign: {title}" when the post has `related_petition_id` (add nullable mapping via existing WP integration; omit the line, not the card, when absent). "View all articles" → `/magazine`. Hide block if fewer than 3 posts in locale; no cross-language fallback.

### 2.11 Footer — keep as today, add two links
Existing dark footer: footer logo, the three magazine paragraphs, `hello@freecause.com`, link list in current order with **For organizations** and **Claim your organization** inserted after "Explore petitions", **Cookies** made a working link to the consent settings, existing copyright line.

### Remove
"Change the world!" hero, "What is online petition" block, "CREATE PETITION" promo block, Featured/Recent activity tabs, 22-category grid, recent-activity ticker.

## 3. Data and performance

- Add per-petition `signatures_7d_cache` (unsigned int) and per-organization `signatures_7d_cache`, refreshed by an hourly queued job from confirmed signatures of the last 7 days using existing indexes; never aggregate raw rows per request.
- Homepage aggregates (`total_signatures`, `organizations_count`, `unclaimed_count`, `verified_count`, cause counts) cached 10 minutes per locale/country; invalidate on organization state change.
- Country targeting: petition/organization `country` attribute; locale as fallback; no geo-IP in this release.
- One query batch per block; eager-load organization and cause relations; no N+1.
- LCP target: < 1.0 s warm / < 2.5 s cold on Lighthouse mobile preset; hero has no image; magazine images lazy with existing blur-up.

## 4. Acceptance

- Each block renders/hides correctly against three seeded fixtures: empty, sparse (below thresholds), full.
- No organization or petition below its threshold ever renders on the homepage.
- Trending and campaigns use the 7-day caches; job refresh tested; status change invalidates caches.
- Header/mobile bar/footer links present on all pages; stray URL removed; Cookies is a link.
- Magazine cards unchanged in markup except the optional related-campaign line.
- Locale/country variants render correct H1 and blocks for IT, FR, EN with and without country.
- Lighthouse mobile: LCP and CLS within targets; document the measurement in the PR.
- Existing petition pages, search, locale routing and sitemap unaffected.
