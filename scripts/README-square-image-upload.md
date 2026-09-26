# h3li0 → Square image upload kit

Uploads the product photos in `h3li0 Media (web)/<slug>/` to your Square catalog and
attaches them to the matching items, with each product's **hero frame set as primary**.

Contents:
- `square_upload_images.py` — the uploader
- `upload_plan.json` — what to upload (155 products, 2,286 images, hero-first order)

## Why a script (and not the Dashboard)
Square's CSV import can't carry images, and the Dashboard's bulk uploader only puts
**one** image per item by name-guessing. The Catalog API (`CreateCatalogImage`) is the
only way to build ordered, multi-image galleries with a chosen primary — that's what
this does. It matches your items by **SKU**, so it ignores Lisa's `UCL-…` items and
only touches your `CAD-…` products.

## One-time setup
1. Python 3.8+ and the requests library:
   ```
   pip install requests
   ```
2. Get a **production** access token (Square Developer Console → your CornerCAD app →
   Production → Access token) and export it. It is read only from the environment —
   the script never stores or prints it:
   ```
   export SQUARE_ACCESS_TOKEN='EAAA...your_token...'
   ```

## Run it (from the CornerCAD repo root)
The default `--media-root` is `h3li0 Media (web)`, so run these from the repo root.

```
# 1) Dry run for ONE product — shows the plan, writes nothing:
python3 scripts/square_upload_images.py --plan scripts/upload_plan.json \
        --product adrift --dry-run

# 2) Really upload that one product, then go look at it in Square:
python3 scripts/square_upload_images.py --plan scripts/upload_plan.json \
        --product adrift

# 3) Happy with it? Run the whole catalog:
python3 scripts/square_upload_images.py --plan scripts/upload_plan.json
```

`--product` matches on slug, name, or SKU (case-insensitive substring), so
`--product adrift`, `--product "Adrift Planter"`, and `--product CAD-PLA-0005` all work.

## Useful flags
| flag | what it does |
|------|--------------|
| `--dry-run` | Print the plan, upload nothing |
| `--product STR` | Limit to products matching STR (test one first) |
| `--primary-only` | Upload just the hero image for each product |
| `--max-per-product N` | Cap images per product at N |
| `--env sandbox` | Point at Square Sandbox instead of production |
| `--sleep 0.25` | Pause between uploads (raise it if you hit rate limits) |
| `--state FILE` | Progress file (default `upload_state.json`) |

## Resume & re-runs are safe
Every successful upload is recorded in `upload_state.json`. If the run stops (or you
Ctrl-C it), just run the same command again — it skips everything already done.
Idempotency keys are deterministic and Square de-dupes identical files, so you won't
get duplicate images.

## How ordering works
For each product, `upload_plan.json` lists images with the **hero first**. The first
image is attached with `is_primary = true` (your storefront thumbnail); the rest are
appended to the gallery in listed order. The hero was taken from each product's
`featured_image` (the labeled title-card frame — the last frame in each folder),
verified present for all 155 products.

## If something doesn't match
- `!! no matching item in Square` — no catalog item has that SKU (e.g. the item wasn't
  imported, or the SKU differs). The product is skipped; nothing else is affected.
- `!! missing file` — the image isn't on disk at the expected path. Check `--media-root`.
