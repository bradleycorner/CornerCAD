# CornerCAD Homepage + Destination Pages — Implementation Plan (Spec A)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn cornercad.com from a blog index with no products into a coherent site with a real homepage, a catalog destination, an About page, and a Custom Work on-ramp — built on staging, verified, then pushed.

**Architecture:** Page content is authored as Gutenberg block markup in **files in this repo** (`content/blocks/*.html`), then pushed to WordPress over the `cornercad-com` MCP. The repo is the source of truth and the review surface; MCP is only the delivery mechanism. This mirrors the FreeCAD macro convention in the global `CLAUDE.md` — changes arrive as reviewable, re-runnable artifacts rather than opaque edits. The site's own Front Page template is reduced to `header + post-content + footer` so the homepage lives in a page and survives a theme swap.

**Tech Stack:** WordPress 6.x · WooCommerce 10.9.4 · SellAny block theme (FSE) · `cornercad-com` MCP (AI Engine) · `woocommerce` MCP · Bluehost staging.

## Global Constraints

Every task's requirements implicitly include this section.

- **Confirm every write with Bradley before calling it** — tool name, exact target (ID + name), plain-language summary. Never batch or chain writes silently. (`CLAUDE.md`)
- **Build on staging** (`https://cornercad.com/staging/6862`), not production, once Task 1 succeeds.
- **Staging is the single source of truth once cloned.** No parallel production edits — Bluehost's push replaces rather than merges.
- **`woocommerce_coming_soon` stays `"yes"` on production** for the whole plan. Turning it off is a separate go-live decision, not a step here.
- **Create as `draft`, verify with `wp_get_post_snapshot`, then publish.** No draft-version rollback exists in WordPress.
- **Core blocks only** in page content — no bespoke theme CSS classes (`banner-section`, `category-box`, `is-style-button-light`). `theme.json` preset classes (`has-x-large-font-size`, `var(--wp--preset--spacing--large)`) are fine; they are the sanctioned mechanism.
- **PNG only into the media library.** Never upload SVG, DXF, or EPS — production files stay local.
- **Never print the AI Engine bearer token** into the transcript, a file, or a commit.
- **`wp_add_post_terms` defaults to `append: false`** and replaces every term in that taxonomy. Pass `append: true` when adding.
- **Read with `content_format: "full"`** when inspecting block attributes. `"prose"` strips attribute JSON and will make correct markup look broken.
- Brand copy is authoritative in `content/home.md`, `content/about.md`, `content/custom-work.md`, `content/_brand.md`. Do not improvise marketing copy.

---

## File Structure

| File | Responsibility |
|---|---|
| `content/blocks/front-page-template.html` | The neutralized Front Page template body (header/post-content/footer) |
| `content/blocks/home.html` | Homepage bands 1, 2, 3, 5, 6 as block markup, with `__TOKEN__` placeholders for attachment IDs |
| `content/blocks/about.html` | About page block markup |
| `content/blocks/custom-work.html` | Custom Work page block markup (thin, contact CTA) |
| `content/blocks/media-ids.env` | Attachment IDs and URLs recorded at upload time; consumed by the substitution step |
| `content/blocks/render.sh` | Substitutes `media-ids.env` values into a tokenized block file, emitting final markup |
| `docs/wordpress-mcp-protocol.md` | Modified — add the staging server entry and the `content_format` caveat |
| `CLAUDE.md` | Modified — correct the theme record, add staging, add the blocks-in-repo convention |

---

## Task 1: Reach staging over MCP

Nothing downstream is safe until the tools demonstrably hit staging rather than production.

**Files:**
- Modify: `~/.claude.json` (MCP server registry, project scope — **contains secrets, never print it**)
- Modify: `docs/wordpress-mcp-protocol.md`

**Interfaces:**
- Produces: an MCP server named `cornercad-staging` whose `mcp_ping` returns the staging site, used by every later task.

- [ ] **Step 1: Confirm the clone finished**

```bash
curl -sS -o /dev/null -w '%{http_code}\n' -L --max-time 25 "https://cornercad.com/staging/6862/"
```

Expected: `200`. If `500`, the clone is still running — wait and retry. Do not debug it; a 500 during provisioning is normal.

- [ ] **Step 2: Read the existing MCP entry**

Read the `cornercad-com` entry under the CornerCAD project in `~/.claude.json`. Note its transport, command/URL shape, and header names. **Do not echo the token value.**

- [ ] **Step 3: Add the staging entry**

Duplicate the `cornercad-com` entry as `cornercad-staging`, changing only the base URL from `https://cornercad.com/...` to `https://cornercad.com/staging/6862/...`. The bearer token carries over — the clone copies the options table, so AI Engine's token is identical.

- [ ] **Step 4: Reload MCP and verify the target**

Restart Claude Code, then call `mcp__cornercad-staging__mcp_ping`.

Expected: a response naming the site. Then run the discriminator — staging and production have different template IDs:

Call `mcp__cornercad-staging__wp_get_posts` with `post_type: "wp_template"`, `post_status: "any"`.

Expected: Front Page records present. Record their IDs — **these are the staging IDs and they may differ from production's 21/36.** Every later task uses staging IDs.

- [ ] **Step 5: Prove writes land on staging, not production**

Call `mcp__cornercad-staging__wp_create_post` with `post_title: "MCP target check"`, `post_type: "post"`, `post_status: "draft"`. Record the returned ID.

Then call `mcp__cornercad-com__wp_get_post` (production) with that same ID.

Expected: production returns **not found**, or a different post. If production returns a post with that title, the staging entry is misconfigured — **stop and fix Task 1 before doing anything else.**

- [ ] **Step 6: Delete the check post**

Call `mcp__cornercad-staging__wp_delete_post` with that ID and `force: true`.

- [ ] **Step 7: Document and commit**

Add the `cornercad-staging` server to `docs/wordpress-mcp-protocol.md` §0.2 (name, backing URL, purpose) and add the `content_format: "prose"` caveat to the read-tools table.

```bash
git add docs/wordpress-mcp-protocol.md
git commit -m "docs(mcp): register the staging server and the prose read caveat"
```

**If Task 1 cannot be completed** (staging unreachable, or the token doesn't work there), fall back to building on production with coming-soon on. Say so explicitly, and substitute `cornercad-com` for `cornercad-staging` in every later task.

---

## Task 2: Build the product_cat tree

**Files:**
- No repo files. Site state only.

**Interfaces:**
- Produces: term IDs and verified archive URLs for `3d-printed`, `laser-engraved`, `automotive`, `coasters`, `signage`, `vases-planters`, `lamps` — consumed by Task 5's tile hrefs.

- [ ] **Step 1: Read the current tree**

Call `mcp__cornercad-staging__wp_get_terms` with `taxonomy: "product_cat"`.

Expected: only `Uncategorized`.

- [ ] **Step 2: Create the two parents**

Call `wp_create_term` twice on `product_cat`:

| name | slug |
|---|---|
| `3D Printed` | `3d-printed` |
| `Laser Engraved` | `laser-engraved` |

Record both term IDs.

- [ ] **Step 3: Create the five children**

Call `wp_create_term` five times, each with `parent` set to the recorded ID:

| name | slug | parent |
|---|---|---|
| `Automotive` | `automotive` | 3D Printed |
| `Vases & Planters` | `vases-planters` | 3D Printed |
| `Lamps` | `lamps` | 3D Printed |
| `Coasters` | `coasters` | Laser Engraved |
| `Signage` | `signage` | Laser Engraved |

- [ ] **Step 4: Verify the hierarchy**

Call `wp_get_terms` on `product_cat` again.

Expected: seven new terms, each child reporting the correct `parent` ID. Leave `Uncategorized` alone — Woo uses it as the default fallback.

- [ ] **Step 5: Resolve the real archive URLs**

The spec assumes Woo's hierarchical permalinks. Verify rather than assume — a tile linking to a 404 is worse than no tile.

```bash
for u in \
  "https://cornercad.com/staging/6862/product-category/3d-printed/" \
  "https://cornercad.com/staging/6862/product-category/laser-engraved/" \
  "https://cornercad.com/staging/6862/product-category/3d-printed/automotive/" ; do
  printf '%s -> ' "$u"; curl -sS -o /dev/null -w '%{http_code}\n' -L --max-time 25 "$u"
done
```

Expected: `200` for all three. If Automotive 404s, hierarchical category permalinks are off — use `/product-category/automotive/` instead and note the correction. **Record the three working paths; Task 5 uses them verbatim.**

---

## Task 3: Upload imagery

Bradley supplies the files. This task cannot invent them.

**Files:**
- Create: `content/blocks/media-ids.env`

**Interfaces:**
- Produces: `HERO_ID`/`HERO_URL`, `TILE_3DP_ID`/`TILE_3DP_URL`, `TILE_LASER_ID`/`TILE_LASER_URL`, `TILE_AUTO_ID`/`TILE_AUTO_URL`, `AUTO_BAND_ID`/`AUTO_BAND_URL` — consumed by Task 5.

- [ ] **Step 1: Get the file paths from Bradley**

Ask for five images, by absolute local path:

| Slot | Content | Guidance |
|---|---|---|
| Hero | Shop or product wide shot | Landscape, ≥2000px wide, works with a 60% dark overlay and white text over it |
| Tile — 3D Printed | A vase, planter, or lamp | Portrait or square |
| Tile — Laser Engraved | An engraved coaster or sign | Portrait or square |
| Tile — Automotive | A printed car part, ideally fitted | Portrait or square |
| Automotive band | Daytona Coupe or shop scene | Landscape |

All must be **JPG, PNG, or WEBP**. If Bradley offers an SVG, decline and ask for a raster export.

- [ ] **Step 2: Verify each file before upload**

```bash
for f in "$HERO" "$TILE_3DP" "$TILE_LASER" "$TILE_AUTO" "$AUTO_BAND"; do
  printf '%s -> ' "$f"; file -b "$f" 2>/dev/null || echo MISSING
done
```

Expected: each reports JPEG/PNG/WebP image data with dimensions. Any `MISSING` or non-raster type stops this task.

- [ ] **Step 3: Upload each**

Call `mcp__cornercad-staging__wp_upload_media` once per file. Set a descriptive `title` and real `alt_text` — alt text is an accessibility requirement, not decoration:

| Slot | alt text |
|---|---|
| Hero | `A CornerCAD workshop bench with finished printed and engraved pieces` |
| Tile — 3D Printed | `A 3D-printed vase` |
| Tile — Laser Engraved | `A laser-engraved slate coaster` |
| Tile — Automotive | `A 3D-printed automotive bracket fitted to a car` |
| Automotive band | `The Daytona Coupe the automotive parts are developed on` |

Record each returned attachment ID and source URL.

- [ ] **Step 4: Write the ID file**

```bash
cat > content/blocks/media-ids.env <<'ENV'
# Attachment IDs and URLs on STAGING. Regenerate after any re-clone —
# IDs are not stable across a staging rebuild.
HERO_ID=
HERO_URL=
TILE_3DP_ID=
TILE_3DP_URL=
TILE_LASER_ID=
TILE_LASER_URL=
TILE_AUTO_ID=
TILE_AUTO_URL=
AUTO_BAND_ID=
AUTO_BAND_URL=
ENV
```

Fill in the recorded values.

- [ ] **Step 5: Verify the count**

Call `mcp__cornercad-staging__wp_count_media`.

Expected: `6` — the one pre-existing attachment plus five new.

- [ ] **Step 6: Commit**

```bash
git add content/blocks/media-ids.env
git commit -m "chore(content): record staging attachment IDs for homepage imagery"
```

---

## Task 4: Neutralize the Front Page template

Until this lands, homepage content cannot render at all — the Front Page template takes precedence over every other template, and SellAny's does not include `wp:post-content`.

**Files:**
- Create: `content/blocks/front-page-template.html`

**Interfaces:**
- Consumes: the staging Front Page template ID recorded in Task 1 Step 4.
- Produces: a front page that renders whatever page is assigned to it.

- [ ] **Step 1: Read the current template with full attributes**

Call `mcp__cornercad-staging__wp_get_post` with the staging Front Page ID and `content_format: "full"`.

Expected: the porcelain demo, and — critically — `wp:template-part` blocks **with** their `slug` attributes visible (`header`, `footer`). Record the exact slugs and any `tagName`/`theme` attributes. `content_format: "prose"` would hide these; that is why this step specifies `full`.

- [ ] **Step 2: Write the replacement**

```bash
cat > content/blocks/front-page-template.html <<'HTML'
<!-- wp:template-part {"slug":"header","tagName":"header"} /-->

<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->
<main class="wp-block-group"><!-- wp:post-content {"layout":{"type":"constrained"}} /--></main>
<!-- /wp:group -->

<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->
HTML
```

If Step 1 reported different slugs, correct them here before proceeding.

- [ ] **Step 3: Push it**

Call `mcp__cornercad-staging__wp_update_post` with the staging Front Page template ID and `post_content` set to the file's contents.

This is the step the spec flags as unproven — writing a `wp_template` record over MCP. If it errors or silently no-ops, fall back to the Site Editor: **Appearance → Editor → Templates → Front Page**, replace the content with the same three blocks, save. Same end state.

- [ ] **Step 4: Verify the write took**

Call `wp_get_post` on the template ID with `content_format: "full"`.

Expected: exactly the three blocks. No cover, no product-collection, no query loop.

- [ ] **Step 5: Prove it renders page content**

Create a throwaway page and point the front page at it:

1. `wp_create_post` — `post_title: "Render probe"`, `post_type: "page"`, `post_status: "publish"`, `post_content: "<!-- wp:paragraph --><p>RENDER-PROBE-OK</p><!-- /wp:paragraph -->"`. Record the ID.
2. `wp_update_option` — `show_on_front` → `page`
3. `wp_update_option` — `page_on_front` → that ID

```bash
curl -sS -L --max-time 25 "https://cornercad.com/staging/6862/" | grep -c 'RENDER-PROBE-OK'
```

Expected: `1` or more. **If `0`, the template is still winning** — do not continue to Task 5; the rest of the plan depends on this working.

- [ ] **Step 6: Clean up the probe**

`wp_delete_post` on the probe page with `force: true`. Leave `show_on_front` as `page`; Task 5 sets `page_on_front` to the real Home page.

- [ ] **Step 7: Commit**

```bash
git add content/blocks/front-page-template.html
git commit -m "feat(site): neutralize the Front Page template so page content renders"
```

---

## Task 5: Build the homepage

**Files:**
- Create: `content/blocks/home.html`
- Create: `content/blocks/render.sh`

**Interfaces:**
- Consumes: `content/blocks/media-ids.env` (Task 3), the verified archive paths (Task 2 Step 5).
- Produces: the Home page ID, consumed by Task 9's nav.

- [ ] **Step 1: Write the renderer**

```bash
cat > content/blocks/render.sh <<'SH'
#!/usr/bin/env bash
# Substitute media-ids.env values into a tokenized block file.
# Usage: content/blocks/render.sh content/blocks/home.html
set -euo pipefail
# Resolve the input path BEFORE cd, or a relative argument breaks.
src="$(cd "$(dirname "$1")" && pwd)/$(basename "$1")"
cd "$(dirname "$0")"
[ -f media-ids.env ] || { echo "media-ids.env missing — run Task 3" >&2; exit 1; }
set -a; . ./media-ids.env; set +a
out="$(cat "$src")"
for k in HERO TILE_3DP TILE_LASER TILE_AUTO AUTO_BAND; do
  for s in ID URL; do
    v="${k}_${s}"
    [ -n "${!v:-}" ] || { echo "$v is empty in media-ids.env" >&2; exit 1; }
    out="${out//__${v}__/${!v}}"
  done
done
case "$out" in *__*_ID__*|*__*_URL__*) echo "unsubstituted tokens remain" >&2; exit 1;; esac
printf '%s' "$out"
SH
chmod +x content/blocks/render.sh
```

- [ ] **Step 2: Write the homepage markup**

Copy is verbatim from `content/home.md` and the spec. Band 4 is intentionally absent — it belongs to Spec B.

```bash
cat > content/blocks/home.html <<'HTML'
<!-- wp:cover {"url":"__HERO_URL__","id":__HERO_ID__,"dimRatio":60,"customOverlayColor":"#000103","isUserOverlayColor":true,"minHeight":34,"minHeightUnit":"rem","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|x-large","bottom":"var:preset|spacing|x-large"}}}} -->
<div class="wp-block-cover alignfull" style="padding-top:var(--wp--preset--spacing--x-large);padding-bottom:var(--wp--preset--spacing--x-large);min-height:34rem"><img class="wp-block-cover__image-background wp-image-__HERO_ID__" alt="A CornerCAD workshop bench with finished printed and engraved pieces" src="__HERO_URL__" data-object-fit="cover"/><span aria-hidden="true" class="wp-block-cover__background has-background-dim-60 has-background-dim" style="background-color:#000103"></span><div class="wp-block-cover__inner-container"><!-- wp:heading {"level":1,"fontSize":"x-large"} -->
<h1 class="wp-block-heading has-x-large-font-size">Made to be used, not unboxed and forgotten.</h1>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>CornerCAD designs and builds functional objects — vases, planters, lamps, coasters, and precision automotive parts — designed in FreeCAD and built to keep. These aren't the throwaway prints flooding the internet. They're things you'll still be using in five years.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"fontSize":"x-small"} -->
<p class="has-x-small-font-size">Designed and built by a maker in Jacksonville, FL.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/catalog/">Browse the Catalog</a></div>
<!-- /wp:button -->

<!-- wp:button {"className":"is-style-outline"} -->
<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="/custom-work/">Need something custom?</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div></div>
<!-- /wp:cover -->

<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|large","bottom":"var:preset|spacing|large"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--large);padding-bottom:var(--wp--preset--spacing--large)"><!-- wp:columns {"align":"wide"} -->
<div class="wp-block-columns alignwide"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":3,"fontSize":"base"} -->
<h3 class="wp-block-heading has-base-font-size">Designed, not downloaded.</h3>
<!-- /wp:heading -->

<!-- wp:paragraph {"fontSize":"x-small"} -->
<p class="has-x-small-font-size">Every product starts as a real parametric model in FreeCAD — dimensioned, tolerance-checked, and built to fit its job.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":3,"fontSize":"base"} -->
<h3 class="wp-block-heading has-base-font-size">Made on real machines.</h3>
<!-- /wp:heading -->

<!-- wp:paragraph {"fontSize":"x-small"} -->
<p class="has-x-small-font-size">Multi-material FDM, high-resolution resin, and CO₂ laser — the right process for each product, not one printer forced to do everything.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":3,"fontSize":"base"} -->
<h3 class="wp-block-heading has-base-font-size">Made to last.</h3>
<!-- /wp:heading -->

<!-- wp:paragraph {"fontSize":"x-small"} -->
<p class="has-x-small-font-size">Materials and finishes chosen so the thing survives daily use, not just the first twenty minutes.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></div>
<!-- /wp:group -->

<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|large","bottom":"var:preset|spacing|large"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--large);padding-bottom:var(--wp--preset--spacing--large)"><!-- wp:heading {"textAlign":"center","fontSize":"medium"} -->
<h2 class="wp-block-heading has-text-align-center has-medium-font-size">Shop by what it is</h2>
<!-- /wp:heading -->

<!-- wp:columns {"align":"wide"} -->
<div class="wp-block-columns alignwide"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:cover {"url":"__TILE_3DP_URL__","id":__TILE_3DP_ID__,"dimRatio":40,"customOverlayColor":"#000103","isUserOverlayColor":true,"minHeight":22,"minHeightUnit":"rem"} -->
<div class="wp-block-cover" style="min-height:22rem"><img class="wp-block-cover__image-background wp-image-__TILE_3DP_ID__" alt="A 3D-printed vase" src="__TILE_3DP_URL__" data-object-fit="cover"/><span aria-hidden="true" class="wp-block-cover__background has-background-dim-40 has-background-dim" style="background-color:#000103"></span><div class="wp-block-cover__inner-container"><!-- wp:heading {"textAlign":"center","level":3,"fontSize":"base"} -->
<h3 class="wp-block-heading has-text-align-center has-base-font-size"><a href="__PATH_3DP__">3D Printed</a></h3>
<!-- /wp:heading --></div></div>
<!-- /wp:cover --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:cover {"url":"__TILE_LASER_URL__","id":__TILE_LASER_ID__,"dimRatio":40,"customOverlayColor":"#000103","isUserOverlayColor":true,"minHeight":22,"minHeightUnit":"rem"} -->
<div class="wp-block-cover" style="min-height:22rem"><img class="wp-block-cover__image-background wp-image-__TILE_LASER_ID__" alt="A laser-engraved slate coaster" src="__TILE_LASER_URL__" data-object-fit="cover"/><span aria-hidden="true" class="wp-block-cover__background has-background-dim-40 has-background-dim" style="background-color:#000103"></span><div class="wp-block-cover__inner-container"><!-- wp:heading {"textAlign":"center","level":3,"fontSize":"base"} -->
<h3 class="wp-block-heading has-text-align-center has-base-font-size"><a href="__PATH_LASER__">Laser Engraved</a></h3>
<!-- /wp:heading --></div></div>
<!-- /wp:cover --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:cover {"url":"__TILE_AUTO_URL__","id":__TILE_AUTO_ID__,"dimRatio":40,"customOverlayColor":"#000103","isUserOverlayColor":true,"minHeight":22,"minHeightUnit":"rem"} -->
<div class="wp-block-cover" style="min-height:22rem"><img class="wp-block-cover__image-background wp-image-__TILE_AUTO_ID__" alt="A 3D-printed automotive bracket fitted to a car" src="__TILE_AUTO_URL__" data-object-fit="cover"/><span aria-hidden="true" class="wp-block-cover__background has-background-dim-40 has-background-dim" style="background-color:#000103"></span><div class="wp-block-cover__inner-container"><!-- wp:heading {"textAlign":"center","level":3,"fontSize":"base"} -->
<h3 class="wp-block-heading has-text-align-center has-base-font-size"><a href="__PATH_AUTO__">Automotive</a></h3>
<!-- /wp:heading --></div></div>
<!-- /wp:cover --></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></div>
<!-- /wp:group -->

<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|large","bottom":"var:preset|spacing|large"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--large);padding-bottom:var(--wp--preset--spacing--large)"><!-- wp:columns {"align":"wide","verticalAlignment":"center"} -->
<div class="wp-block-columns alignwide are-vertically-aligned-center"><!-- wp:column {"verticalAlignment":"center","width":"55%"} -->
<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:55%"><!-- wp:image {"id":__AUTO_BAND_ID__,"sizeSlug":"large"} -->
<figure class="wp-block-image size-large"><img src="__AUTO_BAND_URL__" alt="The Daytona Coupe the automotive parts are developed on" class="wp-image-__AUTO_BAND_ID__"/></figure>
<!-- /wp:image --></div>
<!-- /wp:column -->

<!-- wp:column {"verticalAlignment":"center","width":"45%"} -->
<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:45%"><!-- wp:heading {"fontSize":"medium"} -->
<h2 class="wp-block-heading has-medium-font-size">For the build</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Parts that don't exist off the shelf. Designed in FreeCAD and printed for fit and function — brackets, dash mounts, brake-cooling ducts, and door cards, developed on my own Daytona Coupe before they're offered to anyone else.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"className":"is-style-outline"} -->
<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="__PATH_AUTO__">See automotive parts</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></div>
<!-- /wp:group -->

<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|large","bottom":"var:preset|spacing|large"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--large);padding-bottom:var(--wp--preset--spacing--large)"><!-- wp:heading {"textAlign":"center","fontSize":"medium"} -->
<h2 class="wp-block-heading has-text-align-center has-medium-font-size">Have something specific in mind?</h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center"} -->
<p class="has-text-align-center">I take on custom parametric design and production work — a bracket that doesn't exist yet, engraved signage, or a one-off you can't buy anywhere. If you can describe it, I can usually design and build it.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/custom-work/">Start a custom project</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->

<!-- wp:paragraph {"align":"center","fontSize":"x-small"} -->
<p class="has-text-align-center has-x-small-font-size">Buying in volume, or need molds, containers, or a dealer program? → <a href="https://cornercadworks.com">CornerCADWorks.com</a></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
HTML
```

- [ ] **Step 3: Substitute the category paths**

`render.sh` handles media tokens; the three archive paths come from Task 2 Step 5. Replace them with the **verified** paths:

```bash
cd content/blocks
sed -i '' \
  -e 's|__PATH_3DP__|/product-category/3d-printed/|g' \
  -e 's|__PATH_LASER__|/product-category/laser-engraved/|g' \
  -e 's|__PATH_AUTO__|/product-category/3d-printed/automotive/|g' \
  home.html
grep -c '__PATH_' home.html
```

Expected: `0`. If Task 2 Step 5 found different working paths, use those instead.

- [ ] **Step 4: Render and check for leftovers**

```bash
./content/blocks/render.sh content/blocks/home.html > /tmp/home-rendered.html
grep -c '__' /tmp/home-rendered.html
```

Expected: `0`. Any non-zero means a token went unsubstituted and the page would ship broken markup.

- [ ] **Step 5: Create the page as a draft**

Call `mcp__cornercad-staging__wp_create_post`:
- `post_title`: `Home`
- `post_type`: `page`
- `post_status`: `draft`
- `post_content`: the contents of `/tmp/home-rendered.html`

Record the Home page ID.

- [ ] **Step 6: Verify the stored content**

Call `wp_get_post_snapshot` on the Home page ID.

Expected: content matches what was sent; no `__` tokens; the five image references carry real attachment IDs.

- [ ] **Step 7: Publish and wire the front page**

1. `wp_update_post` — Home page ID, `post_status: "publish"`
2. `wp_update_option` — `page_on_front` → the Home page ID
3. `wp_update_option` — confirm `show_on_front` is `page`

- [ ] **Step 8: Verify the rendered page**

```bash
curl -sS -L --max-time 25 "https://cornercad.com/staging/6862/" -o /tmp/home-live.html
for s in "Made to be used" "Designed, not downloaded" "Shop by what it is" "For the build" "Have something specific"; do
  printf '%-32s %s\n' "$s" "$(grep -c "$s" /tmp/home-live.html)"
done
echo "--- porcelain demo leftovers (must be 0) ---"
grep -c "Fine Porcelain\|New Arrivals\|Free Shipping" /tmp/home-live.html
echo "--- dead links (must be 0) ---"
grep -c 'href="#"' /tmp/home-live.html
```

Expected: every band string ≥1, demo leftovers `0`, dead links `0`.

- [ ] **Step 9: Look at it**

Open `https://cornercad.com/staging/6862/` in a browser. Check that hero text is legible over the image at the 60% overlay, the three tiles are equal height, and it holds together at phone width. Adjust `dimRatio` or `minHeight` in `home.html` and re-run Steps 4–7 if not.

- [ ] **Step 10: Commit**

```bash
git add content/blocks/home.html content/blocks/render.sh
git commit -m "feat(content): homepage block markup — bands 1,2,3,5,6"
```

---

## Task 6: Retitle Shop to Catalog

**Files:**
- No repo files. Site state only.

**Interfaces:**
- Consumes: nothing.
- Produces: a working `/catalog/` that the homepage's primary CTA already points at.

- [ ] **Step 1: Record the current shop page setting**

Call `mcp__cornercad-staging__wp_get_option` with `key: "woocommerce_shop_page_id"`.

Expected: `14` (or the staging equivalent). Record it — Step 4 confirms it is unchanged.

- [ ] **Step 2: Retitle and re-slug**

Call `wp_update_post` on the shop page: `post_title: "Catalog"`, `post_name: "catalog"`.

- [ ] **Step 3: Verify the page**

Call `wp_get_post_snapshot` on that ID.

Expected: title `Catalog`, slug `catalog`, status `publish`.

- [ ] **Step 4: Confirm Woo still points at it**

Call `wp_get_option` on `woocommerce_shop_page_id` again.

Expected: the same ID as Step 1. Woo resolves the shop by ID, so the rename is safe — this step proves it rather than assuming it.

- [ ] **Step 5: Verify both URLs**

```bash
printf 'catalog -> '; curl -sS -o /dev/null -w '%{http_code}\n' -L --max-time 25 "https://cornercad.com/staging/6862/catalog/"
printf 'old shop -> '; curl -sS -o /dev/null -w '%{http_code} (final: %{url_effective})\n' -L --max-time 25 "https://cornercad.com/staging/6862/shop/"
```

Expected: `/catalog/` returns `200`. `/shop/` may 404 or redirect; either is acceptable since nothing links to it and the site has never been public.

---

## Task 7: Build the About page

**Files:**
- Create: `content/blocks/about.html`

**Interfaces:**
- Produces: the About page ID, consumed by Task 9's nav.

- [ ] **Step 1: Resolve the open placeholders with Bradley**

`content/about.md` is **not finished copy** — it carries four bracketed items its author explicitly refused to invent. Ask Bradley for each and record the answer:

| Placeholder | Question | Default if he declines |
|---|---|---|
| Backstory | One or two sentences on how you started — engineering background, hobby that grew, how long you've been designing | Omit the paragraph entirely |
| Events | Which local markets or events do you sell at? | Keep the drafted generic line, "select Jacksonville-area events" |
| Creality Cloud | The URL to your profile | Omit the "Also find my designs on" bullet |
| Photo | A shot of you at the machines, or the workspace | Omit the image block |

Do not invent any of these. A fabricated backstory on an About page is worse than a shorter page.

- [ ] **Step 2: Write the markup**

The copy below is `about.md`'s body with the optional items omitted — the default resolution. Steps 3 and 4 add back whatever Bradley supplied.

**On the contact form:** `about.md` specifies a form delivering to `brad@cornercad.com`, and notes it needs SMTP configured plus Proton SPF/DKIM records or the mail lands in spam. Neither is MCP-reachable, and **a contact form that silently fails is worse than no form**. This page ships with a `mailto:` link; the WPForms build plus mail deliverability is a follow-up, recorded under "Deferred" at the end of this plan.

```bash
cat > content/blocks/about.html <<'HTML'
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|large","bottom":"var:preset|spacing|large"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--large);padding-bottom:var(--wp--preset--spacing--large)"><!-- wp:heading {"level":1,"fontSize":"x-large"} -->
<h1 class="wp-block-heading has-x-large-font-size">A maker in Jacksonville, building things worth keeping.</h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"fontSize":"x-small"} -->
<p class="has-x-small-font-size">Parametric design, made physical.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>CornerCAD started from a simple frustration: most 3D-printed products are novelties. They look clever for about twenty minutes, then live in a drawer. I wanted to make the opposite — objects that earn their place on a shelf, a desk, or a car, and stay there.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Everything here begins in FreeCAD as a real parametric model — dimensioned, tolerance-checked, and designed to do its job before a printer ever turns on. Then it's made on the right machine for the material: a Creality K2 Plus for functional multi-material prints, an Elegoo Saturn 16K for fine-detail resin work, and a Longer Ray5 laser for engraving wood and slate. One printer forced to do everything is how you get mediocre everything; matching the process to the product is how you get something that lasts.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Automotive parts are one example of that standard in action. Brackets, dash mounts, brake-cooling ducts, and door cards get designed and tested on my own Daytona Coupe before they're offered to anyone else — if a part can't survive heat, vibration, and daily use on a real car, it doesn't ship. But the same rule applies to a vase, a planter, or a set of coasters: it should earn its place and keep it.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Most of what I make is <strong>made to order</strong>. When you buy something, I build it — so the print is fresh, the finish is current, and I can offer it in the material and color you actually want. It takes a few days longer than pulling stock off a shelf. That's the point.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"fontSize":"medium"} -->
<h2 class="wp-block-heading has-medium-font-size">Where to find us</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li><strong>Based in:</strong> Jacksonville, FL</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><strong>What we make:</strong> made-to-order 3D-printed and laser-engraved products — vases, planters, lamps, coasters (print, wood, slate), and custom/automotive parts.</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><strong>In person:</strong> We sell at select Jacksonville-area events. Following a QR code from one of our coasters? Welcome — you're in the right place.</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><strong>Custom &amp; wholesale:</strong> Individual custom work → <a href="/custom-work/">Custom Work</a>. Business, wholesale, or volume orders → <a href="https://cornercadworks.com">CornerCADWorks.com</a>.</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:heading {"fontSize":"medium"} -->
<h2 class="wp-block-heading has-medium-font-size">Get in touch</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Questions about a product, a custom project, or an event? Send a note and I'll get back to you.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="mailto:brad@cornercad.com?subject=CornerCAD%20enquiry">Email brad@cornercad.com</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group -->
HTML
```

- [ ] **Step 3: Add the backstory paragraph, if supplied**

If Bradley gave one in Step 1, insert it immediately **before** the "Most of what I make is made to order" paragraph, using his wording verbatim:

```html
<!-- wp:paragraph -->
<p>BRADLEY'S EXACT WORDS</p>
<!-- /wp:paragraph -->
```

If he declined, skip this step — the page reads fine without it.

- [ ] **Step 4: Add the events, Creality link, and photo, if supplied**

If he named specific events, replace `select Jacksonville-area events` in the "In person" item with the real names.

If he gave a Creality Cloud URL, add this list item after "Custom & wholesale":

```html
<!-- wp:list-item -->
<li><strong>Also find my designs on:</strong> <a href="CREALITY_URL">Creality Cloud</a></li>
<!-- /wp:list-item -->
```

If he supplied a photo, upload it with `wp_upload_media` (alt text: `Bradley Corner at the machines in the CornerCAD workshop`), record it in `media-ids.env` as `ABOUT_PHOTO_ID` / `ABOUT_PHOTO_URL`, and insert after the third paragraph:

```html
<!-- wp:image {"id":ABOUT_PHOTO_ID,"sizeSlug":"large","align":"wide"} -->
<figure class="wp-block-image alignwide size-large"><img src="ABOUT_PHOTO_URL" alt="Bradley Corner at the machines in the CornerCAD workshop" class="wp-image-ABOUT_PHOTO_ID"/></figure>
<!-- /wp:image -->
```

- [ ] **Step 3: Create as a draft**

Call `wp_create_post` — `post_title: "About"`, `post_type: "page"`, `post_status: "draft"`, `post_content` from the file. Record the ID.

- [ ] **Step 4: Verify**

Call `wp_get_post_snapshot` on the About page ID.

Expected: every heading and paragraph from `about.md` is present. Diff mentally against the source; missing copy is the failure mode here.

- [ ] **Step 5: Publish and check**

`wp_update_post` → `post_status: "publish"`, then:

```bash
curl -sS -o /dev/null -w '%{http_code}\n' -L --max-time 25 "https://cornercad.com/staging/6862/about/"
```

Expected: `200`.

- [ ] **Step 6: Commit**

```bash
git add content/blocks/about.html
git commit -m "feat(content): About page block markup"
```

---

## Task 8: Build the Custom Work page

Thin by design. The login-gated intake flow is Spec C — **do not build a form here.**

**Files:**
- Create: `content/blocks/custom-work.html`

**Interfaces:**
- Produces: the Custom Work page ID, consumed by Task 9's nav. The homepage already links to `/custom-work/`.

- [ ] **Step 1: Write the markup**

Copy is verbatim from `content/custom-work.md`. Two notes on what is and isn't here:

- **The contact address is `brad@cornercad.com`** — already confirmed active in Proton Mail per `about.md`. Do not ask Bradley again.
- **The example gallery is omitted.** `custom-work.md` calls for 4–8 photos of past custom pieces, and none exist in `media-ids.env`. An empty gallery band is worse than no band. Adding it is listed under "Deferred".

```bash
cat > content/blocks/custom-work.html <<'HTML'
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|large","bottom":"var:preset|spacing|large"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--large);padding-bottom:var(--wp--preset--spacing--large)"><!-- wp:heading {"level":1,"fontSize":"x-large"} -->
<h1 class="wp-block-heading has-x-large-font-size">Work with me.</h1>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>If you can describe it, I can usually design and build it. Custom parametric design and made-to-order production — one piece or a small run.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"fontSize":"x-small"} -->
<p class="has-x-small-font-size">Parametric design, made physical.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"fontSize":"medium"} -->
<h2 class="wp-block-heading has-medium-font-size">What I take on</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Real, functional custom work — not just "put my logo on it." A few examples of what fits:</p>
<!-- /wp:paragraph -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>A bracket, mount, or adapter that doesn't exist off the shelf.</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>A replacement part for something discontinued, reverse-engineered to fit.</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Engraved signage or a personalized coaster/keepsake in wood or slate.</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>A one-off vase, planter, or lamp sized to a specific spot.</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>A prototype you need turned from an idea into a physical part.</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:paragraph -->
<p>If it needs to actually <em>fit</em> and <em>work</em>, that's the point — everything starts as a dimensioned FreeCAD model, not a guess.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"fontSize":"medium"} -->
<h2 class="wp-block-heading has-medium-font-size">How it works</h2>
<!-- /wp:heading -->

<!-- wp:list {"ordered":true} -->
<ol class="wp-block-list"><!-- wp:list-item -->
<li><strong>Tell me what you need.</strong> Rough sketch, photo, measurements, or just a description — send what you've got.</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><strong>I scope it.</strong> I'll confirm whether it's a fit, rough timeline, and a price before any work starts.</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><strong>Design &amp; approve.</strong> I model it in FreeCAD and share it for sign-off before printing.</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><strong>Made to order.</strong> Once approved, I build it on the right machine for the job and ship it.</li>
<!-- /wp:list-item --></ol>
<!-- /wp:list -->

<!-- wp:heading {"fontSize":"medium"} -->
<h2 class="wp-block-heading has-medium-font-size">Materials &amp; processes</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Multi-material FDM (Creality K2 Plus), fine-detail resin (Elegoo Saturn 16K), and laser engraving on wood and slate (Longer Ray5). Based in Jacksonville, FL.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"fontSize":"medium"} -->
<h2 class="wp-block-heading has-medium-font-size">Start a custom project</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Send the details and I'll tell you if it's a fit, what it'll cost, and how long it'll take.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="mailto:brad@cornercad.com?subject=Custom%20project%20enquiry">Request a custom project</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group -->

<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|large","bottom":"var:preset|spacing|large"}},"color":{"background":"#000103"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-background" style="background-color:#000103;padding-top:var(--wp--preset--spacing--large);padding-bottom:var(--wp--preset--spacing--large)"><!-- wp:heading {"textAlign":"center","fontSize":"medium"} -->
<h2 class="wp-block-heading has-text-align-center has-medium-font-size">Buying for a business?</h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center"} -->
<p class="has-text-align-center">Wholesale runs, retail/dealer programs, candle molds and containers, or volume production are handled through my B2B arm. → <a href="https://cornercadworks.com">CornerCADWorks.com</a></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
HTML
```

- [ ] **Step 2: Confirm no unresolved tokens**

```bash
grep -c '__' content/blocks/custom-work.html
```

Expected: `0`.

- [ ] **Step 3: Create as a draft**

`wp_create_post` — `post_title: "Custom Work"`, `post_type: "page"`, `post_status: "draft"`, content from the file. Record the ID.

- [ ] **Step 4: Verify**

`wp_get_post_snapshot` on the ID. Expected: copy present, mailto link carries a real address, no form block.

- [ ] **Step 5: Publish and check**

`wp_update_post` → `publish`, then:

```bash
curl -sS -o /dev/null -w '%{http_code}\n' -L --max-time 25 "https://cornercad.com/staging/6862/custom-work/"
```

Expected: `200`.

- [ ] **Step 6: Commit**

```bash
git add content/blocks/custom-work.html
git commit -m "feat(content): Custom Work page — copy and contact CTA, intake flow deferred to Spec C"
```

---

## Task 9: Navigation, footer, and policy pages

**Files:**
- No repo files. Site state only.

**Interfaces:**
- Consumes: page IDs from Tasks 5, 6, 7, 8.

- [ ] **Step 1: Publish the two policy drafts**

Call `wp_update_post` twice with `post_status: "publish"` — Privacy Policy (ID 3 on production; confirm the staging ID first with `wp_get_posts` on `post_type: "page"`, `post_status: "draft"`) and Refund and Returns Policy (ID 18 equivalent).

Payment gateways generally require these live before go-live, and publishing a finished draft costs nothing now.

- [ ] **Step 2: Inspect the header template part**

Call `wp_get_posts` with `post_type: "wp_template_part"`, `post_status: "any"`. If a `header` record exists, read it with `content_format: "full"`. If none exists, SellAny is serving its header from theme files and editing it requires the Site Editor.

- [ ] **Step 3: Set the navigation**

Target menu: **Home · Catalog · Custom Work · About**.

If a `wp_navigation` post exists (`wp_get_posts` with `post_type: "wp_navigation"`), update its content with one `wp:navigation-link` per destination:

```html
<!-- wp:navigation-link {"label":"Home","type":"page","id":__HOME_ID__,"url":"/","kind":"post-type"} /-->
<!-- wp:navigation-link {"label":"Catalog","type":"page","id":__CATALOG_ID__,"url":"/catalog/","kind":"post-type"} /-->
<!-- wp:navigation-link {"label":"Custom Work","type":"page","id":__CUSTOM_WORK_ID__,"url":"/custom-work/","kind":"post-type"} /-->
<!-- wp:navigation-link {"label":"About","type":"page","id":__ABOUT_ID__,"url":"/about/","kind":"post-type"} /-->
```

Substitute the four recorded page IDs. If no `wp_navigation` post exists, build the menu in **Appearance → Editor → Navigation** — block-theme menus are frequently not reachable over this MCP, and the admin UI is the supported path, not a workaround.

- [ ] **Step 4: Set the footer tagline**

The footer must read: **CornerCAD — designed in FreeCAD, built to keep.**

Edit the `footer` template part if one exists as a `wp_template_part` record; otherwise use the Site Editor. Remove any SellAny demo footer content — placeholder social links, "Free Shipping" style promises the business does not make, and any payment-processor logos. **No marketplace or processor branding appears anywhere on the public site** (`CLAUDE.md`).

- [ ] **Step 5: Verify navigation on the rendered page**

```bash
curl -sS -L --max-time 25 "https://cornercad.com/staging/6862/" -o /tmp/nav-check.html
for s in '/catalog/' '/custom-work/' '/about/'; do printf '%-16s %s\n' "$s" "$(grep -c "$s" /tmp/nav-check.html)"; done
echo "--- built to keep ---"; grep -c 'built to keep' /tmp/nav-check.html
```

Expected: each path ≥1, tagline ≥1.

- [ ] **Step 6: Click every link**

```bash
for p in "" "catalog/" "custom-work/" "about/" "product-category/3d-printed/" "product-category/laser-engraved/" "product-category/3d-printed/automotive/"; do
  printf '%-46s ' "/$p"; curl -sS -o /dev/null -w '%{http_code}\n' -L --max-time 25 "https://cornercad.com/staging/6862/$p"
done
```

Expected: `200` across the board. Any 404 is a broken CTA — fix before Task 10.

---

## Task 10: Final verification and production push

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Walk the definition of done**

Check each item in spec §12 against the staging site, and write the result next to each one. Items 1–8 must pass. Item 9 (coming-soon still `yes` on production) is verified in Step 3. Item 10 (band 4 absent) is expected, not a failure.

- [ ] **Step 2: Review on a phone-width viewport**

Open staging at 390px wide. Check hero legibility, that the three tiles stack rather than squash, that the automotive band's image and text reflow, and that nothing overflows horizontally.

- [ ] **Step 3: Confirm production is still protected**

```
mcp__cornercad-com__wp_get_option  key: woocommerce_coming_soon
```

Expected: `"yes"`. If it reads `"no"`, production is publicly serving the old blog index — tell Bradley immediately.

- [ ] **Step 4: Get explicit approval for the push**

Show Bradley the staging URL and the §12 results. The Bluehost staging→production push **replaces production wholesale**. Confirm with him that no production-only changes have been made since the clone — if any have, they will be destroyed.

This is a Bluehost admin-UI operation. Bradley performs it; there is no MCP tool for it.

- [ ] **Step 5: Verify production after the push**

```bash
curl -sS -L --max-time 25 "https://cornercad.com/" -o /tmp/prod.html
grep -c "Made to be used" /tmp/prod.html
```

Note: with coming-soon on, production may serve the holding page to logged-out requests — in which case verify while logged in through the browser instead. Also re-check `show_on_front`, `page_on_front`, and `woocommerce_shop_page_id` on production, since a push can renumber nothing but is worth confirming.

- [ ] **Step 6: Correct CLAUDE.md**

It currently records the theme as `bluehost-blueprint` and the page table as Woo defaults only — both now wrong. Update:
- Theme → `sellany` (block theme), noting `auto-parts-and-car-accessories` and `bluehost-blueprint` as prior
- Pages table → Home, Catalog (was Shop), About, Custom Work, plus the Woo pages and the now-published policies
- Gaps list → strike items 1 (no static homepage), 3 (bare `product_cat`), 4 (one attachment), 6 (policy drafts); leave 2 (zero products) and 5 (Square) open
- Add the staging site and the "staging is the source of truth" rule
- Add the convention that page block markup lives in `content/blocks/*.html` and is deployed over MCP

- [ ] **Step 7: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: record SellAny, staging, and the completed Spec A site state"
```

---

## Deferred — not this plan

- **Band 4** (the live coaster product grid) — Spec B. The homepage is complete without it.
- **Coaster products, photography, design curation** — Spec B.
- **Login-gated custom-request intake** — Spec C.
- **Square OAuth, shipping zones, tax** — admin-UI only, and go-live scope.
- **Turning off coming-soon mode** — a deliberate go-live decision, made separately.
- **The WPForms contact form on About**, plus the SMTP configuration and Proton SPF/DKIM records it needs to actually deliver. `about.md` specifies the form; this plan ships a `mailto:` instead, because a form that silently drops enquiries is worse than a link that works. Do this as one piece of work — form and deliverability together, never the form alone.
- **The Custom Work example gallery** — `custom-work.md` calls for 4–8 photos of past custom pieces. Add the band when the photos exist.
- **Bradley's About backstory, event names, Creality Cloud link, and workshop photo** — requested in Task 7 Step 1; if he declines at that point, they stay open here.
