# Product Population — Design Spec

**Date:** 2026-07-31
**Status:** Design settled. Square CSV built and awaiting upload; Woo side not started.
**Scope:** Getting real products into WooCommerce and Square. Two tracks — the coaster line, and the
h3li0 licensed catalog.

---

## 1. Context

cornercad.com has **zero published products**. Square holds a catalog under Bradley's wife's merchant
account (`ML0RSAXT9HH0B`); CornerCAD is one **location** within it, alongside Unique Creations by
Lisa C. Square is not merely a payment processor — it is the **point of sale for in-person shows**, so
every website product must also exist in Square, with photographs.

Products can originate on either side: authored at a desk in WooCommerce, or created on a phone at a
booth with the Square app.

### Locations (verified via Square API, 2026-07-30)

| ID | Name | Status | Business name |
|---|---|---|---|
| `LS4SZ98SBX4F6` | **CornerCAD** | ACTIVE | CornerCAD |
| `L71MXVF5YWZE4` | Unique Creations by Lisa C, LLC | ACTIVE | Unique Creations By Lisa C, LLC |
| `LV94H6Q7QPB42` | Duval County | **INACTIVE** | Unique Creations By Lisa C |

⚠️ **WooCommerce is currently pointed at `LV94H6Q7QPB42`** — the inactive location belonging to the
wrong business. See §8.

---

## 2. Architecture decision — WooCommerce is the system of record

**Decision:** WooCommerce is authoritative. Square receives data. `system_of_record` is set to
`woocommerce` once the prerequisites in §8 are met.

### Why

`system_of_record` is a **single global scalar** in the `wc_square_settings` option — verified by
direct read. There is no per-product, per-category or per-family override.

The decisive constraint is images. Per WooCommerce's object-mapping documentation:

> WooCommerce images upload to Square **only when WooCommerce is Sync Settings** [system of record].

With Square authoritative, anything authored in Woo reaches Square **without photos** — useless at a
booth. Only Woo-as-authority populates Square completely.

It also delivers:

| Benefit | Mechanism |
|---|---|
| Lisa's ~145 items can never appear on cornercad.com | Nothing imports *from* Square |
| Rich copy, SEO, block content preserved | Square has no equivalent fields; it never writes down |
| No empty-description overwrite risk | Direction is Woo → Square only |

Location scoping stays in place as a **second line of defence**, not the only one.

### The cost, stated plainly

Products created in the Square app at a show **do not flow down**. This is the one requirement the
platform cannot satisfy automatically; it is handled by reconciliation (§7), not by sync.

### Rejected alternatives

- **Square as system of record** — fails the image requirement. Also risks Square's thinner data model
  overwriting Woo content; the docs are silent on whether an empty Square description blanks Woo's.
- **Split by product family** — not configurable. One global switch.
- **A Square CSV as the population route** — Square's item CSV **cannot carry images**; image URLs
  appear only in *exports*. Square's own guidance is to bulk-upload photos afterward through the Item
  Library UI. Useful for data (§5.1), useless for photography.
- **Custom MCP reconciliation replacing the plugin** — unnecessary custom code once the native path
  was shown to work. Retained only for the narrow show-item case (§7).

---

## 3. SKU scheme

SKU is the **join key** between WooCommerce and Square. Woo enforces global uniqueness; the Square
plugin matches on SKU. A mismatch creates duplicate Square items rather than updates.

```
<BRAND>-<CATEGORY>-<NNNN>[-<VARIANT>...]

CAD-CLK-0001-BLK              single-axis variant
CAD-VAS-0003-GOLD             Silk Lattice Vase, Gold
UCL-BRA-0006-65IN             Byzantine bracelet, 6.5 inches
CAD-COA-0001-SLT-RND-P04      Slate / Round / pack of 4
```

| Segment | Rule |
|---|---|
| Brand | 3 chars. `CAD` CornerCAD · `UCL` Unique Creations by Lisa C LLC · `CCW` reserved for CornerCADWorks. Fixed width keeps the SKU positionally parseable and avoids `CC`/`CCW` misreads on a packing slip. |
| Category | 3 chars, semantic: `VAS` vase · `PLA` planter · `LMP` lamp · `CLK` clock · `COA` coaster · `FIG` figure · `EAR` `BRA` `NEC` `PEN` `RNG` `KEY` `GEM` `CHN` `CHM` `FDG` `SET` |
| Number | 4 digits, numbered **per category** |
| Variant | Zero or more segments. Sizes encode numerically (`6.5 inches` → `65IN`); single words take 4 chars (`Gold` → `GOLD`); multi-word takes 2+2 (`Feeling Wicked` → `FEWI`). |

**SKUs live on variations, not parents.**

**Licensing is not encoded in the SKU** — h3li0 attribution is product meta plus a term. The SKU says
what a thing *is*, not who licensed it.

⚠️ **Naïve truncation collides.** An early build produced 6 duplicate SKUs because `6 inches`,
`6.5 inches` and `7 inches` all reduced to `INC`. The generator now special-cases sizes and appends a
numeric suffix on any residual collision.

---

## 4. Track A — Coasters

The **LightBurn library is the real inventory.** `CornerCad_Coaster_ideas.lbrn2` carries Cut, Scan and
Image layers together, with settings in `Longer_Ray5_20W.clb`. A template is sellable because a
LightBurn file exists for it — not because a PNG exists.

`~/Documents/Laser/+17K Files Bundle Laser Works Hub` holds **126 coaster PNGs** across 8 collections,
with 126 DXF and 130 SVG production files. Most have no LightBurn setup and may never get one, so
classifying all 126 upfront would be discarded work.

### Four families

| Family | Model |
|---|---|
| **Stock laser coasters** | Variable product. Material × Shape × Pack. Made-to-order. |
| **Clan crests** | Own `product_cat`; stock templates per clan, same variation structure. |
| **Personalized** | **Not catalog** — quote funnel (§4.4). |
| **3D-printed coasters** | Variable product, 5 variations, SKU-matched to Square's existing `Coasters` item ($24). |

### 4.1 Variation matrix

Material and shape are **independent** — a clean cross-product.

| Axis | Values |
|---|---|
| Material | Slate, Wood |
| Shape | Round, Square, Hexagon — Round and Square blanks owned and tested; **Hexagon not yet purchased**, modelled and priced but shipped disabled until stock exists |
| Pack | 4, 6, 25 *(see §10)* |

Supplier price ladder: 4 → $15.14/unit · 6 → $13.48 · 25 → $12.08 · 50 → $8.46 · 100 → $7.61 ·
250 → $5.90.

**Pack size is a variation, not a cart-level discount.** This needs no plugin, and it is the only
option where bulk prices exist in Square as ringable SKUs. A quantity-discount plugin would leave
Square showing base price only.

Made-to-order: `_manage_stock = no`, lead time stated in copy (5–7 business days).

### 4.2 Templates are content, not SKUs

Template selection is **order meta**, never a variation — 126 templates × 6 variations would be 756
SKUs for a choice that does not change price. The gallery filters by chosen **shape**, since shape is
a property of the template.

### 4.3 Template manifest

`content/coaster-templates.json`, git-tracked, one record per *proven* configuration:

```json
{ "id": "geo-0001", "name": "Geometric Snowflake", "collection": "Geometric Designs",
  "shape": "round", "materials": ["slate","wood"], "process": "cut",
  "preview_png": "...", "svg": "...",
  "lightburn_file": "Commercial/Coasters/geo-coaster.lbrn2", "sku_suffix": "0001" }
```

Launches with roughly six entries (`coaster - ocean`, `geo-coaster`, `mandella01`, `CoasterSet1/2`,
Eagle/Crest) and grows as prints are dialled in. This is the join between the laser bench and the
site, and it keeps the 3,484-design bundle out of the website.

Previews upload via `wp_upload_request` + local `curl`.

**Open question:** the sampled templates are openwork **cut** designs with DXF cut files, while the
existing copy in `content/products/` describes *engraved* slate. Cut-from-wood and engraved-on-slate
are different products with different production steps, and slate cannot be cut through. The manifest
records `process` per template for this reason.

### 4.4 Personalized work

Custom text, customer-supplied graphics and bespoke crests require quoting and back-and-forth. These
route to the quote funnel in `2026-07-20-login-gated-custom-request-design.md`, not the catalog.
Reference model: customcoastersnow.com's custom personalized stone coasters.

---

## 5. Track B — h3li0 licensed catalog

### 5.1 Scale — corrected

**13 products**, not ~400. The 400 figure came from `catalog-bulk-import-findings.md`, an obsolete
Concrete-era spike, and it drove an entire round of bulk-import, request-volume and SSH analysis that
turned out to be unnecessary.

Local source: `~/Documents/3dPrinting/Commercial License/h3liØ` (spelled with **Ø** — plain-ASCII
searches miss it). 1.3 GB of 3MF/STL, only 3 PNGs.

| Category | Designs |
|---|---|
| Vases | Alvor, Apex, Evora, Romeu, Sintra, Vanta, Wavy, Wisp |
| Planters | Briosi, Evolve, **Tavira**, Wavy |
| Lamps | Drift |

h3li0's full catalog is **~116+ models** (39 vases, 77 planters on MakerWorld), so this is a curated
subset, deliberately.

⚠️ The local folder was named **Tivara**; the design is **Tavira**. h3li0 names designs after
Portuguese towns (Alvor, Évora, Sintra, Porto, Mafra, Faro, Braga, Tavira…). Corrected in the CSV.

### 5.2 License terms — Commercial + Photo, $15/month

| Term | Consequence for the build |
|---|---|
| *"Include 'designed by h3li0' in your item description"* | **Mandatory attribution**, generated into every description |
| Product photography may be used on listings | No need to photograph these; use h3li0's images |
| Digital files may not be resold or distributed | **Physical only** — `_virtual = no`, `_downloadable = no`, no file-download CTA |
| Geometry may not be modified; **scale may be** | Size variants are legitimate; derivative designs are not |
| No moulds for mass production; no crowdfunding | — |
| Non-exclusive, non-transferable, no sublicensing | See risk below |
| **License ends if the Patreon subscription is cancelled** | See risk below |

**Risk — the license dies with the subscription.** Both the right to sell and the right to use the
photography end at once. Every h3li0 product therefore carries a **`licensed-h3li0` term/meta flag**
so the whole line can be found and unpublished in a single filtered action. This is also the planned
exit: h3li0 is a **stop-gap** until Bradley has enough of his own designs (~300), at which point the
line is retired deliberately.

**Risk — B2B.** The license covers *Bradley* selling prints. A wholesale buyer who resells is not
covered, and sublicensing needs written consent. Selling h3li0 designs through CornerCADWorks needs
h3li0's sign-off first.

### 5.3 Product notes

- **Drift Lamp — printed parts only.** Requires an E14 lamp kit with plug and switch, not included.
  Selling an assembled mains-powered lamp would mean shipping a finished electrical product with an
  uncertified kit; parts-only avoids that. A kit may be offered later as a separate add-on SKU. The
  description states this explicitly.
- **Evolve Planter** is sized for **IKEA FEJKA artificial plants** — a fit constraint, stated in copy.
- **Apex** ships in two heights and **Briosi** in dual/single colour upstream. No size variants for
  now; pick which is stocked before SKUs harden.
- **Single-head FDM.** Multi-*filament* single prints are out; multi-*part* assemblies (drip plates,
  wrap-around plates, shell + insert) are fine as separate runs. None of the 13 needs a second head.
- ⚠️ **Photography must match the SKU sold.** h3li0's shots show the version *they* printed. If Briosi's
  hero image is the dual-colour build and single-colour ships, the listing misrepresents the product.
  Check per product when wiring images.

### 5.4 Copy

h3li0's Patreon descriptions are **maker's notes, not sales copy** — they trail into "published on:
makerworld printables thanks", naming competing platforms. Older posts have almost no description.
The license obliges attribution, not verbatim reuse, so listings use rewritten retail copy informed by
those notes, with the credit line appended.

---

## 6. What Square cannot hold

Woo-only: SEO fields, Gutenberg block content, long-form descriptions, downloadable files, custom
meta, reviews, related/upsell products.

Square's *export* does include `SEO Title`, `SEO Description` and `Permalink` — those belong to Square
Online and are **not** covered by the Woo↔Square sync mapping.

Quantity-tier pricing survives only because pack size is modelled as a variation (§4.1).

---

## 7. Reconciliation — show-created products

Woo-as-authority does not pull down. Products created in the Square app at a booth are caught by a
periodic read-only pass:

1. Read the CornerCAD location's catalog via the Square MCP.
2. List items whose SKU has no matching Woo product.
3. Create those in Woo as draft; fill in copy and images.
4. The next sync pushes the completed record up; SKU makes it an update, not a duplicate.

Read-only on Square. No writes to the shared catalog.

---

## 8. Prerequisites — before enabling sync

1. **Repoint Woo at `LS4SZ98SBX4F6`.** It currently points at `LV94H6Q7QPB42` — inactive, and named
   for the wrong business. This is also the **statement-descriptor fix**: the CornerCAD location's
   `business_name` is "CornerCAD", so receipts and card descriptors stop showing the parent business.
   *(Live-site write — needs explicit approval.)*
2. ~~Fix location crossovers~~ — **handled in the CSV.** GSG and Love Earrings were enabled at both
   locations; both set to `N` for CornerCAD.
3. ~~Assign SKUs~~ — **handled in the CSV.** 155 rows had 1 SKU between them; all now populated.
4. **Get Woo correct first.** With Woo authoritative, the first sync **overwrites Square**. Enabling it
   while Woo products are half-built would push incomplete data over good Square records.

Sync stays `disabled` until all four are done.

---

## 9. Access audit — no SSH required

SSH is ruled out (prior bad experience). Nothing in this design needs it.

| Task | Path |
|---|---|
| Bulk product import | WooCommerce native CSV importer (admin UI) |
| Bulk image delivery | cPanel / Bluehost File Manager, single zip |
| Individual images | `wp_upload_media {url}` — WordPress fetches remote URLs directly, so h3li0's Wix CDN images need no local download |
| Local-file images | `wp_upload_request` + local `curl` (local shell → HTTP endpoint) |
| Variable products | MCP raw path — `wp_add_post_terms`, `_product_attributes`, `product_variation` child posts |
| Verification | `wp_get_post_snapshot`, `wc_list_products` |
| Square reads | Square MCP, read-only |
| Plugin install | WP admin — **no MCP tool exists** |

`wc_create_product` accepts `type: variable` but exposes **no attribute or variation parameters**;
variable products must use the raw MCP path.

---

## 10. Open items

1. **Wholesale boundary.** *Assumption:* cornercad.com carries packs of **4 / 6 / 25**; larger routes
   to CornerCADWorks.com. 250 units at $5.90 is wholesale pricing and `CLAUDE.md` puts B2B on the Works
   site. Overrule if the consumer site should carry the full ladder.
2. **Coaster process** — are the 126 templates cut-from-wood, engraved-on-slate, or both? Determines
   whether template choice is constrained by material (§4.3).
3. **Drift Lamp price** — $60 was set before the parts-only decision. Confirm it still holds without
   the electrical kit.
4. **Apex height / Briosi colour** — which version is stocked.
5. **"Lattice Vase" vs "Silk Lattice Vase"** — possible overlap, unresolved. Both are Bradley's, both
   created March 2026, both $42 base, but they have **different images and different categories**
   (`Vases & Planters` vs `Prints`), which suggests they are distinct products rather than a
   duplicate. A local folder `Lattice-Vase-V2` exists, hinting at a V1/V2 relationship. Even if
   distinct, the two names sitting adjacent in a catalog will confuse buyers — worth renaming one.
6. **Silk Lattice Vase category** is `Prints` while every other vase is `Vases & Planters`. That was
   its pre-existing value and was deliberately not overwritten; normalise if consistent reporting
   matters.

---

## 11. Square side — ✅ COMPLETE (2026-07-31)

`docs/square-import-DRAFT-2026-07-31.csv` (168 rows) was uploaded via Square's **quick import**,
choosing **"Add and update existing library"**.

⚠️ The other option — *"Delete and overwrite existing item library"* — would have wiped the entire
shared catalog and its history. Never select it.

**Verified against the live catalog after import:**

| Check | Result |
|---|---|
| Total catalog | 87 items (was 74, +13) |
| At CornerCAD | **22** |
| At Lisa's | 65 |
| At **both** | **0** |
| Duplicate names at CornerCAD | none |
| h3li0 items present | **13 / 13** |
| Variations without SKU | **0** |
| GSG · Love Earrings | CornerCAD `False`, Lisa `True` |

Tokens matched correctly — 155 updated in place, 13 created, nothing duplicated.

**Also done:** Woo's `production_location_id` repointed from `LV94H6Q7QPB42` → **`LS4SZ98SBX4F6`**
(§8 item 1), written as the full 15-key array and verified by re-read. Bradley separately deactivated
the six county locations, leaving exactly the two active locations the CSV columns assume.

### Note on Square's quick import
The review screen does **not** show the `Token` column, so update-vs-create cannot be confirmed from
it. The confirmation dialog is the real gate. If ever unsure, `docs/square-import-TEST-2rows.csv`
(one tokened row + one blank-token row) proves the behaviour for the cost of a single duplicate.

---

## 11a. Product photography

**Upload to WordPress, never directly to Square.** Woo is the system of record, and sync pushes images
*up* — the only direction that carries them. Uploading into Square first is work Woo later overwrites,
and leaves the images stranded on the POS side.

Order that avoids rework: rename/optimise locally → create the Woo product → upload and set
featured + gallery → enable sync → Square receives them.

### Filenames

**Square stores the original filename verbatim** (`image_data.name`), but its public S3 URL is a
content hash — so in Square the name is internal metadata only. **In WordPress the filename becomes
the permanent public URL and seeds the alt text**, and cannot be cleanly changed later. So filename
hygiene is a WordPress concern, not a Square one.

Convention: product slug + sequence, SKU omitted (it means nothing to a search engine):

```
vanta-vase-01.jpg
vanta-vase-02.jpg
```

First in sequence becomes the featured image.

### Image prep
- Camera originals are 4:3 (1920×1440); Woo grids assume **square** thumbnails and will centre-crop,
  so crop to 1:1 deliberately rather than letting the theme choose.
- Target ~1500px and ~200–400 KB. Straight-from-camera JPEGs are several MB; Jetpack Boost will not
  rescue oversized originals.

**Source of photography:** the Commercial + Photo license permits using h3li0's own product shots, but
Bradley has begun shooting his own (a `vanta_vase_photos` set was uploaded to Square as a look-and-feel
test on 2026-07-31 — that test predates this decision and should be re-done into WordPress).

⚠️ Where h3li0's photos *are* used, the shot must match the SKU sold — their images show the version
they printed (dual-colour Briosi, taller Apex), which may not be what ships.

---

## 12. Verification plan

| Stage | Check |
|---|---|
| Square upload | Preview shows 155 updates / 13 creates, not 168 creates |
| Before sync | Correct location, sandbox off on production, all SKUs populated |
| CSV smoke test | 3 rows on staging → correct categories, images, variations |
| Post-import | `wc_list_products` count matches manifest; sample `wp_get_post_snapshot` shows meta + terms |
| First sync | Spot-check Square: correct location, photos present, SKUs matched not duplicated |
| Descriptor | One live transaction, refunded — confirms the statement descriptor reads CornerCAD |

Products are created as **draft**, verified, then published. WordPress has no draft-version rollback.

---

## 13. Corrections logged during design

Recorded so they are not re-derived:

| Believed | Actual |
|---|---|
| h3li0 is ~400 products | **13** (from a ~116-model catalog); the 400 came from an obsolete Concrete-era doc |
| Bulk import needs SSH or 1,200 MCP calls | WooCommerce ships a **native CSV importer** |
| h3li0 source wasn't on this machine | It is — `h3liØ`, spelled with Ø |
| Photography must be shot in-house | The **Commercial + Photo** license explicitly permits using h3li0's |
| h3li0's mark on photos breaks the branding rule | That rule covers *marketplace/processor* branding, not design attribution |
| Square CSV could carry the catalog wholesale | It carries data but **not images** |
| Square holds no SEO fields | It does — but they are outside the Woo sync mapping |
