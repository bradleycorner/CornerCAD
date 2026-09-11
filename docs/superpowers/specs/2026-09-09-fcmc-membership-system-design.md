# FCMC Membership System — Design

**Site:** fcmc-dev.cornerfamily.com → firstcoastmiataclub.org
**Date:** 2026-09-09
**Status:** §1 and §2 **built and verified 2026-09-10** (`fcmc-membership-lifecycle.php`,
`fcmc-roster.php`). §3 not started. One deliberate departure from this design is recorded in §2 —
the roster is a My Account tab, not a wp-admin screen.

## Scope

Covers three subsystems of the First Coast Miata Club site:

- **A — Membership lifecycle** (term calculation, status, renewals)
- **B — Roster and officer tooling**
- **C — Member communications** (dues reminders, weekly digest)

**Out of scope, own spec:** subsystem **D** — publishing, events-as-objects, social
cross-posting, photo gallery maintenance. Note §3 has a hard dependency on D (see below).

**Deferred (future enhancement):** printable club directory. The club used to produce one;
agreed 2026-09-09 to defer rather than build now.

## Decisions taken

| Decision | Value | Basis |
|---|---|---|
| Membership engine | Plain WooCommerce + focused mu-plugin. **No** membership plugin. | WooCommerce Memberships is $199/yr = 43% of the annual hosting saving, for ~3 of 8 features, and its fixed-date plans still need a manual edit every June. Revisit only if custom proves painful. |
| Dues unit | **$30 per car** | Confirmed 2026-09-09. Matches the built cart flow (one line per car) and the single $60 order in the data. |
| Members per household | **Two total**, cars are just cars | A couple with two Miatas pays $60 and remains two members. |
| Payment methods | **Card only** | Club stopped accepting cash/cheque. No offline payment recording needed. |
| Refunds | **None** — annual fee | Do not use "can't issue a refund" in any club-facing copy. The stock WooCommerce refund policy page is wrong and must not go live. |
| Lapsed handling | Keep account, mark lapsed | Preserves payment history and car details; rejoining is one click. |
| Membership record | **Stored on the WP user** (approach 2) | Absorbs the spreadsheet import without fabricating orders; supports manual officer corrections; makes the roster a fast user query. Orders remain the payment ledger. |

## § 1 — Term calculation and status

**BUILT 2026-09-10** as `fcmc-membership-lifecycle.php`, and verified against every worked example
below. Two things the original design did not mention, added because without them the cached
fields would misstate the ledger:

- **`on-hold` is not a paid status.** The offline/cheque gateway parks orders there and an
  uncleared cheque is not a paid membership, so only `processing` and `completed` count
  (filterable via `fcmc_paid_order_statuses`).
- **Order hooks run in both directions.** Reaching a paid status recomputes the member; *leaving*
  one (refund, cancellation) clears the cached date. Otherwise the cache would keep asserting a
  membership the ledger no longer supports.
- `fcmc_member_since` never moves backwards on recompute, so an officer's hand-entered join date
  survives the roster import.

The cutoff constant ships as `4` behind `apply_filters( 'fcmc_early_renewal_cutoff_month', 4 )`, so
the board's answer is a one-line change plus `wp fcmc recompute`.

Club year runs **June 1 → May 31**. No proration: paying late still expires the following
June 1.

The whole calculation reduces to one function with one tunable constant:

```php
function fcmc_paid_through( DateTime $paid ): DateTime {
    // Payments from this month onward count toward the UPCOMING club year.
    $EARLY_RENEWAL_CUTOFF_MONTH = 4;   // April. Board may confirm 5 (May).
    $year = (int) $paid->format('Y');
    return new DateTime( sprintf(
        '%d-06-01',
        (int) $paid->format('n') >= $EARLY_RENEWAL_CUTOFF_MONTH ? $year + 1 : $year
    ) );
}
```

| Paid | Covers through |
|---|---|
| Sep 2, 2026 | June 1, 2027 (late payment for the current year) |
| Jun 15, 2026 | June 1, 2027 |
| May 15, 2026 | June 1, 2027 (early renewal — 45% of payments look like this) |
| Apr 15, 2026 | June 1, 2027 (**the unconfirmed rule**) |
| Feb 10, 2027 | June 1, 2027 |

**The unconfirmed April rule is a single constant.** Board says April → `4`; board says early
renewal opens in May → `5`. Nothing else changes, so implementation is not blocked on the answer.
The data already rules out `6`: 19 members paid in May 2026 expecting a full year.

### Status

Derived, then cached on the user by a nightly job so the roster is a fast query:

- **active** — `today < paid_through`
- **grace** — `paid_through ≤ today < paid_through + 60 days`
- **lapsed** — beyond that

Three user meta fields: `fcmc_paid_through`, `fcmc_status`, `fcmc_member_since`. A `recompute`
routine can rebuild them from order history to catch drift.

A lapsed member who pays later needs no special handling — the new payment recalculates
`paid_through`. This covers the 4-of-42 who paid after grace expired in 2026 with no intervention.

## § 2 — Roster and officer view

**BUILT 2026-09-10** as `fcmc-roster.php`.

### ⚠️ Departure from the original design: My Account tab, not a wp-admin screen

This section originally specified a **Members screen in wp-admin**. It was built instead as a
**Club Roster tab inside My Account**, on Bradley's call 2026-09-10.

The reason: club officers are volunteers, not WordPress administrators. A wp-admin screen means
handing an officer a backend login and trusting a role to fence them in. As a My Account tab they
sign in exactly as any member does and simply see one tab more, themed like the rest of the club
site, with no backend surface to scope.

The cost, accepted knowingly: wp-admin would have supplied `WP_List_Table` free — sorting,
pagination, search, bulk actions, CSV plumbing. On a front-end endpoint the table is hand-rolled.
At ~48 households that is a fair trade; pagination barely matters at this size. Revisit only if the
roster grows enough that hand-rolled paging becomes the limiting factor.

### As built

One row per **household** (= one WP user; a household holds up to two members and any number of
cars), never one row per transaction. Columns:

- primary member (name + email), second member (name + email)
- status chip (active / grace / lapsed / never paid), `paid_through`, joined date
- the cars, broken out per field — year, generation, trim, colours, name — with one line per car
  so a two-car household reads across cleanly

Filter by status; filter by joined-on-or-after — that filter *is* Mike's "new members since X" for
the Road Runner, rather than a separate report, and the page says so when the filter is active.
Summary counts are always computed across the whole roster, never the filtered view, so narrowing
to "lapsed" cannot make the club look like it has shrunk.

Access is the `fcmc_manage_members` capability, carried by a new `membership_officer` role (which
inherits everything `customer` can do, since an officer is a member first) and granted to
administrators. Assign the role per user in wp-admin.

**Implementation note.** mu-plugins have no activation hook, so role creation and the rewrite flush
run behind an `fcmc_roster_version` option. Without that flush the tab 404s until someone re-saves
permalinks by hand.

**Still outstanding:** CSV export honouring each household's per-field directory-consent flags.

Two things the WooCommerce Orders screen cannot do, which is why this exists: show **lapsed**
members (who have no recent order to sort by), and show a **household** rather than an order.

## § 3 — Member communications

### Deliverability (foundation)

WordPress default mail from a shared Bluehost IP lands in spam often enough that a dues reminder
would fail silently — the worst outcome, since nobody notices until renewals don't arrive.
Requires WP Mail SMTP against an authenticated sender with SPF and DKIM on the domain. Volume is
trivial (~48 households) so this is a configuration decision, not a cost one.

### Mail: as built and verified 2026-09-09

Club mail is on **Proton** (`info@`, `membership@`), independent of GoDaddy — the 2026-09-25
cancellation cannot affect it. Proton **SMTP submission is available on the club's plan**, so no
third-party sender is needed and no DNS changes were required.

- **WP Mail SMTP 4.9.0**, mailer `smtp`, Proton host on **587 / STARTTLS**, authenticated
- Sends as `membership@firstcoastmiataclub.org`, name "First Coast Miata Club of Jacksonville FL",
  **From forced** so WooCommerce and CF7 cannot substitute an unauthenticated sender
- **mail-tester.com score 10/10** (`test-sbgeql4rj`, 2026-09-09 10:36 UTC):
  SPF **pass** (`v=spf1 include:_spf.protonmail.ch ~all`) · DKIM **valid**, 2048-bit,
  `d=firstcoastmiataclub.org` · **DKIM_VALID_AU pass** (signed by the club's own domain, not
  Proton's) · DMARC **pass** against `p=quarantine` · rDNS present · not on any of 20 blocklists ·
  SpamAssassin 0.2

rDNS and author-domain DKIM matter specifically here: the membership skews to AOL, BellSouth,
Comcast and Earthlink, and AOL rejects outright from servers without rDNS.

🔧 **Build requirement — List-Unsubscribe.** The test flagged its absence. Correct for dues
reminders (transactional, no unsubscribe). **Required for the weekly digest**: Gmail and Yahoo
demand one-click unsubscribe from bulk senders, and without it the digest gets throttled once it
goes to ~100 recipients at once. Implement `List-Unsubscribe` and `List-Unsubscribe-Post` headers
on the digest send path only.

Credentials were entered by Bradley directly in wp-admin and have never appeared in a transcript
or in git. Do not read the `wp_mail_smtp` option wholesale — it contains the SMTP token; pluck
individual non-secret keys instead.

### Two mail categories — must stay separate

- **Dues reminders** — transactional. No unsubscribe.
- **Weekly digest** — bulk. Requires a working opt-out.

Collapsing them fails badly: someone opts out of the digest in March and misses their renewal
notice in May.

### Dues reminder schedule

Derived from actual 2026 payment timing (45% May, 33% June, 12% July, 10% after grace):

1. **Early May** — renewal opens. Does most of the work.
2. **~May 25** — second notice to the unpaid.
3. **June 1** — dues due.
4. **During grace** — reminders.
5. **End of grace** — lapse notice. **Queued for Mike to send, not automatic** — 4 of 42 members
   paid after grace expired last year and would have received a wrongful removal notice.

Sent to **both addresses on file** where a second exists (17 of 37 households) — the person who
reads club mail is not reliably the one who paid.

### Weekly digest

A mini-newsletter rather than one email per item.

- **Friday morning, automatic.** Skips empty weeks silently.
- **New publications only.** Recorded on the status transition *into* published, stamped once.
  Editing a published post fires no transition, so edits and drafts are excluded naturally.
- **Audience:** active + grace members, both household emails.
- Example: "This week: 2 events, 3 posts."

## Data migration

Source of truth for the seed roster is **Lisa/Mike's spreadsheet** (not yet received).

Reconstructed evidence assembled 2026-09-09, in `~/Desktop/temp/`:

| File | Contents |
|---|---|
| `fcmc-paid-orders-linked.csv` | 42 paid orders (May 1 – Sep 9), 35 with email recovered by matching form-submission timestamps to card charges |
| `fcmc-jobforms-all.csv` | 37 households with both members, both emails, address, car. 29 join to a paid order |

Known gaps: 8 form submissions with no matching payment — 3 of those submitted inside the
transaction window and are genuine non-payment candidates, the other 5 fall outside it and may
well have paid; plus 6 payments in early May whose form records were not captured. Individuals
are identified in the local working files only, never in this repo.

⚠️ **The 415-contact export is NOT the roster.** 300 of those are contact-form traffic with no
phone or address; 115 are anyone who ever transacted since 2018. Its `Member` flag is set on 7
records from 2018 and means "had a GoDaddy website login". The roster is ~48 paid households.
The 415 is an announcement list, useful for subsystem D outreach only.

## Open questions

1. **The April rule** — does a member joining Apr 1 – Jun 1 get through the *following* June 1?
   Affects ~6 people/year. Board question. One constant.
2. ✅ **RESOLVED AND VERIFIED 2026-09-09 — outbound mail is done.** See "Mail: as built" below.
3. **Road Runner overlap** — a weekly digest overlaps Colin Busch's monthly newsletter.
   Organisational, not technical. Talk to him before the first send.
4. **Events are not objects** — the Event Calendar is hand-written prose. §3's digest cannot
   collect events until they are real records. Blocks part of the digest; belongs to subsystem D.

## Why this exists

Answering "who paid?" today requires matching form-submission timestamps to card charges within a
60-minute window, then hand-scraping a web inbox — because registration and payment are separate
steps with no shared record. The current form also has no validation: four date formats in twenty
submissions, a letter `O` typed for a zero, a wrong zip nobody caught, one member entered twice on
the same day.

One transaction, one record is the point of the whole exercise.
