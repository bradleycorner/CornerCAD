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

## Platform state (verified 2026-07-25)

**Theme:** `bluehost-blueprint` (block theme, from The Bluehost Plugin).
**Permalinks:** `/%postname%/`. **Currency:** USD. **Store address:** 4531 Praver Dr N.

### Plugins installed
WooCommerce **10.9.4** · WooCommerce **Square 5.4.2** · WooCommerce.com Update Manager · Payments &
Shipping 1.12.1 · Yoast SEO 28.1 · Jetpack 16.0.1 · WPForms Lite 2.0.0.2 · AI Engine 3.6.2 + AI Provider
for Anthropic (this is what serves the MCP) · MonsterInsights · OptinMonster · Akismet · InstaWP Connect ·
The Bluehost Plugin · Hello Dolly.

### Post types
`post`, `page`, `attachment`, `product`.

### Pages (all that exist)
| ID | Page | Status |
|---|---|---|
| 14 | Shop | publish |
| 15 | Cart | publish |
| 16 | Checkout | publish |
| 17 | My account | publish |
| 3 | Privacy Policy | draft |
| 18 | Refund and Returns Policy | draft |

These are **WooCommerce's auto-created pages only**. Home, About, Custom Work, Catalog do **not exist yet**.

### Current gaps (the working backlog)
1. **`show_on_front` = `posts`** — no static homepage. Site currently renders the blog index at `/`.
2. **Zero products.** `wp_get_posts` on `product` returns `[]`. Slate Coaster needs recreating.
3. **`product_cat` has only `Uncategorized`.** The category tree from the spec
   (Automotive · Home & Garden · Display & Retail · Functional/Hardware) is unbuilt.
4. **Media library has 1 attachment.** All product imagery needs re-uploading.
5. **Square not credentialed.** WooCommerce Square is installed and has registered its taxonomies
   (`wc_square_synced`, `pos_product_visibility`), but the OAuth connect is **admin-UI only** — not MCP work.
6. **Policy pages are drafts.** Payment gateways generally require these published before go-live.

## Migration mapping — Concrete CMS → WordPress

| Concrete concept | WordPress equivalent |
|---|---|
| `Product` page type + `product_*` attributes | `product` CPT + post meta (`_regular_price`, `_sku`, …) |
| `packages/cornercad_setup` CIF package | **Obsolete** — Woo registers the CPT; categories are terms created via `wp_create_term` |
| Community Store | WooCommerce 10.9.4 |
| Square Payment Method add-on | WooCommerce Square 5.4.2 |
| Genesis theme areas / blocks | Gutenberg blocks (`wp_write_blocks`, block patterns) in `bluehost-blueprint` |
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
