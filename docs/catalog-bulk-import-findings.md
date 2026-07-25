> ## ⚠️ OBSOLETE — 2026-07-25
> This spike existed because the Concrete REST API could not bulk-create `Product` pages. On
> **WordPress + WooCommerce** that constraint is gone: `wp_create_post` (post_type `product`) +
> `wp_update_post_meta` + `wp_add_post_terms` create products directly over MCP. Bulk import is now a
> straightforward loop, not a packaging problem. Kept for history only.

# Bulk Catalog Import — Feasibility Findings (CIF / installer package)

**Date:** 2026-07-20
**Status:** Spike complete — recommendation below. No live-site changes made.
**Question asked:** Can we bulk-create ~400 `Product` pages (with attribute values *and*
images) without doing 400 rounds of Dashboard Composer, given the REST API can't create
Product pages at all?

---

## TL;DR

**Yes — via a package, and it's the only automated route.** A Concrete CMS **package's
`install()` runs server-side PHP with full core access**, so it can create Product pages
with `Page::add()`, set `product_*` attribute values, assign category topics, and import +
attach images — every one of the things the REST API and Composer-gate block. Deliver it as
a package installed over SSH/CLI (or Dashboard → Extend), driven by a generated
`manifest.json`. For ~400 dynamic records a **programmatic installer** beats hand-authored
CIF `<page>` XML.

---

## Why the API route is blocked (recap, confirmed in project memory)

- `packages/cornercad_setup/install.xml` defines the `product` page type with
  **`launch-in-composer="1"`**. Confirmed 2026-07-16: `add-page` with `type: product`
  returns **401** (the "401-as-validation" quirk masking a composer-page-type rejection).
- `product_*` **attribute values are not API-settable** — `add-page`'s attribute schema has
  a fixed key set with no `product_*` fields, and `update-page-by-id` attribute writes
  return "Endpoint out of scope."
- The `concretecms` MCP has **no Community Store endpoints** — Store products are
  Dashboard-only.

**Net:** via the API you can build the *catalog container* (the `/catalog` landing + its
`page_list` grid, standard pages) but **not** the Product pages themselves.

## Why a package is the escape hatch

Package `install()`/`upgrade()` execute inside Concrete's PHP runtime, not through the REST
API. They call the core object layer directly:

- `Page::add($pageType, $data, $template)` — creates a page of **any** type, `launch-in-composer`
  is irrelevant to programmatic creation.
- `$page->setAttribute('product_price', …)` etc. — sets **any** attribute value, including
  `product_categories` (topics) and `product_featured_image` (image_file).
- `\Concrete\Core\File\Import\FileImporter` — imports a physical image into the file
  manager, returning a File to hand straight to the image attribute.

So a package bypasses the composer gate, the API attribute-scope limit, and the manual
Composer workflow all at once. This is a platform capability, not a hack.

---

## What CIF itself supports (verified against 9.x docs)

CIF (`installContentFile()`) can go beyond the model — it can also carry content:

- **`<page name= path= template= pagetype=>`** nodes create pages of the `product` type at
  chosen paths.
- Inside a page: **`<attributes>`** (set `product_price`, `product_short_desc`,
  `product_cta_type`, `product_categories` topics …) and **`<area><block>`** (content/image
  blocks).
- **Images:** CIF resolves file-placeholder tokens
  `{ccm:export:file:<hash>:<filename>}` through the `import/value_inspector` service, so an
  `image_file` attribute can point at an imported image.

## Two architectures — and the recommendation

| | **Pure CIF** (`content.xml` with 400 `<page>` nodes) | **Programmatic installer** ✅ recommended |
|---|---|---|
| How | Generate 400 `<page>` XML blocks + a `<files>` section | `install()` loops `manifest.json`, calls `Page::add()`, `setAttribute()`, `FileImporter` |
| 400 records | Huge brittle XML, awkward to re-run | Clean loop, idempotent, re-runnable |
| Images | `<files>` + placeholder tokens (fiddly at scale) | `FileImporter` → set attribute by File (robust, documented) |
| Data source | Hand/generated XML | Same `manifest.json` that doubles as the folder→catalog mapping |
| Verdict | Fine for small fixed content | **Best fit for ~400 dynamic products** |

Both ship as a package and both bypass the limits. The programmatic path is chosen for
maintainability and because the **manifest is the folder→catalog mapping** you wanted
anyway — one artifact, two uses.

---

## How the three product families map

| Family | Count | Installer role | Notes |
|---|---|---|---|
| **h3lio (licensed)** | ~400 | Flat catalog Product pages | Licensed credit tag ("designed by h3li0"). Category mostly Home & Garden / Display & Retail. Physical `buy` → each needs a Store product later (Dashboard/CS) OR launch as `inquire` first. |
| **Personal 3D prints** | your projects | Flat catalog Product pages | Mix of `download` (Printables/Creality links) and `buy`/`inquire`. e.g. Daytona Coupe parts → Automotive. |
| **Coasters** | 1 configurator | Creates the coaster **landing/configurator page** only | Material × Shape × Design SKUs live in a **Community Store variant product**, *not* one page per combo. Community Store has no native multi-step wizard — the drill-down is a Phase-B custom front-end. |

---

## Honest caveats / scope boundaries

1. **Images must be pre-optimized and bundled** in the package (~400 × <300 KB ≈ ~120 MB).
   The optimize step from the 2.4 GB download is a prerequisite (see `scripts/build_manifest.py`).
2. **Buy vs. catalog split.** The installer creates catalog **Product pages** (display +
   download/inquire CTAs). Community Store **"buy" products are separate objects**; creating
   those in bulk is a *separate* extension (Community Store's own `Product` PHP API) and is
   out of scope for the page installer's first pass.
3. **Host must allow CLI/SSH package install** (deploy convention says SSH/Dashboard — should
   hold; confirm once).
4. **Exact core API surface must be verified on 9.5.2 at deploy.** `Page::add()` signature,
   `FileImporter::import()` return type, and the topics-attribute setter are stable in v9 but
   the installer marks these `// VERIFY ON DEPLOY` — this spike could not run against the live
   server from the workstation.
5. **Idempotency / re-runs.** The installer skips a product whose `/catalog/<slug>` page
   already exists, so it is safe to re-run as the manifest grows (a partial first batch, then
   the full 400).

## Recommended path

1. Bradley downloads the Proton folder locally (2.4 GB).
2. `scripts/build_manifest.py` walks the tree → `manifest.json` (name, type, category, image
   list per product) **and** writes optimized web images into the package's `images/` bundle.
3. Review/adjust `manifest.json` (categories, CTA types, prices, licensed tags).
4. Deploy `packages/cornercad_catalog_import/` to the server, install → Product pages appear
   under `/catalog`, images attached, categories set.
5. Later: create Store `buy` products for physical items; build the coaster configurator
   front-end.

See `packages/cornercad_catalog_import/README.md` for the concrete deploy steps.
