# Changelog

## 0.2.0 (2026-09-15)

- Client area usage: a `/enhance` page listing each hosting account with its disk, bandwidth and resource counts against the plan's limits, plus every website on the plan with its size, PHP version, last backup and 30 days of visitors, requests and traffic. A `mod_enhance_usage.html.twig` partial shows the same on the hosting service page. Read live from the panel, nothing is stored.
- `accountUsage()` on the server manager, client API `enhance/usage` and `enhance/accounts`.

## 0.1.0 (2026-09-14)

First release.

- Enhance server manager: create, suspend, unsuspend, cancel, change plan, change domain, change password, single sign-on to the panel, and synchronization of existing accounts. Accounts are found by the client's email and the order's domain, so "Import existing" works without any extra setup.
- Optional setup email per hosting plan (`send_setup_email` custom value).
- `Enhance` module: Extensions → Enhance page that creates or updates FOSSBilling hosting plans from the packages configured in the panel, linked by their Enhance plan ID.
