# CornerCAD Custom Requests

Login-gated custom-work intake with status tracking, shared across both sites
(cornercad.com + cornercadworks.com — one Concrete multisite installation).

**Flow:** guest fills the form (never lost) → request saved immediately
(`owner=0`) → register/login → **claim** → track status under *My Requests*.
Brad manages every request from one Dashboard Express queue.

- Spec: `docs/superpowers/specs/2026-07-20-login-gated-custom-request-design.md`
- Plan: `docs/superpowers/plans/2026-07-21-login-gated-custom-request.md`

## What install() creates (idempotent)

- Express object **`custom_request`** with 13 attributes (name, email,
  description, dimensions, material, timeline, budget, attachment file IDs,
  owner uid, claim token, internal notes, status, source).
- User group **`Custom Request Customers`**.
- Single-pages **`/custom-request`** (+ `/thanks`) and **`/my-requests`**.

## Deploy (staging first)

1. Upload this folder to `<site>/packages/cornercad_custom_requests`.
2. Install: Dashboard → Extend Concrete → "CornerCAD Custom Requests"
   (or `./concrete/bin/concrete c5:package:install cornercad_custom_requests`).
3. One-time Dashboard config:
   - Members → Registration: **enabled**, **auto-approve**, **email verification ON**.
   - Confirm **Cloudflare Turnstile** is the active captcha library + keys set.
   - System & Settings → Email: **From** address/name set (Brad + claim emails).
4. Wire the CTAs (plan Task 11):
   - CornerCAD Custom Work CTA → `/custom-request?source=individual`
   - CornerCADWorks Custom Work CTA → `/custom-request?source=business`

## Verify pure logic (host has PHP 8.3)

    php packages/cornercad_custom_requests/tests/run.php

Expected: all checks `PASS`, exit 0. Covers StatusLadder, SourceResolver,
ClaimToken, FileValidator, InputSanitizer.

## `// VERIFY ON DEPLOY` markers

These lines use live 9.5.2 APIs authored without server access — sanity-check on
first staging install:

- **Express object build + select-option seeding** (`Installer.php`). If
  `seedSelectOptions()` fails, fall back to `text` attributes for `cr_status`
  / `cr_source`; `StatusLadder` remains the single source of truth and Brad
  still sets status in the Dashboard.
- **Express entry accessors** (`RequestRepository.php`) — `getCr*/setCr*`
  generated methods vs. `getAttribute()/setAttribute()`.
- **Captcha display/check**, **file importer**, **mail service**, **login
  redirect signature** (controllers).

## Not in this phase (deferred)

- **Status-change emails to the customer** — Express has no native on-update
  event; needs a custom handler (Phase 2). Brad-notification and the guest
  claim/verify email ARE included.
- **Square API** — no calls. `cr_email` is stored as the future customer key;
  see spec §12 for the mapping.
