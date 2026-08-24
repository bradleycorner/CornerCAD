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
- **Picture Gallery is a named, specific pain point, not just "a gallery":** the current GoDaddy
  page **redirects to Facebook** because GoDaddy has no on-site album hosting — breaks the
  experience for anyone without a Facebook account. The proposal already specifies the fix:
  **NextGEN Gallery**, a free WordPress plugin, for bulk-upload event albums with on-site lightbox
  viewing — and its **password-protected albums** feature is a natural fit for the per-field
  member privacy/directory consent already found on the paper form. Supersedes this doc's earlier
  generic "WordPress native Gallery block" suggestion for that page specifically.
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

Still to do: theme selection/branding (dark teal, gold accents), NextGEN Gallery for Picture
Gallery, an events list/page for the Event Calendar, configuring the Checkout Field Editor fields
(member 2 info, car info, directory/web-page consent flags per the paper form), and — blocked on
Bradley — creating the new Square account and connecting it.

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
