# CornerCAD — Project Instructions

cornercad.com — **Concrete CMS 9.5.2** site. A clean-branded consumer hub for Bradley's CAD / 3D-printed /
laser-engraved products (vases, planters, lamps, coasters, and automotive/custom parts). B2B/wholesale is a
separate arm at **CornerCADWorks.com**. No marketplace/processor branding appears publicly.

Full site design + IA (the reusable template that also seeds Lisa's site):
`docs/superpowers/specs/2026-06-24-cornercad-site-structure-design.md`.

## Concrete CMS operating protocol
Operate the site **only** through the `concretecms` MCP tools, following the protocol here:

@docs/concrete-cms-api-protocol.md

## Theme — Genesis (c5box, marketplace v1.2.0.0, Concrete 9 compatible)
Active theme. **Template area handles** (needed for `add-block-to-page-area`):
- `full` → `Banner` (page title via `page_attribute_display`), `Main`
- `left_sidebar` → `Banner`, `Main`, `Sidebar`
- `home` → `Banner`, `Home Section 1`, `Intro`, `Main`, `Marquee Area`, `Parallax Area`,
  `Parallax Area 2`, `Scroll Text`

Useful Genesis pieces: **Page List** custom templates (3-col grid, carousels), **Containers**
(Responsive Grid of Four/Six, Highlight Stripe, Two Column Highlight), `image_slider`, `feature` blocks.
Genesis **sample content is installed** (demo pages under Home + demo blocks on Home) — mine these for
block `value` configs, then delete them all at cleanup.

## Commerce — hybrid
- CIF **`Product` page type + `product_*` attributes** (installed via `packages/cornercad_setup`) drive
  catalog display and the **download / inquire** CTAs.
- **Community Store** "Add to Cart" + checkout is bolted onto physical **buy** items only.
- **Payment = Square**, via a **custom Community Store gateway built as a SEPARATE project** — brief:
  `docs/community-store-square-gateway-brief.md`. Stripe is the launch fallback.

## Known landmines
- **Pages 270 (Catalog), 271 (Custom Work), 272 (About)** — created 2026-06-25 — are **CORRUPT**: GET
  works but writes/deletes return "Page not found." Must be deleted in the **Dashboard + Trash emptied**,
  then recreated via `add-page`. Do not try to build into them.
- Token **cannot change templates** (out of scope) — set page templates in the Dashboard.

## Content
`content/*.md` hold the finalized page copy (home, catalog, custom-work, about). Direction: read as a
**general 3D/laser business**; automotive is one specialty (but **keep both product bands on Home**).
Car naming = **"Daytona Coupe"** (technically a Factory Five Type-65; not "Shelby").

## Credentials
`api.md` holds the API-integration Client ID/Secret — **do not commit or publish it**.
OAuth setup gotchas: see project memory `project_cornercad_mcp_oauth`.
