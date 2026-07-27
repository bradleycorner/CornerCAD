# WordPress / WooCommerce — Operating Protocol (via the `cornercad-com` MCP)

**Applies to:** cornercad.com, now running **WordPress + WooCommerce** (migrated off Concrete CMS,
2026-07-25). Supersedes `concrete-cms-api-protocol.md`.

**Verified:** 2026-07-25 — `mcp_ping` returned `cornercad.com`, and a full write round-trip
(create draft product → set meta → assign term → force-delete → confirm gone) completed cleanly.

---

## 0. Tooling rule
Operate the site **only** through the `mcp__cornercad-com__*` MCP tools. No curl/WP-CLI/PHP wrappers.
The MCP exposes WordPress core functions directly (`wp_insert_post`, `update_post_meta`, `wp_set_object_terms`,
`update_option`, …), so it is far less restrictive than the old Concrete REST API — most of the
"Dashboard-only" limits recorded for Concrete simply do not exist here.

Tools are **deferred** in this harness — load them with a single batched `ToolSearch`:
`select:mcp__cornercad-com__wp_get_post,mcp__cornercad-com__wp_update_post,...`

### 0.1 Write-confirmation rule (enforced in `.claude/settings.json`)
cornercad.com is **live**. Per `CLAUDE.md`, every mutating call must be confirmed by Bradley first:
show the **tool name, exact target (ID + name), and a plain-language summary**, then wait for an
explicit yes. Never batch or chain writes silently — lay out the whole plan and get it approved before
the first write.

This is backed by config, not just convention:
- `.claude/settings.json` → `permissions.ask` lists **every** `cornercad-com` write tool, so each one
  raises a prompt.
- `.claude/settings.local.json` → `permissions.allow` holds **read-only tools only**. If a write tool
  ever reappears there (the harness adds entries when you approve "always allow"), it silently defeats
  the rule — **remove it**.

Read-only tools (`wp_get_*`, `wp_count_*`, `wp_list_*`, `mcp_ping`) run freely.

### 0.2 The two MCP servers
Both are registered at **project scope in `~/.claude.json`** (project `.mcp.json` still holds only the
dead `concretecms` server — ignore it).

| Server | Backing | Purpose |
|---|---|---|
| `cornercad-com` | AI Engine WP plugin | General WordPress: posts, pages, meta, terms, media, options. 43 tools, inventoried below. |
| `woocommerce` | `@automattic/mcp-wordpress-remote` v0.3.5 → `https://cornercad.com/wp-json/woocommerce/mcp` | WooCommerce's own native MCP endpoint. Auth via `CUSTOM_HEADERS` → `X-MCP-API-Key`. |

`woocommerce` is a **proxy** — it forwards `tools/call` to the Woo MCP endpoint, so its tool names come
from **WooCommerce on the site**, not from the npm package. `WOO_CUSTOMER_KEY` / `WOO_CUSTOMER_SECRET`
are not set; they are only needed for `wc_reports_*` tools (Basic Auth). Everything else authenticates
through the `X-MCP-API-Key` header.

### 0.3 `woocommerce` tool inventory (9 tools — **verified from the live server 2026-07-25**)
Naming is `woocommerce-<resource>-<verb>`:

| Read (auto-allowed) | Write (gated by `permissions.ask`) |
|---|---|
| `woocommerce-products-list` | `woocommerce-products-create` |
| `woocommerce-products-get` | `woocommerce-products-update` |
| `woocommerce-orders-list` | `woocommerce-products-delete` |
| `woocommerce-orders-get` | `woocommerce-orders-create` |
| | `woocommerce-orders-update` |

**This corrects an earlier error:** the original rules in `.claude/settings.json` guessed
`product-create` / `products-query` / `order-update-status` / `order-add-note`. **None of those names
exist**, so none of them gated anything. They have been replaced with the verified names above.
Lesson: never assume a permission rule protects you until the tool name is confirmed against the
running server.

### 0.4 Which server to use for what
- **Products and orders → `woocommerce`.** It speaks the Woo REST model (prices, stock, line items,
  order status) instead of raw post meta, so it validates input and can't leave a half-written product.
  It is also the only way to read **orders** at all.
- **Everything else → `cornercad-com`.** Pages, Gutenberg blocks, media, terms, options, users.
- **Mixed cases:** create the product via `woocommerce`, then use `cornercad-com` for anything Woo's
  REST shape doesn't expose (arbitrary meta, featured-image wiring, block content on the product page).

## 0.5 AI Engine **Pro** — 93 tools on staging (recorded 2026-07-27)

AI Engine Pro is installed on **staging only** (`ai-engine-pro` 3.6.2). Production still runs the
free plugin. So:

| Server | Tools |
|---|---|
| `cornercad-staging` | **93** — 42 Core + 25 WooCommerce + 13 Plugins + 13 Themes |
| `cornercad-com` (production) | 43 Core only |

**A restart is mandatory after installing Pro.** MCP negotiates its tool list at connection time,
so an existing session cannot see newly registered functions no matter how many times you retry.
Verified: ToolSearch immediately after the install returned only the original 43.

### WooCommerce (25) — use these for Spec B
`wc_list_products` · `wc_get_product` · `wc_create_product` · `wc_update_product` ·
`wc_alter_product` · `wc_delete_product` · `wc_update_stock` · `wc_bulk_update_stock` ·
`wc_get_low_stock_products` · `wc_get_stock_report` · `wc_list_orders` · `wc_get_order` ·
`wc_update_order_status` · `wc_add_order_note` · `wc_create_refund` · `wc_get_orders_by_customer` ·
`wc_list_customers` · `wc_get_customer` · `wc_update_customer` · `wc_list_reviews` ·
`wc_approve_review` · `wc_delete_review` · `wc_get_sales_report` · `wc_get_revenue_stats` ·
`wc_get_top_sellers`

This supersedes the raw `wp_create_post` + `wp_update_post_meta` product path in §3 **and** largely
supersedes the separate `woocommerce` proxy server's 9 tools. `wc_create_product` takes attributes,
variations and pricing in one call.

### Plugins (13) and Themes (13)
Lifecycle works: `wp_list_plugins_detailed`, `wp_list_themes`, `wp_activate_plugin`,
`wp_deactivate_plugin`, `wp_switch_theme`, `wp_delete_plugin`, `wp_rename_theme`, etc.

⚠️ **The file tools are scoped to AI Engine themes/plugins, not installed ones.** This is a scope
limit, not a permissions one — an earlier note in this file wrongly blamed `DISALLOW_FILE_EDIT`.

Tested: `wp_theme_list_dir {slug: "sellany"}` → **`Dir not found`**. The `wp_theme_*` and
`wp_plugin_*` file tools (`get_file`, `put_file`, `alter_file`, `mkdir`, `delete_path`, `list_dir`)
address only themes/plugins that AI Engine itself created. Their descriptions say "of an AI Engine
theme" — read that literally.

| Tool group | Operates on |
|---|---|
| `wp_list_themes` · `wp_switch_theme` · `wp_delete_theme` · `wp_rename_theme` | any installed theme |
| `wp_copy_theme` | duplicates an installed theme **into** an AI Engine theme |
| `wp_theme_*` file tools | AI Engine themes only |

**So theme file editing IS possible** — via `wp_copy_theme` (SellAny → AI Engine theme), edit,
then `wp_switch_theme`. That is effectively a child-theme workflow and is *safer* than editing
SellAny in place, since theme updates cannot clobber it.

The `editable: false` reported by `wp_list_themes` / `wp_list_plugins_detailed` for all 20 plugins
and 9 themes is consistent with this, but is not the mechanism — do not cite it as the reason.

SSH remains the right tool for arbitrary file work; Bradley has it.

### Standing rule
Prefer typed tools. **Treat direct database/SQL access as a last resort** — it bypasses draft →
verify → publish, post revisions, and the git-mirrored block markup in `content/blocks/`, on a live
store. Same principle as global `CLAUDE.md` rule #7 (typed FreeCAD tools before `execute_python`).

## 1. Tool inventory — free tier (43 tools, recorded 2026-07-25)

### Connectivity
| Tool | Purpose |
|---|---|
| `mcp_ping` | Connectivity check → GMT time + site name. **Call this first whenever any other tool errors or times out.** If ping also fails, the server is down — stop calling tools. |

### Read — posts / pages / CPTs
| Tool | Notes |
|---|---|
| `wp_get_posts` | List. Fields: ID, title, status, excerpt, link — **no body content**. Defaults to **10** if `limit` omitted. Filter: `post_type`, `post_status`, `search`, `author`/`author_name`, `before`/`after`, `offset`/`paged` (`paged` ignored when `offset` set). |
| `wp_get_post` | Single post, with content. |
| `wp_get_post_snapshot` | **Post data + all meta + terms in one call.** Prefer this over three separate reads. |
| `wp_get_post_meta` | One key, or all meta if `key` omitted. |
| `wp_get_post_terms` | Terms attached to a post. |
| `wp_count_posts` | Counts by status. |
| `wp_get_post_types` | Registered public post types. |

### Write — posts / pages / CPTs
| Tool | Notes |
|---|---|
| `wp_create_post` | `post_title` required. `post_status` defaults **draft**, `post_type` defaults **post** — pass `product` for WooCommerce. `post_content` takes HTML / Gutenberg blocks / shortcodes **as-is**; plain prose with no markup is auto-converted from Markdown. `meta_input` sets custom fields at creation. |
| `wp_update_post` | Full-body update. |
| `wp_alter_post` | **Surgical edit** — insert/replace a paragraph or shortcode without resending the whole body. Use this for small changes. |
| `wp_delete_post` | Trash by default; `force: true` permanently destroys (irreversible). |
| `wp_update_post_meta` | Pass a `meta` object to set **many keys at once** — the workhorse for WooCommerce products. |
| `wp_delete_post_meta` | Remove a meta key. |
| `wp_add_post_terms` | `append: false` (default) **replaces** all terms in that taxonomy; `append: true` adds. Also used for Woo attributes (`pa_color`, `pa_size`). |
| `wp_set_featured_image` | Set post thumbnail. |

### Taxonomies
`wp_get_taxonomies` · `wp_get_terms` · `wp_count_terms` · `wp_create_term` · `wp_update_term` · `wp_delete_term`

### Media
`wp_upload_media` · `wp_upload_request` · `wp_get_media` · `wp_update_media` · `wp_delete_media` · `wp_count_media`

### Blocks (Gutenberg)
`wp_write_blocks` · `wp_list_block_patterns` · `wp_insert_block_pattern`

### Options / site config
`wp_get_option` (has a `raw: true` flag to bypass object cache + `option_*` filters) · `wp_update_option`
— **this is how WooCommerce settings are read and written**, since Woo stores nearly everything in options.

### Users & comments
`wp_get_users` · `wp_create_user` · `wp_update_user` · `wp_get_comments` · `wp_create_comment` · `wp_update_comment` · `wp_delete_comment`

### Plugins & AI
`wp_list_plugins` (name + version only — no activate/deactivate) · `mwai_image` · `mwai_vision` (AI Engine plugin)

## 2. What this does NOT cover
- **Coupons, refunds, customers, reports, settings** — no tool on either server. Woo admin only.
  (`wc_reports_*` would need `WOO_CUSTOMER_KEY`/`WOO_CUSTOMER_SECRET`, which are not configured.)
- **No plugin install/activate/update**, no theme switching, no file writes. Bluehost/WP admin only.
- **No Square credential entry.** WooCommerce Square's OAuth connect flow is admin-UI only.

Note: products *can* still be driven the raw way through `cornercad-com`
(`wp_create_post` + `wp_update_post_meta` + `wp_add_post_terms`) — §3 documents that path, and it
remains the escape hatch for meta the Woo REST shape doesn't expose. But prefer the `woocommerce`
server for ordinary product work.

## 3. WooCommerce product meta cheat-sheet
Keys to set via `wp_update_post_meta` when creating a `product`:

| Key | Value |
|---|---|
| `_regular_price` | list price, e.g. `"20.00"` |
| `_sale_price` | optional |
| `_price` | **must be set too** — Woo's queryable price; mirror sale price if on sale, else regular |
| `_sku` | unique SKU |
| `_stock_status` | `instock` / `outofstock` |
| `_manage_stock` | `yes`/`no`; with `_stock` for the count |
| `_virtual` / `_downloadable` | `yes`/`no` — set `_virtual: yes` for download-only items so shipping is skipped |
| `_weight`, `_length`, `_width`, `_height` | shipping dims |
| `_visibility` / `product_visibility` terms | catalog visibility |

Product type is a **taxonomy**, not meta: set `product_type` to `simple` / `variable` / etc. via
`wp_add_post_terms`. Category = `product_cat`, tags = `product_tag`.

**Lesson carried over from Community Store:** a variation's own stock overrides the parent's. In Woo the
equivalent trap is a variable product whose variations lack `_price` — they render as unavailable.
For the coaster, prefer **variations on a Shape attribute (Round / Square)** over shape-in-the-description.

## 4. Fail-safe operations
- **Everything writes live.** WordPress has no unapproved-draft-version safety net like Concrete's, so the
  Concrete trick of "write → verify → delete the draft version to roll back" **does not apply**.
  Instead: create as `post_status: draft`, verify, then flip to `publish`.
- `wp_delete_post` **without** `force` goes to Trash and is recoverable. Only pass `force: true` when
  you intend permanent destruction.
- Read back with `wp_get_post_snapshot` after any product write — it returns post + meta + terms together.
- Post IDs are **stable** across edits (unlike Concrete blockIDs, which changed on every update).
- `wp_update_post_meta` with a `meta` object **merges** — omitted keys are untouched.
