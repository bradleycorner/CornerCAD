# Category Scheme Plan

**Date:** 2026-08-08
**Related:** task #7; decision 2026-08-07 (method-primary + cross-cutting Coasters); coaster spec
`2026-08-08-laser-coaster-workflow-design.md`

## 1. Target structure

**Top-level (browse + homepage "Shop by what it is" cards):**
**3D Printed · Laser Engraved · Automotive · Coasters**

- **Under 3D Printed:** Vases · Planters · Lamps · Gardening · Fidgets · Clocks/Décor
- **Under Laser Engraved:** Signage
- **Coasters:** top-level, **cross-cuts methods** (plastic = 3D Printed, wood/slate = Laser Engraved)
- **Automotive:** top-level card (currently the homepage links it as nested `/3d-printed/automotive/` — see Open Decisions)

**Lisa's jewelry categories** (Gemstones, Earrings, Bracelets, Keychains, Necklaces, Charms, Rings,
Pendants, Chainmail) — **UNTOUCHED.**

## 2. Management split

- **h3li0 + non-coaster CornerCAD products** (3D-printed / laser): **Square = system of record** →
  synced to Woo. Set the hierarchy **on Square**.
- **Coasters** (Phase 1 picker): **Woo-native** → categories (**Coasters + method**) assigned **directly
  in Woo** (multi-category is trivial there; no sync question).

## 3. The moves (Square — CornerCAD items only; filter on `CAD-` SKU / CornerCAD location `LS4SZ98SBX4F6`)

Key insight: nesting is done by setting each type category's **`parent_category`** — a handful of
**category** edits, **NOT** re-tagging every item. Items stay in their type category (e.g. a vase stays
in "Vases"); "Vases" simply becomes a child of "3D Printed."

1. **CREATE** Square categories: **Laser Engraved**, **Automotive** (they don't exist yet). Optionally
   **Clocks/Décor** (a child of 3D Printed for the clocks / Baby Dragon / egg).
2. **NEST under 3D Printed** (set `parent_category`): Vases · Planters · Lamps · Gardening · Fidgets
   (+ Clocks).
3. **NEST Signage under Laser Engraved.**
4. **RE-TAG** the automotive items → **Automotive** (a handful of items).
5. **RECONCILE** existing Square categories **Home Decor · Office · Prints** — fold into the scheme
   (e.g. Prints → 3D Printed) or keep. NEEDS DECISION.
6. **Coasters** stays top-level.

## 4. Woo side

- The sync brings Square categories → Woo `product_cat`. Reconcile the as-built Woo terms (3D Printed,
  Automotive, Coasters, Lamps, Laser Engraved, Signage, **Vases & Planters**, Uncategorized) — Square is
  the source of truth, so the split **Vases + Planters** wins over the combined "Vases & Planters".
- **Homepage:** add the 4th **Coasters** card to "Shop by what it is" (page 49); ensure the 4 cards use
  document-relative links (`./product-category/3d-printed/`, `./laser-engraved/`, `./automotive/`,
  `./coasters/`).

## 5. ⚠️ Verify FIRST (before the live reorg) — sync + display tests on sandbox/staging

1. **Does the sync carry category HIERARCHY** (Square parent→child → Woo `product_cat` parent→child)?
   Nest a sandbox category under a parent, import, inspect the Woo term's parent.
2. **Does the WooCommerce "3D Printed" parent archive show *descendant* products** (vases/planters/…)? If
   not, enable "show products from subcategories" or adjust the category/shop display settings.
   - **Fallback if hierarchy doesn't sync:** set the Woo hierarchy manually (risk: a later sync may reset
     it — re-check), OR represent "method" as a Woo-side grouping (nav/menu or tag) rather than Square
     hierarchy.

## 6. Sequencing

1. Run the §5 verification tests on sandbox/staging.
2. Do the Square category reorg on the LIVE catalog (CornerCAD items only) — **with per-write
   confirmation** (live account shared with Lisa; touch only `CAD-` items / the CornerCAD location).
3. Verify the synced result on staging (import → Woo categories + browse behavior).
4. Add the Coasters homepage card.
5. Coaster categories are handled in Woo when the Phase 1 picker is built (Woo-native).

## 7. Open decisions

- **Home Decor / Office / Prints** — fold into the scheme or keep?
- **Automotive placement** — top-level category, or nested under 3D Printed? (Homepage currently links
  `/3d-printed/automotive/`. A card can be top-level while the category is nested, or promote Automotive
  to its own top-level term. Pick one for URL/breadcrumb consistency.)
- **Clocks/Décor** — its own child category under 3D Printed, or leave those items directly in "3D
  Printed"?
