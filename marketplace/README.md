# Adobe Commerce Marketplace material

Not part of the module: `bin/build-dist.sh` leaves this folder out of the zip.

| File | What it is | Marketplace field |
|---|---|---|
| `listing.md` | name, short description (no price), long description (what it does, how it works, requirements, pricing, external services, support), feature bullets, FAQ, changelog, tested versions, source-code note | Marketing: descriptions, features; Technical: notes for the reviewer |
| `release-notes.md` | what is in each version (1.1.0, 1.0.1 and 1.0.0) | Release notes |
| `user-guide.pdf` | user and installation guide, built from `user-guide.html` | Documentation: user guide, installation guide |
| `icon-512.png` | the square Intents logo from the brand kit (`brand/assets/directory/intents-logo-square-512.png`), 512×512 | Product icon |
| `screenshots/` | see below | Gallery |

Rebuild the guide after editing `user-guide.html`: `node plugins/magento/marketplace/build-guide.cjs`.

## Screenshots

Real captures from the `dev/magento` store (Magento Open Source 2.4.8-p5, Luma, PHP 8.3) on
2026-11-26, 2560×1440 (16:9, 1280×720 at 2×), in test mode, against api.intents.fi.

| File | Caption |
|---|---|
| `screenshots/1-settings-test-mode.png` | The settings in test mode: Connect to Intents, or enter keys manually. |
| `screenshots/2-checkout-pay-with-crypto.png` | "Pay with crypto" at checkout: the price first. |
| `screenshots/3-paid-order-admin.png` | A paid order: Processing, Total Paid, and the payment confirmation in its comments. |
| `screenshots/payment-page.png` | (extra, for the guide) The payment page: the order total, then the way to pay. |

The captures show `127.0.0.1:8080`; `payment-page.png` shows the test merchant's real name and the
Test payment badge. Retake from a real https store before submission if you'd rather not show
those. Not captured: paying from an exchange (QR code), which only a live invoice shows.

## Packagist (public mirror)

Packagist reads only public repositories, and the monorepo is private, so `plugins/magento` is
mirrored to **`intents-fi/magento2-checkout`** with `scripts/mirror-magento.sh` (a `git subtree
split` of this folder, checked, pushed as `main` plus a `v<version>` tag; it refuses to move a
published tag). Tested against a local bare repository, which then served `composer require` on
2.4.7-p10 and 2.4.8-p5.

1. Install the Claude GitHub app on the `intents-fi` organisation (or run the script yourself).
2. Create the public repository `intents-fi/magento2-checkout` (empty, no README).
3. From the monorepo at the release commit:
   `scripts/mirror-magento.sh --push git@github.com:intents-fi/magento2-checkout.git`
4. On packagist.org (account `intentsfi`), submit `https://github.com/intents-fi/magento2-checkout`
   and enable the GitHub hook. `composer require intentsfi/module-checkout` then works.

`.gitattributes` keeps `dev/`, `bin/`, `Test/` and `marketplace/` out of the GitHub archives
Composer downloads.

## Submitting (Luke)

1. Sign in to the Commerce Marketplace Developer Portal (commercedeveloper.adobe.com) with the
   intents.fi Adobe ID, and complete the developer profile (company, support email
   support@intents.fi, payout/tax details only if the extension will ever be paid; it is free).
2. Create the extension: name and description from `listing.md`, category Payments, free,
   compatibility: only the versions under "Tested versions" in `listing.md`.
3. Technical submission: upload `plugins/.dist/intentsfi-magento2-1.1.0.zip` (built with
   `plugins/magento/bin/build-dist.sh`; check `bin/phpcs.sh` is clean first). Version `1.1.0`,
   the package name `intentsfi/module-checkout`, and the release notes. The automated review
   runs the Magento Coding Standard, installation and compilation on its own install.
4. Marketing submission: the descriptions, feature bullets, FAQ, icon, screenshots and
   `user-guide.pdf`; links to https://intents.fi/docs/checkout/magento, https://intents.fi/terms
   and https://intents.fi/privacy.
5. Answer reviewer questions from `listing.md` ("Data and external services", "Source code").
