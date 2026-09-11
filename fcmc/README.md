# First Coast Miata Club — site code

Custom code for **fcmc-dev.cornerfamily.com** (→ firstcoastmiataclub.org).

Design spec: `docs/superpowers/specs/2026-09-09-fcmc-membership-system-design.md`

## mu-plugins

Deployed to `wp-content/mu-plugins/` on the server. Must-use plugins load automatically —
there is no activation step, and they cannot be deactivated from wp-admin.

| File | Purpose |
|---|---|
| `fcmc-membership-registration.php` | Account-level member fields on the WooCommerce register / edit-account forms, plus `[fcmc_membership_signup]` — lets a logged-in member declare how many car-memberships they are buying, fill in each car, and adds one cart line per car with its own meta before checkout. |
| `fcmc-membership-lifecycle.php` | §1 of the membership design — `fcmc_paid_through()`, the derived active/grace/lapsed status, and the three cached fields on the WP user (`fcmc_paid_through`, `fcmc_status`, `fcmc_member_since`). Order hooks keep the cache honest in both directions; a nightly job refreshes status; `wp fcmc recompute [user_id]` rebuilds from order history. |
| `fcmc-roster.php` | §2 — the officer-facing **Club Roster**, as a My Account tab rather than a wp-admin screen. Adds the `membership_officer` role and the `fcmc_manage_members` capability. |
| `fcmc-newsletter-display.php` | `[fcmc_latest_newsletter]` and `[fcmc_newsletter_archive]`. Reads `Vol-XX-Issue-YY-*.pdf` straight from the Media Library, so uploading a new issue is the only step — the Newsletters page never needs editing. |

### Deploying

```bash
scp fcmc/mu-plugins/*.php \
  cornerfa:/home1/cornerfa/public_html/fcmc-dev/wp-content/mu-plugins/
```

Git is the source of truth. Edit here, then deploy — do not edit on the server, or the next
deploy silently reverts it.

### Not in this repo

- **`sso.php`** — the host's single-sign-on plugin (author: Garth Mortensen, Mike Hansen), not
  club code. Vendor-managed and overwritten by the host; deliberately excluded.
- **Checkout Field Editor fields** — the 20 household/vehicle/consent fields live in the
  `wc_fields_additional` database option, not in a file. Not yet versioned.
- **WPCode snippet 448** (`Display all Albums`) — lives as a `wpcode` post in the database.
- **WP Mail SMTP settings** — database option containing the Proton SMTP token. Must never be
  committed; read individual non-secret keys rather than the whole option.
- **Member data** — see `.gitignore`. This repo is public.

## Launch checklist

State as of **2026-09-10**. Site still on `fcmc-dev.cornerfamily.com`; DNS repoint deliberately
deferred until the flow is proven.

Done:

- [x] Guest checkout **off**, account creation at checkout **on** — closes the "paid but no
      registration record" defect the migration exists to fix. Configured, not yet proven
      end-to-end.
- [x] **Membership For WooCommerce removed** (plugin, its 18 orphaned `wps_*` options, its
      `wps_membership_expiry_check` cron hook, its auto-created $0 product 453, and its leftover
      draft page 434). WooCommerce Square removed. The mu-plugin flow is now the only membership
      implementation, per the design spec.
- [x] Sample Page trashed.
- [x] Page 12 rewritten as **Membership Dues Policy** (was the stock 30-day refund boilerplate,
      which was factually wrong — the club gives no refunds). Still `draft`.
- [x] Page 3 **Privacy Policy** rewritten for real. Still `draft`.

🚨 **Blocks launch — must be cleared before DNS repoint:**

- [ ] **Disable the Cheque gateway.** Enabled 2026-09-10 as a temporary offline method so the
      signup flow could be tested while WooPayments KYC is in review. Titled
      "TEST ONLY — Offline payment (remove before launch)" so it is self-announcing at checkout.
      **The club takes card only** — this must not go live.
      `wp option update woocommerce_cheque_settings --format=json <<< '{"enabled":"no"}'`
- [ ] **Finish WooPayments onboarding.** Account `acct_1UDVSEFsSfuxGsJY` is connected and live-mode
      but `status: restricted`, `details_submitted: false`, `payments_enabled: false`. The gateway's
      `needs_setup()` gates on `payments_enabled`, so **no card gateway appears at checkout until
      KYC completes**. Blocked on the EIN. This is the one hard blocker for the 2026-09-25 GoDaddy
      deadline.
- [ ] Publish the two policy pages once reviewed.
- [ ] `woocommerce_terms_page_id` is empty — no Terms page wired at checkout. Optional.
- [ ] **Revert debug logging.** `WP_DEBUG`/`WP_DEBUG_LOG` were turned on in `wp-config.php`
      2026-09-10 to catch the signup fatal. Backup of the original at
      `~/wp-config.fcmc-dev.bak-20260910`. Must be off before launch.
- [ ] **Site visibility is now `Live`.** Changed 2026-09-10 (`woocommerce_coming_soon` `yes` -> `no`)
      so a customer-role member could actually reach checkout. `blog_public` is `1`, so the dev site
      is indexable — decide whether to re-hide it, or leave it until the real domain goes live.
      `woocommerce_store_pages_only` is still `yes` and is inert while visibility is Live.
- [ ] **Test account `fcmc-test-member` has a known password** set 2026-09-10 for automated
      flow testing. Change or delete the account before launch.

## Debugging notes

**Where fatals actually land:** `wp-content/uploads/wc-logs/fatal-errors-*.log`. WooCommerce's own
logger writes there regardless of `WP_DEBUG`. The site-root `error_log` is drowned in AI Engine
`MWAI_WPAI_GATEWAY` noise and is not the place to look.

### Fixed 2026-09-10 — signup form fataled with a 500

`Call to a member function empty_cart() on null` at `fcmc-membership-registration.php:444`.

The cart handler is registered on `admin_post_fcmc_add_memberships_to_cart`, which runs through
`wp-admin/admin-post.php`. That is an **admin-context** request, and WooCommerce only builds the
session/customer/cart objects for frontend ones — `WooCommerce::is_request('frontend')` is
`( ! is_admin() || DOING_AJAX ) && ...` (`class-woocommerce.php:709`). So `WC()->cart` was null.

Fix: call `wc_load_cart()` (which does `initialize_session()` + `initialize_cart()`) before touching
the cart. Latent since the file was written — the flow had never completed end-to-end; it only
became reachable once a payment gateway existed.

Verified after the fix: POST returns 302 → `/checkout/`, session holds `product_id=14 qty=2` with
both cars' `fcmc_cars_data` intact and a $60.00 total.

### Coming-soon mode hides checkout from members, not from you

WooCommerce "Coming soon" + "apply to store pages only" lets administrators and shop managers
through to store pages but shows everyone else a coming-soon screen **that still carries the real
page's `<title>`**. So `/checkout/` returns `200` with a plausible title and a large body while
containing no checkout form at all.

Do not read a `200` as proof a page works. Check for the actual markup — `wc-block-checkout` for the
block checkout — or test as a customer-role user, never as an admin. Verified 2026-09-10: as
`fcmc-test-member`, checkout was 149,871 bytes with `wc-block-checkout` count `0` and WooCommerce's
"Great things..." copy present; after switching to Live it was 347,925 bytes with the block markup
present and the copy gone.

### Changed 2026-09-10 — signup button behaviour and header cart

Three related fixes after the flow was walked for real:

1. **Header cart was invisible.** `.site-header-cart` was always in the DOM and visible, but the
   8-item primary menu wrapped, orphaning "Club Store" onto a second row while the cart stayed on
   the row above. Scroll down slightly and the entire first row — all seven items *and* the cart —
   went out of view, leaving a nav that looked like it held one item. Fixed with stepped padding /
   font-size media queries in the `custom_css` post (16); thresholds measured on the live page and
   recorded in the CSS comment. Verified: 8 items on one row, cart on the same row.
2. **`empty_cart()` -> targeted removal.** The handler emptied the *entire* cart before adding, so
   anything else a member had would be silently destroyed. Now removes only lines whose
   `product_id` is `FCMC_MEMBERSHIP_PRODUCT_ID`, which still prevents duplicate memberships on
   re-submit. Verified: a 2-qty line re-submitted as 1 car became one line, qty 1, $30.00 —
   replaced, not stacked.
3. **Button relabelled and redirect changed.** "Add to Cart & Continue to Payment" promised one
   thing and did another — it replaced the cart and jumped past it to checkout. Now labelled
   **"Add to Cart"** and redirects to `wc_get_cart_url()`, so the member sees the line they just
   created and proceeds themselves.

### Changed 2026-09-10 — signup page is now renewal-aware

`$saved_cars` was read at the top of the shortcode and then **never used** — the JS built the list
from an empty template and ended with `addCar(); // always start with one car block visible`. So a
returning member with cars on file still saw a blank "Car 1". Prefill was intended and never wired.

Now: the list is seeded from `fcmc_car_profiles`, and **the list on the page IS the bill** — cars
shown are the memberships being bought, so removing one means not paying for it this year.

- Saved cars render **collapsed** (`2001 NB · "Solo"` / `Silver / Black · LS` / `$30.00`) with Edit
  and Remove. Their seven inputs stay in the DOM, hidden and populated, so the form posts exactly
  as before and **the handler is untouched**.
- New cars render expanded. A brand-new member still gets one empty block, as before.
- Live total = price x visible cars. The price is read from
  `wc_get_product( FCMC_MEMBERSHIP_PRODUCT_ID )->get_price()`, never hard-coded, so the page cannot
  disagree with what the cart charges.
- Removing every car disables Add to Cart and explains why. Submitting zero cars previously bounced
  back with `fcmc_error=1` and rendered nothing — that error is now displayed.
- Car block indices come from a monotonic counter, so removing a middle car cannot collide two
  blocks onto the same `cars[N]` key.

⚠️ **Consequence of "the list is the bill":** the handler mirrors whatever is submitted into
`fcmc_car_profiles`, so removing a car and checking out erases it from the saved profile
permanently — it does not merely pause payment for it. Accepted deliberately 2026-09-10.

### Built 2026-09-10 — §1 lifecycle and §2 roster

**§1 — `fcmc-membership-lifecycle.php`.** Club year June 1 -> May 31, never prorated. The April
rule ships as `4` but behind `apply_filters( 'fcmc_early_renewal_cutoff_month', 4 )` — if the board
says May, return 5 and re-run `wp fcmc recompute`. Nothing else changes. Grace is 60 days, also
filterable.

⚠️ **`on-hold` is deliberately NOT a paid status.** The offline/cheque gateway parks orders there
and an uncleared cheque is not a paid membership, so `fcmc_paid_order_statuses()` is
`processing` + `completed`. While the test gateway is the only one enabled, real checkouts will
leave everyone reading "never paid" — that is correct, not a bug.

Order hooks run in **both** directions: reaching a paid status recomputes the member, and *leaving*
one (refund, cancellation) clears the cached date — otherwise the cache would keep asserting a
membership the ledger no longer supports. `fcmc_member_since` never moves backwards on recompute,
so an officer's hand-entered join date survives an import.

Verified 2026-09-10 — all of the spec's worked examples plus edge cases:

| paid | -> paid through | |
|---|---|---|
| 2026-09-02 | 2027-06-01 | late payment for the current year |
| 2026-05-15 | 2027-06-01 | early renewal (45% of payments) |
| 2026-04-15 | 2027-06-01 | the unconfirmed April rule |
| 2026-03-31 | 2026-06-01 | just before the cutoff |

Grace boundaries: expiry day itself -> grace; day 59 -> grace; day 60 -> lapsed.

**§2 — `fcmc-roster.php`. Departs from the spec deliberately: the roster is a My Account tab, not a
wp-admin screen.** Officers are volunteers, not WordPress administrators; this way they sign in as
members and see one extra tab, themed like the club site, with no backend access to scope. The cost
is hand-rolling the table instead of getting `WP_List_Table` free — acceptable at ~48 households.
**Update the design doc to match.**

One row per household (= one WP user), never per transaction — so a **lapsed** member appears, which
is exactly what the Orders screen cannot do. Filters: status, and joined-on-or-after, the latter
being Mike's "new members since X" for the Road Runner rather than a separate report. Summary counts
are always whole-roster, never filtered.

Access is the `fcmc_manage_members` capability on a new `membership_officer` role (plus
administrators). Assign it per user in wp-admin. Verified: a plain `customer` sees no tab and gets
refused at `/my-account/club-roster/`; an officer sees the tab and the table.

mu-plugins have no activation hook, so the role creation and the **rewrite flush** run behind the
`fcmc_roster_version` option — without that flush the tab 404s until someone re-saves permalinks.

**Table layout, tuned 2026-09-10.** Cars are broken out per field — Year / Gen / Trim / Colors /
Car name — with one line per car in every car column, so a two-car household reads straight across
(verified: all five car cells in a two-car row render identical heights). Purchase date is
deliberately not a roster column; it is a "tell me about your car" detail, not something an officer
manages a roster by, and it already shows on the member's own account page.

Dates and car values are `white-space: nowrap` — a date broken over two lines is unreadable at a
glance, and a wrapped car value would destroy the line-per-car alignment.

Ten columns do not fit the standard My Account content column, so the roster tab **only** (scoped to
WooCommerce's `woocommerce-club-roster` body class) widens `.col-full` to 96vw and turns the account
nav into a horizontal strip above the table. Every other My Account tab keeps its normal sidebar.
Table font is 15px: measured at a 1000px window the table needs 1360px at the theme's 16px, 1030 at
15px, 933 at 14px, 916 at 13px. 15px fits any realistic officer laptop (a 1280px screen leaves
~1230 available) while staying readable for an older membership; below ~1100px it scrolls inside its
own container rather than breaking the page.

**Responsive: one breakpoint, and it is WooCommerce's.** `table.shop_table_responsive` — the class
on this table — already has WooCommerce CSS that stacks it at `max-width: 768px`, and those
selectors outrank anything in this file. So 768px is the real breakpoint whether or not we agree
with it, and this file matches it deliberately rather than competing.

That one breakpoint gives the club exactly what it asked for, for free: a phone is under 768px held
tall and over it held wide, so **portrait gets cards and landscape gets columns** with no
orientation query at all. Confirmed on a real device 2026-09-10.

⚠️ **Two wrong turns here, recorded so they are not repeated.** (1) Invented width tiers — 13px
between 1000-1149px, cards below 1000px — which guessed at device widths that cannot be measured
from the dev machine; being ~50px out truncated the last two columns in landscape. (2) An
`@media (orientation: …)` pair, which is the correct *statement of intent* but loses to
WooCommerce's more specific max-width rule, so landscape still rendered as cards on a narrow
screen. Verified by probe: at a 600x400 iframe the orientation rule matched (font dropped to 11px)
while the layout stayed cards.

⚠️ **Browser resize tooling does not work on this setup** — the window stays pinned at its current
width, so iframe probes wider than the parent window silently report the parent's width instead of
the requested one. Narrow widths can be verified with the same-origin iframe trick; anything wider
than the dev browser window must be checked on a real device. Do not size breakpoints from
arithmetic about hardware you cannot measure.

**Card layout, 2026-09-10.** Below 1000px the table stops being a table: each household
becomes a card, the header row is hidden, and every cell prints its own label from the `data-title`
attribute. `white-space: nowrap` and the widened container are both undone there — nowrap exists to
protect column alignment, and there are no columns left to align. Multi-car households join their
values with a bullet (`2001 · 2016`). The filter form stacks. Verified in a real 426px viewport: no
horizontal overflow, cards 381px wide.

⚠️ **WooCommerce's own responsive-table CSS competes here.** `table.shop_table_responsive tbody tr
td` is more specific than `.fcmc-roster td`, so WooCommerce wins plain ties — it, not this file, is
what actually sets `display:block` and injects the `data-title` labels. Suppressing the label on the
card's heading cell needs `!important`, or the card reads "Member:fcmc-test-member". Do not assume a
rule here applies without checking the computed value.

Still to do on §2: **CSV export honouring the per-field directory-consent flags**, deferred.

⚠️ **Test data on `fcmc-test-member` (user 2) is fabricated** — a second car ("Bluebird") and a
second household member ("Pat Hollis") were invented 2026-09-10 to verify multi-car row alignment.
Delete before the real roster import.

### Fixed 2026-09-10 — homepage officers list rendered vertically on phones

The `wp:columns` block holding the officer list (President, Vice President, …) collapsed to **zero
width** on phones, so its text wrapped one CHARACTER per line down the page. Measured in a real
426px viewport: `.wp-block-columns` computed `width: 0px`, `height: 6833px`, inside a `.entry-content`
that was 381px wide.

**The cause is not understood.** No CSS rule sets a width; core's stacking CSS is present and
correct (`flex-wrap:wrap!important`, with `nowrap` properly confined to `min-width:782px`); and a
block-level flex container in a block parent should never compute to zero. The second column of that
block is empty, which may be a factor.

`width:100%` is a **verified** fix (0px/6833px → 381px/508px); `display:block` also works. Applied
in the `custom_css` post scoped to `max-width:781px`, so desktop — which renders correctly — is
untouched. If this recurs elsewhere, suspect the same shape rather than re-deriving it.

**How to test mobile here:** the browser resize tooling does not change the page viewport on this
setup. Load the page in a same-origin `<iframe>` sized to 430px instead — media queries evaluate
against the iframe, giving a genuine phone render that can be measured.

### ⚠️ Open — the 20 Checkout Field Editor fields do not render on block checkout

The checkout page (post 10) is the **block** checkout (`<!-- wp:woocommerce/checkout -->`). All 20
fields are present in the classic `wc_fields_additional` option, but **zero** are registered with
the block checkout — confirmed three ways: `CheckoutFields::get_fields_for_location()` returns 0 for
contact/order/address; no `thwcfe-block/*` id appears in the rendered checkout HTML; no `fcmc_*`
name appears either. The plugin's block integration *is* active (its block CSS/JS enqueue), so this
is a section-placement issue, not a missing integration — the fields live in classic sections rather
than the block-compatible ones.

**Before fixing this, note the fields are largely redundant.** The mu-plugin already collects the
same data earlier and in a better place: `fcmc_account_fields()` captures 2nd household member,
newsletter preference and all six consent flags on the register / edit-account forms, and
`fcmc_car_fields()` captures the car details in `[fcmc_membership_signup]` before the cart. The
CFE set duplicates both. The mu-plugin's own docblock says checkout should stay 100% native
WooCommerce fields, which argues for **deleting the CFE fields** rather than porting them to block
sections. Decide before porting.
