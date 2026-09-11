# CornerCAD Collections & Repeat Business Strategy

## Overview

CornerCAD should evolve from selling individual products into selling **collections and complementary product families**.

The goal is to increase:

- Repeat purchases
- Average order value
- Cross-selling
- Customer account creation
- Customer lifetime value
- Perceived value of CornerCAD products

The core idea is to make products feel like parts of a larger design system rather than isolated objects.

---

## 1. Collections Instead of Individual Products

Many licensed products already naturally form families.

For example:

### Existing / Licensed Product Family

- Vase
- Planter
- Lamp

As CornerCAD's own product line expands, use the same strategy.

### Example: Office Collection

A coordinated design could include:

- Planter
- Pen/pencil holder
- Coaster set
- Catch-all tray
- Phone stand
- Business-card holder
- Desk clock
- Lamp

Products should share a recognizable **design language**, pattern, geometry, or visual treatment.

The customer should be able to buy:

1. A single product
2. Several complementary products
3. The complete collection

---

## 2. Design Families

Avoid creating a large number of unrelated products.

Instead, develop a smaller number of reusable design families.

### Example: Hex Collection

- Hex planter
- Hex vase
- Hex pen holder
- Hex coaster
- Hex catch-all
- Hex lamp

### Example: Ribbed Collection

- Ribbed planter
- Ribbed vase
- Ribbed pencil holder
- Ribbed coaster
- Ribbed lamp

### Example: Automotive Collection

Potential products could include:

- Automotive-inspired desk objects
- Key tray
- Coasters
- Phone stand
- Desk organizers
- Classic-car-inspired decorative objects

The important principle is:

> Build the underlying design language once, then derive multiple products from it.

This fits particularly well with parametric CAD and FreeCAD workflows.

---

# 3. Mixed Manufacturing: 3D Printing + Laser Cutting

The coaster concept is a good example of how CornerCAD can create products that feel more finished than a basic 3D print.

## Example: Printed + Cork Coaster

Construction:

- 3D-printed outer ring
- Decorative perimeter/pattern
- Recessed interior
- Precisely laser-cut cork insert

The cork could be slightly recessed into the printed body.

This creates a deliberate material combination:

**3D-printed structure → cork surface**

The same manufacturing combination could potentially be used for other products.

---

# 4. Checkout Cross-Selling

The order-confirmation page should become the beginning of the next sale rather than simply a receipt.

Instead of a generic:

> You might also like

Use contextual recommendations.

## Example

### Complete Your Collection

**You purchased:** Ribbed Planter

Complete the matching desk collection:

- Pen Holder
- Coaster Set
- Catch-All Tray

> Save 12% when purchased as a set.

The recommendations should be based on the product purchased and its associated collection.

---

# 5. Build Your Set

Eventually, CornerCAD could offer flexible collection building.

## Example

### Build Your Desk Set

Choose any 3 → **10% off**

Choose any 5 → **15% off**

Possible products:

- Planter
- Pen Holder
- Coasters
- Catch-All
- Phone Stand
- Desk Clock

This provides the economics of a bundle without requiring a literal preassembled physical set.

It also creates an incentive to add one more product.

Example messaging:

> You're $8 away from unlocking 15% off.

---

# 6. CornerCAD Rewards

A loyalty program can complement collection-based selling.

Rather than relying exclusively on permanent discounts, reward customers for returning.

## Suggested initial incentive

### Create an Account

> **Join CornerCAD Rewards**

Create a free account and receive:

- Free shipping on your next order
- Member-only offers
- Order history
- Faster checkout

Consider making the first reward valid for a limited period, such as 60 days.

Example:

> **Your next order ships FREE.**
>
> Reward valid for 60 days.

This creates urgency without requiring a permanent discount.

---

# 7. Potential Loyalty Levels

Once there is enough order volume to understand customer behavior, introduce tiers.

### Maker

Free account

Benefits:

- Member-only offers
- First repeat-order shipping reward

### Builder

3 orders

Benefits:

- 10% off next order
- Additional member offers

### Master Builder

5+ orders

Benefits:

- 15% off
- Free shipping
- Early access to new collections

The exact thresholds and discounts should be based on actual margins and repeat-purchase behavior.

---

# 8. Referral Program

A referral program could complement loyalty rewards.

Example:

> **Know another maker who'd love this?**
>
> Give them 10% off their first CornerCAD order.
>
> Get $5 toward your next order when they purchase.

This turns an existing customer into a customer-acquisition channel.

The economics should be evaluated against the cost of acquiring a new customer through advertising.

---

# 9. Checkout Page Structure

Recommended order-confirmation page hierarchy:

```text
ORDER RECEIVED

Thank you. Your order has been received.

[ Order Summary ]

────────────────────────────────

🎁 JOIN CORNERCAD REWARDS

Get FREE SHIPPING on your next order
when you create your free account.

✓ Member-only offers
✓ Free shipping reward
✓ Order history
✓ Faster checkout

        [ CREATE MY ACCOUNT ]

────────────────────────────────

KEEP BUILDING

Complete your collection.

[ Product ] [ Product ] [ Product ]

        [ SHOP ALL ]

────────────────────────────────

REFER A MAKER

Give a friend 10% off.
Get $5 toward your next order.

────────────────────────────────

Shipping / Billing

Additional Information

────────────────────────────────

FOOTER
```

The page should not overwhelm the customer with every possible promotion.

The hierarchy should be:

1. Confirm the purchase
2. Establish the customer relationship
3. Encourage the next purchase
4. Encourage collection completion
5. Introduce referrals

---

# 10. Recommended Product Architecture

Collections should become a core concept in the store architecture.

A product should ideally be associated with:

- Product
- Design family
- Collection
- Complementary products
- Bundle eligibility
- Loyalty eligibility

For example:

```text
Ribbed Planter
│
├── Collection: Ribbed
│
├── Related:
│   ├── Ribbed Pen Holder
│   ├── Ribbed Coaster Set
│   └── Ribbed Catch-All
│
├── Bundle:
│   └── Ribbed Desk Set
│
└── Cross-sell:
    └── Matching Lamp
```

This makes cross-selling manageable as the catalog grows.

---

# 11. Key Principle

Do not make discounts the entire reason customers return.

Instead:

> **Give customers a reason to buy another product, then give them a reason to come back again.**

Collections create the first reason.

Rewards create the second.

Referrals create a third acquisition channel.

---

# 12. Recommended Rollout

Do not implement everything at once.

## Phase 1 — Immediate

Implement on the order-confirmation page:

- Account creation incentive
- Free shipping on next order
- Contextual "Complete Your Collection" section
- Related products

## Phase 2 — Catalog Structure

Add collection relationships to products:

- Collection
- Design family
- Related products
- Bundle relationships

## Phase 3 — Bundles

Introduce:

- Complete collection bundles
- Collection discounts
- Build-your-own-set functionality

## Phase 4 — Loyalty

After enough customer/order data exists:

- CornerCAD Rewards
- Order-count tiers
- Member-only offers
- Free-shipping thresholds

## Phase 5 — Referrals

Add:

- Customer referral codes
- New-customer discount
- Existing-customer reward

---

# 13. Metrics to Watch

The strategy should be evaluated using actual customer behavior.

Track:

- Repeat purchase rate
- Average order value
- Revenue per customer
- Time to second purchase
- Percentage of customers creating accounts
- Collection attachment rate
- Bundle conversion rate
- Free-shipping reward redemption
- Referral conversion rate
- Customer lifetime value

The most important early metric is probably:

**Percentage of first-time customers who make a second purchase.**

If that number increases, the strategy is working.

---

# Core Concept

CornerCAD should not just be:

> "A store that sells 3D-printed products."

It should become:

> **A collection of functional objects designed to work together.**

That creates a natural reason for customers to buy the next product, complete a set, discover another design family, and eventually become repeat customers.
