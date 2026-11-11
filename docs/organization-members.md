# Organization members and roles

Implements the owner's members delta to specification v6. Personal accounts remain the only identities; the magazine is independent. Normal transactional email remains enabled with existing provider/quota retries.

## Data and ownership

The additive migration creates memberships and hashed single-use invitations, and backfills existing owner memberships. A stored generated owner slot plus a unique organization/slot index prevents two owners under both SQLite and MySQL. Organization/user membership is unique. The owner mirror and membership rows change in one transaction; unclaimed and owner-deleted suspended organizations legitimately have no owner. Active invitation email slots are unique until accepted/revoked; resends revoke the old token and count toward the 20/day organization limit.

Voluntary transfer selects an existing admin and demotes the old owner to admin. Platform reassignment preserves the pre-existing security rule: the previous owner loses membership, while the new owner's membership is created/promoted. Owner deletion suspends the organization without promoting another member or deleting campaigns.

## Authorization

Central OrganizationPolicy/PetitionPolicy checks replace inline owner-only authorization, including rechecks inside write transactions. Owner/admin may edit the organization profile and organization-attributed campaigns; editors may manage only their own attributed campaigns. Personal campaigns remain private to their author. Attribution adds organization management rights; detachment removes them. Removing/leaving a membership never rewrites campaign authorship, signatures or organization attribution. Existing restrictions after an external signature remain unchanged. Membership grants aggregate consent/signature counts, never another author's signature/contact exports.

## Routes and privacy

Use the site's normal `/{locale}` prefix for personal-account routes. Platform admins use `/admin/organizations/{slug}/members` behind the existing admin-auth and organizations-edit permission; the same controllers/policies enforce actions in both contexts. Members page: `/org/{slug}/manage/members`; invitation acceptance: `/organization-invitations/{token}`. GET never accepts. POST requires confirmation; a different signed-in email requires a second explicit confirmation. Anonymous users return through email/password or Google login/registration and mandatory profile completion. Expired, consumed, revoked and suspended-organization invitations cannot be accepted; unverified claims cannot add members.

Only owner/admin receive the roster, member/invitation emails and last 20 audit events. Editors can leave from their private page without roster access. Public profiles expose no team list. The invitation/member layouts contain no third-party trackers or advertising. Authentication/profile pages reached from an invitation suppress trackers/ads and use no-referrer, so the private link is not disclosed through analytics. Private responses use no-store/noindex.

Invitation email jobs are encrypted, use existing recipient language preferences and editable/translatable MailText defaults, stop after expiry/revocation, and follow normal SMTP/quota retries. The inviter also receives the private acceptance link in the response; raw tokens are not stored on invitation rows or in audit events. Resending issues a fresh token.

Terms version `2026-11-11-members` adds owner responsibility, member authorization and restricted email visibility in all 14 supported languages. Prior legal files and historical acceptances remain intact.

## Release checks

Run focused organization/member/privacy/auth tests, the integrated suite, explicit-path Pint, language parity, design-system lint and browser checks. Validate generated constraints/backfill on an isolated native MySQL instance. Use only the normal GitHub CI/Deployer production release, including its migration recovery snapshot. No production fixture accounts, campaigns or invitations are created for this feature.
