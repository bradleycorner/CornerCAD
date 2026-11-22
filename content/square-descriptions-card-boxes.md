# Square descriptions — Trading card deck boxes (CornerCAD original)

Same format and parser as the other description files.

    python3 scripts/square_push_descriptions.py content/square-descriptions-card-boxes.md

Two products — **MTG Deck Box** and **Oversized Deck Box (Planechase / Archenemy)** — six variations each, from Bradley's own parametric model
(`~/Documents/3dPrinting/Commercial License/MagicCardBox`). **Not an h3li0 design** — no
"Designed by h3li0" line. Facts below are taken from that project's `CLAUDE.md` / `plan.md`.

**This file is blocked on purpose** (the push script refuses while any CONFIRM tag remains):
the Square items don't have this shape yet. Today Square holds only a single-variation
"MTG card box 100 cards" (`CAD-BOX-100-FLT`, $21.53). Restructure first, then clear the tags
and push.

**Not to be confused with "Card Deck Box" (`CAD-BOX-0001`, Woo 4797)** — that is the h3li0
box for 52-card *playing card* decks, a separate product that stays as it is. That's why
these two are named "Deck Box" with the game in front, not "Card Deck Box".

Variation labels (one Square option set per product — the sync drops 2+ dimensions):

| Label | Proposed SKU |
|---|---|
| 60 · Smooth | `-60-SMT` |
| 60 · Fluted | `-60-FLT` |
| 60 · Fluted + Label | `-60-LBL` |
| 100 · Smooth | `-100-SMT` |
| 100 · Fluted | `-100-FLT` |
| 100 · Fluted + Label | `-100-LBL` |

## Cost basis and pricing — real PrusaSlicer slices (2026-11-21)

**Slices:** `MagicCardBox/scripts/slice_price_matrix.py` → `gcode/slice_prices.json`.
PrusaSlicer 2.9.7 CLI, system presets *Prusa CORE One L HF0.4 nozzle* /
*0.20mm BALANCED @COREONEL HF0.4* (infill overridden to **15%**) / *Prusament PETG @COREONE
HF0.4*, one box (box + lid, print pose) per slice. Validated: `100-fluted` = 120.15 g /
5h06m vs Bradley's GUI slice 120.14 g / 5h07m.

**Cost inputs** (Bradley 2026-11-21; also created in Maker-Ops as machine **92** and material
**65**, and macro 60 now points at them instead of test fixtures 1/1):

| | |
|---|---|
| filament | Prusament PETG $29.99/kg = $0.02999/g, × **1.05** waste |
| machine | CORE One L $1,599 / 5,000 h × 1.125 (maintenance + power) = **$0.360/h** |
| magnets | 4 × $0.20 = **$0.80** |
| labor | 2 min × $30/h = **$1.00** (plate off, free the hinge, fit/glue 4 magnets) |
| POS fee | 2.6% + $0.15 (Square in person) |
| online fee | 2.9% + $0.30 processing |
| online fulfillment | **free shipping baked into the price**: ~$5.40 USPS Ground Advantage (commercial) + $1.25 mailer/bubble wrap = **$6.65** |

Prices: Fluted base per Bradley (2026-11-21, online = bundled free shipping); **Smooth −$2, Fluted + Label +$3** in both columns.
Net = price − fees − unit cost. $/printer-h = net ÷ single-box print time.

| Variant | Grams | Print time | **Unit cost** | POS price | POS net | POS margin | POS $/printer-h | Online price (ships free) | Online net | Online margin | Online $/printer-h |
|---|---|---|---|---|---|---|---|---|---|---|---|
| MTG 60 · Smooth | 97.3 | 2h 52m 29s | **$5.90** | $16 | $9.53 | 60% | $3.32 | $23 | $9.48 | 41% | $3.30 |
| MTG 60 · Fluted | 97.1 | 4h 1m 8s | **$6.30** | $18 | $11.08 | 62% | $2.76 | $25 | $11.02 | 44% | $2.74 |
| MTG 60 · Fluted + Label | 96.6 | 3h 50m 17s | **$6.22** | $21 | $14.08 | 67% | $3.67 | $28 | $14.02 | 50% | $3.65 |
| MTG 100 · Smooth | 121.2 | 3h 28m 56s | **$6.87** | $20 | $12.46 | 62% | $3.58 | $27 | $12.40 | 46% | $3.56 |
| MTG 100 · Fluted | 120.2 | 5h 6m 28s | **$7.42** | $22 | $13.86 | 63% | $2.71 | $29 | $13.79 | 48% | $2.70 |
| MTG 100 · Fluted + Label | 119.6 | 4h 33m 42s | **$7.21** | $25 | $16.99 | 68% | $3.73 | $32 | $16.91 | 53% | $3.71 |
| MTG 100 · Fluted — specialty color | 120.2 | 5h 6m 28s | **$7.42** | $25 | $16.78 | 67% | $3.28 | $32 | $16.70 | 52% | $3.27 |
| Oversized 60 (Planechase / Archenemy) · Smooth | 146.2 | 4h 11m 31s | **$7.91** | $22 | $13.37 | 61% | $3.19 | $30 | $14.27 | 48% | $3.40 |
| Oversized 60 (Planechase / Archenemy) · Fluted | 146.2 | 5h 29m 43s | **$8.38** | $24 | $14.85 | 62% | $2.70 | $32 | $15.74 | 49% | $2.86 |
| Oversized 60 (Planechase / Archenemy) · Fluted + Label | 145.4 | 5h 19m 21s | **$8.29** | $27 | $17.85 | 66% | $3.35 | $35 | $18.74 | 54% | $3.52 |
| Oversized 100 (Planechase / Archenemy) · Smooth | 190.3 | 5h 10m 56s | **$9.65** | $26 | $15.52 | 60% | $3.00 | $34 | $16.41 | 48% | $3.17 |
| Oversized 100 (Planechase / Archenemy) · Fluted | 189.9 | 7h 2m 58s | **$10.31** | $28 | $16.81 | 60% | $2.38 | $36 | $17.70 | 49% | $2.51 |
| Oversized 100 (Planechase / Archenemy) · Fluted + Label | 189.0 | 6h 56m 2s | **$10.24** | $31 | $19.80 | 64% | $2.86 | $39 | $20.68 | 53% | $2.98 |

Unit costs re-checked 2026-11-21 through Maker-Ops `POST /products/calculate/from-gcode` on the real
G-code (MagicCardBox `scripts/price_from_gcode.py`, inputs above): every row matches within a cent. Oversized 60
Fluted moved $8.44 → $8.38 because its STL was re-exported (same solid, re-tessellated) and slices 9 min
faster. Oversized 100 is THICK_SLEEVE (interior ~71.3 mm), exported and gated by macro 41 the same day.

**What the table says.**
- Every box clears **56–68% net margin** at these prices.
- But per **printer-hour** they return only **$2.38–$3.73** (Oversized 100 · Fluted is the lowest) — a quarter of the Vexel's
  $13.30. On the project's own pricing model these are a poor use of printer time *when the
  printer is the bottleneck*, and fine filler when it isn't. Packing 4 MTG 100s on a plate
  (2 × 2 on 300 × 300) doesn't change this much: a plate's time is roughly 4 × one box.
- **Fluted is the worst earner.** The flutes add ~1.1–1.6 h over Smooth for the same price
  band; Fluted + Label is *faster* than Fluted (the plate replaces lid flutes) and earns the
  most. Smooth earns more per hour than Fluted despite the −$2.
- SPEED was sliced (below) and buys only ~10%. The K2 Plus PLA 0.12 adaptive slice of the
  60-fluted-label was 2h29m vs 3h29m SPEED here — the gap is printer/material, not profile.

### 0.20mm SPEED vs BALANCED — MTG boxes (2026-11-21)

Same printer, filament and 15% infill; `gcode/slice_prices-speed.json`. Grams identical.

| Variant | BALANCED time | SPEED time | Saved | SPEED unit cost | POS net / printer-h | Online net / printer-h |
|---|---|---|---|---|---|---|
| MTG 100 · Fluted + Label | 4h 33m 42s | 4h 4m 55s | 29 min (11%) | $7.03 | $25 → $17.17 / **$4.21** | $28 → $18.60 / **$4.56** |
| MTG 100 · Fluted | 5h 6m 28s | 4h 35m 59s | 30 min (10%) | $7.24 | $22 → $14.04 / **$3.05** | $25 → $15.49 / **$3.37** |
| MTG 100 · Smooth | 3h 28m 56s | 3h 5m 36s | 23 min (11%) | $6.73 | $20 → $12.60 / **$4.07** | $23 → $14.05 / **$4.54** |
| MTG 60 · Fluted + Label | 3h 50m 17s | 3h 28m 48s | 21 min (9%) | $6.09 | $21 → $14.21 / **$4.08** | $23 → $14.69 / **$4.22** |
| MTG 60 · Fluted | 4h 1m 8s | 3h 38m 21s | 23 min (9%) | $6.17 | $18 → $11.21 / **$3.08** | $20 → $11.70 / **$3.22** |
| MTG 60 · Smooth | 2h 52m 29s | 2h 34m 50s | 18 min (10%) | $5.79 | $16 → $9.64 / **$3.74** | $18 → $10.13 / **$3.93** |

SPEED saves only **9–11%** (18–30 min a box) and lifts net/printer-hour by ~$0.30–0.50 —
not enough to change the conclusion. Fluted is still the slowest and lowest per hour.

Previous estimator tables (Maker-Ops volumetric, then $20/kg + K2 Plus) are superseded.

Open items:
- ⚠️CONFIRM **Oversized 100 is TBD.** Rows added to macro 41's MATRIX (uncommitted) but not
  exported through its gates, so there's no STL to slice. Prices above are placeholders.
- **Oversized card size — settled 2026-11-21 by test print.** The 2 mm worry (model 125 mm
  card + 1 mm/side = 127 interior vs 3.5" × 5" = 127.0 mm cards) did not bite: Bradley's
  printed Oversized 60 fits real cards, and he thinks sleeved ones too. Sleeved fit is
  untested, so the copy does not claim it.
- **Oversized 60 capacity — settled 2026-11-21 by test print:** 60 real oversized cards fit
  with "a little bit to spare", and the tester was happy. So the plan.md fear of ~20 mm empty
  headroom was wrong — these cards are about as thick as a sleeved MTG card (~0.67 mm,
  *inferred* from "a little to spare" ≈ 3 mm; not measured). Consequence: the Oversized 100
  rows in macro 41 were switched THIN → THICK pitch (interior ~71 mm, not 63), or 100 cards
  wouldn't have fit. Still to verify on the first Oversized 100 print.
- ⚠️CONFIRM **Two price tiers — the override plan as written does NOT work.** Decided: base
  Square price = Online (free-shipping) price, in-person price via a location price override.
  But Bradley rings up in-person sales at the **CornerCAD location (`LS4SZ98SBX4F6`) — the same
  location Woo syncs from** — and WooCommerce Square **reads that location's override**:
  `includes/Handlers/Product.php` `update_price_money()` (plugin v5.5.3, the installed version)
  takes `get_location_id()` and, if a variation has a `location_overrides` entry with
  `price_money` for that location, calls `set_regular_price()` with it. So an in-person
  override at CornerCAD would become the **website** price.
  Workable fix (recommended): a second Square location for in-person sales (e.g. "CornerCAD
  Events", business name CornerCAD so receipts/descriptor match), items present there, POS
  price as its override; Woo keeps reading `LS4SZ98SBX4F6`, which has no override → base =
  online price. Cost: sales reporting splits across two locations. Bradley to decide.
- ⚠️CONFIRM **Specialty color (+$3)** can't be a variation (one dimension only — it would be a
  second axis) and Square **modifiers don't sync to Woo**. Online it has no home yet.
- **Heat claim — settled 2026-11-21 as "about 150°F", no CONFIRM.** Basis: Prusament PETG V0
  heat deflection 68°C (154°F) at 1.80 MPa / 74°C (165°F) at 0.45 MPa, ISO 75; the standard
  Prusament PETG sheet appears to say 68°C (garbled PDF, not cleanly read). 150°F sits under
  all of those. "Safe up to 175°F" (79°C) was dropped — that is at/above where PETG softens.
- ⚠️CONFIRM **Parent SKUs** in the headings below are placeholders until the items exist.

## MTG Deck Box — CAD-BOX-100-FLT

### Summary

A hinged deck box for sleeved standard-size cards (Magic: The Gathering and other
63 × 88 mm games). Printed in one piece with a print-in-place hinge and a magnetic lid —
nothing to assemble. Choose 60- or 100-card capacity and a smooth, fluted, or fluted
finish with a name-plate recess on the lid.

### Details

The **MTG Deck Box** is designed and printed in-house by CornerCAD. The lid and box come off
the printer as one part, already hinged: the lid swings fully open and back on a
print-in-place knuckle hinge, and closes on magnets.

Pick your size and finish:
- **60 cards** — a sleeved 60-card deck with room to spare
- **100 cards** — a sleeved 100-card deck, sized for Commander
- **Smooth** — clean, flat faces
- **Fluted** — vertical flutes around the outside for grip and texture
- **Fluted + Label** — fluted, with a recessed name plate on the lid to mark the deck

Every box is 3D printed to order in **Prusament PETG**, in your choice of color.

**Heat-resistant PETG.** Printed in PETG, which holds its shape to about 150°F — well past
the point where ordinary PLA deck boxes start to warp. A box left in a warm car is fine;
keep it off a sun-baked dashboard.

Features:
- One-piece print-in-place hinge — nothing to assemble, nothing to come loose
- Magnetic lid closure
- Sized for sleeved standard cards
- 60- and 100-card capacities
- Three finishes
- Heat-resistant PETG — holds its shape to about 150°F
- Made to order in your color

Designed by CornerCAD.

## Oversized Deck Box (Planechase / Archenemy) — CAD-BOX-O60-FLT

### Summary

A one-piece hinged deck box for oversized 3.5" × 5" cards — Planechase planes, Archenemy
schemes, oversized Commander cards, and Vanguard. Print-in-place hinge and magnetic lid, in
Oversized 60 or Oversized 100 capacity with a smooth, fluted, or fluted-with-label finish.

### Details

The **Oversized Deck Box** takes our MTG deck box and scales it up for oversized
Magic: The Gathering cards — **Planechase, Archenemy, Oversized Commander, and Vanguard
(3.5" × 5")**. It prints as a single part with a working knuckle hinge and closes on
magnets — nothing to assemble.

Pick your size and finish:
- **Oversized 60 (Planechase / Archenemy)**
- **Oversized 100 (Planechase / Archenemy)**
- **Smooth** — clean, flat faces
- **Fluted** — vertical flutes around the outside for grip and texture
- **Fluted + Label** — fluted, with a recessed name plate on the lid to mark the deck

Every box is 3D printed to order in **Prusament PETG**, in your choice of color.

**Heat-resistant PETG.** Printed in PETG, which holds its shape to about 150°F — well past
the point where ordinary PLA deck boxes start to warp. A box left in a warm car is fine;
keep it off a sun-baked dashboard.

Features:
- One-piece print-in-place hinge
- Magnetic lid closure
- Fits oversized 3.5" × 5" cards: Planechase, Archenemy, Oversized Commander, Vanguard
- Oversized 60 and Oversized 100 capacities
- Three finishes
- Heat-resistant PETG — holds its shape to about 150°F
- Made to order in your color

Designed by CornerCAD.
