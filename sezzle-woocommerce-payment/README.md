# Sezzle Plugin for WooCommerce

## Introduction
This document will help you in installing `Sezzle` plugin in WooCommerce. This plugin is a certified one and listed [here](https://wordpress.org/plugins/sezzle-woocommerce-payment) in the marketplace.

## How to install the plugin?

* Login to `WordPress` admin.
* Make sure you have `WooCommerce` plugin installed.
* Go to `Plugins > Add New` and search for `Sezzle WooCommerce Payment` in the Search box.
* Once you find `Sezzle WooCommerce Payment` plugin, click on `install` and later `activate` the plugin.


## How to upgrade the plugin?

* Login to `WordPress` admin.
* Navigate to `Plugins > Installed Plugins`.
* You will see a notifcation below `Sezzle` plugin if any upgrade in available.
* Click on `Update now` to upgrade the plugin.

## Configure Sezzle

* To configure your `Sezzle` plugin in `WooCommerce`, complete the following steps. Prerequisite for this section is to obtain `Merchant ID`, `Private Key` and `Public Key` from [`Sezzle Merchant Dashboard`](https://dashboard.sezzle.com/merchant/). Sign Up if you have not signed up to get the necessities.
* Go to `WooCommerce > Settings > Payments > Sezzle`.
* Configure the plugin as follows:
    * Check the `Enable/Disable` checkbox for enabling `Sezzle`.
    * Check the `Payment option availability in other countries` if you want to allow Sezzle outside of US and Canada.
        * Note, Sezzle operates only in US and Canada. Be sure to check this option.
    * Set `Merchant ID` as received from `Business` section of [`Sezzle Merchant Dashboard`](https://dashboard.sezzle.com/merchant/).
    * Copy your `Private Key` and `Public Key` from your [`Sezzle Merchant Dashboard`](https://dashboard.sezzle.com/merchant/), and paste them into the corresponding fields.
    * Check the `Create order post checkout completion` checkbox for enabling WooCommerce order creation only after Sezzle checkout is successfully completed.
    * Set `Minimum Checkout Amount` if you want to restrict Sezzle based on a minimum order total.
    * Set the `Transaction Mode` as `Live` for production and `Sandbox` for sandbox testing mode.
    * Check the `Show Sezzle widget in product pages` checkbox for adding widget script in the Product Display Page, which allows enabling Sezzle Widget Modal in PDP.
    * Configure the installment plan widget under `Installment Plan Widget Configuration` settings
        * Check the `Enable Installment Widget Plan in Checkout page` checkbox for enabling installment widget plan.
        * Set the `Order Total Container Class Name`. Default is `woocommerce-Price-amount`.
        * Set the `Order Total Container Parent Class Name`. Default is `order-total`.
    * Check the `Sync Orders` checkbox the if you agree to sent all orders from your store to Sezzle.
    * Check the `Enable Logging` checkbox for logging Sezzle checkout related data. This is helpful for debugging issues, if encountered.
* Save the settings.

### Your store is now ready to use Sezzle as a payment gateway.

## Restrict Sezzle based on user roles
Make sure `Sezzle` plugin is `active` in `Wordpress` admin.

#### Hide Sezzle Payment Gateway
If you want to hide` Sezzle's` payment gateway based on user roles

1. Add the following function to your code:

```php
function restrict_sezzle_pay($available_gateways) {
    unset($available_gateways['sezzlepay']);
    return $available_gateways;
}
```

2. Call the following filter `inside` the user's access deciding code:

```php
add_filter('woocommerce_available_payment_gateways', 'restrict_sezzle_pay');
```

## Frontend Functonality

* If you have correctly set up `Sezzle`, you will see `Sezzle` as a payment method in the checkout page.
* Select `Sezzle` and move forward.
* Once you click `Place Order`, you will be redirected to `Sezzle Checkout` to complete the checkout and eventually in `WooCommerce` too.

## Capture Payment

* Capture is performed instantly from the plugin after order is created and validated in WooCommerce.

## Refund Payment

* Go to `WooCommerce > Orders` after logging into Wordpress admin.
* Go inside the order that you want to refund and click `Refund`.
* Provide the amount you want to refund in the respective places and click on `Refund $xx.xx via Sezzle`.
* If refund is successful, you will see `Order Status` as either `Refunded` or `Processing`(in case of `Partial Refund`) as per the input given and an `Order Note` added as `Refund of xx.xx successfully sent to Sezzle`.
* The same can be verified in the `Sezzle Merchant Dashboard`. `Status` as `Refunded` indicates the payment has been fully refunded and `Status` as `Partially Refunded` indicates the payment has been partially refunded.  

## Order Verification in WooCommerce Admin

* Login to `Wordpress` admin and navigate to `WooCommerce > Orders`.
* Proceed into the corresponding order.
* `Order Status` as `Proceesing` and `Order Note` shown as `Payment approved by Sezzle` successfully means payment is successfully captured by `Sezzle`.
* `Order Status` as `Pending` and `Order Note` not shown as `Payment approved by Sezzle` successfully means there is something wrong while processing the order.

## Order Verification in Sezzle Merchant Dashboard

* Login to `Sezzle Merchant Dashboard` and navigate to `Orders`.
* Proceed into the corresponding order.
* Status as `Approved` means payment is successfully captured by `Sezzle`.
* Status as `Authorized`, uncaptured means payment is authorized but yet not captured.

## How Sandbox works?

* In the `Sezzle` configuration page of your `WooCommerce` admin, enter the `Sandbox` `API Keys` from your [`Sezzle Merchant Sandbox Dashboard`](https://sandbox.dashboard.sezzle.com/merchant/) and set the `Sezzle API URL` to `https://sandbox.gateway.sezzle.com/v1`, then save the configuration. Make sure you are doing this on your `dev/staging` website.
* On your website, add an item to the cart, then proceed to `Checkout` and select `Sezzle` as the payment method.
* Click `Continue` then `Place Order` and you should be redirected to the `Sezzle Checkout` page. If prompted, sign in and continue.
* Enter the payment details using test data, then click `Complete Order`.
* After the payment is completed on `Sezzle`, you should be redirected back to your website and see a successful payment page.
* `Sandbox` testing is complete. You can login to your `Sezzle Merchant Sandbox Dashboard` to see the test order you just placed.

## Troubleshooting/Debugging

* There is logging enabled by `Sezzle` for tracing the `Sezzle` actions.
* In case merchant is facing issues which is unknown to `Merchant Success` and `Support` team, they can ask for this logs and forward to the `Platform Integrations` team.
* Name of the log should be like `Sezzlepay-2020-04-27-ec6929b2d9c82df755ba0835a5ce6337.log`.
