# Laser-Engraved Coaster Workflow — Design Spec

**Date:** 2026-08-08
**Status:** Approved design (pre-implementation-plan)
**Related tasks:** #7 (add laser products to Square), #8 (laser coaster workflow)
**Related memory:** `project_staging-validation-and-prelaunch`, `project_laser-design-bundle`

## 1. Purpose

Turn Bradley's laser assets — a proven coaster process (LightBurn templates + positioning jig) plus a
~3,464-design commercial-licensed bundle — into a **sellable, made-to-order laser-engraved coaster
line** on cornercad.com, without hand-listing thousands of products.

Launch offering: **decorative** (customer picks a ready design) **+ personalized** (customer adds their
own text). Custom-logo/art (customer-supplied files) is explicitly deferred.

## 2. Architecture — the coaster line is Woo-native (deliberate exception)

The rest of the store uses **Square as system of record**, synced into WooCommerce. The coaster line
**cannot** work that way: the design picker and live-text preview require **WooCommerce Product
Add-ons / a product-personalizer plugin**, which Square has no concept of (Square modifiers don't sync
to Woo). Therefore:

- **Coaster products are created and managed *directly in WooCommerce*** — not synced from Square. The
  themed listings (one listing carrying a 15–30-design picker) don't map to Square catalog items anyway.
- **Square's only role for coasters is payment** — checkout runs through the WooCommerce Square gateway,
  so funds still land in the CornerCAD Square account (`LS4SZ98SBX4F6`); only the product/order
  configuration lives in Woo.
- **Made-to-order** — no inventory tracking on the online line.

### Two-channel model (no conflict)
| Channel | Model | System |
|---|---|---|
| **Online** | Made-to-order, configurable (design picker + personalized templates, live preview) | **Woo-native**, paid via Square |
| **Shows / POS** | A curated set of **pre-made** printed/engraved coasters (physical stock) | **Square catalog items** with real inventory, rung up in person |

Square POS never needs to know about the online configurator; the show line is separate pre-made stock.

**Net:** planters/vases = Square-driven · coasters (online) = Woo-driven · shows = Square stock · all
paid via Square.

## 3. Product structure (Woo online line) — launch lean

**Decorative — themed listings** (each = a design picker of ~15–30 curated designs from that bundle
theme):
- Mandala Coasters · Geometric Coasters · Ocean Coasters (Bradley's three proven themes).
- Expand later (Holidays, etc.).

**Personalized — template listings** (text-centric layouts, live preview):
- Monogram Coasters · Family Name / "Established" Coasters.
- Expand later (Initials, Date).

**Material** — an option on each listing: **Slate + Wood** (the laser launch). Plastic coasters are
3D-printed and ride the 3D-Printed side; excluded from the *laser* launch to stay focused (can join as a
material option later).

**Set size** — default **Set of 4**, with a **Single** option. Sets of 6 later if demand warrants.

**Pricing** — flat, made-to-order, per **material × set** (e.g. Slate Set-of-4 = $X, Wood Set-of-4 =
$Y). Personalization **included** (no upcharge) for simplicity. Exact numbers set once material + time
cost are known.

Launch footprint: **3 decorative theme listings + 2 personalized template listings**, each in
slate/wood, single or set of 4.

## 4. Customer experience & personalization engine

**Decorative listing** (e.g. Mandala Coasters): pick a **design** from a visual thumbnail picker → pick
**material** + **set size** → Add to Cart. No text.

**Personalized listing** (e.g. Monogram Coasters): **type text** (name/initials/date) → see a **live
preview** on the coaster template → pick material + set → Add to Cart.

### Engine (plugin) — evaluate on staging, don't lock now
Plain WooCommerce Product Add-ons does image-swatch pickers + text fields but **no live preview**. The
personalized templates need a **product-personalizer plugin** with a live-preview canvas.

**Vetted candidates (WooCommerce/WordPress support confirmed):**
- **Zakeke — LEAD.** Official "Zakeke Interactive Product Designer for WooCommerce" connector plugin in
  the WordPress.org repo (install via Plugins → Add New → "Zakeke"); SaaS backend, actively supported.
  Live preview + order-attached production/proof file. **14-day free trial** → ~$29.90/mo (+ ~1.7%/order).
  The ideal evaluate-first candidate — trial it on staging.
- **Fancy Product Designer – WooCommerce Edition** (CodeCanyon, ~$59 **one-time**) — **FALLBACK.**
  Woo-native, real-time preview, order-attached print file, no monthly fee. ⚠️ **Now maintenance-only —
  no further updates/versions** — a long-term WP/Woo-compatibility risk; use only if avoiding a monthly
  fee outweighs building on a stale plugin.
- **Ruled out for Woo:** **Teeinblue** (Shopify-first; its WooCommerce tier is **~$378/mo** — enterprise
  pricing, not viable for this line). **Customily** (no clear Woo support surfaced).

**Hard selection criteria** (a candidate failing any of these is out):
1. **Live text-on-template preview** for personalized products.
2. **Clean order-line data** — the chosen design + typed text land on the order as readable data
   (e.g. *Design: Mandala-14 · Text: "The Smiths"*).
3. **Rendered proof image attached to the order line item** — the customer's live preview captured as an
   image, so production matches exactly what the customer saw (this is what makes "no manual proof"
   safe — the customer's own preview *is* the proof).
4. Reasonable cost.

The decorative picker can use the same plugin's image-swatch selection, or the simpler/cheaper Product
Add-ons (no preview needed — the thumbnail is the preview).

## 5. Fulfillment flow (order → engraved coaster → ship)

1. **Order lands in Woo** carrying the config as line-item data + the **proof image**: *Design:
   Mandala-14 · Text: "The Smiths" · Material: Slate · Set of 4*. Payment already captured via Square.
2. **Open the LightBurn template** for that material (existing templates + jig). Resolve the **Design ID
   → SVG file** via the map (§6); drop it in. For personalized, drop the template + type the customer's
   text (matched against the proof image).
3. **Engrave** — slate/wood, using the jig for repeatable positioning.
4. **Package + ship** — print the UPS label (current workflow; WooCommerce Shipping/Shippo later); mark
   the Woo order complete.

**Linchpin:** the **design-ID → SVG-file map** (§6) makes the order→template mapping unambiguous —
you never guess which design a customer chose. The **proof image on the order** lets you visually match
the customer's wishes (especially with added text).

A dedicated "production sheet" view (thumbnail + text + material on one screen) is a nice later
efficiency, not a launch blocker — order line-item data is enough.

## 6. Curation & prep (launch groundwork)

1. **Curate a pilot set per theme** — ~15–30 best designs each for Mandala / Geometric / Ocean (ones
   that engrave cleanly at ~4″). Proven winners first, not hundreds.
2. **Build the design-ID → SVG map** — every curated design gets a stable **ID** ("Mandala-14") + a
   **thumbnail** (for the picker) + a **pointer to its SVG** in the bundle. Single source of truth
   linking customer choice → engrave file. Maintain as a spreadsheet/CSV.
3. **Build the personalized templates** — Monogram / Family-Name layouts as SVG/LightBurn templates with
   text zones defined, loaded into the plugin for live preview + proof capture.
4. **Material/size test** — confirm curated picks engrave cleanly on slate + wood at coaster size.
5. **License check** — the bundle is commercial-licensed; **verify the terms cover selling *finished
   engraved products*** (most laser "commercial use" bundles do; some distinguish reselling the *file*
   from selling the *made item*). Keep the license doc on file.

## 7. Catalog / category fit

Ties into the settled category scheme (task #7): **method-primary + Coasters cross-cutting**.
- These online coaster listings live under the **Coasters** top-level category.
- The decorative themes (Mandala/Geometric/Ocean) can be **tags or sub-collections** under Coasters for
  browse/SEO.
- Because slate/wood are laser: these products also carry **Laser Engraved**. (Woo-native products, so
  categories are assigned directly in Woo — the multi-category sync caveat that applies to Square-synced
  products is not a concern here.)

## 8. Out of scope / deferred

- **Custom / photo-upload tier (deferred, but designed):** customer supplies their own photo/art/logo
  (pet photo, business logo, clan crest). Higher-touch, so it uses a **setup fee + manual email proof**,
  *not* self-serve live preview:
  - **Flow:** customer buys a **"$10 Custom Coaster Setup + Proof"** product and uploads their image →
    Bradley converts it in **LightBurn** (greyscale + dithering, tuned per material — see note below) →
    **emails a proof** → customer approves → engrave + ship. The **coaster balance is invoiced via Square
    Invoice** on approval (reuses the same Square Invoices flow used for shows/custom work).
  - **Why the $10 setup:** covers the image-prep/proof labor and filters low-intent requests; Bradley is
    paid for the design effort regardless of whether the order proceeds. Recommended over an all-in-one
    full-price custom listing (where the work happens before the customer can bail).
  - **Greyscale conversion is a LightBurn production step, NOT a web plugin.** LightBurn's Image mode does
    greyscale + dithering (Jarvis/Stucki/Atkinson…) tuned per material; a WordPress greyscale plugin would
    give a flat, un-dithered image that engraves worse. Optional customer-facing engraved-look *preview*
    could come from the personalizer plugin's image filters or a laser-image tool (Imag-R) — polish only;
    the real conversion is always in LightBurn.
  - Note: the launch tiers (decorative vector designs + text) need **no** greyscale — vectors engrave
    directly. Greyscale only applies to this raster/photo tier.
- **Plastic (3D-printed) coasters** in the laser launch — they ride the 3D-Printed side; can join as a
  material option later.
- **Sets of 6**, additional themes/templates, and a production-sheet UI — post-launch expansions.

## 9. Open items to finalize during implementation

1. **Plugin choice** — trial **Zakeke** on staging (14-day free WP.org plugin) against the §4 hard
   criteria (esp. proof-image-on-order + clean order data). Fallback: Fancy Product Designer – Woo
   Edition (one-time, but maintenance-only). Teeinblue ruled out (~$378/mo for Woo); Customily unverified.
2. **Pricing numbers** — per material × set, once material + time cost are known.
3. **Exact launch curation** — which specific designs make the pilot set per theme; the personalized
   template layouts.
4. **License terms** — verify finished-product resale is covered; file the doc.
5. **Show/POS stock** — which pre-made designs to stock as Square catalog items for shows (separate,
   lightweight — not part of the online build).

## 10. Success criteria

- A customer can buy a **decorative** coaster (pick design + material + set) and a **personalized**
  coaster (type text, see live preview) online, paid via Square.
- Each order arrives with **unambiguous production data**: design ID → SVG, personalization text, and a
  **proof image** matching what the customer configured.
- Bradley can fulfill from the order using existing LightBurn templates + jig, with no guesswork about
  which design/text was ordered.
- The line launches lean (3 decorative + 2 personalized listings, slate/wood, single/set-of-4) and
  expands by adding designs/themes/templates without new plumbing.
