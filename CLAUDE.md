# CornerCAD — Project Instructions

cornercad.com — **WordPress + WooCommerce** site (migrated off Concrete CMS **2026-07-25**). A clean-branded
consumer hub for Bradley's CAD / 3D-printed / laser-engraved products (vases, planters, lamps, coasters,
and automotive/custom parts). B2B/wholesale is a separate arm at **CornerCADWorks.com**. No
marketplace/processor branding appears publicly.

Site design + IA (platform-neutral; written against Concrete but the IA/content model still holds — see
the migration mapping at its top): `docs/superpowers/specs/2026-06-24-cornercad-site-structure-design.md`.

## Operating protocol
Two MCP servers, split by job:
- **`woocommerce`** (9 tools) — products and orders. The only way to read orders.
- **`cornercad-com`** (43 tools) — pages, blocks, media, terms, options, users.

Full inventory and the read/write split:

@docs/wordpress-mcp-protocol.md

MCP tools are **deferred** — batch-load them in one `ToolSearch` call before use.

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

**Production now runs AI Engine Pro**, so the 93-tool set (incl. `wc_*`) is available there — but MCP
negotiates its tool list at connection time, so **a Claude Code restart is required** before this session
can see them. Verified 2026-07-30: `cornercad-com` still exposed only the free 43.

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

### Current gaps (the working backlog)
1. **No published products.** `product` count is 1 **draft**, 0 publish. Slate Coaster still needs
   finishing and publishing. All `product_cat` counts are 0.
2. **Media library has 5 attachments.** Product imagery is still largely missing.
3. ~~Square not credentialed.~~ **Square IS connected.** See "Square environment split" below.
   Remaining work there: paste the sandbox credentials into staging (Bradley is fetching them).
4. **Policy pages are drafts.** Payment gateways generally require these published before go-live.

Resolved since 2026-07-25: static homepage, the page tree (About/Custom Work/Catalog/Blog/Contact), and
the `product_cat` tree all now exist on production.

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

### State (2026-07-30)
| | `enable_sandbox` | Credentials / location |
|---|---|---|
| production | `no` | live seller, OAuth, location `LV94H6Q7QPB42` |
| staging | **`yes`** (set 2026-07-30 via `wp_update_option`) | sandbox app + sandbox test-account token, entered in admin UI 2026-07-30 |

The split is in place. Sync remains **off on both** (`system_of_record: disabled`,
`enable_inventory_sync: no`, `enable_order_fulfillment_sync: no`) — enable it on **staging only**, and
only after products exist to test with.

### Setup — DONE 2026-07-30 (admin UI; do NOT paste tokens into chat or git)
Staging is on Sandbox with app `sq0idp-2t7sPpvaoDNYq55vcQXn9Q` (Square app **`uniquecreationsbylisac`**),
its default sandbox test account ("Sandbox for sq0idp-2t7s…", created 2025-12-10), and
**Sync Settings = Disabled** — to be enabled once there are published products to test against.

⚠️ **The Application ID is the SAME in both environments.** Only the *access token* differs (each app has
a production token and a separate Sandbox token). A `sq0idp-` value in the **Sandbox** Application ID
field is therefore **correct** — do not read it as "staging is pointed at live." The legacy
`sandbox-sq0idb-` prefix no longer applies, and I raised a false alarm on this 2026-07-30.
Application IDs are **public identifiers, not secrets**; access tokens are the sensitive half.

**Account structure:** CornerCAD operates **under Bradley's wife's Square account**
(`uniquecreationsbylisac`) — that is the correct/parent Square account, and `LV94H6Q7QPB42` is a location
under it. So the app name looking "wrong" for CornerCAD is expected; don't flag it.

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
