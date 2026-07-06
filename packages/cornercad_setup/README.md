# cornercad_setup — Concrete CMS package (Phase 0 content model)

Installs the part of the CornerCAD site structure the **REST API cannot create**:
the `Product` page type, its 7 attributes, and the `Product Categories` topic tree.
Verified empirically that Concrete's REST API has no endpoints/scopes for these, so
this package (CIF) is the supported programmatic path. Pages/blocks are built over the
API afterward (Phase 1+).

## What it creates
- **Topic tree** `Product Categories`: Automotive · Home & Garden · Display & Retail · Functional / Hardware
- **Page type** `Product` (handle `product`, all templates, default `full`)
- **Attributes** (page/collection):
  | handle | type | purpose |
  |---|---|---|
  | `product_short_desc` | textarea | card/listing text |
  | `product_featured_image` | image_file | listing thumbnail (full gallery = a block on the page) |
  | `product_price` | text | shown when CTA = buy |
  | `product_cta_type` | select (buy/download/inquire) | the mixed-CTA switch |
  | `product_cta_target` | text | checkout URL / download file / inquiry anchor |
  | `product_external_links` | textarea | Printables/Creality URLs (data for future syndication) |
  | `product_categories` | topics → Product Categories | category membership |

## Deploy (one time per site)
1. Upload this folder to the site over SSH:
   ```
   scp -r packages/cornercad_setup  <user>@<host>:~/<webroot>/packages/
   ```
   (target = the Concrete install's `packages/` directory)
2. Install it, either:
   - **Dashboard** → Extend Concrete → Awaiting Install → **CornerCAD Site Setup** → Install, or
   - **CLI:** `cd <webroot> && ./concrete/bin/concrete c5:package:install cornercad_setup`
3. Verify: Dashboard → Pages & Themes → Attributes (the 7 `product_*` keys) ·
   Topics (Product Categories) · Page Types (Product).

## Important
- **Install once.** `upgrade()` intentionally does not re-import — CIF re-imports page
  types with no existence check and would duplicate `Product`. If the model changes,
  edit in the dashboard or uninstall + reinstall on a fresh site.
- **No composer form** is defined in v1 — attributes are edited via the page's
  Attributes panel (or set via the API). A composer layout can be added later for
  nicer manual product entry.

## Reuse on another site (e.g. uniquecreationsbylisac.com)
Edit only the `<topic>` lines in `install.xml` (the `<trees>` block) to that site's
categories. The page type + attributes are the reusable core. Redeploy + install on
that site's Concrete install.
