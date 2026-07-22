# Concrete CMS REST API — Operating Protocol (via the `concretecms` / Macareux MCP)

**Applies to:** any Concrete CMS 9.x site operated through the `concretecms` MCP
(cornercad.com, uniquecreationsbylisac.com, …). **Theme-agnostic** — project- and theme-specific
details (area handles, page IDs, commerce model) live in each project's `CLAUDE.md`.

> **Reuse:** this file is meant to be **copied verbatim into each Concrete CMS project** and imported
> from that project's `CLAUDE.md`. It is the single source of truth for *how to operate the API*.

---

## 0. Tooling rule
Operate the site **only** through the `mcp__concretecms__*` MCP tools. Do **not** write curl/Python
wrappers — they hit the same REST API and the same OAuth token scopes, so they gain nothing and break
the workflow convention. The MCP tools are thin wrappers over Concrete's REST API; every error below is
the REST API's own response surfaced through the MCP.

## 1. Sequential content construction (page → blocks → publish) — Critical
The API isolates page registration from block injection. You **cannot** create a page and its blocks in
one payload. Use this strict dependency chain:

1. **`add-page`** to create the collection node.
   - **`type` is REQUIRED** (a page-type handle, e.g. `page`). Omitting it returns a **401 that is really
     a validation error** ("401-as-validation") — *not* an auth failure. Also pass `parent` (parent cID)
     and `template` (template handle, e.g. `full`).
2. **Capture the returned page `id` (cID)** from the JSON response.
3. **`add-block-to-page-area`** with that pageID + `areaHandle` (`Main`, `Sidebar`, `Banner`, …) to push
   each block object into a layout zone. Blocks **append in call order** — add sequentially when order
   matters (parallel calls interleave nondeterministically).
4. **Publish.** Each block-add creates a **new *unapproved* draft version**. To make edits public, approve
   it: **`update-page-version-by-page-id-and-version-id`** with `is_approved: true`
   (find the latest version via `get-page-versions-by-page-id`).

## 2. Block schema payloads (the `value` object)
Map `value` to the keys Concrete core expects for that block handle:
- **content block** (`content`): `{"content": "<h2>Headline</h2><p>Body…</p>"}`
- **html block** (`html`): `{"html": "<div class='widget'></div>"}`
- Other core blocks reliably created via API (observed): `page_list`, `page_attribute_display`, `autonav`,
  `image_slider`, `feature`, `search`.
- **Tip:** to learn a block's exact `value` keys, read a page that already uses it:
  `get-page-by-id` with `includes: ["areas.content"]`, and copy the block's `value` as a template.
- ⚠️ **The `feature` block requires a Link on the API create path.** Passing `externalLink: ""` +
  `internalLinkCID: "0"` returns **HTTP 500 — "Please select a value for Link"**, even though theme demo
  content ships feature blocks with no link (the API validates more strictly than the stored data). For a
  feature card that shouldn't link anywhere, render it as a styled `content` block instead.

### 2.1 Editing an existing block in place
`update-block-in-page-area` (pageID + areaHandle + blockID + `value`) **works** — verified 2026-07-22.
Do **not** use the old delete-then-re-add workaround: re-adding appends to the END of the area, which
drops a block out of its layout-grid cell and breaks the row.

Two behaviours that will bite you:

- **The blockID CHANGES on every update.** Concrete versions blocks, so the response returns a *new* id
  (observed 262→383, 367→384). **Never cache a blockID across edits** — re-read the page with
  `get-page-by-id` (`includes: ["areas"]`, `version: "recent"`) to get the current id before each write.
- **Blocks inside layout grids are editable** — pass the sub-area handle **verbatim, spaces included**,
  e.g. `areaHandle: "Main : 46"`. Verified: the updated block stayed inside its grid cell and sibling
  blocks in the neighbouring cells were untouched.

`value` **replaces** the block's value — unlike page `attributes`, it does not merge. Send the complete
value object, not just the keys you're changing.

## 3. What the API / token CANNOT do — use the Dashboard

> **History note:** several limits recorded here through mid-2026 were **scope gaps, not missing features**,
> and disappeared once the token was re-authorized with full scopes (2026-07-22). Page name/template/
> attributes, in-place block edits, and Store reads all work now. **Before documenting something as
> impossible, re-check the granted token's scopes** — see §4.

Genuinely still out of reach:

- **Create model objects** — page types, attribute keys, topic trees, Express objects — have **no API
  scopes**. Ship them as a **CIF package** installed via SSH/Dashboard (see each project's setup package).
  (Setting the *value* of an attribute is API-capable; *creating the attribute key* is not.)
- **Custom block templates.** A block's custom-template handle (`btCustomTemplate`) is not exposed in the
  block `value`. The API always creates/updates a block with its **default** template. → Wire the block +
  content via API, then flip the custom template in the Dashboard.
- **Containers / area layouts.** These appear as `core_area_layout` blocks with generated sub-areas and
  are not reliably creatable via the API. Create the layout in the Dashboard, *then* fill its cells via
  API using the `"Area : N"` sub-area handles (§2.1).
- **Fix corrupt / "ghost" pages:** some pages return data on **GET** but "Page not found" on **write AND
  delete** (e.g. pages created in an earlier broken state). The API can neither repair nor remove them —
  delete them in the **Dashboard sitemap and empty the Trash** to free their URL path, then recreate fresh
  via `add-page`. Do **not** try to build into them.

### 3.1 Page writes that DO work
`update-page-by-id` accepts `name`, `template`, `type`, `description`, and an `attributes` object (any
attribute key assigned to the site, including package-installed ones like `product_*`). Notes:
- It creates a **new unapproved draft version** — same as block adds, so it must be approved via
  `update-page-version-by-page-id-and-version-id` to go public.
- `attributes` **merges** — keys you omit are left alone; you can't unset a key by omitting it.
  (Contrast with block `value`, which replaces — see §2.1.)
- To discard a bad write, `delete-page-version-by-page-id-and-version-id` on the unapproved version
  rolls back name/template/attributes **and block edits** together (they're all versioned).

### 3.2 Community Store endpoints
Present only when the **Community Store** add-on is installed. Three **read-only** endpoints:

| MCP tool | Path | Scope |
|---|---|---|
| `get-store-products` | `GET /cs/api/v1/products` | `cs:products:read` |
| `get-store-orders`   | `GET /cs/api/v1/orders`   | `cs:orders:read`   |
| `get-store-config`   | `GET /cs/api/v1/config`   | `cs:config:read`   |

- **GET only.** The spec defines no POST/PUT/DELETE for any of them, so **creating or editing Store
  products and orders remains Dashboard work** even when the token holds `cs:products:write` /
  `cs:orders:write`. Holding the write scope does *not* imply a write endpoint exists.
- **No parameters.** No path params (you cannot fetch a single product by id) and no exposed pagination
  params — responses carry a `meta.pagination` block but default to 20 per page. Filter client-side.
- `get-store-products` returns the full product record: pricing (`price`, `sale_price`, `wholesale_price`),
  `stock_level` / `stock_unlimited`, `variations[]` with their own options + stock, `groups`, `categories`,
  `primary_image` / `additional_images` as absolute URLs, and `shipping` dimensions.
- **Watch variation stock:** a variation carries its *own* `stock_unlimited` / `stock_level`, which
  overrides the parent product's. A parent with `stock_unlimited: true` can still have a variation that
  reads as out of stock.
- `get-store-config` reports the Community Store version, the Store API version, currency, and the
  **store's own time zone** — which can differ from the admin account's, making order timestamps look
  shifted. Check it before interpreting `date_added` on orders.

## 4. Fail-safe operations
- Confirm the parent exists (read the sitemap: `get-child-pages` / `get-pages`) before creating a child.
- On **401** → first suspect the **"401-as-validation"** quirk (a required field like `type` is missing),
  *then* token expiry.
- On **400 / 403 / 404 / "Endpoint out of scope. Try reauthorizing with the proper scope."** → the
  **granted token** lacks that scope. Verify + enable scopes at the Dashboard integration screen:
  `/index.php/dashboard/system/api/scopes`.
  - ⚠️ The integration may show **"All"** scopes while the **granted OAuth token** is narrower — you must
    **re-authorize the token** to pick up newly-added scopes.
  - Treat "out of scope" as **stale token first, missing feature last.** Most limits previously written
    down as permanent turned out to be this.
- **Re-auth ergonomics (this MCP):** the server runs an authorization-code + PKCE flow, briefly serving a
  local callback listener, and writes tokens to `.tokens.json` in the MCP server directory. Two gotchas:
  - After a successful exchange the listener **shuts down**, so reloading the callback URL shows a
    browser "can't connect" error. That error is **cosmetic** — it does not mean the flow failed.
  - The real check is `.tokens.json`'s **mtime and `expires_at`**, plus a live `get-account` call. Tokens
    observed with a **~24h lifetime**, so expect roughly daily re-auth.
- Prefer building into **Dashboard-created** or **freshly `add-page`d** pages; both accept block writes.

## 5. Verify
- Read back with `get-page-by-id` (`includes: ["areas.content"]`) to confirm blocks + version state,
  and/or fetch the **public URL** to confirm the *approved* version renders to visitors.
- Use `version: "recent"` to inspect the unapproved draft; omit it (or `"active"`) to see what the public
  currently gets.

### 5.1 Safe experimentation
Because every write lands on a **new unapproved version**, the live page does not move until you approve.
This makes destructive-looking API experiments safe on production:

1. Write (block update, page attribute, whatever).
2. Read back with `version: "recent"` to confirm the effect.
3. `delete-page-version-by-page-id-and-version-id` on the unapproved version to roll back — the page
   returns to its previously approved version, with **no residue**.

Use this instead of hand-transcribing original content to "restore" it; step 3 is exact and step 2 tells
you whether it worked. Confirm the rollback by re-reading with `version: "recent"` and checking the
returned version id is the approved one again.
