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

**🚨 HARD DEADLINE — confirmed from billing history, 2026-08-24:** Square Online Plus Plan renews
annually on **October 1** — `$348.00` charged 2024-10-01 and 2025-10-01. That's **~5 weeks from
today**. Miss it and the next $348 charge lands. This turns "migrate Lisa's site" from open-ended
into **time-boxed: WooCommerce site needs to be live and Square Online cancelled before 2026-10-01**
to actually realize the cost saving this migration is for. There's also a separate, smaller
`$19.95` "Square Paid Services" charge on Aug 17 (2025 and 2026) — **unidentified**, may or may not
be Square-Online-related; worth Bradley checking what that line item actually is, since cancelling
Square Online might not touch it.

`uniquecreationsbylisac.store` is **purchased but unconfigured** — no DNS response on port 80 or
443 as of 2026-08-24.

**RESOLVED — domain split, Bradley 2026-08-24.** New WordPress/WooCommerce site builds on
`.store`. `.com` gets CNAMEd to redirect to it once live; Bradley may additionally mask `.store`
behind `.com` via `.htaccess` if he wants the address bar to keep showing `.com` — noted as a
detail to work out at cutover time (URL masking + a valid SSL cert on `.com` needs care, not just
a plain redirect).

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

### Build status — started 2026-08-24
**Live at `uniquecreationsbylisac.store`** (Bluehost's "Add Website" flow, PHP 8.3 explicitly set,
staggered cron at `:14,:35,:56`). **Storefront theme installed and branded against the actual live
Square Online site** (colors/logo pulled directly from `uniquecreationsbylisac.com`, not
approximated): background `#F5EFF0`, accent `#E67E86`, font Inter, her real logo uploaded and set.
WooCommerce + WooCommerce Square + Jetpack Social installed and active.

**WooCommerce Square connected to Sandbox, deliberately** (Bradley, 2026-08-24) — "for now I just
switched to sandbox until we're ready to start pulling inventory." Confirmed via the actual
in-product settings screen, not assumed. This is the correct, safe staging point: no risk to the
live Square Online catalog until Bradley deliberately flips to Production.

**RESOLVED — both environments now connected** (checked directly via `wc_square_settings`,
2026-08-24): production OAuth tokens are stored (`production_location_id` = `L71MXVF5YWZE4`,
correct), alongside sandbox. `enable_sandbox` is still `yes` — sandbox remains the active mode,
production is staged and ready. **Agreed plan, matching the proven CornerCAD playbook**: sandbox
checkout test (sandbox location `L4KK3G9JY6RDY` already configured) → catalog mode while building
→ flip to production only once verified → one small real transaction + refund to prove statement
descriptor/settlement before calling it live. Same sequence that worked for CornerCAD 2026-08-07.

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

### Branding — live site verified, 2026-08-24 (not from memory)
Actually browsed `uniquecreationsbylisac.com` rather than assuming from the platform check alone:
**soft blush-pink/cream background, a floral watercolor "UC" logo mark, dusty-rose accent buttons,
delicate minimal typography** — a boutique/feminine handcrafted-jewelry aesthetic. The shop page
itself (sidebar filters — price/availability/sale, product grid, popularity sort) is a completely
conventional WooCommerce-shaped layout, so **Storefront (WooCommerce's own free official theme,
90k+ installs, actively maintained) is a good structural match** — but its default palette is
generic and does **not** match this look out of the box. Getting the actual blush/rose/floral
identity requires real Customizer/CSS work using her real assets, not something any theme choice
gives for free.

⚠️ **Under evaluation, not decided: a new tree-motif logo** (Bradley showed two color versions,
2026-08-24) — a hand-drawn tree with pendant/gem-shaped "fruit," in either brown/earth tones or
teal/purple. **If adopted, the brown/earth-tone version, not teal/purple** — but this is explicitly
still an open option, not a committed rebrand. Already purchased/licensed (full file available, not
just a watermarked preview). Don't build the site's palette around this until Bradley decides —
the live site's actual current brand (blush/rose/floral) remains what to design against for now.

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
- A **new Square account will be set up — this is mandatory, not a preference.** The previous club
  web admin (Steve) passed away, and the club lost admin/reporting access to the old Square
  account as a result. So none of the existing location/credential mess applies — but it does mean
  a fresh statement-descriptor check before the first real payment, and no export/history to carry
  forward from the old account.
  **⚠️ Correction, Bradley 2026-08-24: the old account isn't dead, it's worse than that — it's
  still live and processing real credit card payments right now (the GoDaddy `$30` Buy Now button
  works), with nobody able to log in and see who paid, pull a report, or issue a refund.** Real
  member dues are moving through an account with zero oversight or auditability until the new
  account replaces it. This raises the practical urgency of Part 2 beyond "nice to modernize" —
  money is currently unaccounted for in a way that compounds every month it continues.
- Getting admin access back to the GoDaddy site itself was also a real struggle (Bradley,
  2026-08-24) — consistent with the same single-admin-dependency pattern showing up everywhere
  this club's infrastructure touches Steve's accounts.
- **Same risk pattern, a third time: the club's Facebook page is solely managed by a member who
  stepped down as president and now "updates it when he remembers"** (Bradley, 2026-08-24). Not
  urgent, but worth designing around rather than repeating: Jetpack Social's free auto-cross-posting
  (already planned for Lisa's site, see Part 1) would let new WordPress content post to Facebook
  automatically, reducing reliance on that one member remembering to do it manually.
- **Board is informal — no formal vote required.** Bradley confirmed the March 2026 proposal was
  never formally voted on because there's no formal board process; it stands as the working plan
  by practice, not by ratification. Resolves the earlier open question about whether it was
  approved.
- Apricot (Sumac) is being considered. Preference is to do it in WordPress if it's comparable.
- **RESOLVED — build on a temporary DNS name.** Bradley's plan: stand up the WordPress site on a
  temporary hostname now, decoupled from the `firstcoastmiataclub.org` domain decision, so the
  build isn't blocked waiting on DNS/domain logistics. Cut over the real domain once it's live.
- **RESOLVED — Bradley wants a straight migration, not a rebuild.** "Pretty much copy the site as
  is. Find a template that is close." His reason for leaving GoDaddy Website Builder: expensive,
  and deliberately unintuitive/feature-limited to push upsells — his words, 2026-08-24.

### Current site audit — RESOLVED, browsed 2026-08-24 (GoDaddy Website Builder 8.0.0000, not WordPress)
The whole site is 8 pages, all simple content types — nothing here needs anything beyond standard
free WordPress plugins:

| Page | What it actually is | WordPress equivalent |
|---|---|---|
| **Home** | Nav + full-width hero photo (Miata lineup), olive-green header band, no other content | Cover block hero + custom theme colors |
| **Event Calendar** | Manually-written monthly text list under a heading ("Upcoming Events" — dates, plain descriptions), NOT an interactive calendar widget | A page, or The Events Calendar (free) if recurring-event structure is wanted later |
| **Join Our Club** | Membership info text + a **registration form** (App date, member name/phone/email ×2 for household, address, car model/year/color, reCAPTCHA) feeding into a **separate** `$30.00 USD` Buy Now/Add to Cart product | WPForms (already installed on cornercad.com, free) for the form + WooCommerce simple product for the $30 charge |
| **Newsletters** | "The Road Runner" — monthly PDF archive by year, 30+ back issues to 2022 (club itself founded 1991) | WordPress Media Library + a plain page listing links, no plugin needed |
| **Picture Gallery** | Photo gallery from car events | WordPress native Gallery block |
| **Favorites** | Static bullet list of external links (Mazda news, forums, vendors, SCCA, YouTube) | Plain page |
| **Contact Us** | Standard contact form | WPForms |
| **Club Store** | Separate merch store (not checked in detail — URL slug not confirmed) | Existing WooCommerce install, separate product category from membership |

**This resolves part of open question 3 ("what does membership gate?") by observation, not
assumption:** none of Newsletters, Picture Gallery, or Event Calendar sit behind a login today —
all are publicly browsable. Membership today is a dues/social concept, not an access-control
mechanism. A straight migration doesn't need a member-login gate unless Bradley/the club wants to
add one deliberately.

**This also effectively answers the recurring-billing question (open item 2) by precedent:** the
live site already does the "simplest" option from this doc's original table — a flat `$30/year`
one-time Buy Now purchase, repeated manually each renewal, no subscription. A straight migration
naturally carries this forward: **WooCommerce simple product, not a subscription**, unless Bradley
decides mid-build he wants to upgrade to automatic billing.

### Known problem to fix, not carry forward: the two-step process loses records
Bradley, 2026-08-24: **the current two-step flow is broken in practice.** Step 1 (registration
form: names, contact info, car info) and step 2 (the separate `$30` Buy Now product) are two
unlinked systems — some members pay without ever submitting the form, so there's no reliable
report of who actually paid vs. who registered. This should **not** be carried forward in the
"straight migration" — it's a genuine defect in the current site, not a feature to replicate.

**Fix, verified feasible 2026-08-24:** WooCommerce's Block Checkout supports native custom
required fields via the free **"Checkout Field Editor (Checkout Manager) for WooCommerce"** plugin
(wordpress.org, no code, saves custom fields to the order via WooCommerce's Store API). Attach the
missing registration fields (2nd member name/phone/email, car model/year/color) directly onto the
`$30` membership product's own checkout, as required fields. Payment and registration become
**one atomic step** — an order can't complete without the fields, and the WooCommerce order itself
becomes the single source of truth for "who paid and who they are," which is exactly the report
that's currently missing. No separate form-then-payment glue needed.

### Member tracking (admin roster + self-service accounts) — new requirement, 2026-08-24
Bradley confirmed both: an admin-side roster (all members, car info, paid/lapsed status) **and**
member self-service accounts (log in, view/update own info, see renewal status).

**Recommendation: stay inside the WooCommerce + Square stack already chosen, don't bolt on a
second membership-plugin ecosystem.** The alternative — Paid Memberships Pro (free core, genuinely
built for member directories/self-service) — was considered, but its only real Square gateway is
the third-party `payments-connect-square` plugin flagged earlier at **<10 active installs**.
Introducing both a new plugin ecosystem *and* an unproven payment gateway for real dues money from
~100 members is more risk than the payoff justifies, especially when WooCommerce already covers
most of this natively:

1. **Self-service accounts** — WooCommerce's native "My Account" login is free and already gives
   every member their own renewal/order history with zero extra plugins.
2. **Persistent editable profile** (car info, household member, viewable/editable between
   renewals, not locked inside a single past order) — needs a small, scoped customization to sync
   Checkout Field Editor's fields to user profile meta and surface them on the Edit Account tab.
   Same pattern already used elsewhere in this project (WPCode snippets) — not a new plugin
   ecosystem, a targeted piece of code reviewed before it ships.
3. **Admin roster** — WooCommerce's native Orders screen, filtered to the membership product, is
   already most of a roster (who paid, when, their custom fields) with zero extra plugins. A small
   custom admin view/column (active vs. lapsed based on most recent order date) turns it into an
   at-a-glance roster — same scoped-customization pattern as #2.

**RESOLVED — full field list recovered from the club's actual 2020 paper renewal form**
(`FCMC 2020 Membership Renewal form.pdf`, provided by Bradley 2026-08-24). Two other sources were
checked and came up empty first: `miata.odb` (an old LibreOffice Base file) is a **blank shell,
never populated** — 512-byte Firebird backup, created 2019-10-08, no tables/data, confirmed by
extracting and inspecting it directly, not assumed. The paper form is the real source of truth and
is far richer than the live GoDaddy form:

- Date, Membership Type (New/Renewal), Name(s) — up to 2 members
- Mailing Address, City, State, Zip
- Primary Phone, Secondary Phone(s) — up to 2
- Email Address(es) — up to 2
- **Newsletter delivery preference**: Paper via USPS or Digitally via Email — matters
  operationally; some members may still need a physical mailing list export, not just email.
- **Per-field privacy consent for the Club Directory** — separate Yes/No checkboxes for whether
  Name(s), Address, Phone, Email, and Car Information may each be listed. **Separately**, a Yes/No
  for whether the member's name may appear on the public Club web page. This is a real design
  requirement, not a nice-to-have: any member directory or public roster the new site builds must
  respect these per-field, per-member opt-outs — it isn't safe to assume "all members" means
  "all fields public."
- Car info: **Model Year, Package (trim level), Color (Body), Color (Top), Purchase Date
  (mm/yyyy), Car Name/Radio Call Name** (a nickname used during group drives — real club culture,
  worth keeping).
- Payment: $30 dues, method (Check/Cash/Other), check number if applicable.
- **Membership year runs June 1 – May 31**, not a calendar year. ⚠️ Confirm this is still current —
  the live GoDaddy site's `$30` Buy Now product doesn't show a June-only renewal window, suggesting
  the club may already be renewing on a rolling/anytime basis in practice even though the fixed
  date was confirmed. Worth a direct check before locking the renewal-cadence design.
- "Club Use Only" box: `Dbase ___ Email ___ Membership Voucher ___` — checkboxes confirming a
  paper form was manually keyed into *some* database and a voucher/card issued. Consistent with
  "we used to have a Windows database, but migrated to spreadsheets" — the tooling changed, the
  process (paper form → manual entry → card) didn't.

**Also noted:** the form lists mailing address as `c/o Brad Corner, Membership` — Bradley was (as
of this 2020 form) the club's membership officer himself. Worth confirming whether that's still
his role, since it bears on open question 5 ("who administers it after handover").

**RESOLVED — `fcmc-migration-proposal.docx.pdf` read, 2026-08-24 (at Bradley's request).** It's a
real board proposal Bradley wrote in **March 2026** — before Steve (the previous admin) died. It
confirms and adds real detail:

- **Page list matches exactly**: Home, Event Calendar, Picture Gallery, Newsletters, Join Our
  Club, Favorites, Contact Us, Club Store — same 8 pages this doc's live-site audit found.
- **Brand colors, in Bradley's own words: "dark teal, gold accents."** Supersedes this doc's
  earlier "olive-green" description from screenshot inspection alone — use the club's own stated
  palette (cross-check against the actual round club logo's colors when building).
- **Picture Gallery — RESOLVED, corrected 2026-08-25, the March proposal's claim was wrong.**
  ~~The current GoDaddy page redirects to Facebook because GoDaddy has no on-site album hosting.~~
  **False, verified by actually browsing the live page and reading its DOM**, not by re-citing the
  proposal: the live page carries a stale "check our Facebook Site for a picture gallery" notice
  sitting directly above **18 real, fully on-site-hosted event galleries** (June 2024 – November
  2025), **262+ images already loaded** (several galleries have an unexpanded "Show More," so the
  true count is higher), hosted on GoDaddy's own `img1.wsimg.com` CDN — directly downloadable, no
  auth needed, no Facebook dependency at all. The Facebook notice text is just leftover copy nobody
  removed once real galleries got built. **NextGEN Gallery is still the right destination plugin**
  (already installed on fcmc-dev) — that part of the proposal holds — but the task is a **straight
  media migration** (download + reorganize + re-upload, preserving the 18 event names/dates as
  separate NextGEN galleries), not building a Facebook-independent alternative from nothing.
- **Phased 4–6 week plan already drafted**: Phase 1 (board approval, hosting decision, theme setup,
  ~2 wks) → Phase 2 (content migration, ~3 wks) → Phase 3 (review, launch, 30-day GoDaddy
  read-only fallback, announce via newsletter/Facebook, ~1 wk).
- **⚠️ Worth raising directly, not silently adopting either way:** the proposal's own Risk
  Mitigation section recommends "creating a dedicated club email account to own the Bluehost
  account **independently of any individual member**" — specifically to avoid the site being tied
  to one person's account. **This is the exact failure pattern that then happened**: Steve's death
  cut the club off from the old Square account because it wasn't club-owned. The current working
  plan (per this doc's brief) puts the Miata WordPress install on the **same Bluehost account as
  CornerCAD** — i.e., tied to Bradley individually, the same pattern the proposal warned against.
  Doesn't have to change the near-term build (temp DNS name on the CornerCAD account is fine to
  start), but the board-owned-account question the proposal raises deserves a real answer before
  calling this migration done, not just the technical Square-account fix already in progress.
- ~~Not yet known: was this proposal ever voted on / approved by the board~~ **RESOLVED** — see
  "board is informal" above.

**RESOLVED — the club-owned-account question, 2026-08-24.** Bradley's answer isn't to stand up a
separate club-owned Bluehost account now — it's to keep the site cheap to exit rather than costly
to leave: he'll host it on the CornerCAD Bluehost account at a **heavily discounted rate to the
club (~$30–100/year, well under the real ~$120–168/year Bluehost cost)**, and rely on the fact that
**it's portable WordPress, not a proprietary builder** — a future admin can take a WordPress
backup and move it to any other Bluehost (or other host's) account with relatively little friction,
made easier by Bluehost's automatic monthly backups plus whatever additional backup process this
project adds. This directly answers the risk the March proposal itself raised: the mitigation
isn't organizational independence up front, it's technical portability plus routine backups,
which is a real and reasonable answer given WordPress's export/migrate story versus GoDaddy's or
Square Online's lock-in. Also directly addresses "it would be great if we could pass
responsibilities around" (Bradley, 2026-08-24) — the explicit goal driving this whole
consideration, and the reason the Facebook-page and old-admin patterns above are worth naming, not
just the Square account.

### Template recommendation
Checked purpose-built free options: **VW Automobile Lite** (wordpress.org, 900+ installs, updated
Oct 2025, actively maintained) is the closest literal "car" theme, but it's built for dealership/
inventory display — more surface area than an 8-page social club site needs.

**Recommendation: don't hunt for a niche "car club" theme.** The current site's actual layout —
solid-color header band, big photo hero, plain white sections with centered headings under a thin
divider rule — is trivially reproduced with WordPress's own default block theme (Twenty
Twenty-Five) plus the existing olive-green/cream palette and round club logo, via the native Site
Editor. Zero theme cost, zero third-party maintenance risk (core-maintained), matches the
"straight migration" instruction without importing a dealership feature set nobody asked for. This
mirrors the same reasoning already applied to Jetpack Social and PMPro-Square above: prefer the
option with real, active maintenance over a niche plugin/theme with thin adoption.

### ❓ Questions that decide everything — needed before a plan can be firm
1. **How many members, and what's the renewal cadence?**
   **RESOLVED — fixed annual date, not rolling; ~100 members.** Both confirmed by Bradley
   2026-08-24. 100 members at $30/year is ~$3,000/year in dues — small enough that manual renewal
   admin (one annual email blast) stays very tractable, reinforcing the simple-product
   recommendation over subscription machinery.
2. **Does it need to be recurring/automatic**, or is an annual "renew now" email acceptable?
   **Bradley's answer: "Square provides subscription capability."** This is correct at the Square
   API level and changes the picture — see "What must be verified" below, now resolved with a
   third real option this doc didn't originally have. The manual-vs-automatic decision itself is
   still open; it now hinges on cost/effort of Option 3 below, not just "is automatic possible."
3. **What does membership gate?**
   **RESOLVED, and scope grew.** Bradley, 2026-08-24: "it would be fantastic if we could use the
   site as a member tracking site as well" — **admin roster + member self-service accounts**, not
   just a dues receipt. Membership unit confirmed as per-car/household (1–2 members sharing one
   $30 fee, tied to one vehicle), with fields beyond the original form list: Miata model/generation,
   year, color, and other metrics not yet fully enumerated. See "Member tracking" design below.
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

### Build status — started 2026-08-24
**Live at [fcmc-dev.cornerfamily.com](https://fcmc-dev.cornerfamily.com)** (temp DNS name, per plan).

⚠️ **Provisioning note for next time:** the subdomain/DB/WP-core were first created manually via
cPanel UAPI + WP-CLI (matching the pattern used everywhere else in this project), but **Bluehost's
own dashboard doesn't track sites created that way** — it wouldn't show up under "Websites," and
critically **AutoSSL hadn't been issued** (site served Bluehost's generic `*.bluehost.com` cert,
failing hostname validation). Bradley used Bluehost's dashboard "Add Website" flow to provision a
throwaway site, then **"Connect Domain"** to point it at the already-existing
`fcmc-dev.cornerfamily.com` subdomain — this triggered AutoSSL and dashboard tracking **without
touching the existing WP install's files or database**. Net effect: same WordPress site, now with
valid SSL and proper Bluehost dashboard visibility. **For Lisa's site, use Bluehost's "Add
Website" flow from the start** rather than manual UAPI, to avoid this rework.

Done so far:
- PHP explicitly set to **8.3** on this vhost (subdomains inherit the parent domain's PHP version
  by default — `cornerfamily.com`, the account's main domain, is on PHP 8.0, which would have
  silently put this site on an unsupported PHP version if left unset).
- `DISABLE_WP_CRON` + a cPanel cron entry staggered at `:07,:28,:49` (offset from cornercad.com's
  `*/21` pattern, per the shared-cage risk in Part 0).
- Permalinks set to `/%postname%/`.
- Plugins installed & active: **WooCommerce 11.0.1, WooCommerce Square 5.4.3** (same versions
  already running on cornercad.com), **Checkout Field Editor (Checkout Manager) for WooCommerce
  2.1.9**, **Contact Form 7 6.1.7**.
- WooCommerce base config: USD, Jacksonville FL store address.
- Draft product created: **"FCMC Annual Membership," $30, simple product** — draft until Square is
  connected.

~~Still to do: theme selection/branding...~~ **Content migration pass, 2026-08-24:**
- **Storefront branded**: real club logo, dark teal (`#0F3D3E`) + gold (`#A69538`, sampled from the
  logo's actual star) via Additional CSS.
- **All 58 RoadRunner newsletter PDFs migrated** from `~/Documents/RoadRunner Newsletter/`
  (Vol 29–36, 2019–2026). Deduped first — 3 exact byte-for-byte duplicate files found and skipped
  (verified via md5, not guessed), leaving 58 unique issues, zero import errors. **Newsletters**
  page built: latest issue featured at top, archive grouped by year (matches the original site's
  own "RoadRunner Archive YYYY" grouping convention) — real direct PDF URLs, not attachment-page
  wrappers.
- **NextGEN Gallery installed** (the fix for the Facebook-redirect problem) — **Picture Gallery**
  page created with the gallery shortcode, but empty: needs the club's actual photos, which
  haven't been supplied yet.
- **Favorites** page rebuilt from the live site's actual link list (Mazda News, SCCA, vendor
  links, etc. — content captured while auditing the live site earlier).
- **Contact Us** page created with the default Contact Form 7 form.
- **Home** page written (intro copy from the paper form / live site) — not yet set as the static
  front page.
- **Event Calendar** page created with the upcoming-events text visible on the live site at audit
  time — will go stale; whoever administers events needs to keep it current.
- **Join Our Club** page has the membership info text, but the actual registration/checkout is
  intentionally left as a placeholder — blocked on the still-incomplete "other metrics" field list
  (see "Member tracking" above) and the Checkout Field Editor configuration.
- **Club Store** — placeholder only, no merch products exist yet to show.
- Not yet done: primary navigation menu, setting Home as the static front page.
- Still blocked on Bradley: the new Square account itself, and the complete field list for the
  membership checkout.

### Build status update, 2026-08-25
**Corrections to the 2026-08-24 build-status entry above, from a direct WP-CLI check** (not from
this doc): the "Join Our Club" and "Club Store" pages **do not actually exist** — only 6 pages are
live (Home, Event Calendar, Newsletters, Picture Gallery, Favorites, Contact Us). The previous
entry's claim that Join Our Club "has the membership info text" was wrong; there is no such page in
the database. Also, the active theme is **Storefront 4.6.2**, not the Twenty Twenty-Five this doc
recommended — worth a deliberate decision, not silently correcting the doc to match, since Storefront
was the theme actually used for Lisa's site (Part 1) and reusing it here wasn't discussed for Miata.

Done today, via `ssh cornerfa` + WP-CLI (no MCP server exists for this site — confirmed, none
registered):
- **Static front page set** — `show_on_front=page`, `page_on_front=86` (Home). Verified live: the
  real Home copy now renders at the site root with the branded teal/gold header.
- **Primary nav menu built and assigned** — Home, Event Calendar, Newsletters, Picture Gallery,
  Favorites, Contact Us, in that order, location `primary`. Verified live via screenshot.
- Noted, not yet fixed: the Home page's inline "Join our club" link points to `/join-our-club/`,
  which 404s — expected, that page is the checkout page scoped below, not built yet.

**Membership checkout field list — RESOLVED, Bradley 2026-08-25.** Finalizes the "other metrics"
open item from Part 2's Member Tracking section. Full field set for the Checkout Field Editor
config on product 14 ("FCMC Annual Membership," $30):

*Native WooCommerce billing fields (no config needed):* primary member name, email, phone, address/
city/state/zip.

*Custom fields (Checkout Field Editor, attached to the membership product), 17 total:*
1. 2nd household member — name
2. 2nd household member — phone
3. 2nd household member — email
4. Car Model Year
5. **Car Generation (NA/NB/NC/ND)** — new field, not on the 2020 paper form; Bradley wants it
   tracked separately from Model Year rather than derived from it.
6. Car Package/Trim
7. Body Color
8. Top Color
9. Purchase Date (mm/yyyy)
10. Car Name / Radio Call Name
11. Newsletter delivery preference (Paper via USPS / Digital via Email)
12–16. Directory consent, per-field Yes/No — Name(s), Address, Phone, Email, Car Information
       (kept at full paper-form granularity — Bradley confirmed, not simplified to a single toggle)
17. Public web page name consent (Yes/No) — separate from directory consent above

*Dropped from the paper form, no longer applicable:* payment method (Check/Cash/Other) and check
number — payment now runs through Square at checkout, so there's no method to record.

**Checkout Field Editor config — DONE, 2026-08-25.** Built via direct `wc_fields_additional` option
write (matching the plugin's own internal schema — no WP-CLI command exists for this plugin, and the
admin UI's AJAX save path isn't scriptable, so this was the correct escape hatch, verified against
the plugin's own save-handler code before writing). 19 entries live (17 data fields + 2 section
headings), confirmed by calling the plugin's actual `woocommerce_checkout_fields` filter directly,
not just reading the saved option. Required: Car Model Year, Car Generation, Newsletter Preference.
Everything else (2nd member info, package/colors/purchase date/car name, all 6 consent checkboxes)
optional by design.

⚠️ **Known limitation, confirmed by reading plugin source, not assumed:** the **free** tier of
Checkout Field Editor for WooCommerce (ThemeHigh) has no working per-product/cart-conditional field
display — that's Pro-only (the free code ships an unused `conditional_rules` property but nothing
evaluates it). So these 19 fields show on **every** checkout, not just the $30 membership. Harmless
today (membership is the only real product), but will need a fix once Club Store ships real merch:
either a small WPCode snippet hooking `woocommerce_checkout_fields` to strip the `fcmc_*` keys
unless the cart contains product 14, or a ThemeHigh Pro purchase for real conditional rules.

Not yet done: creating the Join Our Club page itself and wiring the checkout onto it.

**Square account, in progress, 2026-08-25.** Bradley created a dedicated Square app for the club
(separate from CornerCAD's and Lisa's, following the same pattern). Sandbox Application ID in hand:
`sandbox-sq0idb-Eo_U4qI_eNh-ORZ4bPXNdA`. **Production app id is not yet active — Square requires
account validation first**, which Bradley is working through. Sandbox is usable immediately
regardless of that gate. Bradley is handling the Square account personally, not delegated.

**Sandbox connected and sync turned on, same day.** Confirmed via direct `wc_square_settings` read
(token redacted, same caution as CornerCAD/Lisa): `enable_sandbox: yes`, `sandbox_location_id:
LGR943HV1RGQ5`, `system_of_record: square`, `enable_inventory_sync: yes`, `hide_missing_products:
no`. Matches the CornerCAD/Lisa pattern exactly. Square's sandbox catalog is currently empty — expected,
nothing has been created there yet.

⚖️ **DECIDED — the $30 membership stays WooCommerce-native, not a Square catalog item.** Bradley's
call, 2026-08-25: unlike CornerCAD/Lisa's physical inventory, dues are a service fee, not something
that benefits from Square's catalog/POS machinery. Product 14 (draft, built directly in Woo, already
wired to the 19-field checkout config) stays as-is and is **safe** under the current sync settings —
`hide_missing_products: no` means Square sync won't touch or hide a product it doesn't recognize.
This decision only covers the membership item; if Club Store ever gets real merch, that inventory
*should* go through Square first, matching the established pattern, since sync is already live.

**Superseded same day — Bradley flipped `system_of_record` to `woocommerce`.** Confirmed by direct
read: `system_of_record: woocommerce`, `enable_inventory_sync: yes` still on, `sandbox_location_id`
unchanged. **This reverses the direction from CornerCAD/Lisa** (there, Square is authoritative and
Woo is populated by import) — here, WordPress/WooCommerce is authoritative and pushes out to Square.
Fits the WooCommerce-native membership decision above more naturally: things get built in WordPress
first (membership now, Club Store merch later) rather than requiring a Square catalog entry before
anything can exist on the site. Verified product 14 unaffected by the flip — still a plain draft, no
`_square_item_id` or any Square linkage, since drafts don't push and nothing existed to pull.

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

### Membership registration/signup flow — built and largely verified, 2026-08-25

Bradley's spec for the actual member flow: (1) returning member logs in and pays/updates, (2) new
member registers, is asked how many car-memberships (cars) they're paying for, fills in the 17
fields, (3) that many memberships go in the cart, (4) on-screen + emailed receipt after payment.

**Architecture decided:** all 17 fields live in a **pre-purchase** registration/signup step, not on
checkout. This was forced by a real discovery, not a preference — see "Block checkout can't render
the 17 fields" below. Net effect: checkout itself stays 100% native WooCommerce (no custom fields),
which sidesteps the Block-checkout limitation entirely instead of working around it.

**Built as a single mu-plugin**, `wp-content/mu-plugins/fcmc-membership-registration.php` (not a
WPCode snippet — this site has no WPCode installed, and a file this size is better reviewed as code
than pasted into a snippet editor with its own cache-doesn't-update-until-resaved gotcha):

- **10 account-level fields** (2nd household member name/phone/email, newsletter pref, 6 directory
  consent checkboxes) on the native WooCommerce Register **and** Edit Account forms
  (`woocommerce_register_form` / `woocommerce_edit_account_form`), saved as user meta.
- **`[fcmc_membership_signup]` shortcode**, published as the "Membership Sign-Up" page (ID 96): a
  "+ Add Another Car" repeater (up to 6) collecting the 7 car-specific fields per car (model year,
  generation, package, body/top color, purchase date, car name).
- **Login/registration auto-redirect** to that signup page for everyone except admins
  (`woocommerce_login_redirect` / `woocommerce_registration_redirect`).
- **One cart line item, quantity = number of cars** — per Bradley's simplification ("all they really
  need to know is I'm paying for X number of cars/memberships"): `WC()->cart->add_to_cart(14,
  count($cars), ...)`, not N separate line items. Cart/checkout shows plainly "2 × FCMC Annual
  Membership, $60.00" with **zero custom fields visible** — the per-car detail rides along as
  cart-item data, never rendered on checkout.
- **Per-car detail persisted to the order as hidden (`_`-prefixed) item meta** —
  `_fcmc_cars_data` (structured array) and `_fcmc_cars_summary` (human-readable) — on
  `woocommerce_checkout_create_order_line_item`. Verified against WooCommerce core
  (`get_formatted_meta_data()`/`get_all_formatted_meta_data()`) that underscore-prefixed order-item
  meta is auto-hidden from the customer-facing thank-you page/emails but **shown in full on the
  wp-admin Edit Order screen** — no custom filter needed, this is WC's own default behavior.
- **Dashboard tab enhancement** (`woocommerce_account_dashboard`) — added after Bradley asked "can
  the dashboard show my membership info, I only saw some fields on Account details." Shows both the
  car profiles (mirrored to `fcmc_car_profiles` user meta on each signup submission, so it also
  prefills next time) and the household/preference fields together, with Renew and Update buttons.

⚠️ **Real discovery, not assumption — Block checkout can't render the 17 fields.** Checked directly
against WooCommerce core and the installed plugin, not guessed: WooCommerce Blocks' native
Additional Checkout Fields API supports exactly **3 field types — `text`, `select`, `checkbox`**
(`CheckoutFields.php: $supported_field_types = ['text','select','checkbox']`), no radio, no
headings/sections. And the free tier of Checkout Field Editor for WooCommerce (ThemeHigh) only
wires custom fields into the **address** section for Block checkout — never a general "additional
info" area (confirmed: no registration calls for other locations exist in the plugin's block
support code). The classic-checkout field set built earlier that day (19 fields via
`wc_fields_additional`) is consequently **dead/inert** on this Block-checkout site and should be
considered superseded by the pre-purchase flow above, not something to fix or migrate forward.

**Verified live, in-browser, 2026-08-25** (fresh single-session test, not mixed CLI+browser — an
earlier mixed-session test produced a misleading qty mismatch between two different WC sessions for
the same user, since resolved by testing end-to-end in one real browser session only):
registration with the 10 fields → auto-redirect to signup → "+Add Another Car" → 2 cars filled
(1996 NA, 2005 NB) → cart/checkout correctly show **"2 × FCMC Annual Membership — $60.00"**,
`"Total price for 2 FCMC Annual Membership items: $60.00"` → Dashboard correctly displays both
cars plus household/preference fields → billing address fields persist correctly across page
reloads (server-side WC session, not client state).

**Card payment — PROVEN, 2026-08-25 (Order #100, completed by Bradley manually).** Automated entry
into Square's cross-origin, PCI-isolated card iframe hit persistent browser-automation-tool
rendering issues in-session (confirmed not a site bug — the iframe is correctly absent from the
accessibility tree, expected PCI isolation), so Bradley completed the final card-entry step himself
using the already-built test cart (sandbox card `4111 1111 1111 1111`). **Full pipeline confirmed
end-to-end via direct order inspection:**
- Order #100, `$60.00`, `payment_method: square_credit_card`, real Square transaction id
  (`FmGQXEhMIzmOr5s7ImDOO5wk0RDZY`), Visa •1111 (matches the sandbox card), sandbox location
  `LGR943HV1RGQ5` — confirms this never touched anything live.
- Line item: `FCMC Annual Membership × 2`, `$60.00` — exactly "2 x Membership" as specified, zero
  custom fields visible on the customer-facing Order Received page.
- **Hidden order-item meta landed correctly**: `_fcmc_cars_data` (full structured 2-car array) and
  `_fcmc_cars_summary` (`Car 1 — Car Model Year: 1996, Car Generation: NA (1989–1997) | Car 2 —
  Car Model Year: 2005, Car Generation: NB (1998–2005)`) — present on the order, invisible to the
  customer, exactly the design.

⚠️ **Order landed `on-hold`, not `processing`** — Square **authorized** the $60
(`_wc_square_credit_card_authorization_amount: 60.00`) but did **not capture** it
(`_wc_square_credit_card_charge_captured: no`). Root cause confirmed, not guessed: the gateway
settings option holds only `{"enabled":"yes"}` — enabling the gateway earlier (to unblock testing)
never touched the Charge Type field, so it's sitting at the class default (authorize-only). Not a
bug; on-hold orders can still be captured manually from the Edit Order screen. ⚖️ **DECIDED —
Bradley 2026-08-25: stay manual for now.** No gateway change made; every new membership order will
land `on-hold` until captured by hand from the Edit Order screen. Revisit before go-live if the
per-renewal admin overhead becomes a real burden at ~100 members.

**Known follow-up items, not yet built:**
- Editing a **past** car-membership's info (vs. the "current profile" mirror) isn't exposed anywhere
  — only the latest signup submission is editable/visible.
- No admin roster UI yet — car data is captured (hidden order item meta + user meta) but there's no
  at-a-glance admin view across all members yet, per the original Part 2 "admin roster" requirement.
- Product 14 is published but its price/description weren't revisited today.

### Todo, added by Bradley 2026-08-25

1. **Membership report** — an admin-facing roster/report (who's paid, when, contact + car info).
   This is the same "admin roster" gap noted above, now explicitly requested rather than just
   inferred from the original Part 2 design. Data already exists (order item meta
   `_fcmc_cars_data`/`_fcmc_cars_summary`, account-level user meta) — needs a reporting view built
   on top, not new data capture.
2. **Members need to see/modify their own car(s) after signup**, not just at the moment of a new
   purchase. Right now the Dashboard only *displays* `fcmc_car_profiles` read-only; there's no
   dedicated edit surface for car-specific fields — "Update Household & Preferences" only reaches
   the 10 account-level fields via Edit Account, not the 7 car fields. Needs a real edit form (e.g.
   hook into `woocommerce_edit_account_form` alongside the account fields, or a dedicated "My
   Car(s)" tab) that writes back to `fcmc_car_profiles`.
3. **Migrate photos from the live site to the Picture Gallery — DONE, 2026-08-25.** Expanded every
   "Show More" via the live DOM (JS-rendered SPA, so a SiteSucker mirror was tried first and turned
   out unusable — see the correction below), landing on **285 images across all 18 galleries**
   (slightly more than the earlier 262+ estimate once every gallery was fully expanded). Full
   pipeline: extracted `img1.wsimg.com` URLs grouped by event from the live DOM → confirmed
   stripping the trailing `/:/rs=...` resize-path segment yields the true full-resolution original
   (verified: 182×114 thumbnail vs. 2560×1602 original, same file) → downloaded all 285 (146MB,
   zero failures) → uploaded to the server → imported via NextGEN/Imagely's own
   `Manager::import_gallery_from_fs()` (the same method backing the plugin's admin-UI "Import
   Folder" feature, called directly via `wp eval-file` rather than through the REST/HTTP layer) →
   **18/18 galleries created, 285/285 images imported, exact match, zero discrepancies.** Verified
   live in-browser (screenshot) both immediately after import and again after cleanup. The
   Picture Gallery page already had `[ngg_images source="galleries" ...]` wired up from an earlier
   session — only needed its stale "photo albums pending" placeholder text removed.
   Staging copies (147MB under `wp-content/ngg-import-staging/`) deleted after confirming NextGEN
   copies files into its own `wp-content/gallery/<slug>/` storage (verified directly, not assumed —
   229MB there: original + `_backup` + generated thumbnail per image).

   **Not done / correction to earlier note:** the SiteSucker download Bradley ran turned out
   unusable as a source — GoDaddy's URLs put resize parameters as trailing path segments, so
   SiteSucker saved every resized variant of each photo under a filename built only from that
   trailing segment (e.g. `rs=w:1110,cg:true,m.jpeg`), losing the real filename and all
   gallery/event grouping, and also missing everything behind "Show More" (SiteSucker doesn't
   execute JS). Live-DOM extraction was the correct path instead. The homepage/gallery-header hero
   image (Miata lineup outside a Mazda dealership) was **not yet migrated** to the new site's Home
   page — still open.

4. **Cross-post between the WordPress site and Facebook, both directions — new design goal, stated
   2026-08-25.** Bradley: "the goal is to make the website the central place we add events,
   pictures etc. and that in turn creates facebook events posts and photos." WordPress-authoritative
   push-out matches the **Jetpack Social** plan already recommended for Lisa's site (Part 1) and
   flagged earlier in Part 2 as the fix for "the Facebook page is solely managed by one member who
   updates it when he remembers." ⚠️ **Real gap, not yet solved:** Jetpack Social's free tier
   auto-cross-posts the native `post` type out of the box; it does **not** natively know about
   NextGEN Gallery entries or an Event Calendar custom post type unless those are wrapped in an
   actual blog post, or unless additional wiring is built. Needs design work before promising this
   works automatically for galleries/events specifically, not just blog posts.

5. **Home hero photo — fixed, and root cause corrected, 2026-08-26.** The photo migrated on
   2026-08-25 (`IMG_4655(2).jpg`, a Mazda dealership scene) turned out to be the **wrong** photo —
   grabbed from the Picture Gallery page's header, not the actual live homepage hero. Bradley caught
   it with a screenshot of the real live homepage (Miata lineup under oak trees/Spanish moss).
   Corrected: scanned the live homepage's `background-image` CSS (GoDaddy renders this hero as a CSS
   background on a div, not an `<img>` tag) for a `wsimg` URL, found the real photo
   (`Screenshot 2025-01-05 161827.jpg`), visually confirmed it matches Bradley's reference exactly,
   uploaded as attachment 412, deleted the wrong attachment 408.

   ⚠️ **Real bug found in the same pass, and it's what Bradley's "the photo doesn't scroll but the
   content does" comment was actually describing** — not the old GoDaddy site's behavior, which is
   how I first (wrongly) read it. My earlier hero fix had left WordPress's core **Background Image**
   customizer setting (`theme_mods_storefront.background_image`) pointed at the hero photo. That
   setting applies a fixed `<body>` background **site-wide** — it was bleeding through on every page
   (Newsletters, Club Store, everywhere), not just Home. Root-caused by reading
   `theme_mods_storefront` directly (`wp option get`), not by guessing from CSS. Fixed with
   `wp theme mod remove background_image` (+ `background_repeat`); verified clean on Home,
   Newsletters, and Club Store in a fresh tab.

   **Correction — Bradley actually wanted the fixed-photo/scroll-over effect, just scoped to Home
   only.** Once the global bug was gone, he said so directly: "I actually liked the behavior where
   the tree/miata picture didn't move and the content rolled over it." Re-added it the *correct*
   way: WordPress's native Cover block has a built-in **parallax** toggle (`hasParallax:true`),
   which applies `background-attachment: fixed` scoped to that one block via WP core block CSS —
   not a site-wide setting. Enabled on Home's existing `wp:cover` block (image 416) only; verified
   the fixed/reveal effect works scrolling Home, and re-verified Newsletters/Club Store are still
   unaffected. (Home's actual persisted content had stayed a plain `wp:cover` block with image 416
   the whole time — an earlier note about an `wp:html`-based rewrite describes an intermediate
   attempt that never ended up as the saved state.)

6. **Club Store page built, 2026-08-26 — corrects the "placeholder, no merch exists" assumption
   throughout this doc (Part 2 pages table, product-sync notes).** Checked the live page directly
   (`/club-store-1`) rather than trusting the GoDaddy SEO title ("Shop Custom Gear and Apparel"),
   which is misleading boilerplate. **It is not a shop.** It's a static partner-referral page: the
   club's **only** merch partner is **Logo Xpress** (Fleming Island embroidery/screen-print shop,
   904-278-7774, info@mylogoxpress.com), covering 5 items (name tags, club windbreaker, half-round
   embroidery, car magnetics, round embroidery) — each ending in "all sales are between you and Logo
   Xpress." No cart, no pricing, no WooCommerce products, call-only for pricing. Bradley confirmed:
   data is current, keep the 5 items separate (don't merge the near-duplicate half-round/round
   blocks), membership stays a fully separate thing from this page.

   Rebuilt on fcmc-dev as page 424 (`/club-store/`), using `wp:media-text` blocks (image+text,
   alternating sides like the original) instead of 5x repeated disclaimers — one intro paragraph
   covers the "sales are between you and Logo Xpress" framing once, each item block keeps its own
   Logo Xpress contact line. All 5 photos pulled from the live site at full resolution (same
   `strip-the-/:/-suffix` technique as the gallery migration), imported as attachments 419–423.
   **This retires the "Club Store — placeholder only" / "no merch products exist yet" lines
   elsewhere in this doc and the inventory-sync notes premised on Club Store eventually needing a
   Square catalog entry** — there is no plan for Club Store to sell anything directly; it's
   informational only, unless that changes by a future explicit decision.

## Sequencing across both

Do **Lisa's site first** — now with a real deadline behind it, not just a preference: the Square
Online Plus Plan renews **2026-10-01** (~5 weeks from today), so this is the actual critical path,
not merely "do it while the tooling is fresh." Miata's build (temporary DNS name, straight
migration) can run in parallel since it's a separate site, separate Square account, and mostly
blocked on answers from club leadership rather than on Bradley's time — but **never run both
sites' bulk imports/cron on the same window**, per the shared-cage risk below.

## Biggest risk

Not technical. **Three WordPress sites on one shared CloudLinux cage, all driven by cron**, on an
account that has already had a volume-induced outage. Stagger the cron schedules, and never run a
bulk import on two sites simultaneously.

## MCP setup for fcmc-dev and Lisa's UCLC site — 2026-08-28

Bradley's original intent (stated at project start) was for these two sites to get the same MCP
tooling as cornercad.com — this had not actually happened; both had been driven via raw SSH+WP-CLI
the whole build. Closed the gap:

**Site paths (both on the shared `cornerfa` Bluehost account):**
- fcmc-dev: `/home1/cornerfa/public_html/fcmc-dev`
- Lisa's (`uniquecreationsbylisac.store`): `/home1/cornerfa/public_html/website_b895229c` — **not**
  a predictable slug; it's one of several `website_*` hashed folders from Bluehost's "Add Website"
  flow. Identified by checking `wp option get siteurl` in each candidate folder.

**Done, both sites:**
- Installed + activated the **free** `ai-engine` plugin (v3.7.3, from wordpress.org — Pro not
  applied, see license note below).
- Enabled MCP via `mwai_options` (a single serialized WP option): `module_mcp` (master switch) and
  `mcp_core` (WordPress Core tools) both set `true`, plus a generated `mcp_bearer_token` per site.
  ⚠️ Two flags are required, not one — `module_mcp` alone doesn't instantiate the MCP class at all;
  found this by reading `classes/core.php`, not by guessing. An initial attempt set a
  `mcp_feature_enabled` key that doesn't exist in this plugin — harmless no-op, since removed.
- Registered both as **project-scoped** MCP servers in `~/.claude.json` → `mcpServers`, named
  `fcmc-dev` and `uclc`, pointing at `https://<site>/wp-json/mcp/v1/http` with an `Authorization:
  Bearer <token>` header — same shape as `cornercad-com`. **Confirmed connected** via `claude mcp
  list`. ⚠️ Lisa's site is behind Cloudflare (same as cornercad.com) — a raw `curl` probe gets served
  a JS bot-challenge page, but the real MCP client connects fine, same as `cornercad-com` already
  does through the same protection. Don't mistake a curl-probe 403/challenge page for a real outage.
  Tokens live only in `~/.claude.json`, not in this repo.
- Flipped `woocommerce_feature_mcp_integration_enabled` (a WooCommerce 11.0.1 feature flag, **off
  by default**) to `yes` on both sites — this is what backs the `woocommerce`-style MCP server
  (native `/wp-json/woocommerce/mcp`, the same mechanism `woocommerce`/`woocommerce-staging` already
  use for cornercad.com). Endpoint now responds `401` (needs auth) instead of `404`/`403`
  (didn't exist) — confirms the flag is the correct, complete gate.

**Not done — needs Bradley, no CLI/API path exists:**
- **WooCommerce REST API keys**, one pair per site. WP-CLI's `wp wc` command set has no `api-key`
  subcommand — this is wp-admin-only: WooCommerce → Settings → Advanced → REST API → Add key
  (permissions Read/Write) on **both** fcmc-dev and `uniquecreationsbylisac.store`. Once the two
  Consumer key/secret pairs exist, register `woocommerce-fcmc-dev` and `woocommerce-uclc` in
  `~/.claude.json`, same shape as the existing `woocommerce` entry (`WP_API_URL` +
  `X-MCP-API-Key` header built from `key:secret`).
- **A Claude Code restart** — `fcmc-dev`/`uclc` show "Connected" in `claude mcp list` already, but
  this session's own tool index still only resolves `cornercad-com`'s tools (verified via
  `ToolSearch`) — matches the established AI Engine Pro precedent: a session can't see a newly
  registered server's tools until it restarts, no matter how many times you retry.

**AI Engine Pro license — open question, not blocking.** Bradley's Meow Apps dashboard shows
`AI Engine Pro – Starter` (1-site tier), **Status: Active, but Activations: 0 / 1** — active and
apparently working on cornercad.com, yet showing zero registered activations. Traced the actual
cause in `common/premium/licenser.php` rather than guessing: the plugin's periodic re-validation
call goes to a primary URL (`check.meowapps.com`) that **omits** `edd_action=activate_license`
entirely — that parameter (the real EDD activation handshake that increments the vendor's seat
counter) is only sent on a fallback path that hits `meowapps.com` directly, and only fires if the
primary check fails. Best-supported read: `check.meowapps.com` looks like a Cloudflare-block-
avoidance proxy Meow Apps built (the same file has a `detect_block()` method specifically for
Cloudflare/Google block detection) that confirms validity without ever performing a real EDD
activation. **Regardless of how that resolves, Starter is a 1-site license** — no spare seat for
fcmc-dev or Lisa's site without upgrading to Standard ($99/yr, 5 sites) or higher. Free-tier AI
Engine is what's actually installed on both new sites, and is sufficient for the Core WordPress
toolset; Pro would only add the theme/plugin-lifecycle and Woo-specific tool groups on top.

**Explicitly deferred, both by Bradley's own call:**
- **Miata's own separate Square account** — sandbox confirmed working (screenshot verified:
  `sandbox-sq0idb-Eo_U4qI_eNh-ORZ4bPXNdA`), production still blocked on Square's own account
  validation (external, not fixable from our side). The existing Square MCP connector is scoped to
  ONE merchant (confirmed live via a `locations.list` call — only returned the shared
  UCLC/CornerCAD merchant `ML0RSAXT9HH0B`), so it can't reach Miata's account even once production
  is live. A second Square connection would need Bradley's own OAuth action in claude.ai settings
  → Connectors, not anything scriptable from here.
- Lisa/UCLC needs **no separate Square MCP setup at all** — confirmed live, same merchant account
  as CornerCAD (`ML0RSAXT9HH0B`), just different location IDs. Already fully covered by the
  existing connector.
