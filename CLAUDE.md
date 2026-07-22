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
- **Payment = Square**, via the installed **"Square Payment Method" v1.0.1** Community Store add-on
  (installed 2026-07-16 — still needs credentials/sandbox test). This **supersedes** the earlier plan to
  build a custom gateway as a separate project; that brief (`docs/community-store-square-gateway-brief.md`)
  is now marked SUPERSEDED and kept only for the optional marketplace-product path. No Stripe fallback needed.

### Store state (read via `get-store-config` / `get-store-products`, 2026-07-22)
Community Store **2.7.7**, Store API **1.0.5-alpha1**, currency USD. **Zero orders so far** — consistent
with Square not yet being credentialed/tested. One product: **Slate Coaster** (store product id 1, $20,
active, 5 images, group `Coasters`, shippable 4.00 × 0.20 × 0.04).

Three data problems to fix in the Dashboard:
- Its single variation (`Style 1 = "S"`) has `stock_unlimited: false` + `stock_level: 0`, so it reads as
  **out of stock** despite the parent being unlimited — a variation's stock overrides the parent's.
- **Round vs. Square exists only in the description copy**, not as real product options.
- `categories` wrongly includes **`Complete`** (`/checkout/complete`) — the checkout completion page got
  picked up as a product category.

Also: store time zone is **`America/Boise`** while the admin account is `America/New_York`, so order
timestamps read 3h off from what you'd expect.

## Live page IDs
**Home = 1**, **About = 409**, **Custom Work = 416**, **Catalog = 417**,
**Slate Coaster = 418** (`store_product` type).

Home (`home` template) sub-area handles — pass verbatim, spaces included: `Banner : 50`,
`Home Section 1 : 53`, `Intro : 51/52`, `Main : 46` (intro copy) and `Main : 47/48/49` (feature cards),
`Marquee Area : 54`, `Parallax Area 2 : 55/56`. Genesis demo content still occupies most of these.

## API capability (as of 2026-07-22)
The token was re-authorized 2026-07-22 with full scopes. Nearly everything previously recorded as
"API can't do this" was a **stale-token scope gap** and now works: page `name`/`template`/`type`/
`description`/`attributes` (incl. all `product_*` keys), **in-place block edits** — including blocks
nested in layout-grid cells — and **Community Store reads**.

Still Dashboard-only: **custom block templates**, **containers / area layouts**, **creating model
objects** (page types, attribute keys), and **Store product creation/editing** (the Store API is
GET-only). Full detail + the safe write→verify→rollback pattern: protocol doc §2.1, §3, §5.1.

**Token expires ~24h after each auth**, so expect near-daily re-auth. A browser "can't connect to
localhost:3000" after authorizing is **cosmetic** — the listener shuts down after a successful exchange.
Check `.tokens.json` mtime in the MCP server dir + a live `get-account` before debugging anything.

## Known landmines
- **blockIDs change on every update.** `update-block-in-page-area` returns a *new* id each time
  (Concrete versions blocks). Never cache one — re-read the page before each write.
- **Block `value` replaces; page `attributes` merges.** Opposite semantics on the two main write calls.
  Send a block's complete `value` object, not just the keys you're changing.
- Pages 270/271/272 were corrupt and are **gone** (deleted + Trash emptied 2026-07-22; GET on 270 404s).
  Don't resurrect those IDs from old notes — use the Live page IDs above.

## Content
`content/*.md` hold the finalized page copy (home, catalog, custom-work, about). Direction: read as a
**general 3D/laser business**; automotive is one specialty (but **keep both product bands on Home**).
Car naming = **"Daytona Coupe"** (technically a Factory Five Type-65; not "Shelby").

## Credentials
`api.md` holds the API-integration Client ID/Secret — **do not commit or publish it**.
OAuth setup gotchas: see project memory `project_cornercad_mcp_oauth`.
