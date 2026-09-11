# CornerCAD.com — Site Opening Structure (Design Spec)

**Date:** 2026-06-24
**Status:** Approved in conversation; pending written-spec review
**Author:** Bradley Corner + Claude
> ## 🔁 PLATFORM CHANGED — 2026-07-25: Concrete CMS → WordPress + WooCommerce
> **The IA (§2), content model intent (§3), copy, and phasing (§7) all still stand.** Only the
> implementation nouns change. Read this spec with the substitutions below; see `CLAUDE.md` for the
> full mapping and `docs/wordpress-mcp-protocol.md` for how to execute it.
>
> | This spec says | Now build it as |
> |---|---|
> | `Product` page type + `product_*` attributes | `product` CPT + Woo post meta (`_regular_price`, `_price`, `_sku`, …) |
> | Topic tree "Product Categories" | `product_cat` taxonomy |
> | Concrete blocks / Genesis theme areas | Gutenberg blocks + patterns in `bluehost-blueprint` |
> | `/catalog/` landing | WooCommerce **Shop** (page 14) or a custom Catalog page over `product_cat` |
>
> **One non-goal is now reversed:** §1 lists "on-site shopping cart / multi-item checkout" as a
> non-goal, with per-product CTA only. WooCommerce ships Cart (15) / Checkout (16) / My account (17)
> pages and they are live — real cart checkout is **in scope** for `buy` items. The `download` and
> `inquire` CTA types still apply to the non-purchasable catalog items.

**Applies to:** cornercad.com (Concrete CMS 9.5.2). Written as a **reusable template** —
see [§9 Generalization](#9-generalization-to-other-sites) for reuse on
uniquecreationsbylisac.com.

---

## 1. Purpose & positioning

cornercad.com is a **clean-branded hub** for Bradley's CAD / 3D-printed products. The
site is the canonical brand presence; sales-channel and payment plumbing (Etsy, Square,
Facebook) stay **invisible to visitors**. Content is authored once on the site and is
intended to **syndicate outward** to social media and model-sharing platforms
(Creality Cloud, Printables, etc.) to drive traffic back — that syndication is a
**later phase**, not part of this opening structure (see [§7](#7-phasing)).

A first-time visitor can: understand the brand, browse the product/project catalog by
category, and act on a product via a **per-product call-to-action** (buy / download /
inquire). No marketplace branding appears anywhere on the public site.

### Non-goals (this spec)
- On-site shopping cart / multi-item checkout (per-product CTA only).
- Social/Printables/Creality syndication automation (future phase).
- Customer accounts, reviews, blog/news (can be added later).

---

## 2. Information architecture (page tree)

```
/ ............................ Home (Standard page)
/catalog/ .................... Catalog landing — all products, filterable by category
/catalog/<product-slug>/ ..... Product pages (flat, one per item; page type = Product)
/custom-work/ ................ "Work with me" — custom parametric CAD + print jobs
/about/ ...................... Story + contact form (Contact folded in)
```

**Category pages are not separate branches.** Categories
(Automotive, Home & Garden, …) are a **Topic tree**, surfaced as topic-filtered views
of `/catalog/` (reachable from the nav and the catalog landing). This keeps the tree
flat and product URLs stable even if an item is re-categorized.

**Primary navigation:** Home · Catalog · Custom Work · About. The Catalog item exposes
the category topics (dropdown or on-page filter).

---

## 3. Content model

### 3.1 `Product` page type (one per catalog item)
Custom page type with these **attributes**:

| Handle | Type | Purpose |
|---|---|---|
| `product_categories` | Topics (→ *Product Categories* tree) | Category membership; multi-select; one product can be in several |
| `product_short_desc` | Textarea | Card/listing summary |
| `product_gallery` | Image/File (or file set) | Product imagery |
| `product_cta_type` | Select: `buy` \| `download` \| `inquire` | **The mixed-CTA switch** |
| `product_cta_target` | URL / file | Checkout URL, model-download file, or inquiry anchor |
| `product_price` | Text | Shown only when `product_cta_type = buy` |
| `product_external_links` | Textarea (URLs) | Printables/Creality links — data now, used by future syndication |

The product page template renders the CTA based on `product_cta_type`:
- `buy` → "Buy" button → white-labeled checkout URL (no processor branding) + price.
- `download` → "Download" / "View on maker platform" → file or external link.
- `inquire` → "Request" → contact/inquiry form or mailto anchor.

### 3.2 Standard pages
Home, Custom Work, About are **block-built Standard pages** (no special type).

### 3.3 Topic tree — `Product Categories`
Starting set (extensible without rework):
`Automotive` · `Home & Garden` · `Display & Retail` · `Functional / Hardware`

---

## 4. Page-by-page block plan

| Page | Key blocks |
|---|---|
| **Home** | Hero (brand value prop + primary CTA), Featured products (Page List filtered to Product, limited/featured), short "Custom work" teaser, footer |
| **Catalog landing** | Intro, **Page List** block (page type = Product) with **topic filter** UI for categories |
| **Category view** | Same Page List, pre-filtered by the selected `product_categories` topic |
| **Product** | Gallery, description, CTA (driven by `product_cta_type`), price (conditional), related products |
| **Custom Work** | Process / value-prop copy, example gallery (Page List or images), inquiry CTA |
| **About** | Story, photo, **contact form** (Form block), business info |

Navigation uses Concrete's **Auto-Nav** block for the top level; category links derive
from the Product Categories topic tree.

---

## 5. Technical reality — what the Concrete CMS API can and cannot do

Proven against the live instance on 2026-06-24 (see
`~/Projects/concretecms-mcp-server/docs/DRAFT-ISSUES.md` and project memory):

**API CAN** (REST, via the `concretecms` MCP / bearer token):
- Create / update / delete **pages** (`pages:add`, `pages:delete`)
- Add / delete **blocks** in page areas (`pages:areas:add_block`, `…:delete_block`)
- Upload / manage **files** (`files:add`, `files:read`, `files:delete`)
- Read sites, system info, traverse the sitemap

**API CANNOT** (no scopes exist server-side — confirmed by probing the live OAuth
authorize endpoint; every one of these scopes is rejected `invalid_scope`):
- Create/modify **page types** (`page_types:*` ✗)
- Create/modify **attributes** (`attributes:*`, `attribute_keys:*` ✗)
- Create/modify **topic trees** (`topics:*` ✗)
- Create/modify **Express** objects (`express:*` ✗)

**Consequence:** the content *model* (§3) is created with a **CIF package**
(`packages/cornercad_setup/` — Content Interchange Format XML installed by a small
package controller), since the REST API cannot. The API then builds *content* (§2
pages, §4 blocks) on top of that model. This is a Concrete CMS platform limit, not an
MCP limitation. CIF makes Phase 0 reproducible-as-code (reused for Lisa's site by
editing only the topic tree).

**Known gotcha:** the bundled MCP spec lists an ungrantable `pages:areas:add_blocks`
(plural) scope — do not request it. Valid scope set is recorded in SETUP-NOTES.md.

---

## 6. Existing-content note
The "generic" install carries **Atomik theme sample content** (e.g. `/resources/*`
pages, type `resource`). Phase 1 must decide per page: delete, repurpose, or leave.
Default plan: remove sample pages once our tree is in place.

---

## 7. Phasing

| Phase | Work | Mechanism |
|---|---|---|
| **0 — Model** | Create `Product` page type + 7 attributes; create `Product Categories` topic tree; define the `product_cta_type` select options | **CIF package** `cornercad_setup` (in `packages/`), deployed via SSH + installed — reproducible-as-code; reused for Lisa's site by editing only the topic tree |
| **1 — Skeleton** | Create page tree (Home, Catalog, Custom Work, About); set page types; remove sample content | **API** |
| **2 — Content** | Add blocks per §4; create initial Product pages from Bradley's real projects; upload imagery | **API** |
| **3 — Nav & polish** | Auto-Nav, topic filters, CTA template wiring, theme/branding | Dashboard + API |
| **4 — Syndication (future)** | Push content to social + Printables/Creality; pull traffic back | Separate spec |

Each phase is independently reviewable. Phase 0 is a prerequisite gate for 1+.

---

## 8. Success criteria (opening structure = Phases 0–3)
- Visitor can navigate Home → Catalog → a Product, and browse Catalog by category.
- Each Product renders the correct CTA (buy/download/inquire) from its attribute.
- No Etsy/Square/Facebook branding anywhere public.
- Product URLs are stable (`/catalog/<slug>/`) and category changes don't break them.
- The model + page-build steps are captured well enough to **re-run on a second site**.

---

## 9. Generalization to other sites

The **reusable core** (carry over verbatim): the page-tree shape, the `Product` page
type + 7 attributes, the mixed-CTA model, the topic-as-category pattern, the phase plan,
and the API-vs-dashboard split.

**Per-site parameters** (the only things that change):

| Parameter | cornercad.com | uniquecreationsbylisac.com |
|---|---|---|
| Canonical URL | https://cornercad.com | https://uniquecreationsbylisac.com |
| API integration (client_id/secret/token) | own integration | own integration |
| Topic tree (categories) | Automotive, Home & Garden, Display & Retail, Functional/Hardware | *(Lisa's categories — TBD with her: e.g. Jewelry, Bracelets, …)* |
| Branding / theme | CornerCAD | Unique Creations by Lisa C |
| Initial product list | Bradley's projects | Lisa's products |

Adding the second site is purely additive: a second `concretecms` MCP server entry
(her URL + credentials) reusing the same server binary, then re-run Phases 0–3 with her
parameters.

---

## 10. Open questions / to confirm at build time
- Exact white-labeled checkout mechanism for `buy` products (Square hosted link?
  embedded?) — decide when the first `buy` product is built.
- Whether to keep any Atomik sample pages (default: delete).
- Lisa's category tree (only needed when we start her site).
