# Go-Live Checklist — cornercad.com (WooCommerce)

**Production is the source of truth.** The one-time full DB sync (staging→prod) already ran 2026-08-13
and carried all content — pages, blog, policies, Yoast meta — to production. **No more full-DB syncs**
(see workflow below). Remaining launch work is done **directly on production**.

Items marked **[admin]** need the WP/Woo admin UI (can't be done via MCP).
Legend: `[x]` done · `[ ]` pending · `[~]` partially done.

> ⚠️ **Two MCP servers:** `cornercad-com` = **production**, `cornercad-staging` = **staging**
> (`/staging/7680/`). Same `mcp_ping` name — tell them apart by permalink.
> 🔴 **Prod MCP (`cornercad-com`) is currently DOWN** — token expired around the 2026-08-13 sync.
> Re-authorize via `/mcp` in an interactive session to restore MCP access to production.

---

## 🧭 Dev workflow (decided 2026-08-13)

- **DB-touching work → PRODUCTION.** Pages, posts, blog, forms, content/config edits use WordPress's
  native **draft → review → publish** on prod. Content lives in the DB; there's no safe way to sync it.
- **Pure-file work → STAGING, then files-only deploy.** Plugin adds/updates, theme changes, custom code:
  test on a fresh staging cloned from prod, then deploy **files only** (`wp-content` + core — never the DB).
  Nuance: plugins/themes have a file part (staging) AND a DB part (activate + configure on **prod**).
- **No more full-DB staging→prod syncs** — they overwrite prod's DB (config + orders) and clobber the
  live Square connection. Staging is cloned **from** prod (clean prod first).
  Memory: `feedback_dev-workflow-content-prod-code-staging`.

---

## 🚦 Launch critical path (do in this order, all on PRODUCTION)

1. **[admin] Re-authorize the prod MCP** (`cornercad-com`, via `/mcp`) so config/content can be verified.
2. **[admin] Back up production** (DB + `wp-content`, **offsite**) before cleanup/import — see Backups.
3. **[admin] Clean prod test data** (rode the one-time sync over): the **12 "Sandbox sample" test
   products** + the **test order #142** (+ test customer). Products via MCP once re-authed; the order is
   admin-UI only (no MCP delete-order tool).
4. **[admin] Verify content landed on prod:** policy pages published, Terms + `woocommerce_terms_page_id`,
   homepage/Yoast meta. (Homepage confirmed live 2026-08-13 — Yoast title + content correct.)
5. **[admin] Finish prod config:**
   - **Square: already reconnected to LIVE** 2026-08-13 (Environment=Production, Location=CornerCAD
     `LS4SZ98SBX4F6`, OAuth re-done). Sync toggles are OFF right now (deliberate). ⚠️ **Never Disconnect.**
   - **Payments = Square-only** — Stripe + PayPal **OFF** (route to Lisa; PayPal Payments is installed on prod).
   - **Store base country = United States (FL).**
   - **Shipping = flat rate** on the zone.
   - **Email:** From address, order recipients, test send.
6. **[admin] Product import:** delete the old duplicate Vanta Vase (`CAD-VAS-0003`) in Square → flip Square
   sync toggles **ON** (Sync Inventory, **Override product images**, Fulfillment) + **Save** → run
   **"Import all Products from Square"** (~168). Verify: count ≈ 168, **zero non-`CAD`** at
   `LS4SZ98SBX4F6` (location scoping, not SKU — audit first), images came through, re-SKU Ribbed
   `UCL-PLA-0001`→`CAD-PLA-0070`. Then brands + weights (weights from slicer output).
7. **[admin] One small LIVE card transaction + refund** — the only proof the descriptor reads "CornerCAD"
   and the live path works end-to-end. Sandbox can't test this.
8. **[admin] Apple Pay** — confirm the `.well-known` domain file is deployed (tested a few days ago).
9. **[admin] Take prod OUT of Coming Soon / catalog mode** — deactivate the WPCode snippet + OptinMonster
   popup. **Store is live.**
10. **[admin] Create a fresh staging cloned FROM the clean, live prod** — for future code work. From here:
    content on prod (draft→publish), code via staging (files-only). No more full-DB syncs.

---

## 💾 Backups (set up before launch; critical once taking orders)

- **The prod DB is the irreplaceable part** — orders + content + config live only there. Files
  (themes/plugins/code) are reproducible and mostly in staging; media (`wp-content/uploads`) matters too.
- **Two candidates under evaluation (2026-08-13):**
  1. **Cron'd shell script** — Bluehost gives SSH **and cron access**, so a scheduled
     `mysqldump | gzip` + `rsync` of `wp-content`/uploads to offsite storage: free, leanest, gentlest on
     the shared cage, fully controlled. *TODO if chosen:* write the script (`scripts/`), parameterize DB
     creds + offsite target, cron for low-traffic hours, dated retention (e.g. 7 daily + 4 weekly).
  2. **UpdraftPlus (free) → Google Drive** — plugin with scheduled backups, **offsite to Google Drive
     built in**, and one-click restore. Easiest option; satisfies the offsite rule out of the box.
     Trade-off: a plugin zipping the full site is heavier on the shared cage than a lean `mysqldump` —
     schedule for low-traffic hours; consider splitting DB vs files if load becomes an issue.
  - (Other plugins: **Duplicator** / **WPvivid**. **Skip the $60 Jetpack Backup.**)
- **Rules:** store **offsite** (off the server); **before every change + automated daily** once live;
  **test a restore** on staging once (an untested backup isn't a backup); schedule for low-traffic hours
  (the shared CloudLinux cage is load-sensitive — a heavy full-site backup plugin can spike it).

---

## Store & products

- [x] Static homepage (`page_on_front=49`), page tree, `product_cat` tree (3D Printed · Automotive ·
      Coasters · Lamps · Laser Engraved · Signage · Vases & Planters).
- [x] 168-item product line in the **Square** catalog; 2,480 photos in Square (flow to Woo on import when
      `override_product_images`=yes).
- [x] **Cleaned the 12 "Sandbox sample" test products off prod** 2026-08-13 (0 active products now).
- [x] **Test order trashed** — verified via MCP 2026-08-13 (0 orders on prod). Test customer is Bradley's
      own admin account — left in place.
- [x] 🎉 **Square → Woo import DONE 2026-08-14 — 179 published products**, **176 clean `CAD-` SKUs**,
      **zero `UCL-`/Lisa bleed**, **346 images**, descriptions imported for the h3li0 line, categories
      auto-created (Vases · Planters · Lamps · Gardening · Home Decor · 3D Printed · Coasters).
      🚨 **The browser "Import all Products" button CANNOT complete on this host** — LiteSpeed kills every
      request at ~80s (overrides PHP's `max_execution_time`) and breaks the plugin's loopback chain. It
      must be run via **WP-CLI with `--user=1`** (userless CLI silently imports 0 — fails
      `current_user_can('publish_products')` and breaks on the first item). Full method + the OOM-restart
      loop: memory `project_square-woo-import-cli-method`, script `~/square-import.php` on the server.
- [x] **Trashed Woo test product #91** (2026-08-13).
- [ ] **Post-import cleanup:**
  - [x] **Images backfilled 2026-08-14** — re-ran the CLI loop with `start_product_import( true )`;
        **378 attachments, 178/179 products have a featured image.** (The first pass left 17 gaps, one per
        OOM-restart batch; the update pass closed all but one.)
  - [x] **Hive Vase (335) — was an EMPTY SHELL, trashed 2026-08-14.** Not a Square data problem: the post
        had **zero meta** (no SKU, price, image, or Square link) — wreckage from an OOM kill that created
        the post then died before writing meta. The real one is **336** (`CAD-VAS-0021`, $45). Scan
        confirmed it was the only orphan: **178 products, 0 with no Square link.**
  - [ ] **Reclaim the clean slugs.** Both real products sit at `-2` URLs because duplicates squatted the
        good ones: **Hive Vase 336** → `/product/hive-vase-2/`, **Vanta Vase 265** → `/product/vanta-vase-2/`.
        Needs a **permanent** delete of 335 + 203 (trash still holds the slug), then update each slug.
        Do it **before launch** — URLs are painful to change once indexed.
  - [ ] **Ribbed Planter Large (217)** — re-SKU `UCL-PLA-0001` → `CAD-PLA-0070`.
  - [ ] **Delete old duplicate Vanta Vase** — Woo post **203** (blank SKU, variable) + Square
        `CAD-VAS-0003`; superseded by `CAD-VAS-0009` (Woo 265).
  - [ ] **Empty Trash (8 old test products)** — trashed products are **permanently ignored** by future
        imports, so leaving them there creates silent gaps.
  - [x] **Square Debug Mode → Off** — set via MCP 2026-08-14 and verified by re-read. Sandbox fail-safe
        confirmed still intact (all three `sandbox_*` fields empty); sync settings preserved
        (`system_of_record=square`, inventory on, override images on, 24h).
  - [~] **Product galleries — WooCommerce Square syncs ONE image per product, by design.** Confirmed via
        WooCommerce's own docs + long-standing open feature requests: only the main/featured image syncs;
        every other Square image is ignored. So "add photos in Square → nightly sync" **will never** bring
        galleries over. Custom puller written: **`~/square-gallery.php`** (WP-CLI, `--user=1`) — maps
        Square item → Woo product via `_square_item_id`, sideloads all non-featured images, writes
        `_product_image_gallery`; per-image resume so OOM restarts don't duplicate. **Started 2026-08-14
        for all 179; re-run it any time images are added in Square.** No template change needed — Woo
        renders the gallery automatically once the meta exists (Meow Lightbox handles enlarge).
- [ ] **Filament color/type options won't survive import** (Square modifiers don't sync) → Product Add-ons
      plugin if the storefront needs the dropdowns.
- [ ] **Size options → build as Square VARIATIONS, not modifiers** (decided 2026-08-14). Several designs
      ship in multiple heights and we're selling all of them.
  - 🚨 **Modifiers do NOT sync to Woo; variations DO.** Built as modifiers, the sizes exist in Square and
        are invisible on the website — the same trap that lost the filament colour pickers.
  - 🚨 **Every variation needs its own SKU or the WHOLE product fails to import** — this is what the
        Aug 6 alerts were (*"Variations with missing SKUs cannot be imported"*). Pattern:
        **`CAD-VAS-0025-160` / `-180` / `-200`** (base SKU + height in mm).
  - 🚨 **Every variation needs a price in Square.** A Woo variable product whose variations lack `_price`
        renders as *unavailable* (carried over from the Community Store lesson).
  - **Pricing direction: price UP from the smallest.** Woo shows a range ("$40.00 – $50.00") and the
        catalog grid leads with the low number, so a low entry price wins clicks; and upcharging for real
        extra material/time is more defensible than permanently "discounting" off an inflated top price
        (cf. the collections doc: don't make discounts the reason people return).
  - **Price from MakerOps, not by hand.** ⚠️ An earlier estimate here (~$40/$45/$50, from a raw slicer
        screenshot showing ~121g total) was **wrong — that data was ~4× too light**. Use MakerOps: it
        rolls in machine time and overhead, not just filament.

**📐 PRICING METHOD — MakerOps `True Cost` × 2.7 = SRP.** Established with Strata 2026-08-14; use this for
every multi-size design.

| Strata | Mass | Time | True cost | SRP @2.7× | **Listed** |
|---|---|---|---|---|---|
| 160mm | 129.0 g | 2.87 h | $10.39 | $28.06 | **$30** |
| 180mm | 163.8 g | 3.64 h | $13.20 | $35.63 | **$38** |
| 200mm | 206.8 g | 4.60 h | $16.66 | $44.98 | **$45** |

Source: `~/Documents/3dPrinting/Commercial License/h3liØ/Vases/Strata/strata_PLA_2h30m.gcode`
(0.4mm Standard; 63% margin, $6.16 profit/hr).

- **Cost tracks time almost exactly** (+60% time → +60% price), which is the right shape. Round up to
  clean price points; keep **$45** as the top-of-ladder anchor since it matches existing vase pricing.
- ⚠️ **Sanity-check two things before trusting 2.7×:** (a) does `True Cost` include **your labour**
  (setup, removal, inspection, packing, listing)? If not, $6.16/hr is the entire return on machine *and*
  time. (b) Is **failure rate** allowed for? A failed 200mm costs 4.6 hrs and 207 g, and tall vase-mode
  prints are the likeliest to fail.
- 💡 The 160mm at **$30** becomes the cheapest vase in the catalog (others are $45). That's a feature —
  a genuine entry price for first-time buyers, per the collections strategy — but expect it to become
  the volume seller.
  - **Affected (verify each is really offered):** Strata (160/180/200) plus the "two sizes" designs —
        Obscura · Quell · Lucid · Fractal · Influx · **Vellis (200mm/300mm)**.

### 💡 Lamps — printed parts only + sell our own UL-listed kits (decided 2026-08-14)
**Every lamp listing must state, prominently:** *"Supplied as printed parts only — no electrical
components included. Requires a standard E26 lamp kit with plug and switch, sold separately."*
Confirmed with Bradley: **all** lamps are printed parts only. A customer expecting a working lamp and
receiving a printed shade is the most likely refund on the whole catalog.

**Decision: sell the kits ourselves rather than Amazon-affiliate.** Associates pays ~3–4% (≈40¢ on a $12
kit); stocking cord sets keeps real margin, keeps the customer on-site instead of leaving mid-checkout,
and is exactly the "complete your collection" attach in
`docs/CornerCAD_Collections_and_Repeat_Business_Strategy.md`. *(If we ever do use affiliate links, FTC
rules + Amazon's terms require a clear on-page disclosure near the link.)*

🚨 **Buy UL/ETL-listed cord sets only.** Printed décor is low-risk; **electrical parts in someone's home
is real product liability.** Worth a call to the business insurer before the first kit ships.

⚠️ **Socket sizing — h3li0's models are specified in EUROPEAN sizes; the US differs.**
| Design spec | US equivalent | Interchangeable? |
|---|---|---|
| **E27** (Euro, 220–240V) | **E26** (US, 120V) | ✅ Same diameter (26.05–26.45mm) — the printed mount fits either. Only the internal insulation distance differs, so **stock E26** for correct 120V rating. |
| **E14** (Euro) | **E12** (US) | ❌ **NOT interchangeable** — 12mm vs 14mm. Loose contact, flickering, overheating. And the printed mount is sized for a 14mm body, so a US E12 socket sits loose. |

**✅ Resolution: standardize on the E27 plate + E26 kits.** Bradley confirmed at least one lamp ships
**both socket variants as separate plates**, so this is a which-plate-to-print choice, not CAD work.
Printing the E27 variant everywhere means **one kit SKU to stock (E26)** — much better for inventory and
packaging.

- [ ] **Audit the lamp model folders** and record which socket plates each design actually provides:
      both E14+E27 → print E27 · E27 only → fine · **E14 only → needs a decision** (source 120V-rated E14
      kits, remodel for E12, or ship an adapter). **Prismal Desk E14** is named for it, so it's the likely
      problem case. Also check whether the two plates differ *only* in the socket mount or in overall
      proportions — a lamp designed around a small socket may not look right with the large one.
- [ ] Source UL/ETL-listed **E26** cord sets (plug + switch); decide packaging and attach pricing.
- [x] **Product descriptions** — mostly resolved: the h3li0 line imported with real descriptions from
      Square. Still missing on the CornerCAD originals (Clocks, Wall/Desk Clock, Coasters, Baby Dragon, egg).
- [ ] **Brands** re-assigned post-import (sync-safe: Square has no brand concept).

## Payments — Square only

- [x] **Square reconnected to LIVE on prod 2026-08-13** — Environment=Production, Location=CornerCAD
      (`LS4SZ98SBX4F6`), `system_of_record=square`, OAuth re-done after the sync clobbered it. Sync toggles
      currently OFF (deliberate; flip ON before import). ⚠️ **Never press Disconnect** (revokes the whole
      seller's token). *(Verified via MCP 2026-08-13.)*
- [x] **Cleared prod's sandbox fields** 2026-08-13 (via MCP) — the sync had re-populated
      `sandbox_application_id`/`sandbox_token`/`sandbox_location_id` with staging's creds; now empty again,
      so the fail-safe is restored (a stray `enable_sandbox=yes` breaks loudly, not silently). ⚠️ **Re-clear
      after any future full sync** — the sync re-fills them every time.
- [ ] **[admin] Only Square gateway enabled; Stripe + PayPal OFF** — route to **Lisa's** processor; PayPal
      Payments is installed on prod. Verify in the dashboard (reading gateway settings via API exposes keys).
- [ ] **[admin] Wallet button is Square's**, not leftover Stripe.
- [ ] **[admin] One small LIVE transaction + refund** — proves descriptor + live path.
- [ ] **[admin] Apple Pay** — `.well-known` file deployed (tested a few days ago; confirm live).
- [x] Full **sandbox** checkout PROVEN (order #142, Square charge captured).

## Shipping & tax

- **[admin] Shipping — two separate layers:**
  - [x] **Checkout price — DONE 2026-08-13 (blocker cleared).** **USA zone** (United States) with:
    - **Flat rate**, Taxable, base **$8**; shipping-class costs **Coasters $10**, **Heavy $20**, no-class $8;
      calc type **"Per class"**. **Rest-of-world = no methods** (US-only). ⚠️ **Two things to revisit:**
      (a) **"Per class" STACKS** — coaster + heavy in one order = $10+$20 = **$30**; switch to **"Per order:
      most expensive class"** ($20 cap) if you want mixed/bundle orders to be friendlier (recommended given
      the collections strategy). (b) **Coasters $10 > default $8 is INTENTIONAL** — the coaster line is dense
      **stone/slate** (heavy per volume vs. a mostly-hollow FDM print), so it genuinely costs more to ship.
      *Do not "fix" this down to match the default.*
  - [ ] **📏 FOLLOWUP — dial in real shipping amounts from the scale.** Current $8 / $10 / $20 are estimates.
    Bradley has a **shipping scale**; weigh representative items per class (default print, stone/slate coaster,
    heavy/two-part planter) to set data-backed flat-rate + class costs. This same weighing feeds the
    **product weights** task below (labels need real weight), so do them together post-import.
    - **Free shipping over $75** (min-amount OR coupon; "apply min before coupon discount" on). Paired with
      Shipping settings → *"hide rates when free shipping is available"* + *"hide costs until address
      entered."* This is the **intended** free-ship method (the strategy doc's next-order reward), not the
      staging leftover.
    - **Local pickup** enabled ("Pickup", free) at two Jacksonville spots (Starbucks Phillips Hwy · Jax Gem &
      Mineral Society). Uses store address for tax.
    - Minor: shipping destination = *billing address* (Woo default is *shipping*); low impact on flat rate.
  - [ ] **⚠️ Post-import — assign products to shipping classes.** "Coasters"/"Heavy" classes only apply to
    products tagged with them; the Square sync does NOT set shipping class, so until assigned **everything
    ships at the $8 no-class rate.** Same post-import pass as weights.
  - [x] **Label buying/printing (fulfillment) — WooCommerce Shipping installed + activated 2026-08-13.**
    Settings verified on-screen: **label size = 4"×6"** (thermal printer) ✅, **origin = 4531 Praver Dr N,
    Jacksonville FL** (default sender + return) ✅, card on file for postage ✅, receipt emails on ✅, **tax
    IDs IOSS/VOEC/PVA blank** (US-only, correct) ✅, international auto-returns off ✅, USPS SCAN form on,
    **address validation at checkout ON** ✅, auto-open print dialog ON. **Skip ShipStation** (paid
    multi-channel — revisit only if fulfilling across Etsy/Amazon/etc.). *(Shippo plugin also present but
    unused — WooCommerce Shipping is the active tool; deactivate Shippo later to avoid confusion.)*
  - [ ] **Post-import prerequisite:** add product **weights** (+ dims) from slicer output — the Square sync
    doesn't carry them, and buying live-rate labels needs them. (Not needed for flat-rate checkout.)
  - [ ] **Post-import:** buy + print one test **4"×6"** label from a real/test order to prove the thermal
    printer flow end-to-end.
- [x] **Store base country = `US:FL` (Florida)** — fixed 2026-08-13, verified via MCP. Drives both the
      shipping origin and the WooCommerce Tax nexus, so automated tax now configures for Florida (state rate
      + county surtax). *(UI check: Settings → Tax notice should read "…configured for Florida.")*
- [x] **[admin] Tax — WooCommerce Tax installed + enabled 2026-08-13**, nexus = **FL** (auto-calculates
      rates by location; handles FL state rate + variable county surtax). Settings verified correct: prices
      exclusive of tax, calc on shipping address, display excluding tax. ⚠️ It *calculates & collects* only —
      **you still file/remit** FL returns. Only add other states once you cross their economic-nexus
      thresholds. Confirm nexus/filing specifics with an accountant.
  - **Future / revisit when multi-state:** full-compliance services that also **file & remit** for you and
    monitor nexus across states — **TaxJar**, **Avalara**, **Anrok** (Anrok is SaaS-focused, priced for
    scaling businesses). All **paid**. Not needed at single-state (FL) launch; worth it once collecting
    across many states makes manual filing a real chore.
- [x] Store address set (4531 Praver Dr N); confirm timezone.
- [x] **Store locale (verified via MCP 2026-08-13):** selling to **US only** (`allowed_countries=specific`,
      `["US"]`); default customer location = **Geolocate (cache-safe / `geolocation_ajax`)** — correct choice
      since the site uses page caching (Jetpack Boost), so each visitor gets their own location, not a cached one.

## Legal & trust

- [x] **Privacy Policy** (page 3) — real content ("order-only, never sell"), **PUBLISHED on prod**
      (`/privacy-policy/`). *Verified via MCP 2026-08-13.*
- [x] **Refund and Returns Policy** (page 18) — real content, **PUBLISHED on prod** (`/refund_returns/`).
      Made-to-order = no change-of-mind returns, **exception: non-customized coasters** (14-day, unused);
      damage/defect 14 days; refunds 5–10 business days via Square; return shipping not included.
      *Verified via MCP 2026-08-13.*
- [x] **Terms and Conditions** (page 197) — **PUBLISHED on prod** (`/terms/`) + wired to checkout
      (`woocommerce_terms_page_id=197` ✅). Made-to-order acknowledgment, orders/pricing/IP/liability,
      FL governing law. *Verified via MCP 2026-08-13.*
- [~] **[admin] Review WooCommerce → Settings → Accounts & Privacy.** Spot-check 2026-08-13 looked
      good — guest checkout ON, password-setup-link ON, account-erasure options ON, 36-mo inactive-account
      retention. **Privacy-page IS set** (`wp_page_for_privacy_policy=3`, verified via MCP) so the
      `[privacy_policy]` shortcodes in the checkout/registration notices resolve to the published policy.
      **Pending + failed-order retention set to 60 days** 2026-08-13 (was blank/indefinite) — verified via
      MCP (`woocommerce_trash_pending_orders` / `woocommerce_trash_failed_orders` = `{number:60, unit:days}`).
      Final pass still to decide: leave all three "allow account creation" boxes on vs. simplify.
- [ ] SSL valid across the whole site.

## Email

- [ ] **[admin]** "From" address/name (`sales@cornercad.com`); order-notification recipients; business info
      in receipts; a real test email received.

## Site hygiene

- [x] **Search-engine visibility ON** (`blog_public=1`).
- [ ] **Re-authorize the prod MCP** (`cornercad-com`) — token expired around the sync.
- [ ] Admin password secure; unused admin accounts removed.
- [ ] Unused plugins deactivated (Hello Dolly, InstaWP Connect, WPForms Lite, OptinMonster if unused).
- [x] Yoast titles + meta on the 6 main pages (on prod via sync); **sitemap submit to Search Console** post-launch.
- [ ] AI Engine Anthropic content-gen key present (Settings → AI → Environments → Claude) — gets wiped by
      restores/syncs; re-paste if blank. (Separate from the MCP bearer token.)

## Content (buildout — not launch blockers)

- [~] **Build Log** (Daytona Coupe): 16 drafts + a "Build Log" category. Paste the real opening into
      "The Kit Arrives"; finish videos; build the `/daytona-coupe/` hub page. Templates in `content/blog-templates.md`.
- [~] **Maker Blog**: ⚠️ gear/technique posts from the first run were generated **without** the
      settings-placeholder guardrail (the Context wasn't pasted) — re-run those with the corrected Context
      before publishing; product posts are fine. File into the Maker Blog category.

---

## Backlog (post-launch projects)

### 🔌 Build the gallery puller into a real plugin — `cornercad-square-galleries`
**Why:** WooCommerce Square syncs **only the featured image** (documented limitation, years of open
feature requests). Our working fix currently lives at **`~/square-gallery.php`** — a loose file in the
server home dir: not in git, not on staging, invisible to future-us, and easy to lose. Making it a plugin
fixes all of that *and* fits the decided workflow (code → staging → **files-only deploy**;
[[feedback_dev-workflow-content-prod-code-staging]]). It also stays cleanly separate from WooCommerce
Square, so plugin updates can't clobber it (we deliberately never patched that plugin).

**Scope:**
```
cornercad-square-galleries/
  cornercad-square-galleries.php   # plugin header + hooks
  includes/Gallery_Sync.php        # the working pull logic
  includes/CLI.php                 # wp cad-gallery sync [--product=<id>] [--all]
```
- **WP-CLI command** — the reliable path on this LiteSpeed host; replaces the ad-hoc bash restart loop.
- **Hook the Square sync completion** → auto-pull galleries for changed items. This restores the workflow
  Bradley actually wants: *add photos in Square → they show up on the site*, no manual step.
- Keep the **per-image resume** (`_cad_gallery_done` meta + cursor option) so OOM restarts never
  duplicate or lose work. Keep the wp-admin includes (`file.php`/`media.php`/`image.php`) — required for
  `media_sideload_image` outside the browser.
- Settings: **none at first.** Fewer knobs, less to break.

**Public release = separate decision, deliberately deferred.** Demand is obviously real, but shipping to
wordpress.org means support tickets, cross-host compatibility, and maintenance on every WooCommerce
Square API change. Run it privately for a few weeks first, then decide with battle-tested code.

Full technical background: memory `project_square-woo-import-cli-method`.

---

## Standing cautions (see CLAUDE.md + memory)

- 🚨 **No more full-DB staging→prod syncs.** They overwrite prod's DB (config + orders) and clobber the
  live Square connection — confirmed 2026-08-13: the sync flipped prod Square to sandbox (forced a live
  OAuth re-auth) **and re-populated prod's `sandbox_*` fields, defeating the empty-fields fail-safe**
  (clear them after any sync). Content→prod (draft/review/publish), code→staging (files-only deploy).
- **Never press "Disconnect" in Square** — revokes the OAuth token for the whole seller → kills live checkout.
- **Staging is cloned FROM prod** — clean prod first; never staging→prod as a full-DB deploy.
- **Staging is not isolated** — ~50 sites share one hosting cage; high request/process volume has caused
  account-wide outages. Pace bulk operations; don't fire many writes in parallel.
- **The store's base is Florida** (Jacksonville), not California — fix `woocommerce_default_country`.
