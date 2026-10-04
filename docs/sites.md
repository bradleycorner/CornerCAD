# Websites covered by this repo

This repo holds code, content and docs for **three WordPress + WooCommerce sites**, all on one Bluehost
account (`ssh cornerfa`, see `CLAUDE.md` → Environment / access). Everything below was verified
**2026-09-25** by WP-CLI over SSH and live `mcp_ping` calls, unless marked otherwise. Sections marked
**2026-10-04** were updated after the scraper incident (see *Account-wide hazards* below).

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
- **2026-10-04:** live at **`firstcoastmiataclub.org`** since 2026-09-30 (DNS at Bluehost). Email is Proton
  Mail (`info@`, `membership@`). First real paid membership: order #556, 2026-10-01 (WooPayments,
  Google Pay). Membership product 14 is now **Virtual** (no shipping address at checkout). Proton shows
  the domain's DKIM as orange although all three `protonmail*._domainkey` CNAMEs are correct on both
  Bluehost nameservers — key 2 is unpublished on Proton's side; take it to Proton support if it persists.
- No Florida registration (Sunbiz search, 2026-10-04): the club has a federal EIN only, so WooCommerce
  sales-tax collection stays **off**.

## Unique Creations by Lisa C — uniquecreationsbylisac.store
Lisa's craft store, moving off a Square Online store. Shares the Square account with CornerCAD —
**filter on location / SKU prefix** (`UCL-` = Lisa, `CAD-` = CornerCAD).
- **Repo:** `scripts/square_seed_sandbox_uclc.py`, `scripts/seed_state_uclc.json`,
  `docs/2026-08-24-migration-plan-lisac-and-miata.md`. **No site code is mirrored yet.**
- **2026-10-04:** `uniquecreationsbylisac.com` finished transferring to Bluehost 2026-10-03 (DNS now
  Bluehost). Its Proton records (MX, SPF, verify, DKIM ×3, DMARC `quarantine`, Pinterest) were loaded
  **after** the nameserver switch, so mail briefly went to Bluehost — always load mail records first.
  The `.com` currently 301-redirects to `.store`; making `.com` the primary URL is still to do.
- Lisa's `lisa@`/`sales@` live in Bradley's Proton account and forward to her Gmail (hence Proton's
  "NO E2EE MAIL" badge). Header menu now uses **Navigation - top** (template part post 376). The
  SellAny footer still shows theme demo text ("poster shop … Tokyo") — not yet replaced.

## cornerfamily.com (the account's main domain) — 2026-10-04
Bradley's family domain; also hosts the cPanel-generated internal names for every other site
(`fcmc-dev.`, `website-b895229c.`, `bradleycorner.`, …). **Not a WordPress site:** both
`cornerfamily.com` and `genealogy.cornerfamily.com` serve the **webtrees** family tree.
- **DNS is at Cloudflare** (free plan, account `bc80921@gmail.com`, NS `gordon`/`lisa.ns.cloudflare.com`)
  since 2026-10-04. Make cornerfamily.com DNS changes in Cloudflare — cPanel's Zone Editor no longer
  takes effect. Registrar is still Bluehost.
- **Proxied (orange): `genealogy` and `www.genealogy` only.** Everything else is DNS-only, including the
  root and `www`, because the MX record is `0 cornerfamily.com` (Bluehost mail, holding Bradley's
  personal `brad@cornerfamily.com`). Proxying the root would break that mail.
- WAF custom rule **Block_China_genealogy**: `(http.host contains "genealogy" and ip.src.country eq "CN")`
  → Block. Verified blocking within minutes of activation.
- **Open:** the root serves the same tree but is unprotected. To proxy it, first move mail off the root —
  either MX → `mail.cornerfamily.com`, Cloudflare Email Routing, or Proton (all 3 Proton domain slots
  are used; freeing Lisa's forwarding-only domain would make room). Keep Google in any new SPF:
  webtrees sends via `smtp.gmail.com` as `bc80921@gmail.com` (app password re-created 2026-10-04 — it
  had been missing, so webtrees mail was silently broken).

## Account-wide hazards — learned 2026-10-03/04
- **Bluehost's `humans_21909` bot challenge** (HTTP 409 + a JS cookie-reload, served by Bluehost's own
  nginx, `host-header: shared.bluehost.com`) is applied to the **whole account**. It breaks MCP calls,
  API/payment callbacks and some logins (an FCMC admin login on a phone with iCloud Private Relay got
  stuck on it). On/off history from access logs: on 2026-09-30, whitelisted 10-01 03:46 UTC, back
  10-02 ~09:00 UTC, off 10-03 13:00–20:00 UTC, back 20:00 UTC. Ask Bluehost to remove it.
- **Cause:** a distributed scraper on `genealogy.cornerfamily.com` — 284,645 requests on 10-03 (~90 % of
  all account traffic) from 11,356 IPs on Chinese residential networks with fake Chrome user agents. It
  passes the JS challenge, so the challenge only hurts real users. It exhausted the account's process
  limit (SSH "fork: Resource temporarily unavailable") and webtrees' MySQL user
  (`max_user_connections`). Now filtered at Cloudflare (above).
- **Load discipline:** with the account under load, don't fan out SSH/WP-CLI sessions or loop `curl`
  against PHP pages — each holds a process in the shared cage. Prefer MCP; ask before falling back to
  SSH, and batch into one command.
- Bluehost's ThreatShield "unblocked attack attempts" (~2.9K) are vulnerability probes (`config.json`,
  `.env`, …), not the scraper — its paid WAF upgrade would not have stopped this.

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
