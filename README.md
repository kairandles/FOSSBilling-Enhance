# FOSSBilling-Enhance

Server manager for the [Enhance](https://enhance.com) control panel, plus an optional module that creates FOSSBilling hosting plans from the packages in your panel.

## Install

Copy `Enhance.php` to `src/library/Server/Manager/Enhance.php` in your FOSSBilling install. FOSSBilling can't install server managers from the extension directory, so this step is manual.

Then in FOSSBilling go to System > Hosting plans and servers > Servers > New server, choose Enhance and fill in:

- Hostname: your panel hostname (e.g. `panel.example.com`). HTTPS is always used, leave the port blank for 443.
- Organization ID: the UUID of the org your API token belongs to (it's in the panel URL).
- API token: created in the panel. Add your FOSSBilling server's IP to the token's allowed IPs or every request fails with a 401.

Test connection should pass. If it doesn't, `php -l src/library/Server/Manager/Enhance.php` will tell you if the copy went wrong.

## Hosting plans

Each FOSSBilling hosting plan needs to point at an Enhance plan. Add the custom value `plan_id` with the Enhance plan's numeric ID. If it's missing, the manager falls back to matching by plan name.

Optional custom value `send_setup_email` (default off): when a new customer's website is created, Enhance sends them its password setup email.

## The module

`modules/Enhance` adds an Extensions > Enhance page. Copy it to `src/modules/Enhance` and activate it under Extensions. It lists the packages on each Enhance server and creates matching hosting plans (name, limits, `plan_id`). Plans that already exist are skipped unless you tick overwrite. Unlimited values are stored as `unlimited`, same as the WHM and DirectAdmin managers.

## How it works

Nothing from Enhance is stored in FOSSBilling. Every action looks up the customer org by the client's email and the website by the order's domain. That's why "Import existing" works for sites you created by hand in the panel, and why moving a website between servers in Enhance needs no changes in FOSSBilling.

- Create: finds or creates the customer org and its owner login, creates the subscription and the website.
- Suspend/unsuspend: acts on the subscription and the website.
- Cancel: deletes the subscription. The org stays.
- Change plan: moves the subscription to the new plan.
- Change domain: adds the domain and makes it primary. The old one stays as an alias.
- Change password: updates the customer's login.
- Login to control panel: SSO link for the customer's login.

Worth knowing:

- Enhance soft-deletes websites, so a cancelled domain can't be ordered again until the master org purges it.
- An Enhance plan can hold several websites, possibly on different servers. Suspend and cancel act on the whole subscription. The IP FOSSBilling stores is only the one for the order's domain.
- Set the server's generated password length to 12 or more, Enhance rejects shorter ones.
- Username and IP are filled in automatically after activation once FOSSBilling ships the post-activation sync change. Until then use the Sync button on the order.

## Tests

Copy `tests/Unit/Server/Manager/EnhanceTest.php` into a FOSSBilling checkout alongside the manager and run `APP_ENV=test composer test`.

## Licence

Apache-2.0.
