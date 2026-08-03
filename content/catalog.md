# Catalog landing — CornerCAD.com

> Standard page (spec §4): Intro + Page List block (page type = Product) with topic filter for categories.
> Voice from `_brand.md`. Category filter pulls from the Product Categories topic tree.

---

## Page body

### Heading
**The Catalog**

**Intro:** Everything here is designed in FreeCAD and made to order — built when you buy it, in the material
and finish you choose. Browse by category, or scroll the full collection below.

---

### Category filter
*(Topic filter UI — pulls from the Product Categories tree. Reads as a row of filters above the product grid.)*

- **All**
- **Home & Garden** — vases, planters, lamps
- **Display & Retail** — coasters, stands, décor in print / wood / slate
- **Automotive** — parts developed and tested on a real car *(→ also featured on the Home page)*
- **Functional / Hardware** — brackets, mounts, and parts that solve a problem

*(Optional secondary tag surfaced here: **h3li0 (licensed)** — so licensed designs are browsable as their
own set. Keep this filter available but understated.)*

---

### Product grid
*(Concrete **Page List** block, filtered to page type = Product, honoring the selected category topic.)*

Each card renders from the Product page: featured image, `product_short_desc`, price (if `buy`), and its CTA.
No copy needed here — cards pull per product. Cards for licensed items carry the "designed by h3li0" credit.

**Empty/short-catalog note (while the catalog is small):** A short intro line can reassure early visitors —
e.g. "New pieces are added regularly — check back, or follow along on [Creality Cloud]." Remove once the grid
fills out.

---

### Footer CTA
**Line:** Don't see quite what you need? I take on custom work. → `/custom-work/`

---

### Notes
- Category names mirror the Product Categories topic tree in the `cornercad_setup` package. If we rename a
  category, rename it in the package, not just here.
- Automotive appears both here (as a filter) and on Home (as a featured band) — intentional.
