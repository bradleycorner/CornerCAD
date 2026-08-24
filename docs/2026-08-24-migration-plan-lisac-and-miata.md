# Migration plan — uniquecreationsbylisac.com and firstcoastmiataclub.org

Written 2026-08-24, building on what the CornerCAD build actually taught us. Two very different
migrations onto the same hosting: one is a near-clone of CornerCAD, the other is not a store at all.

**Status: plan only.** Nothing here is decided. The open questions are marked and several need
answers before any work starts.

**Update, same day (later 2026-08-24):** several of the open questions below are now answered —
see the "RESOLVED" callouts inline in Parts 1 and 2. Superseded assumptions are struck through
rather than deleted, so the reasoning that led to the correction stays visible.

---

## Part 0 — What carries over

These were all learned the expensive way on CornerCAD. Applying them from day one is most of the
value of doing these second and third rather than first.

### Environment (identical — same host, same account)
- `ssh cornerfa` — **hostname, never the IP.**
- WP-CLI only ever as:
  `cd <site root> && /opt/cpanel/ea-php83/root/usr/bin/php /usr/local/bin/wp <cmd>`
  All three parts load-bearing: `cd` for `wp-cli.yml`, absolute ea-php83 binary, and `--user=1`
  for anything touching product capabilities.
- **Never a curl-based cron.** Cloudflare bot-challenges it; that silently killed WP-Cron for weeks
  and deadlocked Action Scheduler with 477 orphaned claims. Use `DISABLE_WP_CRON` + a cPanel cron
  running WP-CLI.
- ⚠️ If cron runs every 5 minutes, **raise `action_scheduler_failure_period` above 300s**
  (`snippets/wpcode-action-scheduler-failure-period.php`). The default watchdog equals the cron
  interval, so a long job runner gets killed by the next tick and leaks its claim.
- LiteSpeed kills web requests at ~80s regardless of PHP settings. **Any bulk import must run via
  WP-CLI**, never the admin button. See the `square-woo-import-cli-method` memory.
- All ~50 sites share one CloudLinux cage and UID. Request volume against one site can take the
  whole account down — it did, 2026-07-28.

### WooCommerce ↔ Square rules (hard constraints, verified in plugin source)
- **Square is the system of record.** Name, price, description, category and images flow FROM
  Square. Editing them in Woo works until the next sync silently reverts it.
- **One variation dimension per product.** `has_multiple_variation_attributes()` drops a product
  with two from the sync — no error, it just stops appearing. Flatten the second axis into the
  label.
- **Variations must come from a Square OPTION SET.** Hand-made variations produce a storefront
  dropdown labelled literally "Attribute".
- **Never reuse the parent SKU on a variation.** It deleted a Woo product and swallowed a variation.
- Converting an item to variable **deletes and recreates** the Woo product with a `-2` slug.
- **Modifiers do not sync to Woo at all** — POS and Square Online only.
- **Location scoping, not SKU prefix, is the guard** that separates catalogs. A SKU-less item has
  no prefix to filter on and will bleed.
- 🐛 Known open defect: variable products revert to out-of-stock on every sync. Unresolved.

### Content and process
- Descriptions authored in `content/*.md`, pushed to Square, pulled by sync. The `---` separator
  splits excerpt from Description tab via a WPCode snippet.
- ⚠️ WPCode **caches snippets** — a database edit does not take effect until the snippet is re-saved
  in the UI.
- Catalog mode (a `woocommerce_is_purchasable` → `__return_false` snippet) keeps a site
  browsable but unbuyable until launch. Use it on both.
- **Verify against production-shaped data**, never hand-authored fixtures. A broken filter passed a
  test written by hand and failed on the first real record.

---

## Part 1 — uniquecreationsbylisac.com

~~The easy one. Same shape as CornerCAD: physical products, Square catalog, same Square account.~~

**RESOLVED — this is not a from-scratch build.** `uniquecreationsbylisac.com` is a live
**Square Online** storefront today (`<meta name="generator" content="Square Online">`, served via
Square's Weebly-derived infrastructure) — checkout, catalog display, everything already works
end-to-end on the same Square account/location this doc already references. The task is **migrate
off Square Online onto WordPress/WooCommerce**, not stand up a store from nothing. Reason, from
Bradley directly: **cost** — Square Online runs ~$350/year on top of normal Square processing fees,
and WooCommerce + WooCommerce Square is free — plus two capabilities Square Online doesn't give her:
tighter social cross-posting and a public event calendar. See "New requirements" below.

`uniquecreationsbylisac.store` is **purchased but unconfigured** — no DNS response on port 80 or
443 as of 2026-08-24. Whether the new WordPress site lands on `.store` or replaces `.com` is
**still open** — Bradley wants to discuss it, not decided yet.

### What's already true
- Lisa's products already exist in the **same Square account** (`ML0RSAXT9HH0B`) under
  location **`L71MXVF5YWZE4`** ("Unique Creations by Lisa C, LLC"), with `UCL-` SKUs.
- The CornerCAD audit counts **64 items** at other locations that CornerCAD's sync correctly skips.
  Those are largely hers.
- Product families seen while working: chainmail, pendants, earrings, jewellery.

### ⚠️ The architectural decision, and it comes first

**"Same WordPress instance" needs defining, because one WooCommerce install cannot cleanly run two
storefronts.** Three options:

| Option | How | Trade-off |
|---|---|---|
| **A. Multisite** | One WP network, two subsites, each with its own Woo + Square connection | Cleanest separation; each subsite connects to its own Square location. Multisite adds real operational complexity, and plugin/theme updates hit both. |
| **B. Two separate installs** | Second WP in its own directory, own database prefix | Simplest to reason about, fully isolated, no new concepts. Two of everything to maintain. |
| **C. One store, two brands** | Single Woo, categories/brands split the catalog | ❌ Not recommended. One Woo Square connection means one location, so both catalogs would import into one store. Branding, checkout and email would all be shared. |

**RESOLVED — B, two separate installs. Confirmed by Bradley 2026-08-24.** Multisite's benefit is
shared user accounts and central updates, neither of which matters here — the two stores have
different customers and different products. B keeps the CornerCAD blast radius exactly where it
is, and everything learned above applies unchanged.

~~⚠️ **Check first:** does the WooCommerce Square plugin licence permit a second site?~~
**RESOLVED — not a blocker.** WooCommerce Square is **free**, distributed on WordPress.org
(80,000+ active installs), no per-site license key. Confirmed via web search 2026-08-24; the
plugin already installed on cornercad.com (v5.4.3) came from the same free channel. There is no
licensing cost gate on running a second install.

### New requirements (from Bradley, 2026-08-24 — not in the original scope)
- **Public event calendar.** Lisa does onsite events (craft fairs, markets) around North Florida.
  Goal is better advertising of when/where she'll be, to build a following. This is read-only
  promotion, not a booking system — a lightweight WordPress custom post type or a free calendar
  plugin (e.g. The Events Calendar core) covers it. No paid plugin needed.
- **Custom-order consultation booking — nice-to-have.** She advertises custom orders. Bradley
  looked at WooCommerce Bookings and ruled it out as too expensive for now. **Square Appointments**
  is the fallback and is "probably good enough" (Bradley's words): it has a genuine free plan
  (3.3%+30¢ per transaction on Free vs 2.9%+30¢ on paid tiers — no monthly fee), and its booking
  widget/button can be embedded on a WordPress page. ⚠️ The booking flow itself happens on Square's
  domain, not WordPress — embed is a link/button, not a native in-page experience. Confirmed via
  web search 2026-08-24; verify current pricing/embed mechanics at build time, terms move.
- **Social cross-posting.** When she adds a product, it should auto-post to Instagram Business and
  Facebook. **Jetpack Social's free tier does this out of the box for WooCommerce products** —
  WooCommerce ships with Jetpack Social support already wired to the `product` post type, no extra
  plugin config beyond connecting the accounts. ⚠️ Sources disagree on the free-tier share cap:
  one says unlimited since a Sept 2024 policy change, another (WP Tavern) says a 30-shares/month
  cap exists. For a small craft business posting new items a few times a week either way is
  probably fine, but **verify the actual current cap when Jetpack Social is connected** rather than
  assume — same "don't trust the docs" lesson as `has_multiple_variation_attributes`.

### Catalog audit — RESOLVED, ran 2026-08-24 via the Square MCP connector directly
(No token export or script run needed — the Square MCP connector already has account access.
`scripts/square_audit_catalog.py --location L71MXVF5YWZE4` remains available as a fallback but
wasn't required this pass.)

- **64 items** present at `L71MXVF5YWZE4`, out of 243 total across the whole Square account.
- **33/64 (52%) missing descriptions** — same class of finding as CornerCAD's initial audit.
- **0/144 variations missing a SKU** — clean. All 144 variation SKUs are present and correctly
  `UCL-`-prefixed; no non-prefixed SKUs found, so no bleed risk like the CornerCAD "Celtic Line"
  incident.
- **29 items with plural-looking names** — needs Lisa's/Bradley's judgment on whether each is a
  single piece or a set, same as the CornerCAD naming question.
- **23 multi-variation items, checked against the two-axis landmine** (`has_multiple_variation_attributes()`
  silently drops a product with two option dimensions from Woo sync) — **all 23 use a single
  option dimension**, zero at risk. Largest: Full Persian Bracelet (12 variations), Byzantine
  Chainmail Bracelet (11), Barrel Weave Bracelet (9).

### Sequence
1. ~~Decide A/B/C above. Confirm the Square plugin licence covers a second site.~~ Done — see above.
2. **Decide the `.com` vs `.store` domain question** (open, Bradley wants to discuss).
3. Stand up the install; set `DISABLE_WP_CRON` + WP-CLI cron; install the watchdog snippet
   **before** the first bulk import.
4. Connect Square, set the location to **`L71MXVF5YWZE4`**, system of record = Square.
   ⚠️ Verify the location BEFORE importing — CornerCAD nearly shipped with the wrong business name
   on card statements because production pointed at an inactive location.
5. Install catalog mode so nothing is buyable while it's built.
6. ~~Audit her catalog first~~ Done — see "Catalog audit" above. 33 descriptions and 29
   plural-name judgment calls to work through before import.
7. Import via WP-CLI (`~/square-import.php` loop, `--user=1`), never the browser button.
8. Descriptions via the same `content/*.md` → push → sync pipeline.
9. Connect Jetpack Social (free tier) for product auto-cross-posting; set up the event-calendar
   post type/plugin; embed the Square Appointments booking button once Square Appointments is
   configured.
10. **Cut over from Square Online** once parity is confirmed — cancel/downgrade the Square Online
    subscription only after the WooCommerce site is live and verified, to realize the cost saving
    without a gap in Lisa's storefront.

### Reusable as-is
`square_audit_catalog.py`, `square_push_descriptions.py`, `square_insert_separators.py`, the `---`
WPCode snippet, the catalog-mode snippet, the import loop. All take a location or run per-site.

### Worth deciding early
- **Attribution.** CornerCAD mixes h3li0-licensed, Bradley's own, and third-party CC-BY-SA work, and
  a miscredit took real effort to unpick. Establish Lisa's provenance classes before writing copy.
- **Naming.** Settle plural-vs-singular ("Boxes" — one or a set?) once, not per product.
- **Pricing basis.** CornerCAD prices on return per machine-hour. Lisa's work is hand-made, so the
  binding constraint is *her* hours, not a printer's — but the principle transfers: price against
  the scarce resource, not a margin percentage.

---

## Part 2 — firstcoastmiataclub.org

**Not a store.** A club that collects membership fees. Almost none of the Square-catalog work
applies; the transferable parts are the environment, cron, and deployment discipline.

### What we know
- Currently collects membership fees only.
- A **new Square account** will be set up. So none of the existing location/credential mess applies
  — but it does mean a fresh statement-descriptor check before the first real payment.
- Apricot (Sumac) is being considered. Preference is to do it in WordPress if it's comparable.
- **RESOLVED — build on a temporary DNS name.** Bradley's plan: stand up the WordPress site on a
  temporary hostname now, decoupled from the `firstcoastmiataclub.org` domain decision, so the
  build isn't blocked waiting on DNS/domain logistics. Cut over the real domain once it's live.

### ❓ Questions that decide everything — needed before a plan can be firm
1. **How many members, and what's the renewal cadence?**
   **RESOLVED (partial) — fixed annual date, not rolling.** Confirmed by Bradley 2026-08-24. Member
   count still unknown — ask club leadership.
2. **Does it need to be recurring/automatic**, or is an annual "renew now" email acceptable?
   **Bradley's answer: "Square provides subscription capability."** This is correct at the Square
   API level and changes the picture — see "What must be verified" below, now resolved with a
   third real option this doc didn't originally have. The manual-vs-automatic decision itself is
   still open; it now hinges on cost/effort of Option 3 below, not just "is automatic possible."
3. **What does membership gate?** Member-only pages, a directory, event signup, a newsletter, a
   printed roster? Or is it purely a dues receipt? — **still open**, needs an answer.
4. **Is the club a registered nonprofit?**
   **RESOLVED — 501(c)(7) social club.** Confirmed by Bradley 2026-08-24. Does **not** qualify for
   Square's nonprofit processing rate; standard rates apply.
5. **Who administers it after handover?** — **still open**, needs an answer. Should drive the
   choice more than features do — a volunteer treasurer will not maintain a complex stack.

### Options, honestly compared

| | Fit | Notes |
|---|---|---|
| **Apricot / Sumac** | Purpose-built | Real membership management: renewals, directory, reporting. Costs money and lives outside WordPress. Fine if the club wants software rather than a website feature. |
| **Paid Memberships Pro (core gateways)** | Weak for Square | Free core, mature, handles levels/expiry/member content. **RESOLVED — confirmed no native Square gateway.** PMPro core ships Stripe, PayPal, Braintree, and 2Checkout; Square isn't one of them (verified against `paidmembershipspro.com/gateway/`, 2026-08-24). |
| **PMPro + "Connect Square Payments" (3rd-party, wordpress.org)** | Real but unproven | A wordpress.org plugin (`payments-connect-square`) explicitly advertises PMPro support via Square Hosted Checkout, "recurring memberships without WooCommerce Subscriptions." **⚠️ Fewer than 10 active installs** as of 2026-08-24, though recently updated and 5-star reviewed. Real functionality, but essentially unproven at scale — risky for real dues money on a small club with a volunteer administrator. |
| **WooCommerce Subscriptions (paid) + WooCommerce Square as token gateway** | Workable, now verified | **RESOLVED.** Confirmed by reading plugin source (`Payment_Gateway_Integration_Subscriptions.php`, `Handlers/`): WooCommerce Square declares `add_support(['subscriptions', 'subscription_suspension', 'subscription_cancellation', ...])` and hooks WooCommerce Subscriptions' own renewal cron (`woocommerce_scheduled_subscription_payment_*`) to charge a stored card token via `process_renewal_payment()`. **It does not call Square's native Subscriptions API — WC Subscriptions does the scheduling, Square Woo is just the charge gateway.** Requires buying WooCommerce Subscriptions (paid Woo.com extension); cost not yet checked. |
| **Square's own Subscriptions API directly** | New option, not in original doc | **Confirmed via the Square MCP connector, 2026-08-24: Square has a genuine first-class Subscriptions API** — `create`, `search`, `get`, `update`, `cancel`, `pause`, `resume`, `changeBillingAnchorDate`, `swapPlan`, `listEvents`. Square itself schedules and charges; WordPress would only need a thin link-out or embed to a Square-hosted subscription checkout (similar pattern to Square Appointments in Part 1) — no WooCommerce Subscriptions purchase needed. **Not yet scoped**: how much custom glue this needs vs. Square's own hosted checkout/payment-link support for subscription plans. Worth costing against the WC Subscriptions path before choosing. |
| **Woo + a simple annual product** | Simplest | Membership as a $X product bought once a year, renewal by email reminder. No recurring billing, no extra plugin, no subscription liability. Manual-ish but very robust. Still valid given fixed-date renewal is now confirmed. |

**Updated instinct:** fixed-date renewal (confirmed) removes most of the argument for automatic
billing — a once-a-year "renew now" email to the whole membership at once is simple to execute
manually. If Bradley wants automatic anyway, **Square's native Subscriptions API directly is now
the strongest automatic option** — it avoids both the paid WC Subscriptions extension and the
unproven third-party PMPro gateway. This wasn't visible until the Subscriptions API was actually
checked today; the original doc only considered WooCommerce/PMPro-mediated paths.

### What must be verified, not assumed
- ~~Whether WooCommerce Square supports subscriptions/recurring at all.~~ **RESOLVED above** — yes,
  as a token gateway for WC Subscriptions, not via Square's native API.
- ~~Whether PMPro has a maintained Square gateway.~~ **RESOLVED above** — not in core; a real but
  tiny (<10 installs) third-party plugin exists.
- **Not yet checked:** what it actually takes to wire Square's native Subscriptions API to a
  WordPress "Join/Renew" flow — hosted checkout vs. custom glue, and the cost of WooCommerce
  Subscriptions for comparison.
- Whether the new Square account needs to be **separate from Lisa's** or can be a location under it.
  ⚠️ Given the statement-descriptor near-miss on CornerCAD, a genuinely separate account is safer:
  members should see the club's name on their card statement, not a craft business.

### Sequence (once questions are answered)
1. ~~Answer 1–5 above.~~ 1, 4 resolved; 3, 5, and member count still need answers from club
   leadership, not Bradley.
2. ~~Stand up the WordPress install~~ — plan confirmed: temporary DNS name, same environment rules
   as Part 1, domain cutover deferred.
3. Set up the new Square account; verify the location's `business_name` **before** taking a payment.
4. Decide manual-annual vs. Square-native-Subscriptions vs. WC-Subscriptions-paid, using the table
   above, once cost figures for the last two are in hand.
5. Migrate content from the existing site. Scope unknown — needs a look at what's actually there.
6. **Run one small real transaction and refund it** before go-live. That is the only way to prove
   the statement descriptor, fraud rules and settlement — sandbox cannot test any of it.

---

## Sequencing across both

Do **Lisa's site first.** It is a near-clone, it exercises every tool already built, and it will
surface whatever is CornerCAD-specific in those scripts while the knowledge is fresh. The Miata
club is a different problem that shares only infrastructure, and it has open questions that need
answers from people other than Bradley.

## Biggest risk

Not technical. **Three WordPress sites on one shared CloudLinux cage, all driven by cron**, on an
account that has already had a volume-induced outage. Stagger the cron schedules, and never run a
bulk import on two sites simultaneously.
