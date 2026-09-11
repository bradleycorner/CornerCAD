# Product page template — CornerCAD.com

> Copy this for every product. Top block = attribute values (the data that drives the CTA and listings).
> Bottom block = the page body copy. Voice comes from `_brand.md`; sales rules from `_sales-model.md`.

---

## Attribute values (the `product_*` fields)

| Attribute | Value | Notes |
|---|---|---|
| **Title** | *(product name)* | |
| **Slug** | `/catalog/<slug>/` | Stable — don't change after launch. |
| `product_categories` | *(topics)* | Automotive · Home & Garden · Display & Retail · Functional/Hardware. Add a **"h3li0 (licensed)"** tag on licensed items so the whole set is pullable in one click. |
| `product_short_desc` | *(1–2 lines)* | Card/listing text. Lead with use + material. |
| `product_featured_image` | *(image)* | Your photo, or h3li0's supplied photography on licensed items. |
| `product_cta_type` | `buy` \| `download` \| `inquire` | buy = physical MTO · download = **your own files only** · inquire = custom/wholesale. |
| `product_cta_target` | *(URL / file / anchor)* | Checkout link · download file · inquiry anchor. |
| `product_price` | *(text)* | Shown only when `buy`. |
| `product_external_links` | *(URLs)* | Printables/Creality — data for future syndication. |
| **`product_lead_time`** ⟵ NEW | e.g. "Ships in 5–7 business days" | MTO promise. Required on every `buy` item. |
| **`product_licensed_credit`** ⟵ NEW | e.g. "designed by h3li0" | **Required on h3li0 items** (license). Blank on your own designs. |

---

## Page body (block plan, per site spec §4)

**1. Gallery** — hero image + supporting shots. (h3li0 items may use his product photography.)

**2. Headline** — the product name + a short benefit line.

**3. Description** — 2–3 short paragraphs: what it is, what it's made for, why it lasts. Brand voice:
function-first, "keep and use," no toy energy.

**4. Specs strip** — material(s), dimensions, color/finish options, process (FDM / resin / laser).

**5. Made-to-order note** — `product_lead_time`, framed as bespoke, not a delay.

**6. CTA** — rendered from `product_cta_type`:
- `buy` → **Buy** button → `product_cta_target` (white-labeled checkout) + `product_price`.
- `download` → **Download** button → file. *(Your own designs only.)*
- `inquire` → **Request this** → inquiry form/anchor.

**7. Attribution line** — if `product_licensed_credit` is set, render it plainly near the CTA:
"Designed by h3li0. Produced by CornerCAD." *(Mandatory on licensed items.)*

**8. Related products** — Page List of same-category items.

---

## Licensed-item (h3li0) checklist — apply on every one
- [ ] `product_cta_type` = `buy` only (never `download`).
- [ ] `product_licensed_credit` = "designed by h3li0" and rendered on the page.
- [ ] Category includes the **h3li0 (licensed)** tag.
- [ ] Produced **in-house** (see Slant3D note in `_sales-model.md` — licensed files must not go to a third-party farm without written permission).
- [ ] Geometry unchanged (scale-only edits allowed).
