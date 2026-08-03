# Community Store — Square Payment Gateway Add-on — Project Brief

> **Purpose of this doc:** Seed brief for a **separate, dedicated project** to build a Square
> payment gateway for Concrete CMS's Community Store. Captured out of the CornerCAD site-build
> conversation (2026-07-05) so the deep-dive/design can start clean in its own repo.
> This is a *capture*, not a finished design — the full brainstorm → spec → plan happens in the new project.

---

## ⚠️ STATUS (updated 2026-07-16): SUPERSEDED for the cornercad.com need

A native **"Square Payment Method" v1.0.1** add-on ("Square Payment Method for Community Store") is now
**installed and available** on cornercad.com (Extend Concrete → Currently Installed). That **meets the
site's launch need** — a working Square checkout — so **§1's *primary* goal (build a gateway because none
exists) no longer applies**, and the earlier "Stripe is the launch fallback" note is moot.

What remains open:
- **Config/verify:** connect Square credentials (App ID / Access Token / Location ID), sandbox→production,
  and run one end-to-end sandbox order before pointing production checkout at it (see §3, §6).
- **The *secondary*, optional goal** (build & publish an own-brand Square gateway as a ~$50/yr marketplace
  product) is now **fully decoupled from the site** and would only be pursued as a standalone product
  play. Everything below (§2–§11) is retained as reference **only** for that optional path — it is NOT a
  prerequisite for cornercad.com going live.

Before relying on the installed add-on, confirm its provenance/maintenance (which package/author, whether
it's the modern Web Payments SDK vs the deprecated `SqPaymentForm`, and its update cadence).

---

## 1. Goals

1. **Primary (need):** The payment gateway required for **cornercad.com's** hybrid Community Store —
   Square-processed on-site checkout for physical "buy" products.
2. **Secondary (product):** Publish on the **Concrete CMS Marketplace** as a **~$50/yr subscription**
   add-on. Low marginal cost because it's being built for cornercad.com anyway; upside = the gateway
   Bradley needs + a portfolio piece + recurring income.

**Why Square** (not Stripe/PayPal): Bradley already runs **Square for in-person event sales**. One
processor = unified reporting/inventory. (Venmo/PayPal/CashApp exist for uniquecreationsbylisac but are
essentially unused — one Venmo ever.)

---

## 2. Chosen approach (decided)

- **Build fresh** — do **not** fork the abandoned third-party add-on. Rationale: security + latest
  codebases (chosen deliberately over fork-and-modernize).
- **Architectural template:** `community_store_stripe` (the **Elements** variant) — the CS wiki itself
  names the Stripe add-on as the recommended starting point for a new gateway.
- **Front-end:** **Square Web Payments SDK** (client-side card tokenization — the modern replacement for
  the deprecated `SqPaymentForm`).
- **Back-end:** current **`square/square` PHP SDK**, **pinned to a specific version** (NOT the `*`
  wildcard the old add-on used — Square did a major SDK restructure in 2024–25 with breaking changes).
- **Security posture:** tokenized / client-side capture → **card data never touches the server →
  SAQ-A-friendly PCI scope.** Matches CS wiki guidance ("prefer token/direct-post; avoid card data
  through the webserver").

---

## 3. Payment flow (technical)

1. **Front-end (checkout page):** Web Payments SDK initializes with **Application ID + Location ID** →
   renders card input → on submit, `card.tokenize()` returns a **one-time payment token** → placed in a
   hidden field → checkout form submits.
2. **Back-end (`submitPayment()`):** `square/square` PHP SDK →
   `PaymentsApi->createPayment(source_id = token, amount_money, idempotency_key, location_id)` →
   on success, store the returned **payment ID as the order's transaction reference**; on failure, surface
   error back to checkout.
3. **Config:** separate **sandbox vs production** credentials + base URL (toggle in admin settings).
   Square Developer app provides Application ID, Access Token, Location ID.

---

## 4. What to build — Community Store payment-gateway anatomy

A small package with a fixed structure (mirror the Stripe Elements add-on):

- **`controller.php`** — package install/enable; declares dependency on `community_store`; registers the
  payment method; version constraints.
- **PaymentMethod class** — `src/CommunityStore/Payment/Methods/CommunityStore<Name>/…` — the gateway is
  ~6 methods:
  - `dashboardForm()` / `save()` / `validate()` — admin settings: **App ID, Access Token, Location ID,
    sandbox toggle**.
  - `checkoutForm()` — the card UI shown at checkout (Web Payments SDK mount point).
  - `submitPayment()` — the actual charge (CreatePayment call above).
  - plus `getName()`, payment minimum, etc.
- **`elements/`** — PHP view templates (settings form + checkout form).
- **`js/`** — front-end: load Web Payments SDK, build card form, tokenize → hidden field.
- **`composer.json`** — require `square/square` (pinned).
- **`languages/`** — i18n (optional).

---

## 5. Proposed v1 scope (to confirm in deep-dive) — YAGNI

**In v1:**
- **Card payments only** (Visa/MC/Amex/Discover via Web Payments SDK card).
- **Sandbox + production** modes.
- **USD single-currency** to start.
- Store Square **payment ID** on the order; basic success/decline error handling.

**Deferred / maybe-later (do NOT build in v1 unless justified):**
- Apple Pay / Google Pay (Web Payments SDK supports as add-ons).
- ACH / digital wallets.
- **Refunds** from the Community Store order dashboard.
- **Webhooks** for payment reconciliation / disputes.
- Multi-currency.

---

## 6. Sandbox / test strategy

- Square Developer account → **sandbox** App ID / Access Token / Location ID.
- Build + test **entirely in sandbox on a staging copy** of the site.
- **Never point production checkout at it** until a full sandbox order succeeds end-to-end.

---

## 7. Marketplace / productization

- Concrete CMS marketplace supports **subscription pricing**, handles **license-key/billing**, takes a
  **revenue share**.
- **Payment gateway add-ons must pass the PRB (Peer Review Board)** security/standards review before they
  can be sold. (Good forcing function for a gateway.)
- **$50/yr is plausible** vs comparables (e.g., Genesis theme $45/yr).
- **Market reality (why no maintained Square gateway exists):** niche CS ecosystem; Stripe is the dev
  default; gateways carry a **maintenance + liability tail** (Square retires API versions on a schedule;
  support tickets are "my money didn't move"). Not an oversight — marginal economics. The one prior
  attempt (`baxterdmutt/community_store_squareup`) is evidence: built once, then abandoned.

---

## 8. Ongoing cost / maintenance

- **You own the maintenance tail:** Square deprecates API versions on a schedule → periodic **SDK bumps
  (~annually)** + retest.
- PCI burden stays **low** thanks to tokenized/SAQ-A design.

---

## 9. Effort estimate

- **From scratch, Stripe Elements as template, card-only v1:** ~**3–5 focused days.**
  (Fork-and-modernize the old add-on would've been ~1–3 days, but rejected for security/freshness.)

---

## 10. Prior art / reference material

- **`concretecms-community-store/community_store_stripe`** (Elements) — architectural template.
- **`baxterdmutt/community_store_squareup`** — the only existing Square gateway. 2022-era, 0 stars/forks,
  abandoned, **card-only**, uses deprecated `SqPaymentForm` + `square/square: "*"` wildcard. **MIT.**
  Useful only as a *structural* reference for the CS payment-method layout — modernize everything.
- **CS Payment Gateways wiki:** https://github.com/concretecms-community-store/community_store/wiki/Payment-Gateways
- **Community Store add-on:** https://github.com/concretecms-community-store/community_store
- Official maintained gateways (for comparison): Stripe (Checkout/Elements), PayPal, Authorize.Net,
  Mollie, SumUp, Pin Payments, Worldpay, Sage Pay, etc. — **no maintained Square.**

---

## 11. Open questions for the deep-dive (new project)

1. **Square PHP SDK generation/version** to target + pin (post-2024 restructure).
2. **Web Payments SDK scope:** cards-only v1, or include Apple/Google Pay?
3. **Refunds** in v1 (from CS order dashboard) or deferred?
4. **Webhooks** for reconciliation/disputes in v1 or later?
5. **Package handle / naming** (e.g. `community_store_square`).
6. **Target support matrix:** which Concrete CMS + Community Store versions.
7. **License / distribution model:** personal-use vs marketplace subscription; single-site vs multisite.
8. **Idempotency + error/edge handling** design (partial auths, network failures, double-submit).

---

## 12. Consuming context — cornercad.com hybrid store

The first consumer is cornercad.com's **hybrid** commerce model:
- **CIF `Product` page type + `product_*` attributes** (Phase 0, already installed) drive catalog display
  and the **download / inquire** CTAs.
- **Community Store "Add to Cart" + checkout** is bolted onto the physical **buy** items only.
- **The installed "Square Payment Method" v1.0.1 add-on** processes that checkout (as of 2026-07-16 —
  see Status banner above; a *custom-built* gateway is no longer required for the site), with
  **no Etsy/Square/marketplace branding** on the public site.
