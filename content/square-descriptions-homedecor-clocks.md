# Square descriptions — Home Decor: clocks

Same format and parser as the other description files.

    python3 scripts/square_push_descriptions.py content/square-descriptions-homedecor-clocks.md

## Cost basis and pricing (confirmed 2026-08-23)

Movement and screws ARE included; ships assembled. Presuming the EMOON movement
(~$2 in the 15-pack) replaces the $7.20 kit, pending Bradley's fit test.

**Base unit (FDM face)**

| | |
|---|---|
| filament | $2.02 (101 g, 3 plates) |
| movement (EMOON) | ~$2.00 |
| 4x M3 screws | ~$0.20 |
| **COGS** | **~$4.22** |
| **print time** | **3h42m** |

**Face material.** The face is 57.675 mm square, so material is negligible — a 300x300
acrylic sheet yields ~25 faces at $0.18 each; walnut lands at $0.15-0.26. What actually
costs is LASER TIME:

| | laser time | material | machine cost @ $14/h |
|---|---|---|---|
| black walnut | 10:21 | $0.26 | ~$2.68 |
| acrylic | 13:00 | $0.18 | ~$3.21 |

⚠️ Acrylic takes LONGER than walnut, so it must not be priced below it. An earlier
draft had that backwards.

**Prices.** Print hours are the binding constraint, so price on return per machine-hour.
The Wall Clock sets the benchmark at ~$13.90/printer-hour ($35, 2h09m, ~$4 COGS).

| Variation | SKU | Price | Rationale |
|---|---|---|---|
| FDM face | `CAD-CLK-0005-FDM` | **$55** | $13.30/printer-hour — parity |
| Acrylic face | `CAD-CLK-0005-ACR` | **$67** | +$12 |
| Black walnut face | `CAD-CLK-0005-WAL` | **$70** | +$15, the premium option |

Cost-based floor for the upgrades is only ~$3-4 each; the rest is positioning. Hardwood
is what people pay for, so walnut sits highest even though it cuts faster.

**Batch the faces.** 25 come off one sheet. Cutting them per-order instead of per-sheet is
where the margin actually leaks — same logic as batching print plates.

**Variations, not modifiers.** Face material is a single dimension, so the sync accepts it and
it reaches cornercad.com. Each needs its own SKU (`has_sku()` gates the sync). Do NOT add a
second axis — smooth/textured case must stay a modifier or the product stops syncing entirely.

---

## Vexel Clock — CAD-CLK-0005

### Summary

A modern minimalist desk clock built around an exposed geometric case, with a face you choose:
3D printed, laser-cut black walnut, or laser-cut acrylic. Roughly 100 mm wide and 96 mm tall —
sized for a desk, a shelf, or a bedside table. Arrives assembled and running.

### Details

The **Vexel Desk Clock** puts the structure on show. A sculptural printed case carries the
movement, and the face sits proud on the front, fixed with four screws so the join reads as
deliberate rather than hidden.

Every clock is made to order:

- **Choose Your Face Material:** 3D printed in a color of your choice, laser-cut from 3 mm black walnut plywood for warmth and visible grain, or laser-cut from 3 mm acrylic for a crisp, glossy finish.
- **Laser-Cut In House:** The wood and acrylic faces are cut and engraved on our own laser, not outsourced — so the finish and the fit are ours to control.
- **Textured or Smooth Case:** The case can be printed with a fine textured surface or left smooth, your choice when you order.
- **Desk-Scaled:** About 100 mm wide, 67 mm deep and 96 mm tall (roughly 3.9" × 2.6" × 3.8").
- **Assembled and Running:** Supplied fully assembled with the movement fitted and the dials set. Takes a single battery, not included.

Crafted with precision, sustainable materials, and attention to detail.

Designed by h3li0.
