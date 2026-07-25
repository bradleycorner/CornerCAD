# CornerCAD — Homepage + Destination Pages (Spec A)

**Date:** 2026-07-25
**Status:** Approved in conversation; pending written-spec review
**Author:** Bradley Corner + Claude
**Applies to:** cornercad.com — WordPress 6.x + WooCommerce 10.9.4
**Branch:** `brainstorm/wordpress-site-buildout`

Companion specs, deliberately split out of this one:
**Spec B — Coaster product line** · **Spec C — Custom Work intake flow** (see [§10](#10-out-of-scope)).

---

## 1. Purpose

Give cornercad.com a real front door. Today the site has no static homepage, no products, and one
media attachment; `woocommerce_coming_soon` is `yes`, so the public sees a holding page.

This spec covers the smallest slice that turns that into a coherent, navigable site: a homepage
built from the approved copy, the three destinations its CTAs point at, the category tree behind
them, and the settings that make the homepage the homepage. It deliberately does **not** depend on
photography, design curation, or Square credentials — all of which sit in later specs.

Success is a visitor who lands on `/`, understands what CornerCAD is within one screen, and can
reach the catalog, the automotive work, the custom-work on-ramp, and the About page without hitting
a dead link or an empty grid.

## 2. Verified platform state (2026-07-25)

Read live over the `cornercad-com` MCP during the design session:

| Fact | Value |
|---|---|
| Active theme at design time | `auto-parts-and-car-accessories` (block/FSE) — **being replaced, see §4** |
| `woocommerce_coming_soon` | `yes` — public sees a holding page |
| Products | `0` |
| Media attachments | `1` |
| `wp_template` customizations | One: **ID 21, "Front Page"**, published, modified 10:22 today |
| `wp_template_part` customizations | None |
| Existing pages | 14 Shop · 15 Cart · 16 Checkout · 17 My account (publish); 3 Privacy Policy · 18 Refund and Returns Policy (draft) |
| `product_cat` terms | `Uncategorized` only |

**The single most important finding:** `wp_template` ID 21 contains **no `wp:post-content` block**.
It is the theme's automotive demo homepage hardcoded into the database — hero reading *"Fits Your
Ride. Fuels Your Drive. / Up to 60% OFF"*, category tiles for *Headlights and Lighting · Car
Accessories · Tools and Equipment* with `href="#"`, and references to attachment IDs (129, 44, 59,
60) that do not exist in a 1-item media library. While that template exists in its current form, a
static Home page's content **cannot render** — the Front Page template takes precedence over all
other templates.

Its two `<!-- wp:template-part /-->` calls carry **no slug attribute**, and there are zero
`wp_template_part` records. That may be rendering no header and no footer at all. Verify during
implementation.

## 3. Decisions locked in this session

| # | Decision | Rationale |
|---|---|---|
| D1 | **General-maker brand lean**, automotive as one strong band | Matches `content/_brand.md`; the auto-parts theme's identity was the tail wagging the dog |
| D2 | **Process-first `product_cat` tree**, coasters nested under Laser Engraved | Coasters *are* laser-engraved; three flat peers would have overlapped |
| D3 | **Hybrid bands** — static category tiles + one real product grid | A `product-collection` block over an empty catalog renders nothing; tiles never look broken |
| D4 | **Scope = Home + Catalog + About + thin Custom Work** | No CTA may dead-end; the intake flow is a separate design problem |
| D5 | **Switch to the SellAny theme** | Only candidate that is FSE *and* `e-commerce`-tagged *and* aimed at artisans/handcraft |
| D6 | **Approach B** — neutralize the Front Page template, build Home as a page | Content survives a theme swap; Bradley changed themes twice in one day |
| D7 | **Theme-agnostic core blocks**, styled via `theme.json` globals | Three candidate themes in play; no bespoke theme classes in page content |
| D8 | **Coasters ship as themed collection products** | 3,484 designs cannot be Woo variations; collections give real catalog depth |
| D9 | **PNG for web, SVG for laser** | WP blocks SVG uploads by default; production files stay local |

## 4. Theme and the template problem

**Activate SellAny** (`sellany`, v1.0.2, updated 2026-06-02; tags include `full-site-editing`,
`e-commerce`, `block-patterns`, `template-editing`). Theme activation is **admin-UI only** — no MCP
tool exists for it, so Bradley performs this step.

Rejected alternatives, recorded so they are not revisited:

- **`auto-parts-and-car-accessories`** — FSE and Woo-capable, and its Front Page skeleton happens to
  match the chosen layout, but its whole identity is an auto-parts store, which contradicts D1.
- **Navid** — the best-looking of the three, but a **classic theme** (confirmed: the Customizer, not
  the Site Editor; wordpress.org tags carry `blog`/`news`/`grid-layout` and lack `full-site-editing`,
  `block-themes`, and `e-commerce`). Its homepage is assembled from Customizer settings over *posts*,
  and key features sit behind a Pro tier plus a companion-plugin upsell. Worth reconsidering later
  for a build-log or project journal, where it would excel.

**Template handling.** After activation, SellAny supplies its own templates and the orphaned
`auto-parts` customization (ID 21) stops applying. Whatever SellAny's Front Page template turns out
to be, the homepage must render page content, so the target state is a Front Page template reduced
to:

```
wp:template-part {"slug":"header"}
wp:post-content
wp:template-part {"slug":"footer"}
```

Customized templates are stored as `wp_template` posts, so this is reachable via `wp_update_post` —
but writing `wp_template` records over MCP is **unproven on this site**. If it fails, the fallback is
the Site Editor in the admin UI, which achieves the same result manually.

**Do not delete template 21.** Leave it orphaned. It is the only record of the auto-parts layout, and
deleting it buys nothing.

## 5. Taxonomy

`product_cat`, created via `wp_create_term`:

```
3D Printed
├─ Automotive
├─ Vases & Planters
└─ Lamps
Laser Engraved
├─ Coasters
└─ Signage
```

Leave `Uncategorized` in place — Woo treats it as the default fallback term.

Automotive is a child of 3D Printed but still gets its own homepage tile and its own band. That is
intentional: it is the highest-credibility line and should not be buried one level down.

Process is the tree; **use** is not modeled as a second taxonomy. If use-based browsing is wanted
later, add `product_tag`, not a competing hierarchy.

## 6. Page inventory

| Page | Action | Source copy |
|---|---|---|
| **Home** | Create, set as front page | `content/home.md` |
| **Catalog** | **Retitle page 14 "Shop" → "Catalog", slug → `/catalog/`** | — |
| **About** | Create | `content/about.md` |
| **Custom Work** | Create — thin, contact CTA only | `content/custom-work.md` |
| Privacy Policy (3) | Publish the existing draft | existing |
| Refund and Returns (18) | Publish the existing draft | existing |
| Cart (15) · Checkout (16) · My account (17) | Untouched | — |

**On Catalog vs Shop:** WooCommerce already owns `/shop/` as the product archive, and the approved
copy links to `/catalog/`. Building a second page would create two archives competing for one job.
Retitling page 14 and changing its slug gives one canonical destination that matches the copy; Woo
tracks the shop page by ID (`woocommerce_shop_page_id`), so the archive keeps working.

Settings: `show_on_front` → `page`, `page_on_front` → the new Home page ID.

Policy pages are published here because payment gateways generally require them live before go-live,
and publishing a finished draft costs nothing now.

## 7. Homepage structure

Seven bands. Copy is verbatim from `content/home.md` unless noted. Every band except #4 renders
correctly with zero products.

### Band 1 — Hero
`wp:cover` over a shop or product photo.

- **Headline:** Made to be used, not unboxed and forgotten.
- **Subhead:** CornerCAD designs and builds functional objects — vases, planters, lamps, coasters,
  and precision automotive parts — designed in FreeCAD and built to keep. These aren't the throwaway
  prints flooding the internet. They're things you'll still be using in five years.
- **Origin line, small:** Designed and built by a maker in Jacksonville, FL.
- **Primary CTA:** Browse the Catalog → `/catalog/`
- **Secondary, text link:** Need something custom? → `/custom-work/`

### Band 2 — Why this is different
Three columns, typography only, no images. This band carries the page while the catalog is thin.

- **Designed, not downloaded.** Every product starts as a real parametric model in FreeCAD —
  dimensioned, tolerance-checked, and built to fit its job.
- **Made on real machines.** Multi-material FDM, high-resolution resin, and CO₂ laser — the right
  process for each product, not one printer forced to do everything.
- **Made to last.** Materials and finishes chosen so the thing survives daily use, not just the first
  twenty minutes.

### Band 3 — Shop by what it is
Three static image tiles, each a `wp:cover` with a heading link. Static by design (D3) — it cannot
render empty.

| Tile | Links to |
|---|---|
| 3D Printed | `/product-category/3d-printed/` |
| Laser Engraved | `/product-category/laser-engraved/` |
| Automotive | `/product-category/3d-printed/automotive/` |

The Automotive URL assumes Woo's default hierarchical `product_cat` permalinks. Resolve each of the
three URLs in a browser after creating the terms and correct the hrefs if the base differs — a tile
linking to a 404 is worse than no tile.

### Band 4 — Things worth keeping
The one live `wp:woocommerce/product-collection`, filtered to **Laser Engraved → Coasters**, 3–4
columns.

- **Heading:** Things worth keeping
- **Intro (launch wording):** Coaster sets in slate and wood — laser-engraved, rubber-footed, and
  built for daily use.

`home.md`'s original intro promises "vases and planters, lamps, and coaster sets," which would be a
lie against a coasters-only query. Restore that broader line once the catalog carries more than one
line; until then the launch wording above is authoritative.

This is the only band gated on Spec B. Until coaster collection products exist it renders nothing —
so **band 4 is added last**, after Spec B lands. Shipping Spec A with bands 1–3 and 5–7 is a valid,
complete state.

### Band 5 — For the build
The automotive band, built **editorially rather than as product cards**: Daytona Coupe and shop
photography plus copy. Bradley has these photos today; he does not yet have automotive products.

- **Heading:** For the build
- **Intro:** Parts that don't exist off the shelf. Designed in FreeCAD and printed for fit and
  function — brackets, dash mounts, brake-cooling ducts, and door cards, developed on my own Daytona
  Coupe before they're offered to anyone else.
- **CTA:** See automotive parts → `/product-category/3d-printed/automotive/`

Note the CTA target differs from `home.md`, which predates the taxonomy decision and points at
`/catalog/?category=automotive`.

### Band 6 — Custom Work teaser
- **Heading:** Have something specific in mind?
- **Body:** I take on custom parametric design and production work — a bracket that doesn't exist
  yet, engraved signage, or a one-off you can't buy anywhere. If you can describe it, I can usually
  design and build it.
- **CTA:** Start a custom project → `/custom-work/`
- **Secondary line:** Buying in volume, or need molds, containers, or a dealer program? →
  **CornerCADWorks.com**

### Band 7 — Footer
Template part, not page content.

- **Tagline:** CornerCAD — designed in FreeCAD, built to keep.
- **Nav:** Home · Catalog · Custom Work · About
- No marketplace or payment-processor branding anywhere. Contact lives on About.

## 8. Authoring conventions

- **Core blocks only** in page content — `cover`, `columns`, `group`, `heading`, `paragraph`,
  `buttons`, `image` — plus WooCommerce blocks where a live query is genuinely needed. No
  theme-specific class names (D7).
- **Styling through `theme.json` globals**, adjusted in Global Styles rather than per-block inline
  CSS, so a theme swap re-skins instead of breaking.
- **Create as `draft`, verify with `wp_get_post_snapshot`, then publish.** WordPress has no
  draft-version rollback like Concrete's; post revisions are the only undo.
- **Every write is confirmed with Bradley first** — tool name, target ID + name, plain-language
  summary — per `CLAUDE.md`. No silent batching.
- **Coming-soon mode stays `yes`** for the entire build and is turned off as a deliberate, separate
  go-live step.

## 9. Assets

- Product and shop photography: Bradley's own photos, uploaded via `wp_upload_media`.
- Design swatches (Spec B): PNGs from the laser bundle. Line art on white — swatches, never product
  shots.
- **Never uploaded:** SVG, DXF, EPS. Production files stay local (D9).
- The laser bundle lives at `~/Documents/Laser/+17K Files Bundle Laser Works Hub`, ~2 GB, local only.
  3,484 designs across 12 categories; **commercial-use license purchased** (confirmed 2026-07-25).

## 10. Out of scope

**Spec B — Coaster product line.** Curate collections and pick designs per set from the 3,484
available; photograph engraved pieces; build each collection as a variable product with material
(Slate/Wood) × shape (Square/Circle/Hex) = 6 variations, `_price` set on every variation; add the
"designs in this set" swatch pattern. Band 4 of the homepage goes live when this lands.

**Spec C — Custom Work intake.** The login-gated request flow, with the open fork (login-first vs.
fill-then-register) still unresolved. WPForms Lite is installed. Spec B's "upload your own image →
quote" path routes into this rather than growing a parallel implementation.

Also out of scope: Square OAuth (admin-UI only), shipping zones and tax, plugin or theme installation,
non-coaster products, and any syndication to social or model-sharing platforms.

## 11. Risks and unknowns

| Risk | Handling |
|---|---|
| SellAny's Front Page template may not include `wp:post-content` | Step 1 of implementation is to inspect it; reduce to header/post-content/footer if needed |
| Writing `wp_template` records over MCP is unproven here | Fall back to the Site Editor in admin UI; same end state |
| Header/footer template parts may currently render nothing | Verify after activation; both themes ship their own parts |
| SellAny's stock look is lighter/craftier than the dark cinematic direction Bradley liked in Navid | Tune Global Styles; revisit only if it can't get close |
| Retitling page 14 could disturb the Woo shop archive | Woo resolves by ID, not slug; verify `woocommerce_shop_page_id` still reads 14 afterward |
| Category tile images need photos that don't exist for every category | Use the strongest available photo per tile; a tile with a weak photo still beats an empty grid |

## 12. Done means

1. SellAny is active and the front page renders page content.
2. `/` shows bands 1, 2, 3, 5, 6, 7 with real copy and no placeholder text.
3. The `product_cat` tree exists exactly as in §5.
4. Every homepage CTA resolves to a real page — no `href="#"`, no 404.
5. Header nav and footer show Home · Catalog · Custom Work · About.
6. `show_on_front` = `page` and `page_on_front` = the Home page ID.
7. About and Custom Work are published from their source copy.
8. Both policy pages are published.
9. Coming-soon mode is still `yes` — go-live is a separate decision.
10. Band 4 is deferred to Spec B and its absence is intentional, not an oversight.
