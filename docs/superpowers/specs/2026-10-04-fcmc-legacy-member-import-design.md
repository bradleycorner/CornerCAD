# FCMC — Legacy member history import (1991–2020) — design

**Date:** 2026-10-04
**Status:** approved in conversation, awaiting spec review
**Builds on:** `2026-09-10-fcmc-roster-import-design.md` (households, claim flow, `fcmc_paid_through_manual`
floor, PII rules) and `2026-09-09-fcmc-membership-system-design.md` (lifecycle, roster).

## Purpose

The board and membership want a **complete membership history**, not just who paid in 2026. The 2026
import (43 households) only knows 2026 payers, so a returning member — first case: old member #632,
who rejoined online on 2026-10-01 after lapsing in 2019 — is treated as brand new, and long-time
members show a 2026 "member since".

This import loads the club's old membership database so that:

1. every past member exists as a household with a correctly derived status;
2. `member_since` reflects the real first join date for current, returning and lapsed members;
3. a lapsed member who comes back is recognised automatically by email and keeps their history.

## Source data

**Google Sheet "Total DB Record - Members"** (Bradley's Drive, last modified 2020-09-19), sheet
`Total_Record`, A1:AX133 — about 130 data rows plus two footer rows ("Number of current cars /
members"). Kept by Bradley when he ran membership. Covers first entries from 1991 to 2020.

Older copies exist (`Total DB Record - Members.xlsx`, `-2.xlsx`, `.ods`, Oct 2019, 112 rows, same
columns, expired rows coloured red) and are **not** imported — the 2020 sheet supersedes them. The four
`.odb` files in `~/Documents` are LibreOffice front-ends (two point at this sheet, two are empty).

**Known gap — accepted:** nothing covers **2021–2025**. Members who renewed through those years will
show as lapsed in 2021 unless the 2026 data says otherwise; people who joined and left within the gap
are absent. Bradley is pursuing a source with Lisa. The importer is designed so a later file for those
years is one more run of the same command.

## Out of scope

- A members-only directory (active members only, honouring consents) — wanted later; this import
  stores the consents it will need.
- CSV export of the roster (still deferred from the 2026-09-10 spec).
- Home-area reports or maps (the data is stored for them).
- Backfilling 2021–2025.

## § 1 — Command

`wp fcmc import-legacy <sheet.csv> [--overrides=<file>] [--dry-run] [--undo=<batch>]`, added to
`fcmc-import-roster.php` — the file already reserved for one-off migration code, removable after the
migration. The pure logic (reading, translating, merging, deciding) lives in a new WordPress-free
library, `fcmc-legacy-lib.php`, so it can be unit-tested locally with plain `php`
(`fcmc/tests/test-legacy-lib.php`); the command reuses that file's helpers (household writer, email normaliser, `import_batch`) and
`fcmc_paid_through()` / status derivation from the lifecycle plugin. It has its own match-and-merge
logic because legacy data **enriches** existing records rather than creating fresh ones; folding it
into `import-roster` would put two merge policies in one function.

## § 2 — Matching

**Email only**, never names. A row's keys are its `PERSONAL_E-MAIL ADDRESS` and `E-MAIL ADDRESS 2`,
trimmed and lowercased. They are compared against:

- households: `member1_email`, `member2_email` (claimed or not);
- users: `user_email` and `fcmc_member2_email`.

A user and the household it has claimed count as **one** target, not two.

| Row matches | Outcome |
|---|---|
| one 2026 household (claimed or not) | **enrich** (§ 3) |
| one user with no household | **create a household from the row, linked to that user** (`claimed_by` + user's `fcmc_household_id`), then apply the same `member_since` rule to the user |
| nothing | **create an unclaimed household** with all mapped data |
| more than one distinct target | **skip + report** |
| junk — footer/total rows, no last name, or no email *and* no address | **skip + report** |

**Name-only near-matches** (sheet last+first name equals a household or user name, emails differ) are
**reported, never acted on**. Bradley resolves them in the overrides file.

**Overrides file** — CSV kept beside the sheet, outside the repo:
`legacy_member_id,action,target` where action is `link-household` (target = household post ID),
`link-user` (target = user ID), or `skip`. Overrides beat automatic matching.

**Duplicate rows for one person** (the sheet has a few, e.g. a second row holding only dates): rows
sharing an email are merged — earliest `member_since`, latest expiry, first non-empty value for every
other field — and the merge is reported.

## § 3 — Merge rule: 2026 data wins

When a row matches existing data, the sheet **adds history and never overwrites**:

| Field | On an existing household / user |
|---|---|
| `member_since` | set to the earlier of existing and sheet values (only ever moves earlier — same floor semantics as today) |
| `paid_through` (household; mirrored to the user's `fcmc_paid_through_manual` floor by the existing sync) | set to the later of existing and sheet values (usually unchanged because 2026 is later) |
| `legacy_member_id`, `home_area`, `birthdays`, `directory_listed`, `legacy_stat` | set if empty |
| cars | added only if the household has **no** cars |
| contact details (names, emails, phones, address), consents, newsletter preference | **never changed** |

A user's own car profiles, consents and contact details are never touched by this import.

## § 4 — Field translation

**Dates**

- `member_since` = earliest of `ORIG_ENTRY` and `JOIN_DATE`, stored `Y-m-d`.
- household `paid_through` = `EXP DATE` converted to the site convention: **5/31/YYYY → YYYY-06-01**,
  which is what `fcmc_paid_through()` yields for a paid year. Any other expiry date becomes the
  first 06-01 on or after it (e.g. 8/31/2015 → 2016-06-01) and is reported.
- No stored status — derived by the existing lifecycle code (`active` / `grace` / `lapsed` / `none`).
- Two-digit years read as 20xx. Unparseable dates are reported and left empty; never guessed.

**Cars** — up to two per row, same keys as `fcmc_car_profiles`:

| Sheet (car 1 / car 2) | Car field |
|---|---|
| `YRMANF` / `YRMANF 2` | `car_model_year` |
| derived from year | `car_generation`: NA ≤ 1997, NB 1998–2005, NC 2006–2015, ND ≥ 2016 |
| `PKG_EDIT` / `PKG_EDIT 2` | `car_package` |
| `COLOR` / `COLORB 2` | `car_color_body` |
| `Top COLOR` / `COLORT 2` | `car_color_top` |
| — / `PURCH_DATE 2` | `car_purchase_date` |
| `STATS_CAR/CALL NAME` + `TAG` / `CAR/CALL NAME 2` + `TAG 2` | `car_name` — call name; with a nickname too, `"Call name (Nickname)"`; nickname alone if no call name |

`TAG` is the car's nickname (confirmed by Bradley), not a licence plate. A car with no year and no
colour is not created. `Expr1032` is ignored.

**Consents** — `YES`/`Yes` → on, `NO`/`No` → off, **blank → off**:

| Sheet | Household meta |
|---|---|
| `NAME(S)` | `fcmc_consent_directory_name` |
| `REGISTRY_ADDRESS` | `fcmc_consent_directory_address` |
| `PHONE NUMBER` | `fcmc_consent_directory_phone` |
| `REGISTRY_E-MAIL ADDRESS` | `fcmc_consent_directory_email` |
| `CAR INFORMATION` | `fcmc_consent_directory_car` |
| `HOMEPAGE` | `fcmc_consent_public_name` |

`REGISTRY_CAR/CALL NAME` has no separate field; it is covered by the car consent.

**Newsletter** — `RoadRunner` (the club newsletter): `D` → `digital`, `P` → `paper`, blank → unset.

**People / contact** — `LastName1/FirstName1` → `member1_name`; `LastName2/FirstName2` →
`member2_name`; `PRIMARY PHONE` → `member1_phone`; emails as § 2; `PERSONAL_ADDRESS` + `APT`,
`CITY`, `STATE`, `ZIP` → address fields.

**New officer-only household fields** — `legacy_member_id` (`MEMBER_ID`), `home_area` (`HOMEAREA`,
used for choosing fair meeting and drive locations), `birthdays`, `directory_listed` (`Directory`
Y/N), `legacy_stat` (`STAT` C/E/P, reference only).

**Not imported** — `MARITAL` (not needed; household make-up is visible from member 2),
`C_COUNTER` / `P_COUNTER` (the site counts cars and people itself), `PERSONAL_ID`, `STATS_ID`,
`REGISTRY_ID` (duplicates of `MEMBER_ID`), `REGISTER`.

**Row key** (for re-runs, overrides and reports): `id:<MEMBER_ID>`, or `r:<8 hex>` — a hash of the
first email (or name) — for the 2019–2020 rows that have no `MEMBER_ID`. Keys carry no PII.

**Every household created** gets `fcmc_source = legacy-2020` and the run's `import_batch`.

**Claim flow is unchanged.** When a lapsed member returns and claims their household, history
(`member_since`, cars, member 2, paid-through floor) is copied as today. Consents and newsletter
preference are **not** copied — the member chooses them fresh on the signup form.

## § 5 — Roster changes (`fcmc-roster.php`)

- **Default view = current members** (`active` + `grace`). The status filter gains **"All (complete
  history)"**; `lapsed` and `none` remain selectable individually.
- **Counts stay whole-roster** (existing rule): the Lapsed chip grows by roughly 110; Active is
  unchanged.
- **No new columns.** `legacy_member_id`, `home_area`, `birthdays` are shown in the existing row
  detail / mobile card. Backfilled `member_since` appears in the existing Member since column.

- **Needs linking** lists only households that are expected to get an account: legacy-2020
  households that aren't current are summarised as "plus N former members" instead of listed one by
  one. They stay selectable in the Link control.

`fcmc-households.php`: the household edit meta box shows and saves the new officer-only fields.

## § 6 — Running it

PII rules from the 2026-09-10 spec § 8 apply in full: no member rows in the repo, commit messages,
docs, or session transcript — counts, headers and old member IDs only.

1. Export the sheet to `~/Documents/FCMC-private/fcmc-legacy-2020-members.csv` (gitignored by the
   existing `*-members.csv` rule).
2. One approved SSH session: copy to `~/fcmc-import/` (outside the web root), `--dry-run`, bring back
   the report only — counts per outcome, skip reasons, near-match list (old member IDs only).
3. Bradley decides near-matches → overrides file → second dry run.
4. On approval, one SSH session: real run, then delete the CSV and overrides from the server.

MCP cannot be used: mu-plugins are invisible to it and the account is behind Bluehost's `humans_`
challenge. SSH work follows the load rules in `docs/sites.md` (one batched session, no fan-out).

## § 7 — Delivery

Branch `feature/fcmc-legacy-import` from `develop`. firstcoastmiataclub.org is live, so the change
ships as a release: deploy the four mu-plugins (three changed + the new library) over SSH, byte-check against the repo, then merge to
`main` and `develop`.

## § 8 — Testing

1. **Translation tests** — a synthetic CSV (made-up people only) with one row per rule: date choice
   and 5/31 → 6/1, unparseable date, generation boundaries (1997/1998, 2005/2006, 2015/2016),
   car-name folding (call name only / nickname only / both), consents YES/No/blank, newsletter D/P/
   blank, each of the five match outcomes, a duplicate-person pair, footer rows. Run via WP-CLI with
   `--dry-run` against the live code; nothing written.
2. **Real dry run** — outcome counts must sum to the data-row count; old member #632 must resolve to
   "user match → create linked household, member_since 2012-02-11".
3. **After the real run** — roster default view shows only current members; "All" shows the history;
   counts correct; #632's card shows member since 2012 and the legacy details. One claim test: a
   throwaway account whose email matches a synthetic lapsed household links and copies history; both
   are deleted afterwards.

## § 9 — Rollback

`--undo=<batch>` reverses one run using a batch log stored in option `fcmc_legacy_import_log`:

- deletes the households that batch **created** (and removes `fcmc_household_id` from any user it
  linked);
- restores every value the batch **changed** on existing households and users (`member_since`,
  `fcmc_paid_through_manual`, and any field it filled) to the logged previous value — or deletes the
  key if it was previously absent.

Undo refuses to restore a value that has changed since the batch ran, and reports it instead, so an
officer's later edit is never silently reverted.
