# Websites covered by this repo

This repo holds code, content and docs for **three WordPress + WooCommerce sites**, all on one Bluehost
account (`ssh cornerfa`, see `CLAUDE.md` → Environment / access). Everything below was verified
**2026-09-25** by WP-CLI over SSH and live `mcp_ping` calls, unless marked otherwise.

⚠️ All ~50 sites on this account share one UID and one CloudLinux cage. Traffic to *any* site (including
staging) counts against the same limits — a burst against staging caused an account-wide outage once.

## At a glance

| Site | URL | Server folder | DB prefix | MCP server | In this repo |
|---|---|---|---|---|---|
| **CornerCAD** (production) | https://cornercad.com | `public_html/website_14e4a69e` (`cornercad` → symlink) | `awF_` | `cornercad-com` | most of it |
| **CornerCAD** (staging) | https://cornercad.com/staging/8946/ | `public_html/cornercad/staging/8946` | `staging_awF_` (same DB as prod) | `cornercad-staging` ⚠️ | — (it's a clone) |
| **First Coast Miata Club** (dev) | https://fcmc-dev.cornerfamily.com | `public_html/fcmc-dev` | `fcmc_` | `fcmc-dev` | `fcmc/` |
| **Unique Creations by Lisa C** | https://uniquecreationsbylisac.store | `public_html/website_b895229c` (`ucblc`, `usblc` → symlinks) | `pGH_` | `uclc` | seed scripts + plan only |

## CornerCAD — cornercad.com
Bradley's consumer store for 3D-printed and laser-engraved products. **Live store; real money.**
- **Repo:** `CLAUDE.md` (primary instructions), `content/` (page copy, block markup, product copy),
  `snippets/` (mirrors of the live WPCode snippets), `scripts/square_*.py` (Square catalog pipeline),
  `docs/wordpress-mcp-protocol.md`, `go-live.md`.
- **Live WPCode snippets ↔ repo:** 143 catalog mode · 90 AI Engine create-variation · 4717 description
  split · 4796 untracked-variation stock · 4823 auto cross-sells · 4824 coaster min qty — all
  byte-checked against production 2026-09-25. `wpcode-action-scheduler-failure-period.php` is in the repo
  but **not deployed**.
- **Square:** live account, location `LS4SZ98SBX4F6`; Square is the system of record, synced every 24h.
- **Staging** was recreated 2026-09-25 at `/staging/8946/` and inherited production's **live** Square
  config. Its sync is now disabled, but it is **not yet back on sandbox** — see `CLAUDE.md`. The old
  `/staging/7680/` and `/staging/6862/` folders are dead leftovers.
- The `cornercad-staging` MCP entry still targets the dead `/7680/` path and fails; use SSH for staging.
- Not the same as **CornerCADWorks.com** (B2B arm) — not in this repo.

## First Coast Miata Club — fcmc-dev.cornerfamily.com
Car club site being migrated off GoDaddy. Membership, households, roster and newsletters are built as
custom **mu-plugins**.
- **Repo:** `fcmc/mu-plugins/*.php` (deployed copies; 6 files byte-match the server except a one-word
  comment difference in `fcmc-membership-registration.php`), `fcmc/README.md`,
  `docs/superpowers/specs|plans/*fcmc*`.
- **mu-plugins are invisible to MCP** — deploy and inspect them over SSH only.
- The `fcmc-dev` MCP has the extra read-only-by-default `wp_db_query` tool (other sites don't).
- *(From project memory, not re-verified today:)* production domain will be `firstcoastmiataclub.org`
  after a pending transfer; email there is Proton Mail and its DNS records must be recreated first.

## Unique Creations by Lisa C — uniquecreationsbylisac.store
Lisa's craft store, moving off a Square Online store. Shares the Square account with CornerCAD —
**filter on location / SKU prefix** (`UCL-` = Lisa, `CAD-` = CornerCAD).
- **Repo:** `scripts/square_seed_sandbox_uclc.py`, `scripts/seed_state_uclc.json`,
  `docs/2026-08-24-migration-plan-lisac-and-miata.md`. **No site code is mirrored yet.**
- *(From project memory, not re-verified today:)* hard deadline **2026-10-01**, when the Square Online
  plan renews.

## Not covered by this repo
Other sites on the same account (e.g. `bradleycorner.com` at `website_c87b2ade`) are unrelated — don't
touch them.

## Rules that apply to every site above
- Every write to a live site needs Bradley's explicit yes first (tool, target ID + name, what changes).
- Check which site an MCP call targets before writing — staging and production return the **same
  `mcp_ping` name**; tell them apart by the permalink.
- Verify live state (MCP read, WP-CLI, SQL) before asserting it; docs drift.
- Cloudflare fronts cornercad.com and challenges terminal `curl` (`403 cf-mitigated: challenge`). That
  is not an auth failure; MCP with a valid key still works.
