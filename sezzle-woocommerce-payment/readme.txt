=== Sezzle Woocommerce Payment ===
Contributors: sezzledev
Tags: sezzle, installments, payments, paylater
Requires at least: 5.3.2
Version: 5.0.15
Stable tag: 5.0.15
Tested up to: 6.5.3
Requires PHP: 5.6
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Sezzle is an alternative payment platform that increases sales and basket sizes by enabling your customers to 'buy now and pay later' with interest-free installment plans. Sezzle collects 25% of the purchase price from the consumer at the time of purchase, but pays the merchant the full purchase price upfront, less their fee. The merchant assumes no credit or fraud risk. Sezzle then schedules three additional installments of 25% to be automatically debited from the consumer every two weeks, completely interest- and fee-free.

Sezzle is ideal for merchants that cater to young consumers, with order values typically between $50-$750, although we do accept orders up to $1,000. Sezzle increases consumers' purchasing power and is most heavily used by those without a credit card (63% of millennials do not own a credit card), or those with a very low credit limit on their card. However, a wide range of consumers use Sezzle, both in terms of age and financial situation.

Our extension includes a payment gateway, which will enable Sezzle as a payment option in your checkout. Once a customer selects Sezzle as their payment option, they are redirected to our secure checkout, after which they are taken back to your store to view purchase details. The extension also includes a widget that displays a dynamic installment amount on your product and cart pages, which is proven to increase conversions and basket sizes.


## Installation

1. Signup for Sezzle at https://dashboard.sezzle.com/merchant/signup/. Login to your dashboard and keep your API Keys page open. You will need it in step `5`.
2. Make sure you have WooCommerce plugin installed.
3. Install the Sezzle Payment plugin and activate.
4. Go to admin > WooCommerce > Settings > Payments > Sezzle.
5. Fill the form according to the instructions given in the form and save it.


### Your store is ready to use Sezzle as a payment gateway.

For more information, please visit [Sezzle Docs](https://docs.sezzle.com/#woocommerce).

== Changelog ==

= 5.0.15 =
* FIX: Page editing issues.

= 5.0.14 =
* Widget config updated.

= 5.0.13 =
* Widget config updated.

= 5.0.12 =
* Checkouts will not be created when "Create order post checkout completion" is turned off.
* FIX: User not redirected to unauth page when api keys are not correct.

= 5.0.11 =
* FIX: Multiple pages broken in older WordPress versions due to CartCheckoutUtils not found.

= 5.0.10 =
* FIX: Checkout page issues for merchants that do not have yet saved the installment widget settings in their system.

= 5.0.9 =
* Added checkout blocks support.
* "Create order post checkout completion" functionality will only work with standard classic checkout not checkout blocks.
* Merchant needs to enable Sezzle from settings after installation as it will be default disabled after it is installed.

= 5.0.8 =
* FIX: Interval server error while checking out in instances where PHP version less that 8 is used.

= 5.0.7 =
* FIX: Price mismatch between WooCommerce and Sezzle.

= 5.0.6 =
* Setting correct data type for checkout request line item quantity.

= 5.0.5 =
* Send last day merchant orders only if existed.

= 5.0.4 =
* Handle exception and show error message on API keys validation failed during settings save.
* Reversed the order of the API keys in the configuration.
* Remove variable type to support PHP<=7.

= 5.0.3 =
* Support High Performance Order Storage(HPOS).

= 5.0.2 =
* FIX: Class name conflict.

= 5.0.1 =
* FIX: Error completing Sezzle checkout.

= 5.0.0 =
* Create order after Sezzle checkout is completed through "Create order post checkout completion" configuration.
* Removal of EU support.

= 4.0.10 =
* Resolve woocommerce sniff errors.

= 4.0.9 =
* Send shopper to Sezzle checkout unauth URL if authentication is unsuccessful during checking out.

= 4.0.8 =
* Send admin configuration details to sezzle.

= 4.0.7 =
* Replaced installment widget static JS with CDN JS.

= 4.0.6 =
* FIX: Widget default configurations updated.

= 4.0.5 =
* FIX: Order total rounding.

= 4.0.4 =
* FIX: Checkout widget not appearing on shipping option change.

= 4.0.3 =
* Sending platform and plugin details(name and version) to Sezzle for tracking/debugging purpose.

= 4.0.2 =
* Plugin description updated.

= 4.0.1 =
* WordPress 5.9 and WooCommerce 6.1.1 compatibility added.

= 4.0.0 =
* FEATURE: Multi site support.

= 3.1.15 =
* MODIFY: Updated the description to showcase US, CA and EU.
* MODIFY: Update FR translations

= 3.1.14 =
* FEATURE: Add support for internationalization
* FEATURE: Add translation support for DE, ES and FR languages

= 3.1.13 =
* FEATURE: Add support for internationalization
* FEATURE: Add translation support for DE, ES and FR languages

= 3.1.12 =
* MODIFY: Sending Sezzle checkout URL to WooCommerce for instant redirection to Sezzle Checkout.

= 3.1.11 =
* FIX: Merchants receiving error "Sezzle: Unable to authenticate." on WooCommerce plugin.

= 3.1.10 =
* FIX: Checkout breaking while WooCommerce PayPal Payments is active.

= 3.1.9 =
* FIX: Sezzle URL Fix.

= 3.1.8 =
* FEATURE: Default Widget configs.

= 3.1.7 =
* FIX: Complete URL mismatch for multi network sites like https://example.com/ca/fr etc.
* FIX: Gateway region management improvisation.

= 3.1.6 =
* FEATURE: Compatibility for EU region.
* FEATURE: Widget Script will not be served if merchant id is missing.

= 3.1.5 =
* FIX: Assigning order currency to checkout and refund.
* FEATURE: Ability to turn on/off syncing analytical data.

= 3.1.4 =
* FIX: Sqaure and Stripe Payment Method Form blocking.
* FEATURE: Ability to turn on/off installment widget plan from Sezzle settings.

= 3.1.3 =
* FIX: Multiple Installment Widget.

= 3.1.2 =
* FEATURE: Installment Plan Widget under Sezzle Payment Option in Checkout Page.
* FIX: Admin check added in gateway hiding function.

= 3.1.1 =
* FIX: Failing of sudden orders being already captured.
* FEATURE: Ability to turn on/off logging.

= 3.1.0 =
* MODIFY: Transaction Mode added instead of Sezzle API URL.

= 3.0.5 =
* FIX: Undefined index:Authorization during redirection to Sezzle.

= 3.0.4 =
* MODIFY: Updated User Guide.

= 3.0.3 =
* MODIFY: Updated Widget Script URL.

= 3.0.2 =
* FIX: Order key property access through function instead of direct access.

= 3.0.1 =
* FIX: Return URL from Sezzle Checkout changed to Checkout URL of merchant website.
* FEATURE: Added logs for checking API functions.
* FIX: Check payment capture status before capturing the payment so that already captured orders does not fall into the process.

= 3.0.0 =
* FIX: Downgraded to previous stable version due to some conflicts arising in few versions.
* MODIFY: Delayed capture has been removed.
* MODIFY: Widget in Cart has been removed.

= 2.0.9 =
* FIX: Added check to include settings class when not available.

= 2.0.8 =
* MODIFY: Wordpress support version has been changed to 4.4.0 or higher.

= 2.0.7 =
* FEATURE: Hiding of Sezzle Pay based on cart total.
* FEATURE: Sezzle Widget and Sezzle Payment merged into one plugin.
* FIX : Amount converted to cents while refund.

= 2.0.6 =
* FIX: Page hanging issue during order status change for other payment methods.

= 2.0.5 =
* FIX: Security fix and quality improvements.

= 2.0.4 =
* FEATURE: Delayed Capture.
* FEATURE: Sezzle Widget for Cart Page.
* FEATURE: New settings for managing Sezzle Widget.
