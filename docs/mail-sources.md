# Mail sources and the SMTP2GO free tier

Production sends through the SMTP2GO free tier. The monthly allowance ran out in October 2026
(Sentry `FREECAUSE-1N`: `552 Your monthly email allowance is exhausted`). Keep new mail minimal.

## Switches (DB settings, group `global`)

| Setting | Controls | State on production (2026-11-04) |
|---|---|---|
| `petition_digest_enabled` | Daily digest, `petition:send-digest` at 09:00 | **off** (`0`) |
| `petition_new_signatures_enabled` | Hourly owner mail, `emails:petition-new-signatures` | **off** (`0`) |
| `smtp_enabled` | All mail | on |

Both product mailers default to on when the setting is missing, so deleting the row turns them back on.
Turn them back on only after the volume fixes below. Settings are cached 300 s; restart queue workers after a change.

## Sources, biggest first

1. **Digest**: one mail per verified user with `notify_product` and no unsubscribe, every day. Grows with the user base.
2. **New signatures**: one mail per petition owner per hour that has new signatures (up to 24 a day per owner).
3. **Verification on guest signing** (`PetitionSigning.php`): one mail per new email. Throttle is 10/min per IP, plus a daily cap (`verify_guest_daily_cap`, default 200); over the cap the signature stays pending and no mail is queued.
4. **Milestones**: one per milestone per petition. Low.
5. **Contact form**: one per message, deferred while the quota is tripped. Low.
6. **Password reset, resend, register, admin verify**: throttled (5-6/min) or manual. Low.
7. **Queue health alert** (`queue:monitor`, every 5 min): mails only on a problem, so a long outage repeats it. Skipped while the quota is tripped.

## Before turning the product mailers back on

- Digest: weekly instead of daily.
- New signatures: at most one mail per petition per day.
- Queue alert: at most one per hour, done.
- Digest runs with `--limit=100` per day and resumes from a cursor (`digest:cursor`), so one full pass over N recipients takes about N/100 days.
- Verification on guest sign: daily cap done (`verify_guest_daily_cap`).
- Check the real recipient count first (`petition:send-digest --dry-run`) and the plan limit in the SMTP2GO dashboard.

New queued mailables use the `GatedByMailQuota` trait; set `$transactional = true` only when losing the mail loses data.
