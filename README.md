# FOSSBilling-Enhance

Server manager for the [Enhance](https://enhance.com) control panel, plus an optional module that creates FOSSBilling hosting plans from the packages in your panel and shows clients what their account is using.

## Compatibility

Works on the FOSSBilling 0.8 releases and on the current main line. FOSSBilling is moving from RedBean models to Doctrine entities one module at a time, and hosting has been migrated on main but not in any release yet, so the module asks the hosting service which of the two it expects and reads rows the same way.

## Install

Each release on the [releases page](https://github.com/kairandles/FOSSBilling-Enhance/releases) has two archives: `Enhance.zip` is the module on its own, laid out for FOSSBilling's extension installer, and `fossbilling-enhance-x.y.z.zip` is everything for installing by hand.

Copy `Enhance.php` to `src/library/Server/Manager/Enhance.php` in your FOSSBilling install. FOSSBilling can't install server managers from the extension directory, so this step is always manual.

Then in FOSSBilling go to System > Hosting plans and servers > Servers > New server, choose Enhance and fill in:

- Hostname: your panel hostname (e.g. `panel.example.com`). HTTPS is always used, leave the port blank for 443.
- Organization ID: the UUID of the org your API token belongs to (it's in the panel URL).
- API token: created in the panel. Add your FOSSBilling server's IP to the token's allowed IPs or every request fails with a 401.

Test connection should pass. If it doesn't, `php -l src/library/Server/Manager/Enhance.php` will tell you if the copy went wrong.

## Hosting plans

Each FOSSBilling hosting plan needs to point at an Enhance plan. Add the custom value `plan_id` with the Enhance plan's numeric ID. If it's missing, the manager falls back to matching by plan name.

Optional custom value `send_setup_email` (default off): when a new customer's website is created, Enhance sends them its password setup email.

## The module

`modules/Enhance` adds an Extensions > Enhance page. Install it from the extension directory, or copy the folder to `src/modules/Enhance`, then activate it under Extensions. It lists the packages on each Enhance server and creates matching hosting plans (name, limits, `plan_id`). Plans that already exist are skipped unless you tick overwrite. Unlimited values are stored as `unlimited`, same as the WHM and DirectAdmin managers.

The module needs the server manager from the step above. Without it the Enhance page says so and nothing else works.

## Usage in the client area

The module also shows clients what their account is using: disk space, bandwidth for the current month, and the number of websites, domains, mailboxes, databases and FTP users against the plan's limits. Under that, every website on the plan is listed with its size, PHP version, last backup, and the visitors, requests and traffic of the last 30 days. The order's domain is marked as the primary site. The figures are read from the panel when the page loads. Nothing is stored.

They appear in two places:

- `/enhance` in the client area lists every hosting account the client has, each with its usage.
- The hosting service page can show the same block. Add this line to `src/modules/Servicehosting/templates/client/mod_servicehosting_manage.html.twig`, after the `</table>` that closes the Details tab, or to the copy of that template in your theme:

```twig
{% include 'mod_enhance_usage.html.twig' with { 'order_id': order.id } %}
```

A FOSSBilling update overwrites the module template, so you'll need to add the line again afterwards. The include is safe: if the panel can't be reached the block says usage is unavailable and the rest of the page still works.

Enhance tracks usage per subscription, so if a plan holds several websites the figures cover all of them. Every one of those websites is listed, including staging sites, which are labelled. Enhance's own control panel, webmail and hostname sites are left out. Clients can look but not change anything here; the Login to Control Panel button is still where they go to do that.

Each website costs two extra requests to the panel (metrics and backups), so a plan with many sites takes a moment longer to render. If one of those requests fails the row says the statistics are unavailable and the rest still shows.

The client API behind this is `enhance/usage` for one order and `enhance/accounts` for all of a client's hosting orders. Both only return the logged in client's own orders.

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
