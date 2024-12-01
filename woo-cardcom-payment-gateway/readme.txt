=== CardCom Payment Gateway ===
Contributors: Cardcom LTD
Donate link:
Tags: woocommerce, payment-gateway, checkout
Requires at least: 3.3
Requires PHP: 7.2
Tested up to: 6.7.1
Stable tag: 3.5.0.0
License: GPLv2 or later
License URI: https://secure.cardcom.co.il/Html/agreement/agreement.htm

Cardcom payment-gateway plugin for WooCommerce.


== Description ==

Cardcom Payment Gateway

Allows your website to receive payments from your customers, via the WooCommerce plugin at checkout.

= Features =

This plugin supports various configurations & setups:

* WooCommerce Blocks
* Making credit-card deals & saving as "Payment Method" for returning customers.
* Making Credit-line "delayed" payments (aka "Capture charge")
* PayPal integration
* Invoicing automatically upon payment (Requires module)
* Support for WooCommerce-Subscriptions
* General-use Tokens for recurring/on-going charges (implementation of CardCom's API).

= References & Manuals =

- [Support article](https://support.cardcom.solutions/hc/he/articles/360007128393) - settings and configurations
- [Support article](https://support.cardcom.solutions/hc/he/articles/360022382833) - receiving API-keys & terminal-number for setup
- [General article](https://support.cardcom.solutions/hc/he/articles/360007554833) - contacting Cardcom's support team
- [Contact-Us Page](https://www.cardcom.co.il/Pages2/ContactUs.aspx) - contact-channels for CardCom's departments

= More Info =

See additional technical & general info, see "References & Manuals" above.
To read more about the provider of this service - visit [https://www.CardCom.co.il](https://www.cardcom.co.il)


== Installation ==

= Notice =
The plugin comes pre-defined with some default settings at setup (once installed & enabled).  Please follow the Manuals section for guidance on how to manage these settings before publishing.

If you are unsure of the correct settings or configurations for your setup, advise our support team accordingly.

= Minimum Requirements =

* WordPress 3.9 or later - tested up to 5.9
* PHP version 5.6 or later - tested up to 7.3.5
* MySQL version 5.6 or later - tested up to 8.0.16
* WooCommerce 2.7 or later - tested up to 6.1.1

= Procedure =

1. Install the plugin to your website, either by:
    + Search "CardCom" in your WP-control-panel & install this plugin.
    + or, download `woo-cardcom-payment-gateway.zip` under "[Advanced View](https://wordpress.org/plugins/woo-cardcom-payment-gateway/advanced/)", and directly copying it over to your server's `/wp-content/plugins/` directory

2. Then, Activate the plugin through the 'Plugins' menu in WordPress.
Next, you need to configure CardCom plugin.
3. Access the configuration in the contol-panel via:
    + "WooCommerce" menu, "Settings" sub-menu
        + "Payments" tab in settings
        + "CardCom" link / corresponding "Manage" button

4. At the very minimum, you must set the following.
    + `API-Username`
    + `Terminal-Number`

5. Leaving the defaults, deals to be made-out to out test-terminal. See also: [Relevant support article](https://support.cardcom.solutions/hc/he/articles/360007128393)

6. Regarding other configurations and additional support, please see "REFERENCES & MANUALS" section above.


== Frequently asked questions ==

= I don't know what are my "Terminal number" and/or "API-Username" =
Go to [The CardCom management console](https://secure.cardcom.solutions/), and then navigate to "Settings" menu >> "1. Company setup" >> "4. Manage API-keys"
From there you may copy the keys, or have a copy sent over to your email. See also: [Support article](https://support.cardcom.solutions/hc/he/articles/360022382833)

= I'm having trouble getting into my CardCom management console =
You could reset a user's password quickly, by going to the "Forgot password" link on the log-in screen.
From there, enter the relevant Username, and their associated SMS/Email. You'll receive a One-Time password, and then be requested to set a new password.

= I'm still having trouble / cannot access management console =
If you need to, please call our Support team, to get further assistance -
IL Phone number: [+972-3-9436100](TEL:039436100), see also: [Contact-us Page](https://www.cardcom.co.il/Pages2/ContactUs.aspx)

= Checkout page is missing the "CardCom" payment option =
Make sure both that the plugin is ENABLED in your WordPress site (goto control-panel), And that it's ACTIVE for WooCommerce (see step-3 in the Installation guide)

= Cannot proceed from checkout to payment page choosing "CardCom" gateway =

The most likely explication is either:

1. You forgot to set some required parameter at setup
   (See: "Terminal number" and "API-Username")
2. Some feature is active, which requires a module you're not subscribed to in your CardCom account.

Example of the second issue may include,

* Setting "Invoice" is active, but your terminal lacks that module.
* You set "Operation" for an action that requires some module:
  * All of them require "Low-Profile Page" for the set terminal.
	* Ops with "Save Token" / "Capture Charge" - requires "Tokens".
	* Ops "Suspended Deal" (Deprecated) requires that module.

= My customers are not receiving their invoice =
Make sure your CardCom account has an active module for "Invoices",
And ensure the "Invoice" and "Send By Email" settings are enabled.
Contact [support team](https://support.cardcom.solutions/hc/he/articles/360007554833) for help with of the management console and reports.

= Payments are showing-up in reports, but orders stay "Pending payment" or "Cancelled" =
This is a potential issue with the site's hosting service. They may need to allow CardCom's servers & traffic in the host-firewall.
See relevant info at the END of [relevant support article](https://support.cardcom.solutions/hc/he/articles/360007128393)

= Everything is great! Where can I leave some comments about it? =
Feel free to post about your experiences with CardCom at our public [feedback page](https://g.page/r/CTw2FyLxXqMTEAg/review), or contact us directly over written channels!

== Screenshots ==

Please advise [relevant support article](https://support.cardcom.solutions/hc/he/articles/360007128393) for screenshots.

== Changelog ==

Please advise the [plugin development log](https://plugins.trac.wordpress.org/log/woo-cardcom-payment-gateway/) for changes.

== Upgrade notice ==

Please advise the [plugin development log](https://plugins.trac.wordpress.org/log/woo-cardcom-payment-gateway/) for changes.
