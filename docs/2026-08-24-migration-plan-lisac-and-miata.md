# Migration plan — uniquecreationsbylisac.com and firstcoastmiataclub.org

Written 2026-08-24, building on what the CornerCAD build actually taught us. Two very different
migrations onto the same hosting: one is a near-clone of CornerCAD, the other is not a store at all.

**Status: plan only.** Nothing here is decided. The open questions are marked and several need
answers before any work starts.

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

The easy one. Same shape as CornerCAD: physical products, Square catalog, same Square account.

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

**Recommendation: B, two separate installs.** Multisite's benefit is shared user accounts and
central updates, neither of which matters here — the two stores have different customers and
different products. B keeps the CornerCAD blast radius exactly where it is, and everything learned
above applies unchanged.

⚠️ **Check first:** does the WooCommerce Square plugin licence permit a second site? The Woo.com
subscription may be single-site. That's a cost question, not a technical one, but it decides
whether B is affordable.

### Sequence
1. Decide A/B/C above. Confirm the Square plugin licence covers a second site.
2. Stand up the install; set `DISABLE_WP_CRON` + WP-CLI cron; install the watchdog snippet
   **before** the first bulk import.
3. Connect Square, set the location to **`L71MXVF5YWZE4`**, system of record = Square.
   ⚠️ Verify the location BEFORE importing — CornerCAD nearly shipped with the wrong business name
   on card statements because production pointed at an inactive location.
4. Install catalog mode so nothing is buyable while it's built.
5. Audit her catalog first (`scripts/square_audit_catalog.py --location L71MXVF5YWZE4`) — it needs
   no changes to work. Expect the same findings: missing descriptions, missing SKUs, plural names
   that hide whether an item is a set.
6. Import via WP-CLI (`~/square-import.php` loop, `--user=1`), never the browser button.
7. Descriptions via the same `content/*.md` → push → sync pipeline.

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

### ❓ Questions that decide everything — needed before a plan can be firm
1. **How many members, and what's the renewal cadence?** Annual on a fixed date, or rolling from
   join date? Fixed-date renewal is far simpler.
2. **Does it need to be recurring/automatic**, or is an annual "renew now" email acceptable?
   This is the single biggest fork.
3. **What does membership gate?** Member-only pages, a directory, event signup, a newsletter, a
   printed roster? Or is it purely a dues receipt?
4. **Is the club a registered nonprofit?** 501(c)(3) qualifies for Square's nonprofit rate;
   most car clubs are 501(c)(7) social clubs, which do not.
5. **Who administers it after handover?** That should drive the choice more than features do —
   a volunteer treasurer will not maintain a complex stack.

### Options, honestly compared

| | Fit | Notes |
|---|---|---|
| **Apricot / Sumac** | Purpose-built | Real membership management: renewals, directory, reporting. Costs money and lives outside WordPress. Fine if the club wants software rather than a website feature. |
| **Paid Memberships Pro** | Strong | Free core, mature, handles levels/expiry/member content. ⚠️ **Square gateway support needs verifying** — PMPro's first-class gateways are Stripe and PayPal. |
| **WooCommerce Subscriptions** | Workable | Reuses the Woo + Square stack we already know. Subscriptions is a **paid** extension. ⚠️ **Verify that WooCommerce Square supports recurring billing** — it tokenizes cards, but that is not the same as subscription support, and I have not checked. |
| **Woo + a simple annual product** | Simplest | Membership as a $X product bought once a year, renewal by email reminder. No recurring billing, no extra plugin, no subscription liability. Manual-ish but very robust. |

**My instinct, stated as instinct:** if the club is small and renews on a fixed annual date, the
last option is probably right and everyone underestimates it. Recurring billing brings failed
cards, dunning, expiry edge cases and PCI questions — a lot of machinery for one transaction per
member per year. But questions 1–3 decide this, and I would not commit before answering them.

### What must be verified, not assumed
- Whether **WooCommerce Square supports subscriptions/recurring** at all. Check the plugin source
  the way we checked `has_multiple_variation_attributes` — the docs are not authoritative.
- Whether **PMPro has a maintained Square gateway**.
- Whether the new Square account needs to be **separate from Lisa's** or can be a location under it.
  ⚠️ Given the statement-descriptor near-miss on CornerCAD, a genuinely separate account is safer:
  members should see the club's name on their card statement, not a craft business.

### Sequence (once questions are answered)
1. Answer 1–5 above. They change the plan materially.
2. Stand up the WordPress install (same environment rules as Part 1).
3. Set up the new Square account; verify the location's `business_name` **before** taking a payment.
4. Build the membership mechanism chosen above.
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
