# manifest.json — schema

The installer (`controller.php`) reads `manifest.json` from the package root. Generate it
with `scripts/build_manifest.py`, then review by hand before deploying.

## Top level

| Key | Type | Notes |
|---|---|---|
| `catalog_parent_path` | string | Parent page under which Product pages are created. Default `/catalog`. |
| `products` | array | One object per catalog Product page. |

## Product object

| Key | Type | Required | Notes |
|---|---|---|---|
| `slug` | string | ✅ | URL handle → `/catalog/<slug>`. Lowercase, hyphenated, unique. Idempotency key. |
| `name` | string | ✅ | Page title / card name. |
| `type` | string | ✅ | Source product type (`vase`, `planter`, `lamp`, `pen_organiser`, `coaster`, `automotive_part`, …). Drives default category inference. |
| `categories` | string[] | — | Product Categories topic **names** (must match the tree exactly): `Automotive`, `Home & Garden`, `Display & Retail`, `Functional / Hardware`. |
| `licensed` | string\|null | — | Licensed-design credit tag, e.g. `"h3li0"`. `null` for own designs. (Surfaced as the "designed by h3li0" credit / secondary filter.) |
| `short_desc` | string | — | → `product_short_desc` (card summary) + page description. |
| `cta_type` | enum | — | `buy` \| `download` \| `inquire` → `product_cta_type`. |
| `cta_target` | string | — | → `product_cta_target`. Checkout URL (buy), file/link (download), or `mailto:`/anchor (inquire). |
| `price` | string | — | → `product_price`. Shown only when `cta_type = buy`. |
| `external_links` | string | — | → `product_external_links`. Printables/Creality URLs (data for future syndication). |
| `featured_image` | string | — | Path **relative to `images/`** in this package. Imported + set as `product_featured_image`. Empty = no image yet. |
| `gallery` | string[] | — | Additional image paths (relative to `images/`). Reserved for a future gallery-block pass; the first-pass installer only attaches `featured_image`. |
| `configurator` | object\|null | — | Present for variant products (coasters). **Documentation only** — the installer creates the landing page; SKUs live in a Community Store variant product. |

## Notes

- **Idempotent:** a product whose `/catalog/<slug>` page already exists is skipped on re-run.
  Grow the manifest and re-run (or `upgrade`) to top up.
- **Attribute values that can't be set here:** none of `product_*` are blocked in a package —
  that's the whole point (the REST API can't set them; PHP can).
- **`buy` products still need a Store product.** Setting `cta_type: buy` renders a Buy CTA, but
  `cta_target` must point at a real Community Store checkout URL, created separately in the
  Dashboard/CS (no API). Until then, launch those as `inquire`.
