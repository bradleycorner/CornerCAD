# CornerCAD Catalog Bulk Import

Bulk-creates catalog **Product pages** from a generated `manifest.json` — the automated way
to stand up the h3lio (~400) and personal-print catalog without 400 rounds of Dashboard
Composer. See `docs/catalog-bulk-import-findings.md` for the why.

> **Status: SKETCH / not yet deployed.** The PHP is authored from a workstation with no server
> access; lines marked `// VERIFY ON DEPLOY` should be sanity-checked against the live 9.5.2
> instance on first install. Test on staging (or one small manifest) before the full 400.

## What it does

On `install()` (and `upgrade()`), the package loops `manifest.json` and for each product:
1. Creates a `product`-type page at `/catalog/<slug>` via `Page::add()` — **bypasses the
   Composer gate and the REST API scope limits** (a package runs server-side PHP).
2. Sets `product_*` attribute values (short_desc, price, cta_type, cta_target, external_links).
3. Assigns Product Categories **topics**.
4. Imports the bundled featured image and attaches it as `product_featured_image`.
5. Approves the page version.

**Idempotent:** a product whose `/catalog/<slug>` page already exists is skipped, so it is safe
to re-run as the manifest grows (batch 1 → full 400).

## Prerequisites

- **`cornercad_setup` installed** (provides the `product` page type, `product_*` attributes,
  and the `Product Categories` topic tree). This package writes into that model.
- A **`/catalog`** parent page exists (it does — cID 417).
- Server allows CLI or Dashboard package install.

## Build the manifest + images (local, no site changes)

```bash
python3 -m pip install Pillow
python3 scripts/build_manifest.py \
    --src  ~/Downloads/h3li0_photos_2026 \
    --out  packages/cornercad_catalog_import \
    --default-cta inquire        # safe until Store buy-products exist
# → writes manifest.json + images/<slug>/*.jpg (web-optimized)
```

Then **review `manifest.json`** — the generator guesses `categories`, sets empty
`short_desc`/`price`, and applies `cta_type=inquire` by default. Correct these by hand
(see `manifest.schema.md`). A hand-authored example is in `manifest.sample.json`.

## Deploy

```bash
# 1. upload this whole folder (incl. manifest.json + images/) to the server:
#    <site>/packages/cornercad_catalog_import
# 2. install:
./concrete/bin/concrete c5:package:install cornercad_catalog_import
#    (or Dashboard → Extend Concrete → install "CornerCAD Catalog Bulk Import")
# 3. to top up after growing the manifest, bump $pkgVersion and:
./concrete/bin/concrete c5:package:update cornercad_catalog_import
```

Check `application/files/log` (or Dashboard → Reports → Logs) for the
`[catalog_import] done — created N, skipped N, failed N` summary.

## Scope boundaries (read before expecting magic)

- Creates **catalog Product pages only.** Community Store **buy products** (variant SKUs,
  checkout URLs) are *not* created here — that's Dashboard/CS work. `buy` items launch as
  `inquire` until their Store product + `cta_target` URL exist.
- **Coasters:** the installer makes the coaster *landing/configurator page*; the
  Material × Shape × Design SKUs live in a Community Store variant product. The stepped
  drill-down UI is a separate Phase-B front-end.
- **Gallery blocks** aren't built in the first pass — only `featured_image` is attached.
  `gallery[]` is carried in the manifest for a later block-building pass.
