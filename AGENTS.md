# Agent instructions

**Start with [`docs/sites.md`](docs/sites.md)** — which websites this repo covers (CornerCAD, the
First Coast Miata Club, Unique Creations by Lisa C), their URLs, server folders, MCP servers and what
lives where in the repo.

The project instructions for every coding agent (Cursor, Codex, Claude Code) live in
**[`CLAUDE.md`](CLAUDE.md)**, with the MCP/tooling protocol in
[`docs/wordpress-mcp-protocol.md`](docs/wordpress-mcp-protocol.md). Read both before doing anything.

This file is deliberately only a pointer. A copied `AGENTS.md` went stale within weeks (it still
claimed Square sync was off after it had gone live) — keep one source of truth.

Non-negotiables, in brief:
- **Gitflow:** branch from `develop` (`feature/<topic>`), PR back into `develop`. `main` = what is
  deployed; only `release/*` and `hotfix/*` merge into it. Details in `CLAUDE.md` → Git workflow.
- cornercad.com is a **live store**. Every write needs Bradley's explicit confirmation first.
- **Verify live state before asserting it** — query the site, don't reason from docs.
