# Login-Gated Custom-Request Flow — Design Spec

**Date:** 2026-07-20
**Status:** Approved design, pending implementation plan
**Sites:** cornercad.com (site id 1) + cornercadworks.com (site id 2) — single Concrete CMS 9.5.2 multisite installation
**Related:** `docs/superpowers/specs/2026-06-24-cornercad-site-structure-design.md`, `content/custom-work.md`, `content/_sales-model.md`

---

## 1. Purpose & success criteria

Let customers submit a custom-work request, then log in later to **track its status**. The account exists to give the customer visibility into their request, and to give Brad a single, traceable queue of custom jobs.

**Success = all of:**
- A visitor can start a request *without* an account (fill-first), and the request is **never lost** even if they never finish registering.
- Once registered/logged in, the customer sees their request(s) with a live **status** in a "My Requests" area.
- Brad sees every request in **one Dashboard queue** across both sites, and updates status there with no custom admin UI.
- The data model does **not** block a future Square customer/invoice integration.

**Non-goals (this phase):** design-approval sign-off inside the account, in-account messaging/file exchange, any Square API calls, status-change emails to the customer (deferred — see §9).

---

## 2. Key architectural facts (verified 2026-07-20 via `concretecms` MCP)

- **Multisite, one installation.** `get-sites` returns two sites: `default`/CornerCAD (home page 1) and `CornerCADWorks` (home page 422). Because it is one Concrete installation:
  - **User accounts are installation-wide** — one login works on both domains.
  - **Express objects and their entries are installation-wide** — the `Custom Request` object is reachable from either site.
- Therefore "which site hosts the pages" is an **IA/brand decision, not a data-architecture one.** We build **one** pipeline and surface it on **both** sites.
- **The REST API/OAuth token cannot** create Express objects, user groups, single-pages, or attribute keys (all "out of scope"). **This feature ships as a CIF/package + Dashboard config**, not via `add-block` API calls. (See `docs/concrete-cms-api-protocol.md` §3.)
- **Commerce context (settled):** Community Store is the cart/checkout; Square is the card processor via the installed "Square Payment Method" add-on. Customer accounts are shared with Community Store logins. *(Note: `content/_sales-model.md` still says "No Community Store" — that doc is stale and should be reconciled separately; it does not affect this spec.)*
- **Captcha available:** "Captcha by Cloudflare Turnstile" (1.0.2) is installed.

---

## 3. Approach (chosen: "Save-first, claim-after")

Fill-then-register sequencing, implemented so **no submission is ever lost**:

1. Guest fills the public request form and submits.
2. The request is written to the `Custom Request` Express object **immediately**, `status=New`, `owner=0` (unclaimed). Brad is notified at this instant.
3. The customer is sent to register/login. On return, the entry is **claimed** — `owner` set to their user id — via the entry id held in session, with an emailed **claim link** as backup for anyone who leaves and comes back later.
4. A logged-in submitter skips the claim step; `owner` is set on submit.

Rejected alternatives: client-side localStorage draft-hold (fragile — nothing saved server-side until final submit); core Form block behind a login wall (does not deliver a per-customer status view).

---

## 4. Components

Shipped as one package: **`cornercad_custom_requests`**.

| Component | Type | Responsibility |
|---|---|---|
| `Custom Request` | Express object (installation-wide) | Canonical record: customer fields, files, status, owner, source, claim token, internal notes. |
| **Submit single-page** | Package single-page + controller | Renders the request form; creates the entry on submit (guest or logged-in); stashes entry id in session; drives the register→claim handshake; sets `source`. Placed once per site (individual voice on CornerCAD, business voice on Works). |
| **My Requests single-page** | Package single-page + controller | Login-required. Lists the current user's requests with status; per-request detail. Placed on both sites. |
| `Custom Request Customers` | User group | Assigned on registration; segmentation/permissions. |
| **Register form** | Concrete core + Turnstile | Standard registration, captcha-protected, auto-approved, email-verified. |
| **Custom Work front doors** | Existing/added pages | `content/custom-work.md` CTA on CornerCAD points at the Submit page; a parallel business-voiced page on Works points at the same Submit single-page (with `source=Business`). |

**Boundaries:** Submit page owns *create + claim*; My Requests page owns *read/status*; the Express object owns *data*; Brad's edits happen in the stock Dashboard Express UI. Each unit is independently testable.

---

## 5. Data model — `Custom Request` Express object

**Customer-entered fields**
- `name` (text)
- `email` (email) — **the Square-mapping key; store plainly, keep exportable**
- `description` (long text) — what they need
- `dimensions` (text) — rough size
- `material_process` (select, with "Unsure / other" option) — FDM / resin / laser / unsure
- `timeline` (text) — deadline
- `budget` (select, with "Prefer not to say / other" option) — budget range
- `attachments` (file, 1–5, size-capped; image/PDF/STEP/STL) — sketches, photos, model files

**System fields**
- `owner` (user id; `0` until claimed)
- `submitted_at` (datetime)
- `status` (select) — see ladder below
- `source` (select) — `Individual` (CornerCAD) or `Business` (Works); set by the entry point
- `claim_token` (text) — one-time token for the emailed claim link
- `internal_notes` (long text, Brad-only)

**Status ladder** (Brad sets in Dashboard; drives what the customer sees):
`New → Reviewing → Quoted (awaiting approval) → Approved → In design → In production → Shipped → Completed`
plus `Declined / Not a fit` and `On hold`.

---

## 6. Data flow

```
Custom Work front door (CornerCAD or Works)
        │  "Request a custom project"
        ▼
Submit single-page (PUBLIC) — form + file attachments
        │  submit
        ▼
Entry CREATED  (status=New, owner=0, source=<site>)  ──►  Brad emailed immediately
        │
        ├─ logged in? ── yes ──► owner ← uID ─────────────► My Requests ✅
        │
        └─ no ──► session stores entryID; claim/verify email sent
                     │
                     ▼
              Register (Turnstile, auto-approve, email-verify) / Login
                     │
                     ▼
              Claim: owner ← uID   (from session, or claim_token in email link)
                     │
                     ▼
                 My Requests shows it (status=New) ✅
```

**Invariant:** the entry exists the instant the customer hits submit. Abandoned registration never loses the request; Brad can still act on an unclaimed entry.

---

## 7. Registration & approval model

- Registration **enabled**, **auto-approved** (no manual admin gate).
- **Turnstile** on the register form **and** the guest submit form — primary spam defense.
- **Email verification ON.** Because the request is saved *before* verification, this delays only *account access*, never *request capture*. The verify email and the claim link may be combined into one email.
- New users placed in the **`Custom Request Customers`** group.

---

## 8. Back-office workflow (no custom admin UI)

Brad works entirely in **Dashboard → Express → Custom Request**:
- Single queue of all entries across both sites, filterable by **Status** and **Source**.
- Open an entry → change **Status**, add **Internal notes**, view attached files.
- No custom dashboard screens built (YAGNI). An optional summary report can be added later.

---

## 9. Notifications

| Trigger | Recipient | Content | Phase |
|---|---|---|---|
| New request submitted | Brad (`brad@cornercad.com`) | Summary + link to the Dashboard entry | **Core** |
| Guest submission needs an account | Customer | "Finish your account to track this request" + claim/verify link | **Core** |
| Status changed | Customer | "Your request is now *In design*" | **Phase 2 / optional** — Express does not email on entry *update* natively; requires custom code. Deliberately deferred. |

---

## 10. Error & edge cases

- **Abandoned registration** → entry persists unclaimed (`owner=0`); Brad still sees it and can email the customer; claim link stays valid. No lost request.
- **Logged-in submitter** → skips claim; `owner` set on submit.
- **Duplicate submissions** → separate entries; Brad dedupes manually (acceptable at expected volume).
- **Guest file uploads** → required by fill-first; mitigated by **type/size validation + Turnstile**. This is the primary abuse surface.
- **Cross-site** → `source` set by the front door that created the entry; My Requests shows *all* of a customer's entries regardless of which site they registered or submitted on.
- **Claim-token reuse** → token is one-time; invalidated after a successful claim.

---

## 11. Testing

**Unit**
- Claim logic — session path and claim-token path.
- `source` tagging by entry point.
- "Owned by current user" filter for My Requests.

**Functional**
- Guest submit → unclaimed entry created **and** Brad email fires → register → claim → appears in My Requests.
- Logged-in submit → immediate ownership, no claim step.
- Abandoned registration → entry still present and unclaimed.
- Claim-link path from email → associates correctly, token then invalid.

**Manual**
- Turnstile blocks bots on both forms.
- File validation rejects oversize/bad types; accepts allowed types.
- Both site entry points create entries with correct `source`.
- Status change in Dashboard reflects in the customer's My Requests view.

---

## 12. Square future-proofing (no API work this phase)

- `email` stored plainly as the customer key; all fields exportable.
- Documented mapping for a later bridge:

| Custom Request field | Future Square target |
|---|---|
| `email` | Square Customer (match key) |
| `name` | Square Customer name |
| `budget` / quote | Square Invoice line/total |
| `status` (`Quoted`→`Approved`→…) | Square Invoice lifecycle |

Nothing in the model hard-codes anything that would block this. Custom jobs are one-offs, so this is a **customer + invoice** bridge, not inventory sync (inventory sync applies to the Community Store catalog, out of scope here).

---

## 13. Open items to resolve during planning

- Exact package skeleton (single-page paths per site, controller wiring, Express object install via package `on_start`/CIF).
- Whether the Submit form uses the stock Express Form block plus a claim controller, or a fully custom form view (leaning custom view for control over the save-first/claim handshake and file validation).
- Precise combined verify+claim email template.
