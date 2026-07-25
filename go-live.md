# Go-live Checklist — WooCommerce

Rewritten 2026-07-25 for WooCommerce (replaces the Community Store version).
Items marked **[admin]** cannot be done via MCP — they need the WP/Woo admin UI.

## Store setup
- [ ] Static homepage set (`show_on_front` → `page`, `page_on_front` → the Home page) — **currently `posts`**
- [ ] Home / About / Custom Work / Catalog pages built from `content/*.md`
- [ ] `product_cat` tree created: Automotive · Home & Garden · Display & Retail · Functional/Hardware
- [ ] Products created with `_regular_price` **and** `_price`, SKU, stock status, shipping dims
- [ ] Product imagery uploaded and set as featured images (media library currently has 1 attachment)
- [ ] Test products and test orders deleted

## Payments
- [ ] **[admin]** WooCommerce Square connected via OAuth (sandbox first)
- [ ] **[admin]** Sandbox test order placed end-to-end, then switched to live mode
- [ ] **[admin]** Unwanted/test payment methods disabled

## Shipping & tax
- [ ] **[admin]** Shipping zones + methods enabled
- [ ] **[admin]** Tax rates configured (or tax disabled deliberately)
- [ ] Store address / store timezone confirmed correct

## Email
- [ ] **[admin]** "From" address and name configured
- [ ] **[admin]** Order notification recipient(s) set
- [ ] **[admin]** Receipt email header/footer carry business info
- [ ] **[admin]** A real test email received (Woo → Status → Logs if not)

## Legal & trust
- [ ] Privacy Policy published (**page 3, currently draft**)
- [ ] Refund and Returns Policy published (**page 18, currently draft**)
- [ ] Terms page linked from checkout
- [ ] SSL valid across the whole site

## Site hygiene
- [ ] Administrator password secure; unused admin accounts removed
- [ ] Unused plugins deactivated (Hello Dolly, InstaWP Connect, OptinMonster if unused)
- [ ] Yoast: titles/meta set on the four main pages; sitemap submitted
- [ ] Search-engine visibility ON (`blog_public` = 1)
