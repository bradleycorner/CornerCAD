# CornerCAD — Project Instructions

cornercad.com — **WordPress + WooCommerce** site (migrated off Concrete CMS **2026-07-25**). A clean-branded
consumer hub for Bradley's CAD / 3D-printed / laser-engraved products (vases, planters, lamps, coasters,
and automotive/custom parts). B2B/wholesale is a separate arm at **CornerCADWorks.com**. No
marketplace/processor branding appears publicly.

Site design + IA (platform-neutral; written against Concrete but the IA/content model still holds — see
the migration mapping at its top): `docs/superpowers/specs/2026-06-24-cornercad-site-structure-design.md`.

## Operating protocol
Three MCP servers, split by job:
- **`woocommerce`** (9 tools) — products and orders. The only way to read orders.
- **`cornercad-com`** (43 tools) — pages, blocks, media, terms, options, users.
- **Square** (official connector, `get_service_info` / `get_type_info` / `make_api_request`) —
  reads and writes the Square catalog directly. Verified working 2026-08-24 (renamed an item and
  repriced five variations). ⚠️ **Always pass `sparse_update: true`** on `batchUpdateObjects` — a
  full update REPLACES the object and would wipe descriptions, variations and images. Writes need
  Bradley's explicit confirmation, per Square's own rule and the write-safety section below.

Full inventory and the read/write split:

@docs/wordpress-mcp-protocol.md

MCP tools are **deferred** — batch-load them in one `ToolSearch` call before use.

## Environment / access (verified 2026-08-19)

### SSH
```bash
ssh cornerfa
```
Alias in `~/.ssh/config` -> `box2304.bluehost.com`, user `cornerfa`, key `~/.ssh/Cornerfa2026`.

WARNING: **use the hostname, not the IP.** The `cornerfa-ip` alias (`50.87.182.158`) fails with
"no route to host" even though `dig` resolves both names to that same address — Bluehost support
identified the hostname as the fix, after 19 years of the IP working. It is a **jailshell**: `ps`
shows almost nothing and many standard utilities are absent or restricted.

Site root: `/home1/cornerfa/public_html/cornercad` (`~` = `/home1/cornerfa`).

### WP-CLI — the only invocation that works
```bash
cd /home1/cornerfa/public_html/cornercad && /opt/cpanel/ea-php83/root/usr/bin/php /usr/local/bin/wp <command>
```
All three parts are load-bearing:
- **`cd` first** — `wp-cli.yml` is read from the current directory only.
- **The absolute ea-php83 binary.** Bare `php` is `/usr/local/bin/php`, a wrapper that picks its SAPI
  from the environment: **8.0.30 CLI** in an interactive shell, but **`cgi-fcgi` under cron**, which
  WP-CLI refuses to run under. `ea-php80` fails outright too — the `social-engine` plugin's Composer
  platform check requires >= 8.1. The site runs **ea-php83**.
- cPanel MultiPHP gives **each site its own `php.ini` and handler**. That governs the *web* SAPI only
  and tells you nothing about which CLI binary to use.

`--user=1` is **mandatory** for the Square import — a userless CLI silently fails
`current_user_can('publish_products')` and imports 0. See the `square-woo-import-cli-method` memory.

### Database
Table prefix is **`awF_`**, not `wp_`. Action Scheduler lives in `awF_actionscheduler_actions` /
`_claims` / `_logs` / `_groups`.

### Cron
`DISABLE_WP_CRON` is `true` in `wp-config.php`; a cPanel cron drives WP every 5 minutes:
```bash
cd /home1/cornerfa/public_html/cornercad && date >> cron-test.log && /opt/cpanel/ea-php83/root/usr/bin/php /usr/local/bin/wp cron event run --due-now >> cron-test.log 2>&1
```
WARNING: **never go back to a curl-based cron.** cornercad.com sits behind **Cloudflare**, which
bot-challenges the request — that silently killed WP-Cron for weeks and deadlocked Action Scheduler
with 477 orphaned claims. Side effect of the 5-minute cadence: actions can sit up to 5 minutes past
due, so WooCommerce's "past-due action" banner appears intermittently and is **cosmetic**.

### Site timezone is UTC
`timezone_string` is empty and `gmt_offset` is `0`, so every wp-admin timestamp is GMT and matches
the database directly. Subtract 4 for EDT.

### Local prerequisite: the VPN
OpenVPN runs 24/7 on Bradley's Mac and **must be in "Preferred" mode, not Legacy**, with IPv6
disabled at the router (AT&T does not route it). In Legacy mode every MCP call to cornercad.com dies
with `000`/timeout while Chrome loads the site fine. Diagnose from the **terminal**, not a browser:
```bash
curl -sS -o /dev/null -w "%{http_code}\n" https://cornercad.com/
```
Expect **307**.

## Platform state (verified 2026-07-30, after the site restore)

**Theme:** `sellany` (block theme). **Permalinks:** `/%postname%/`. **Currency:** USD.
**Store address:** 4531 Praver Dr N.
**Front page:** static — `show_on_front = page`, `page_on_front = 49` (Welcome).

⚠️ **Read `show_on_front` with `raw: true`.** The cached/filtered read returns a stale `"posts"` on this
site; only `raw: true` returns the true `"page"`. A non-raw read caused a wrong "production is bare"
report on 2026-07-30. Same caution applies to any option you are about to act on.

⚠️ **Staging and production are content-identical** (same post IDs 49/67/68/76/77, same block markup).
Do not assume production lags staging — verify.

### Plugins installed
WooCommerce **10.9.4** · WooCommerce **Square 5.4.2** · WooCommerce.com Update Manager · Payments &
Shipping 1.12.2 · **AI Engine (Pro) 3.6.2** (serves the MCP) · AI 1.2.0 · Yoast SEO 28.1 · Jetpack 16.0.1 ·
Jetpack Boost 4.6.3 · **Contact Form 7 6.1.6** (the form actually in use) · WPForms Lite 2.0.0.2 (installed,
unused) · Matcha Extra 1.0.7 · Meow Lightbox 5.5.8 · One Click Demo Import 3.4.1 · WPCode Lite 2.3.8 ·
MonsterInsights 11.1.2 · OptinMonster 2.16.24 · Akismet 5.7 · InstaWP Connect · The Bluehost Plugin 4.18.0 ·
Hello Dolly.

**Production runs AI Engine Pro and the full 93-tool set is live** — confirmed 2026-07-30 after the
restart, by real calls rather than schema loads (`mcp_ping` → `cornercad.com`; `wc_list_products`
returned data). All 25 `wc_*` tools plus the theme/plugin lifecycle tools are available on
`cornercad-com`. Use `wc_create_product` for product work, not the raw `wp_create_post` path.

⚠️ **`permissions.ask` must be re-audited whenever the tool count changes.** The Pro upgrade landed
~32 write tools ungated on the live store (incl. `wc_create_refund`, `wp_switch_theme`,
`wp_delete_plugin`) because the rule list had been written against the free 43. Fixed 2026-07-30 —
now 61 rules. See `docs/wordpress-mcp-protocol.md` §0.5.

### Post types
`post`, `page`, `attachment`, `product`.

### Pages
| ID | Page | Status |
|---|---|---|
| 49 | Welcome (front page) | publish |
| 67 | About | publish |
| 68 | Custom Work | publish |
| 14 | Catalog | publish |
| 76 | Blog | publish |
| 77 | Contact | publish |
| 15 | Cart | publish |
| 16 | Checkout | publish |
| 17 | My account | publish |
| 3 | Privacy Policy | **draft** |
| 18 | Refund and Returns Policy | **draft** |

Note ID 14 is **Catalog**, not "Shop" — it was retitled.

### `product_cat` terms
3D Printed (24) · Automotive (26) · Coasters (29) · Lamps (28) · Laser Engraved (25) · Signage (30) ·
Vases & Planters (27) · Uncategorized (20). All counts 0.

These are the **as-built** categories and they differ from the spec's four (Automotive · Home & Garden ·
Display & Retail · Functional/Hardware). The as-built tree is the source of truth; the spec is stale.

### Current state (verified 2026-08-24)

**179 published products.** The Square → Woo import is done and the sync is the working pipeline;
products are never built in Woo by hand. Square is the system of record for name, price,
description, category and images.

| | |
|---|---|
| published products | **179** |
| with a `---` split description | **115** |
| with no description at all | **62** |

**Product copy pipeline.** Descriptions are authored in `content/square-descriptions-*.md`, pushed
to Square with `scripts/square_push_descriptions.py`, and reach the site on the next sync. The
`---` separator splits summary (Woo excerpt) from details (Description tab) via the WPCode snippet
mirrored at `snippets/wpcode-square-description-split.php`. `scripts/square_insert_separators.py`
bulk-inserts separators and normalises to US spelling; `scripts/square_audit_catalog.py` is a
read-only catalog audit.

⚠️ **A `⚠️CONFIRM` tag blocks the whole FILE, not one entry** — never leave an unresolved product
in a file with shippable ones. Unresolved copy lives in `square-descriptions-pending.md`.

**Laser coaster design pipeline** (spec: `docs/superpowers/specs/2026-09-05-laser-coaster-launch-design.md`).
Designs are tracked in `content/coaster-designs.csv` (schema/loader: `scripts/coaster_manifest.py`)
and pushed to Square as variations of the coaster parent product via
`scripts/square_push_coaster_designs.py`, then reach the site on the next Square→Woo sync — the same
shape as the description pipeline above, applied to design variations instead of description text.
Minimum order quantity (4, for any coaster product with a Design attribute) is enforced by
`snippets/wpcode-cornercad-coaster-min-qty.php`.

**Variable products** (built 2026-08-23): Wall Clock (5 dial faces, $45), Vexel Clock (3 face
materials, $55/$67/$70), Engraved Slate Coaster (4 shape×pack). Hard constraints learned:
- **One variation dimension only.** `has_multiple_variation_attributes()` silently drops a product
  with 2+ from the sync. Flatten a second axis into the label (as the coasters do).
- **Variations must come from a Square OPTION SET**, not hand-made. The Woo attribute name is read
  from the item option; without one the storefront dropdown is labelled "Attribute".
- **Never reuse the parent's SKU on a variation.** Doing so deleted Woo product 339 and swallowed a
  variation. Use distinct 3-letter suffixes (`-FDM`, `-MER`).
- Converting an item to variable **deletes and recreates** the Woo product with a `-2` slug — reset
  the slug afterwards.

**Pricing model: return per printer-hour**, not margin percent — plate hours are the binding
constraint. Wall Clock $45 / 2h09m = **$18.50/hr** is the benchmark. Vexel $55 = $13.30/hr. The
Clarit set at $30 / 13h21m = **$1.50/hr** and needs re-slicing coarser than 0.12 mm before pricing.

### Open items
1. 🐛 **Variable products revert to out-of-stock on every sync.** Square says
   `track_inventory: false`; the import path honours it, the update path does not. Reproduced and
   handed off as its own task. Both variable clocks are currently unpurchasable.
2. **62 products still have no description**, concentrated in Home Decor and Office.
3. **Policy pages are drafts.** Gateways generally require these published before go-live.
4. **Catalog mode is ON** — a WPCode snippet filters `woocommerce_is_purchasable` to
   `__return_false`, so nothing is buyable by design until launch.
5. Seasonal items carry years in their names (`Xmas 2025`, `Eggs Easter 2026`) — rename before they
   accumulate sales history.


## Square environment split — production→live, staging→sandbox

**Target architecture:** production talks to the **live** Square account; staging talks to the **Sandbox**.
Square's Sandbox is fully isolated — credentials/resources cannot cross environments, cards are never
charged, and each sandbox test account has its own catalog/inventory/orders. So staging can safely run
sync **on** once it's on sandbox.

### 🚨 NEVER press "Disconnect" in WooCommerce → Settings → Square on staging
Square's Disconnect calls `RevokeToken`, which revokes OAuth tokens **for the whole seller**, not for one
site. Staging is a clone sharing production's token, so disconnecting on staging **kills checkout on
production**. Switching `Environment` to Sandbox is the safe operation — it uses sandbox credentials
*instead of* the stored production token without revoking it.

### State — **verified 2026-07-30 by direct `wc_square_settings` read on both sites**
| | production | staging |
|---|---|---|
| `enable_sandbox` | `no` | **`yes`** |
| `sandbox_application_id` | *(empty — deliberate, see below)* | `sandbox-sq0idb-D9tOd3hNREjp6QFaAHIlFQ` |
| `sandbox_location_id` | *(empty)* | `L4KK3G9JY6RDY` |
| `production_location_id` | **`LS4SZ98SBX4F6`** (CornerCAD) ✅ | `LV94H6Q7QPB42` ⚠️ stale |
| `system_of_record` | `disabled` | `disabled` |
| inventory / fulfillment sync | `no` / `no` | `no` / `no` |

The split is in place and confirmed against the database, not just the admin UI. The live token was
**not** revoked — `production_location_id` survives on both sites and production's sandbox fields are
empty, so live checkout is untouched.

Sync remains **off on both** — enable it on **staging only**, and only after products exist to test
with. Minor known drift: `enable_customer_decline_messages` is `yes` on staging, `no` on production.
Harmless.

**Update 2026-08-02 — products now exist, so sync is being set up.** With the Square catalog built,
the intended production config (reviewed, not yet saved as of this writing) is: **Environment =
Production**, **Business location = CornerCAD** (`LS4SZ98SBX4F6`) ✅ — confirmed in the Woo Square
settings, so **no UCBLC/Lisa catalog bleed on import**. **Sync Settings = Square** (Square is the
system of record → Square overwrites Woo), Sync Inventory on, Override product images on, Handle
missing products off, Sync interval 24h, Order fulfillment sync on, Square discount codes on.
**Plan/order of operations:** prove sync on **staging/sandbox first** (per the fail-safe philosophy
below) by seeding the sandbox catalog with **10–20 items, one or two per Square category** (the
sandbox catalog limit is small), verify the flow, *then* enable on production and run "Import all
Products from Square." After import, verify the count is ~168 with **zero `UCL-` items**.

⚠️ **Two things the Woo↔Square sync will NOT bring over:**
- **Modifiers.** The filament pickers (Filament Type, Filament Color, Top/Base Color) are Square
  *modifiers*, and the sync maps name/price/SKU/stock/images/categories/*variations* — not modifiers.
  So imported planters land on cornercad.com **without** the colour/type dropdowns. To offer those on
  the Woo storefront, use a **WooCommerce Product Add-ons** plugin (or Woo variations).
- **Square Online "site visibility."** `ecom_visibility` is a Square-Online-only field and is unrelated
  to Woo; it also is **not writable via the Catalog API** (see the Square catalog section).

### ⚖️ Decision: production gets NO sandbox credentials, deliberately (2026-07-30)
Production's empty `sandbox_*` fields are a **fail-safe, not an omission**. If `enable_sandbox` is
ever flipped to `yes` on production, Square has no sandbox credentials to fall back on, so it breaks
**loudly and immediately**.

Populate those fields and the same accident becomes **silent**: production would process real
checkouts against the sandbox. Orders complete, confirmation emails send, WooCommerce records the
sale — **and no money moves.** That could run for days unnoticed, with every customer believing they
bought something. Loud breakage on a payment path beats silent success.

This is not hypothetical: per the standing cautions below, a re-clone or restore *does* rewrite this
config as a matter of routine, and it has already flipped in the wrong direction once. Credentials in
production's sandbox fields would give the reverse flip somewhere to land.

**To verify live checkout, run one small real transaction and refund it** — that also exercises the
real Square account, fraud rules, settlement, and the **statement descriptor** (see the pre-go-live
check below), none of which sandbox can test. One live test closes both items.

### ⚠️ Reading `wc_square_settings` exposes the sandbox token in plaintext
`sandbox_token` lives inside the same option array as `enable_sandbox`, and `wp_get_option` returns
the whole array — there is no way to check the sandbox flag without pulling the credential into the
transcript. This happened 2026-07-30. Treat any read of this option as **credential-exposing**, and
prefer reading it only when you actually need to act on it.

Not rotated, by Bradley's call 2026-07-30 — the token is a *sandbox* credential (isolated test
account, fake money, nothing built against it), so the blast radius is small. Note the protective
factor is the sandbox isolation, **not** that the token is unused; an unused credential is exactly as
usable as a used one.

### Setup — DONE 2026-07-30 (admin UI; do NOT paste tokens into chat or git)
Staging is on Sandbox using a **dedicated `CornerCAD` Square application** (`sq0idp-wqQUPvuC3RFKbxlUUKOtmA`),
with its **Sandbox** Application ID `sandbox-sq0idb-D9tOd3hNREjp6QFaAHl…` and the matching Sandbox Access
token. **Sync Settings = Disabled** — to be enabled once there are published products to test against.

History: staging was first configured with the *production* Application ID of the
**`uniquecreationsbylisac`** app (`sq0idp-2t7sPpvaoDNYq55vcQXn9Q`) — wrong, see the warning below. Bradley
created the dedicated CornerCAD app and replaced both values. **No credential was exposed** in the
process: Application IDs are public identifiers, and the only token that appeared on screen was clipped
by the input field, so it was a partial prefix rather than a usable token.

⚠️ **Sandbox and production have DIFFERENT Application IDs.** With the **Sandbox** toggle active, the
Credentials page shows a distinct **`sandbox-sq0idb-…`** Sandbox Application ID plus a separate Sandbox
Access token. The `sq0idp-…` value shown on the app-list card (and in the Console URL `/apps/sq0idp-…/`)
is the application's **production** ID.

So a `sq0idp-` value in the **Sandbox** Application ID field is **WRONG** — it means production
credentials were copied. Verified from the Console 2026-07-30. (I initially flagged this correctly, then
wrongly retracted it on the strength of the app-list card plus a weak secondary source. The Console under
the Sandbox toggle is the authority — trust it over search results.)

✅ **SETTLED — do not re-litigate.** The stored value on staging reads
`sandbox-sq0idb-D9tOd3hNREjp6QFaAHIlFQ`, confirmed by direct database read 2026-07-30. Correct
`sandbox-sq0idb-` prefix. The question flip-flopped twice; the database has now answered it. Any
future doubt should be resolved the same way — read the option, don't reason from Console screenshots
or search results.

Square's own page copy is misleading here: with Sandbox active it still says "These are your production
credentials" and "Grants full production access." Ignore that; the red **Sandbox** labels are what count.

Application IDs are **public identifiers, not secrets**; access tokens are the sensitive half.

**Account structure:** CornerCAD operates **under Bradley's wife's Square account**
(`uniquecreationsbylisac`) — that is the correct/parent Square account, and `LV94H6Q7QPB42` is a location
under it. So the app name looking "wrong" for CornerCAD is expected; don't flag it. **Not a registered
DBA** as of 2026-07-30 (may be formalized later if needed).

✅ **Statement-descriptor risk resolved 2026-07-31.** Square derives receipts and the card descriptor
from the **location**, and production was pointed at `LV94H6Q7QPB42` — "Duval County", **INACTIVE**,
business name "Unique Creations By Lisa C". Customers would have seen the wrong business.

Production now points at **`LS4SZ98SBX4F6`**, whose `business_name` is literally **"CornerCAD"**
(`cornercad.com`, `sales@cornercad.com`, ACTIVE, created 2026-07-30). Changed via `wp_update_option`
on `wc_square_settings`, writing the full 15-key array back so nothing else was touched; verified by
re-read.

Still worth confirming with **one small live transaction, refunded**, before go-live — the descriptor
is only truly proven on a real card statement.

### Square locations (live account `ML0RSAXT9HH0B`)
| ID | Name | Status | Business name |
|---|---|---|---|
| `LS4SZ98SBX4F6` | **CornerCAD** | ACTIVE | CornerCAD ← **Woo points here** |
| `L71MXVF5YWZE4` | Unique Creations by Lisa C, LLC | ACTIVE | Unique Creations By Lisa C, LLC |
| `LV94H6Q7QPB42` | Duval County | INACTIVE | Unique Creations By Lisa C |

Plus 4 further INACTIVE locations belonging to Lisa (Clay, Flagler, Nassau, St Johns ×2). Square's
CSV export only includes **active** locations, so exports carry just the first two.

⚠️ **Staging still carries `production_location_id: LV94H6Q7QPB42`.** Inert while staging runs on
sandbox, but it would become live-wrong if staging were ever switched off sandbox.

### Standing cautions
- **A re-clone or restore from production overwrites staging's sandbox settings back to live.** Re-check
  `enable_sandbox` on staging after every clone/restore. (This config survived the 2026-07-29 restore
  *as live* — that's how the exposure was found.)
- Square does not support staging on a **subdomain** (auth domain mismatch); a **subfolder** is required.
  `cornercad.com/staging/7680/` is already the supported shape.
- Token storage key name is unknown — `wc_square_access_token` / `_refresh_token` / `_merchant_id` all
  return `false` even on connected production, so their absence proves nothing about authentication.
- Docs: [sandbox mode](https://woocommerce.com/document/woocommerce-square/testing-the-woocommerce-square-extension-in-sandbox-mode/) ·
  [Square Sandbox](https://developer.squareup.com/docs/devtools/sandbox/overview) ·
  [OAuth best practices](https://developer.squareup.com/docs/oauth-api/best-practices)

## Square catalog — CornerCAD product line (built 2026-08-01/02)

The full h3li0-licensed product line is now built **in the Square catalog** (live account
`ML0RSAXT9HH0B`, all items at the **CornerCAD** location `LS4SZ98SBX4F6`). Done directly via the Square
API/MCP, **not** through WooCommerce — Woo gets populated later by import (see sync section above).

**Scope:** **168 CornerCAD products**, all `CAD-` SKUs (Lisa's are `UCL-`; the account/catalog is
shared, so always filter on the SKU prefix). 155 were bulk-imported by Bradley via Square's item CSV;
13 pre-existed. Prices $30–$60. All are **made-to-order**: `track_inventory = false` at CornerCAD, so
they never read "out of stock."

**Images:** 2,480 photos uploaded via a run-it-yourself script (Bradley runs it locally with a Square
Personal Access Token; the token never touches chat/git). Kit lives in **`scripts/`**:
`square_upload_images.py`, `upload_plan.json` (all 168, hero-first ordering), `README-square-image-upload.md`.
Each product's hero frame (the labelled h3li0 title-card, the **last** frame in each folder) is set as
the primary image. Re-runnable/idempotent via `upload_state.json`.

**Filament customization = Square modifier sets** (NOT variations — avoids color×color explosion, keeps
it made-to-order). Reusable sets, single-select:
- **Filament Type** — PLA / PETG / Matte free, **Silk +$4** (slower print).
- **Filament Color** — standard palette free (Black/White/Gray/Red/Blue/Green/Purple/Gold/Silver/
  Rainbow/Marble), **Metallic Copper +$4**. Used on single-part items + the exceptions below.
- **Top Color** + **Base Color** — same palette; used *instead of* Filament Color on the **60 two-part
  planters** (top + base printed separately). Metallic Copper on both → +$8 stacks (intentional).
- Two-part **exceptions kept on single Filament Color** (single-piece prints): Tundra, Spectrum, Seia,
  Ribbed Planter Large, **Nova** (note: Inova IS two-part), Maia, Evolve, Fusion, Briosi, Beja Planters.

**Categories (Square).** Split the oversized "Vases & Planters" (110+) into **Vases** (39) and
**Planters** (72); **"Vases & Planters" deleted**. Created **Gardening** (Fluxis + Nexus watering cans)
and **Fidgets**. Refiled 4 of Lisa's items that were mis-parked in "3D Printed": Chainmail Snake →
Chainmail, Dragonfly Jewelry → Pendants, Mobius Fidget → Fidgets, Spooky Cuties → Earrings. (The "3D
Printed" category still legitimately holds CornerCAD 3D prints — clocks, Baby Dragon, egg.) Note: Square
categories differ from the Woo `product_cat` tree; reconcile on import.

**Real copper-infused = future premium tier, not built.** colorFabb copperFill (~$84/kg, heavy, ~1
planter/spool, anneal + sand/polish/patina + seal with Sharkhyde). Belongs as its **own premium listing**
(~$70–120+), not a $4 modifier. Bradley's Creality nozzles (hardened steel + copper jacket) handle
abrasive metal-fills — wears faster than normal but works; dedicate a nozzle to abrasives.

### Gotchas learned this session (Square Catalog API)
- **`ecom_visibility` is NOT writable via the Catalog API** — sparse updates to it are silent no-ops.
  To hide CornerCAD items from Lisa's Square Online store (`uniquecreationsbylisac.com`), Bradley set
  **Site visibility → Hidden in the Square Dashboard** (done 2026-08-02; the bulk limit is low, so
  filter into small groups). This is separate from POS: hidden-online items still ring up on the iPhone
  Checkout app (POS visibility = present-at-location + POS channel, untouched by `ecom_visibility`).
- **Category moves need a unique `ordinal`** in `categories`/`reporting_category`, or you hit a
  `duplicate int value … category_id` error (Square reuses the item's old ordinal and it collides).
- **Modifiers don't sync to WooCommerce** (see sync section).

### Square catalog — open items
- **Delete the old duplicate Vanta Vase** (`CAD-VAS-0003`, item `QYXL…`, Gold/Copper variations, one
  photo) — superseded by the new `CAD-VAS-0009` (`36BAC…`). Kept for now, Bradley to delete.
- **Product descriptions:** the 13 pre-existing items have them; the rest mostly don't. A batched
  hero-shot → description pass is queued (~1k tokens/product downscaled, ~150k for all).

## Migration mapping — Concrete CMS → WordPress

| Concrete concept | WordPress equivalent |
|---|---|
| `Product` page type + `product_*` attributes | `product` CPT + post meta (`_regular_price`, `_sku`, …) |
| `packages/cornercad_setup` CIF package | **Obsolete** — Woo registers the CPT; categories are terms created via `wp_create_term` |
| Community Store | WooCommerce 10.9.4 |
| Square Payment Method add-on | WooCommerce Square 5.4.2 |
| Genesis theme areas / blocks | Gutenberg blocks (`wp_write_blocks`, block patterns) in `sellany` |
| Topic tree "Product Categories" | `product_cat` taxonomy |
| Page cIDs 1/409/416/417/418 | **Dead.** Do not reuse — see the Pages table above for what actually exists. |

Superseded docs, kept for history only: `docs/concrete-cms-api-protocol.md`,
`docs/community-store-square-gateway-brief.md`, `packages/cornercad_setup`,
`packages/cornercad_catalog_import`.

## Known landmines (WordPress)
- **No draft-version safety net.** Concrete's write→verify→delete-the-draft-version rollback does **not**
  exist here; writes are live. Create as `draft`, verify, then publish.
- **Set `_price` as well as `_regular_price`.** Woo queries `_price`; a product with only
  `_regular_price` won't sort/filter correctly.
- **`wp_add_post_terms` defaults to `append: false`** — it **replaces** every term in that taxonomy.
- **`wp_delete_post` with `force: true` is irreversible** (bypasses Trash).
- **`wp_get_posts` returns 10 rows** when `limit` is omitted, and never returns body content.
- Post IDs are stable across edits — unlike Concrete blockIDs, which changed on every update.

## WooCommerce / WordPress MCP — write safety (temporary)

The `woocommerce` and `cornercad-com` MCP servers act on the LIVE cornercad.com
store. Until I explicitly say otherwise, treat every mutating operation as
requiring my confirmation:

- Read-only tools (anything `*-query`, list, get, search) may run without asking.
- Before ANY tool that creates, updates, deletes, or changes status/state
  (e.g. product-create, product-update, product-delete, order-update-status,
  order-add-note, or any WordPress post/page/media/settings write), STOP and
  show me: the tool name, the exact target (ID + name), and a plain-language
  summary of the change. Wait for my explicit "yes" before calling it.
- Never batch or chain writes silently. If a task needs multiple writes,
  lay out the full plan and let me approve it before executing any of them.
- This applies even when I've asked for a broader task — confirm each write.

**Enforcement + caveats (verified 2026-07-25):**
- Backed by `.claude/settings.json` → `permissions.ask`, which now lists every `cornercad-com` write
  tool. Read-only tools stay in `settings.local.json` → `allow`.
- ⚠️ Approving **"always allow"** on a write tool adds it to `settings.local.json` `allow` and
  **silently defeats this rule**. If a write tool shows up there, delete it.
- ✅ The **`woocommerce`** server's 9 tools are now **verified** and the `permissions.ask` names were
  corrected on 2026-07-25 — the originals (`product-create`, `products-query`, `order-update-status`,
  `order-add-note`) **did not exist and gated nothing**. Real naming is
  `woocommerce-<resource>-<verb>`; see `docs/wordpress-mcp-protocol.md` §0.3.
- The obsolete `mcp__concretecms__.*` schema-discovery hook is still in `settings.json`; harmless
  (that server is dead) but it protects nothing on WordPress.

## Content
`content/*.md` hold the finalized page copy (home, catalog, custom-work, about) — **still valid**, the copy
is platform-independent and is the source for rebuilding pages in Gutenberg. Direction: read as a
**general 3D/laser business**; automotive is one specialty (but **keep both product bands on Home**).
Car naming = **"Daytona Coupe"** (technically a Factory Five Type-65; not "Shelby").

## Credentials
`api.md` holds the old Concrete API Client ID/Secret — **do not commit or publish it**, and it is now
**dead** (the Concrete site is gone). The WordPress MCP authenticates via the AI Engine plugin's own
bearer token configured in `.mcp.json`.
