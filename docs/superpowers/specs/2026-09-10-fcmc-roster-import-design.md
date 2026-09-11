# FCMC Roster Import — Design

**Site:** fcmc-dev.cornerfamily.com → firstcoastmiataclub.org
**Date:** 2026-09-10
**Status:** Design agreed. Not yet implemented.
**Depends on:** `2026-09-09-fcmc-membership-system-design.md` §1 (lifecycle) and §2 (roster), both
built 2026-09-10.

## Scope

Getting the club's real membership into the new site, given that every 2026 payment happened on
GoDaddy/Square and none of it exists as a WooCommerce order.

Covers: where imported households live, how term dates are derived, how a member's future account
attaches to their imported record, and the importer itself.

**Out of scope:** CSV export of the roster (deferred 2026-09-10); dues reminders and the weekly
digest (§3 of the membership design, not started); any change to the signup or checkout flow.

## Decisions taken

| Decision | Value | Basis |
|---|---|---|
| Accounts at import time | **None.** Roster records only. | No account emails, no passwords, nothing a member must act on. Members create their own account when they renew. |
| Record storage | `fcmc_household` **CPT** | Officer-editable in wp-admin, queryable, survives with or without an account. |
| Link key | **email1 OR email2**, normalised | A household has two members; either may register first. |
| Unmatched registration | **Officer links it by hand** | Nothing auto-merged. Surnames repeat; a wrong merge joins two households and is hard to unpick. |
| Imported households in counts | **Yes** | A household that paid in 2026 is a member whether or not it has logged into the new site. The roster should reflect the club, not the migration's progress. |
| Source for the first load | **The reconstructed CSVs**, now | Officers get a usable roster immediately rather than waiting on a file outside Bradley's control, with the 2026-09-25 GoDaddy date close. |
| Ragged edges | **Import both**, flagged | 8 form-only households and 6 payment-only records are probably real members; dropping them loses people silently. |

## § 1 — Source data

Two files in `~/Desktop/temp/`, **never committed** (see §8). Counted directly 2026-09-10 rather
than taken from the earlier design's round numbers.

**Be precise about what is and is not reconstructed here** — the earlier design called this whole
dataset "reconstructed evidence", which overstates it:

- **Household details are authoritative.** Names, phones, emails, address and car were pulled
  **directly from GoDaddy's form submissions** by Bradley. They are what the member typed. They are
  not inferred, and they do not need the spreadsheet to validate them.
- **Payments are authoritative.** Dates and amounts come from Square's transaction report.
- **Only the LINKAGE between the two is inferred**, by matching form-submission timestamps to card
  charges inside a ~60-minute window. That inference affects `paid_through` and `member_since` and
  nothing else. A household's contact details are correct even where its payment match is wrong.

The practical consequence: an officer reviewing this roster should trust the names and addresses and
scrutinise only the dates.

```
paid orders                    42
  with an email                35
  NO email (unattributable)     7
form households                37
  matched to a payment         29
  no matched payment            8
payment-only (email, no form)   6

households to create           43   (37 form-based + 6 payment-only)
```

`fcmc-jobforms-all.csv` — `form_date, member1, phone1, email1, member2, phone2, email2, address,
city, state, zip, car`
`fcmc-paid-orders-linked.csv` — `paid_date, order_number, amount, email, match_minutes, cars`

### Provenance is recorded, not discarded

Every household carries a `fcmc_source` field: `form+payment`, `form-only`, or `payment-only`. This
is load-bearing for two reasons. It tells an officer why a thin record is thin — a `payment-only`
household has an email and a date and nothing else because the form was never captured, not because
someone did a bad job entering it. And when the real spreadsheet arrives (§9), it is what makes
reconciling two imperfect sources tractable.

### Known residue

**7 payments — $210 — carry no email and cannot become households.** They are surfaced to officers
as a reconciliation list rather than dropped. Do not quietly discard them; somebody paid.

**One email appears to span two form rows** (28 distinct matched emails against 29 matched forms).
The importer merges on normalised email and flags every merge for review.

## § 2 — Storage model

A `fcmc_household` custom post type, not users and not a custom table. Users were rejected because
creating 48 accounts nobody asked for invites stray account emails and password confusion; a custom
table was rejected because officer-editable records in wp-admin come free with a CPT and the volume
(~48 rows) makes the performance argument irrelevant.

Post meta per household:

| Field | Notes |
|---|---|
| `member1_name`, `member1_phone`, `member1_email` | Primary member |
| `member2_name`, `member2_phone`, `member2_email` | Second member; may be empty |
| `address`, `city`, `state`, `zip` | |
| `cars` | Array, same shape as `fcmc_car_profiles` on a user, so a linked account can adopt it unchanged. Imported rows populate `car_model_year` and a free-text `car_description` only — see §10 |
| `paid_through`, `member_since` | Derived — see §3 |
| `fcmc_source` | `form+payment` \| `form-only` \| `payment-only` |
| `claimed_by` | User ID once linked, else empty |
| `import_batch` | Timestamp of the run that created it, so a bad run can be identified and reversed |

## § 3 — Term derivation

Each household's `paid_through` is produced by running its payment date through the **existing**
`fcmc_paid_through()` from `fcmc-membership-lifecycle.php` — not a parallel implementation. Imported
and native members must be computed identically, and the April cutoff must remain a single knob
(`fcmc_early_renewal_cutoff_month`). A May 2026 payment therefore lands on 2027-06-01.

`member_since` is the earliest known payment date for the household. `form-only` households get no
`paid_through` and read **never paid** — visible to officers rather than presented as current.

## § 4 — Linking an account to a household

On `woocommerce_created_customer` and `user_register`, look for an **unclaimed** household whose
`member1_email` or `member2_email` matches the new user's address, trimmed and lowercased.

**On match:** set `fcmc_household_id` on the user and `claimed_by` on the household, then copy onto
the user: `fcmc_paid_through_manual`, `fcmc_member_since`, the second member's name/phone/email,
and `fcmc_car_profiles`. The member's first renewal is then prefilled with the car the club already
knows about, which is the whole point — the signup form reads saved cars and would otherwise show a
returning member a blank form.

**On no match:** the account is created normally and the household stays unclaimed. Nothing is
guessed. See §6 for how an officer resolves it.

## § 5 — Imported dates are a floor, not a cache

⚠️ **This fixes a real bug in the built lifecycle code.** `fcmc_recompute_member()` currently does:

```php
if ( empty( $orders ) ) {
    delete_user_meta( $user_id, 'fcmc_paid_through' );
    update_user_meta( $user_id, 'fcmc_status', 'none' );
```

Every imported member paid on Square and has **no WooCommerce orders**. Their date would be silently
wiped by the first `wp fcmc recompute`, or by any order of theirs changing status — and they would
then be chased for dues they had already paid.

The fix is not a "don't touch me" flag but a change of semantics:

```
paid_through = max( fcmc_paid_through_manual, computed-from-orders )
```

The import becomes a floor. An order can extend it, never erase it — which is the correct reading
anyway, since a 2026 Square payment really did happen and no amount of recomputing makes it
un-happen. `fcmc_recompute_member()` must therefore stop deleting the key and fall back to the
imported value.

## § 5a — Officers can set a membership by hand

Added 2026-09-10 at Bradley's request: an officer must be able to correct a household's paid status
directly, without a payment existing.

This is the **same mechanism as the import**, not a second one. The baseline key is therefore named
for what it is — `fcmc_paid_through_manual` — and holds either an imported date or one an officer
typed. Both behave identically:

```
paid_through = max( fcmc_paid_through_manual, computed-from-orders )
```

An officer editing a household sets `fcmc_paid_through_manual`; the status chip re-derives
immediately and survives every later recompute.

⚠️ **Known limit, accepted:** because the rule is `max()`, an officer cannot set a date *earlier*
than the member's own WooCommerce orders justify. That is deliberate — orders are the payment
ledger, and a real payment should not be erasable from the roster screen. A genuinely wrong order is
corrected on the order, which recomputes the member automatically. For imported households, which
have no orders, the officer's value simply wins.

## § 6 — Roster changes

The roster (`fcmc-roster.php`) currently lists WP users. It must list **households**, of which a user
account is an optional attachment:

- claimed households render as they do now, driven by the linked user
- unclaimed households render identically but with no account behind them
- counts and status chips cover **all** households, claimed or not

New officer view — **Needs linking** — showing unclaimed households alongside members who registered
without matching one, each with a Link control. This is the designed landing place for the no-match
case and for the 7 unattributable payments.

Both existing layouts carry over unchanged: the table above WooCommerce's 768px breakpoint, the card
layout below it.

## § 7 — The importer

A WP-CLI command, `wp fcmc import-roster <forms.csv> <orders.csv>`:

- **`--dry-run` prints what it would do and writes nothing.** The first real run must be preceded by
  one.
- **Idempotent.** Re-running does not duplicate: households are keyed on normalised
  `member1_email`, and an existing record is updated rather than added. Re-runnability matters
  because the first load will be wrong in some detail and will be re-run.
- Merges rows sharing an email, and reports each merge.
- Reports, per run: created, updated, merged, skipped, and the unattributable-payment list.
- Reads from an explicit path **outside the repository** (§8).

## § 8 — PII handling

This repo has a public remote. Member names, home addresses, phone numbers and emails must never
enter it.

`.gitignore` already blocks `*-jobforms*.csv`, `*-paid-orders*.csv` and `*-members.csv`, and no CSV
is tracked under `fcmc/` — both verified 2026-09-10. The importer takes a path argument rather than
reading a fixed in-repo location, so source data stays on Bradley's machine.

Do not paste member rows into commit messages, docs, or a session transcript. Counts and headers
only.

Checked 2026-09-10 and satisfactory, but re-check after any change to roles or visibility:
anonymous `GET /wp-json/wp/v2/users` returns only users with published posts (it listed one), and
`?author=N` for a customer 302s to `/shop/` without leaking a slug. The roster endpoint is gated on
`fcmc_manage_members`.

## § 9 — Reconciling with the real spreadsheet

Lisa/Mike's spreadsheet has not arrived. Importing first is a deliberate trade: officers get a
roster now, and the cost is that the spreadsheet will later have to be merged into **live records**
rather than loaded into an empty table.

That cost is **smaller than it first appears**, per §1: the household details came straight from
GoDaddy and are what the member typed, so the spreadsheet is unlikely to contradict them in any way
worth acting on. Expect it to be useful mainly for the dates — the inferred payment linkage, the 8
form-only households, and the 7 unattributable payments.

`fcmc_source` and `import_batch` are what make that merge tractable — they identify which fields
were reconstructed (and so may be overridden freely) versus entered by an officer since (and so must
not be clobbered). When the spreadsheet lands: dry-run first, treat spreadsheet values as
authoritative for `form-only` and `payment-only` records, and report every conflict on a
`form+payment` record for a human decision rather than resolving it automatically.

## § 10 — Car data is free text and mostly unparseable

Measured 2026-09-10 across all 37 form rows:

```
'2020 Mazda Miata RF'   '2012/Copper Red'   '1999 / Black'
'2001 Red LS'           '2003 Blue'         'NA.....Yet'

rows starting with a 4-digit year   30 / 37
rows empty                           0 / 37
payment amounts                     41 x $30,  1 x $60
```

Three consequences:

**Import the car field verbatim as a single `car_description`.** Do not attempt to parse trim or
colour — `'2012/Copper Red'`, `'2001 Red LS'` and `'NA.....Yet'` have no shared grammar, and
guessing wrong produces exactly the mess the membership design exists to end ("four date formats in
twenty submissions, a letter O typed for a zero"). One row says the member does not have a Miata
yet.

**Extract `car_model_year` only**, where the value matches `^(19|20)\d{2}` — 30 of 37 rows. That
populates the roster's Year column; Gen, Trim, Colors and Car name stay empty for imported
households until the member fills them in at their first renewal, which the signup form already
prompts for.

**Exactly one household has two memberships** — the single $60 payment. The four form rows
containing a comma or slash are describing one car, not two, which the payment amounts confirm. The
importer must therefore **not** split the car field on punctuation; it creates one car per
household, and the $60 household is corrected by hand.

## Open questions

1. **The 7 unattributable payments** ($210) need a human pass against Square's records; the site
   cannot resolve them. They are surfaced in the **Needs linking** view (§6), not dropped.
2. Nothing here is blocked on that question.
