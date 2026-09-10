# First Coast Miata Club — site code

Custom code for **fcmc-dev.cornerfamily.com** (→ firstcoastmiataclub.org).

Design spec: `docs/superpowers/specs/2026-09-09-fcmc-membership-system-design.md`

## mu-plugins

Deployed to `wp-content/mu-plugins/` on the server. Must-use plugins load automatically —
there is no activation step, and they cannot be deactivated from wp-admin.

| File | Purpose |
|---|---|
| `fcmc-membership-registration.php` | Account-level member fields on the WooCommerce register / edit-account forms, plus `[fcmc_membership_signup]` — lets a logged-in member declare how many car-memberships they are buying, fill in each car, and adds one cart line per car with its own meta before checkout. |
| `fcmc-newsletter-display.php` | `[fcmc_latest_newsletter]` and `[fcmc_newsletter_archive]`. Reads `Vol-XX-Issue-YY-*.pdf` straight from the Media Library, so uploading a new issue is the only step — the Newsletters page never needs editing. |

### Deploying

```bash
scp fcmc/mu-plugins/*.php \
  cornerfa:/home1/cornerfa/public_html/fcmc-dev/wp-content/mu-plugins/
```

Git is the source of truth. Edit here, then deploy — do not edit on the server, or the next
deploy silently reverts it.

### Not in this repo

- **`sso.php`** — the host's single-sign-on plugin (author: Garth Mortensen, Mike Hansen), not
  club code. Vendor-managed and overwritten by the host; deliberately excluded.
- **Checkout Field Editor fields** — the 20 household/vehicle/consent fields live in the
  `wc_fields_additional` database option, not in a file. Not yet versioned.
- **WPCode snippet 448** (`Display all Albums`) — lives as a `wpcode` post in the database.
- **WP Mail SMTP settings** — database option containing the Proton SMTP token. Must never be
  committed; read individual non-secret keys rather than the whole option.
- **Member data** — see `.gitignore`. This repo is public.
