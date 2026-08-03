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

## 3. What the API / token CANNOT do — use the Dashboard
- **Change a page's template, name, or attributes:** `update-page-by-id` → "Endpoint out of scope."
  Do these in the Dashboard.
- **Create model objects** — page types, attribute keys, topic trees, Express objects — have **no API
  scopes**. Ship them as a **CIF package** installed via SSH/Dashboard (see each project's setup package).
- **Fix corrupt / "ghost" pages:** some pages return data on **GET** but "Page not found" on **write AND
  delete** (e.g. pages created in an earlier broken state). The API can neither repair nor remove them —
  delete them in the **Dashboard sitemap and empty the Trash** to free their URL path, then recreate fresh
  via `add-page`. Do **not** try to build into them.

## 4. Fail-safe operations
- Confirm the parent exists (read the sitemap: `get-child-pages` / `get-pages`) before creating a child.
- On **401** → first suspect the **"401-as-validation"** quirk (a required field like `type` is missing),
  *then* token expiry.
- On **403 / 404 / "out of scope"** → the token lacks that scope. Verify + enable scopes at the Dashboard
  integration screen: `/index.php/dashboard/system/api/scopes`.
  - ⚠️ The integration may show **"All"** scopes while the **granted OAuth token** is narrower — you must
    **re-authorize the token** to pick up newly-added scopes.
- Prefer building into **Dashboard-created** or **freshly `add-page`d** pages; both accept block writes.

## 5. Verify
- Read back with `get-page-by-id` (`includes: ["areas.content"]`) to confirm blocks + version state,
  and/or fetch the **public URL** to confirm the *approved* version renders to visitors.
