# Laser-Engraved Coaster Line — Launch Architecture

**Date:** 2026-09-05
**Status:** Approved design (pre-implementation-plan)
**Supersedes:** the architecture in `2026-08-08-laser-coaster-workflow-design.md` (§2–§4) — see that
file's status note for what's still salvageable from it.
**Related memory:** `project_laser-design-bundle`, `project_square-untracked-variation-stock-bug`

## 1. Purpose

Turn Bradley's proven laser-coaster designs into sellable products on cornercad.com, using the same
Square-is-system-of-record pipeline as the rest of the catalog — no new plugin, no Woo-native
exception. This is the launch-lean path; a richer design-picker/personalization experience (the
2026-08-08 spec's vision) stays a valid *future* direction but needs its own architecture decision
if pursued, since it genuinely can't ride a Square-synced attribute (see that doc's rationale).

## 2. Why Square-sync, not Woo-native

The existing **Engraved Slate Coaster** product (built 2026-08-23) already works this way: a Square
variable item, synced into Woo, with the print-fit variation dimensions flattened into one Woo
attribute (per the standing hard constraint — `has_multiple_variation_attributes()` silently drops a
product with 2+ true variation attributes on sync). Every other product family in the catalog
(planters, vases, the seasonal lines) follows this same Square-first pattern. Extending it to more
coaster designs is strictly less new surface area than introducing Product Add-ons / Zakeke as a
Woo-native exception — and matches what's actually live today, not just what was once planned.

**Trade-off accepted:** no live design-picker gallery, no text personalization, no per-design
thumbnail swatch UI. The customer picks a Design from a normal Woo variation dropdown, same as
picking a Height on the Drift Planter. If the design library outgrows what a dropdown can comfortably
hold, or personalization becomes a priority, that's the trigger to revisit Woo-native + a
plugin — treat it as a distinct future decision, not something to half-build now.

## 3. Product structure

**One parent product per material.** Material is not a variation dimension — it's which parent
product a design's variation lives under. This is what keeps the architecture open-ended: adding a
new material later (see §9, open item 3) means adding a new parent product, not redesigning the
attribute.

- **Engraved Slate Coaster** (existing product 4787, id stays, variations rebuilt) — slate blanks,
  the material Bradley has already tested and calibrated cut settings for (`Slate/Engrave Settings/…`
  in his LightBurn `CutSetting` profiles).
- **Engraved Birch Coaster** (new, later) — wood blanks, once Bradley has tested and proven the
  engrave settings on that material. Not launching with the first wave.
- Further materials (or new shapes not covered by an existing design's own geometry) get their own
  parent product the same way, as Bradley proves out production methods for each. This is explicitly
  left open per his direction — the structure should not assume slate + birch is the final list.

**One Woo variation attribute per product: "Design."** Shape folds into the value label wherever a
design has more than one physical cut shape available (some of the geometric-family designs exist as
both a round and a square cut, per their `-1`/`-2` LightBurn layer pairing) —

- `Octagon Greek-Key Maze — Round`
- `Celtic Square Knot — Square`
- `Diamond Lattice — Round` / `Diamond Lattice — Square` (same design, both shapes available)

A design that only exists in one shape just gets one value. This is the same "flatten a second axis
into the label" move the coasters already used for shape×pack, applied to shape×design instead.

## 4. Design source & curation

**Primary source for launch: Bradley's own tested `Laser/Commercial/Coasters` designs** — these carry
his proven, slate-calibrated LightBurn cut settings, not the untested commercial bundle sampled
earlier in this design process. Candidate pool surfaced so far:

- `CoasterSet1.lbrn2` — 4 designs (spiral maze, ornate flourish medallion, flower/sunburst, ornate
  circular border)
- `CoasterSet2.lbrn2` — 6 designs (octagon Greek-key, two plaid/checker patterns, diamond lattice, a
  floral mandala, a wolf-head line-art)
- `geo-coaster.lbrn2`, `coaster - ocean.lbrn2`, `MandellaCoasters/mandella01.lbrn2` — single designs,
  each already sheet-tested
- `Eagle CoasterV2/V3.lbrn2` — color eagle medallion, tested with a CornerCAD.com print
- `CornerCad_Coaster_ideas.lbrn2` — hex-shaped CornerCAD-branded test layout

**Splitting these multi-design sheets into standalone per-design files is Bradley's own work, not
blocked on this spec.** An automated split was attempted this session and abandoned — the resulting
`.lbrn2` files were well-formed XML with correct shapes and cut settings, but LightBurn rendered them
blank, likely because internal shape `VertID`/`PrimID` values were left as sparse, non-contiguous
numbers copied from the source sheet rather than renumbered for a standalone file. Reverse-engineering
that further wasn't worth it against an undocumented format; native LightBurn (select a design's
shapes → Cut → paste into a new file → save) is the reliable path and is fast by hand.

**Explicitly excluded from this launch, structure kept open for later:**
- **US Military** and **Clan** themed designs. The specific existing files (the official USMC seal,
  the Johnston/Maclean clan crest badges) carry real trademark/heraldry licensing risk for general
  public sale and are not launch material as-is. Bradley will either create his own original designs
  in these themes or source verifiably royalty-free replacements before either group ships. The
  product structure (§3) needs no change to support this later — they're just more Design values,
  possibly under their own parent product if a new shape/format is involved.

**Exact launch design list is not finalized in this spec** — it's gated on Bradley's own
split-and-verify pass in LightBurn. This doc defines the structure the finalized list plugs into.

## 5. Onboarding pipeline: adding designs in bulk

Adding "all the templates currently on hand" — or any later batch — follows the same shape as the
existing product-copy pipeline (`content/square-descriptions-*.md` → `square_push_descriptions.py` →
Square → Woo sync), not a series of one-off manual product edits.

1. **Split & finalize source files (Bradley, in LightBurn).** For each multi-design sheet
   (`CoasterSet1`, `CoasterSet2`, etc.), select each design's shapes → Cut → paste into a new file →
   save as its own standalone `.lbrn2`. Already-single-design files (`geo-coaster`, `coaster - ocean`,
   `mandella01`, Eagle Coaster) need no split. This is the one manual, per-batch step — everything
   after it is scripted.

2. **Build a design manifest** — `content/coaster-designs.csv` (or `.md`), one row per design, the
   single source of truth a script reads from:

   | Design name | SKU code | Shape(s) | Source file | Price | Status |
   |---|---|---|---|---|---|
   | Octagon Greek-Key Maze | GRK | Round | `CoasterSet2_design1.lbrn2` | $9 | ready |
   | Diamond Lattice | LAT | Round, Square | geometric 2-1 / 2-2 | $9 | ready |

3. **Thumbnails: already on hand, no new export step.** Bradley already has a PNG for every template
   (paired alongside the `.lbrn2` source, or generated separately) — the manifest's Source File column
   just needs to resolve to that existing PNG for the Square variation photo, reusing the same
   image-prep pattern as `scripts/square_upload_images.py`.

4. **A script pushes the manifest to Square** — new, small, same shape as
   `square_push_descriptions.py` / `square_seed_sandbox.py`: for each `ready` row, create/reuse a
   "Design" Option Set value on the Engraved Slate Coaster item, add the variation with its
   SKU/price/image, `sparse_update: true` as always (per the project's standing Square write-safety
   rule).

5. **Run the normal Square → Woo sync** (WP-CLI, `--user=1` mandatory) — pulls every new variation
   into the live Woo product in one pass.

6. **Spot-check the live product page** — variation dropdown shows every design, images swap
   correctly, price and min-qty-4 behave.

Adding a later batch (a proven Birch design, a cleared Military/Clan design) is new manifest rows
plus a re-run of steps 4–6 — no new plumbing.

## 6. SKU pattern

Keep the existing convention: `CAD-COA-####-<3-letter design code>` per variation, extending the SKU
prefix already used for coaster products (e.g. `CAD-COA-0001`, `CAD-COA-0002`). Design codes are
short, memorable 3-letter abbreviations (e.g. `-GRK` for Greek-key, `-OCN` for ocean, `-WLF` for
wolf-head) — assigned when the final design list is locked, avoiding the parent-SKU-reuse mistake
that broke a variation once before (see `project_square-untracked-variation-stock-bug` and the
Variable Products section of the project `CLAUDE.md`).

## 7. Pricing & minimum order quantity

**Per-unit pricing, not pack-size variations** — this replaces the pack-size axis the original
Engraved Slate Coaster variations used. Each Design value is priced individually.

**Minimum order quantity: 4, for slate.** Enforced the same way a plugin-free min-qty rule would be
on any other Woo product (a small `woocommerce_quantity_input_args` filter or equivalent — exact
mechanism decided at implementation time). Not yet specified for other materials as they're added;
default to the same 4-unit floor unless Bradley says otherwise when a new material ships.

**Future: tiered bulk discounts.** Confirmed technically feasible via a WPCode snippet (a quantity
break table applied at cart/checkout), no paid plugin required — not built yet, but the min-qty-4
floor and this mechanism are designed to compose (e.g. a discount tier starting at 20).

**Future: coaster holder/stand add-on and bundle.** Bradley plans a 3D-printed or laser-cut coaster
stand, sold as a standalone add-on to any coaster purchase, and/or as a **"5 coasters + stand"**
bundle at a discounted price. This needs its own design/testing pass before it's buildable — noted
here so the pricing mechanism above (bulk discount tiers) is designed with room for a bundle discount
to reuse the same underlying approach, rather than needing a second one-off mechanism later.

## 8. Relationship to the FDM (3D-printed) coaster line

The existing FDM coaster products (`Cova Coasters`, `Hex Coaster Set`, both simple Square-synced
products) are a separate line from the laser-engraved one, but **list together under the same
Coasters category** — no separate top-level category split between FDM and laser. Cross-selling
between the two lines can reuse the existing auto-cross-sell WPCode snippet
(`snippets/wpcode-cornercad-auto-crosssells.php`) or a manual curated list — **deferred, not a launch
blocker.**

## 9. Open items

1. **Final design list + SKU codes** — pending Bradley's LightBurn split-and-verify pass on
   `CoasterSet1`/`CoasterSet2` and the other proven files.
2. **Min-order-quantity implementation mechanism** — plugin-free filter vs. a small custom snippet;
   decide at implementation time.
3. **Birch (or other material) engrave-setting calibration** — not started; gates when a second
   parent product can ship.
4. **Military/Clan design sourcing** — Bradley's own original art or verified royalty-free assets;
   no timeline set.
5. **Coaster stand/holder design + bundle mechanism** — not started.
6. **The manifest-to-Square push script (§5 step 4)** — not written yet; part of the implementation
   plan.

## 10. Success criteria

- The Engraved Slate Coaster product carries a "Design" variation attribute populated from Bradley's
  proven `Commercial/Coasters` designs, synced from Square exactly like the rest of the catalog.
- Adding a new material later means adding a new parent product, not restructuring this one.
- Adding a new design later (including a future Military/Clan design once cleared) means adding a
  Design value, not a schema change.
- No plugin cost or Woo-native exception introduced for this launch.
