# CornerCAD — Sales Model & Licensing Rules (source of truth)

> Decides what each product's CTA is, and the compliance rules that constrain listings.
> Read alongside `_brand.md`. Approved: **physical-first, digital rare.**

## The three sales lanes (maps to the `product_cta_type` switch)

| Lane | CTA | What goes here |
|---|---|---|
| **Physical made-to-order** | `buy` | Your original work (coasters in wood/slate/print, planters, lamps, automotive) **and** licensed h3li0 designs — all as MTO physical prints. The core business. |
| **Digital download** | `download` | **Your own authored files ONLY.** Rare + premium — a deliberately small, curated set. Never the h3li0 designs. |
| **Custom / wholesale** | `inquire` | One-off custom (CornerCAD.com) and B2B/wholesale (routes to CornerCADWorks.com). |

## MTO positioning note
Made-to-order is an asset, not a limitation — it reads as bespoke and made-for-you, reinforcing "real
objects you keep." Set clear lead times per product; charge upfront. Don't apologize for it; sell it.

## Digital strategy (approved: physical-first, digital rare)
- Physical MTO is the lead product everywhere.
- Digital files: only a select few of **your own** designs, priced as a premium add-on — not a bulk catalog.
- Rationale: bulk file sales drag the brand toward the oversaturated marketplace we're positioned against,
  and a sold file is out of your control forever.

---

## h3li0 licensed designs — COMPLIANCE RULES (must-follow)

Source: h3li0 "Commercial + Photo License," $15/mo Patreon. Covers his MakerWorld / Printables / Creality
Cloud designs + his product photography.

**Allowed**
- Sell **physical prints** of his designs on any platform (so: CornerCAD.com catalog ✓).
- Use his **product photography** on those listings ✓.
- Choose your own materials, colors, and **scale**.

**Required / prohibited — build these into every h3li0 listing:**
| Rule | Build implication |
|---|---|
| Include **"designed by h3li0"** in every item description | Bake attribution into the Product template for licensed items (a `licensed_credit` field or standard line). |
| **Digital files = personal use only; no resale/redistribution** | These are **`buy` (physical) ONLY** — never a `download` product. Hard rule. |
| **No moulds for mass production** from his designs | Candle-mold B2B must use **your own** designs, never h3li0's. Keep sourcing separate. |
| **No crowdfunding** | Don't feature h3li0 items in any Kickstarter/crowdfund campaign. |
| **No geometry modification** (scale only) | Sell as-designed; only resize. |

**Business-continuity + structural risks (important):**
- **Rights end if you cancel the $15/mo Patreon.** Treat h3li0 items as a *supplementary* line, not a brand
  pillar. Tag them as their own category so the whole set can be pulled quickly if the license lapses.
- **License is personal, non-exclusive, non-transferable.** It's tied to you. It does **not** extend to
  uniquecreationsbylisac.com or any third party — Lisa would need her own membership. Note this in the
  §9 generalization plan.
- Wholesale of h3li0 prints (e.g. to ACE) = you selling physical prints, which is permitted — but *you*
  produce them; you can't sublicense the design to a dealer.

---

## Fulfillment lanes (in-house vs. Slant3D print-on-demand)

Slant3D = high-volume **FDM** print farm with print-on-demand fulfillment (no API access fee, pay-per-part;
$9/mo Shopify auto-routing; they handle assembly/packaging/warehousing). Use it to break the "every sale =
my machine time" ceiling — but only within these rules:

| Product | Fulfillment | Why |
|---|---|---|
| **h3li0 licensed items** | **In-house only** | Sending his files to a 3rd-party farm = distributing files + mass production, both prohibited by his license. No Slant3D without his written permission. |
| **Laser (wood/slate) + resin items** | **In-house only** | Slant3D is FDM only — can't produce these. |
| **Your own FDM designs — signature/hero pieces** | **In-house** | Protects the "finished by hand in Jacksonville" brand story. |
| **Your own FDM designs — commodity/volume + B2B wholesale** | **Slant3D** | "We produce at volume" is the selling point here, not "handmade." Scales CornerCADWorks.com. |

**Rule of thumb:** handmade narrative → in-house; volume/wholesale → Slant3D. Label honestly; don't call a
farm-printed item hand-finished.

## Checkout & payments (DECIDED)

**Both sites: Square Payment Links driven from one Square catalog. No Community Store (for now).**

Rationale: the business is **in-person-event-first**, already running Square POS. Keeping a single Square
catalog as the source of truth means event sales and website sales draw down the **same** inventory count
automatically (Square decrements stock across POS + payment links). Community Store would create a second,
disconnected catalog to reconcile by hand — wrong for a two-person shop.

- Square = processor **and** catalog/inventory system for both cornercad.com and uniquecreationsbylisac.com.
- No monthly fee (drops Lisa's $350/yr Square Online) — pay-per-transaction only (~2.9% + 30¢ online).
- Website `buy` items → per-product Square hosted checkout link in `product_cta_target`.
- **Upgrade path (not a rebuild):** if Lisa later needs a true self-serve multi-item cart, add Community
  Store + `community_store_square` gateway on her site only. Products stay products.

## Channels & syndication (DECIDED direction)

**In-person events = primary channel. Website = secondary, and a funnel target.**
- Physical objects drive traffic to the site (Lisa's QR-code wooden coasters → homepage). Keep doing this.
- **Maker platforms (Creality Cloud, Printables, MakerWorld) = discovery funnel, not a digital store.**
  Post *your own* designs, link back to cornercad.com, and record the URLs in `product_external_links`.
  Consistent with "physical-first, digital rare" — presence + traffic, not a file fire-sale.
  Current state: ribbed planter live on Creality; expanding via bulk-upload tools.

**HARD RULE — never upload h3li0 designs to any maker platform.** Public posting = distributing his files =
license violation. Bulk-upload tools make this easy to do by accident; keep the queue to your own geometry only.

**Slant3D Teleport / API:** Teleport's turnkey integrations are Etsy/Shopify/Amazon — NOT Concrete or Square.
Auto-fulfilling website orders would require the raw Slant3D API (Code-mode custom build). Defer; in-house
fulfillment for now. Still off-limits for h3li0 designs. A "scale your own FDM designs later" lever only.

## Net recommendation
1. **CornerCAD.com** — physical MTO catalog (`buy`), lead with your own work, h3li0 items as a tagged
   supplementary category with attribution. A few premium `download` items of your own designs.
2. **CornerCADWorks.com** — B2B/wholesale + custom (`inquire`), your own designs only (safe re: molds).
3. Keep h3li0 catalog logically separable so a lapsed license is a one-click pull, not a rebuild.
