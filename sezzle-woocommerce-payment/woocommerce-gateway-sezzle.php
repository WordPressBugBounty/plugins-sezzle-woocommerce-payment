<?php
/*
Plugin Name: Sezzle WooCommerce Payment
Description: Buy Now Pay Later with Sezzle
Version: 7.0.0
Author: Sezzle
Author URI: https://www.sezzle.com/
Requires at least: 5.6.0
Tested up to: 7.1.3
Requires PHP: 8.0
Copyright: © 2026 Sezzle
WC requires at least: 5.0
WC tested up to: 11.2.1
Text Domain: woo_sezzlepay
Domain Path: /i18n/languages/

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.
This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.
You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.

*/

use Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils;
use Automattic\WooCommerce\Utilities\FeaturesUtil;

if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
	include_once ABSPATH . 'wp-admin/includes/plugin.php';
}

if ( ! defined( 'WC_GATEWAY_SEZZLEPAY_PATH' )) {
	define( 'WC_GATEWAY_SEZZLEPAY_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) );
}

require_once WC_GATEWAY_SEZZLEPAY_PATH . '/includes/class-sezzle-checkout.php';
require_once WC_GATEWAY_SEZZLEPAY_PATH . '/includes/class-service-v2.php';
require_once WC_GATEWAY_SEZZLEPAY_PATH . '/includes/class-sezzle-utils.php';

/**
 * Plugin updates
 *
 * @since 1.0
 */
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) || is_plugin_active_for_network( 'woocommerce/woocommerce.php' ) ) {

	function load_plugin_textdomain_files() {
		load_plugin_textdomain( 'woo_sezzlepay', false, dirname( plugin_basename( __FILE__ ) ) . '/i18n/languages/' );
	}

	add_action( 'plugins_loaded', 'load_plugin_textdomain_files' );
	add_action( 'plugins_loaded', 'woocommerce_sezzlepay_init' );


	function woocommerce_sezzlepay_init() {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		class WC_Gateway_Sezzlepay extends WC_Payment_Gateway {

			public static $log        = false;
			private static $_instance = null;

			public $supported_countries;
			// Request-level cache (shared across all gateway instances in same request)
			private static $request_cache = array();
			// Merchant UUID returned by /v2/authentication during a key validation, so
			// process_admin_options() can persist it as the merchant-id (never entered
			// by hand). Null when the response carried no merchant UUID.
			private $validated_merchant_uuid = null;
			// Set once a capture request has been sent in this request, so error
			// handling never deletes an order whose payment may have gone through.
			private $capture_attempted = false;
				
			const TRANSACTION_MODE_LIVE    = 'live';
			const TRANSACTION_MODE_SANDBOX = 'sandbox';
			const EXPRESS_CHECKOUT_MODE_POPUP = 'popup';
			const EXPRESS_CHECKOUT_MODE_IFRAME = 'iframe';
			const EXPRESS_CHECKOUT_API_CALL_LOCK_KEY = 'sezzle_api_call_lock_';
			const EXPRESS_CHECKOUT_CACHE_KEY = 'sezzle_express_checkout_enabled_';
			// Last flag value actually returned by the gateway, per merchant + mode.
			// Used when a check can't complete, instead of guessing.
			const EXPRESS_CHECKOUT_LAST_CONFIRMED_KEY = 'sezzle_express_checkout_last_confirmed_';
			const ERROR_CART_AMOUNT_MISMATCH = 'Cart amount has been updated';
			// Name prefix of the lock that serializes completion of one Sezzle checkout
			// across concurrent requests, and how long (seconds) a second request waits.
			const COMPLETION_LOCK_PREFIX = 'sezzle_cl_';
			const COMPLETION_LOCK_WAIT = 15;
			// Set when a 401 disabled Sezzle; drives the admin notice until resolved.
			const API_ERROR_NOTICE_OPTION = 'sezzle_api_error_notice';
			// WC session key holding the shopper's cart snapshot taken before a
			// product-page express checkout isolates the cart to a single product.
			const PDP_CART_BACKUP_KEY = 'sezzle_pdp_cart_backup';
			// WC session key holding the coupons applied to that snapshotted cart, so
			// they can be re-applied on restore (empty_cart() clears applied coupons).
			const PDP_COUPONS_BACKUP_KEY = 'sezzle_pdp_coupons_backup';
			// When the PDP flow isolated the cart, and whether it completed. Lets the
			// on-load restore leave a flow that is still open in the popup/iframe alone.
			const PDP_FLOW_STARTED_KEY = 'sezzle_pdp_flow_started_at';
			const PDP_FLOW_COMPLETED_KEY = 'sezzle_pdp_flow_completed';
			// Cart item key of the product the PDP flow added, so a restore can keep
			// anything else the shopper added to the cart while the flow was open.
			const PDP_ITEM_KEY = 'sezzle_pdp_item_key';
			// WC session key holding the WooCommerce shipping rate behind each express
			// checkout shipping option offered to the shopper, keyed by the option uuid we
			// sent to Sezzle. Lets the completion handler rebuild a real shipping line for
			// whichever option the shopper picked inside the Sezzle flow.
			const EXPRESS_SHIPPING_OPTIONS_KEY = 'sezzle_express_shipping_options';
			// After this long (seconds) an unfinished PDP flow is treated as abandoned.
			const PDP_FLOW_ABANDON_AFTER = 1800;
			const EXPRESS_SDK_URL = "https://checkout-sdk.sezzle.com/express_checkout.min.js";
			const EXPRESS_CHECKOUT_DEFAULT_BUTTON_TARGET = 'wc-proceed-to-checkout';
			const EXPRESS_CHECKOUT_DEFAULT_PRODUCT_BUTTON_TARGET = 'form.cart';
			// Block-theme (Cart Block / block Single Product template) equivalents,
			// used as the shown default when the store renders those surfaces as blocks.
			// These mirror the block fallback targets in express-checkout.js.
			const EXPRESS_CHECKOUT_DEFAULT_BUTTON_TARGET_BLOCK = '.wc-block-cart__submit-container';
			const EXPRESS_CHECKOUT_DEFAULT_PRODUCT_BUTTON_TARGET_BLOCK = '.wp-block-add-to-cart-form';
			// Installment widget order-total target (SDK checkoutTotalElemTargetPath,
			// passed to document.querySelector). Default matches how the checkout is
			// rendered; if it matches nothing the SDK falls back to platform targeting.
			const INSTALLMENT_WIDGET_DEFAULT_TARGET_CLASSIC = '.order-total .woocommerce-Price-amount';
			const INSTALLMENT_WIDGET_DEFAULT_TARGET_BLOCK = '.wc-block-components-totals-footer-item-tax-value';
			// Basic, merchant-customizable button styling defaults (per location)
			const EXPRESS_CHECKOUT_DEFAULT_BUTTON_WIDTH = '100%';
			const EXPRESS_CHECKOUT_DEFAULT_BUTTON_BORDER_RADIUS = '4px';
			const EXPRESS_CHECKOUT_DEFAULT_BUTTON_MARGIN = '10px 0';

			public function __construct() {
				$this->id                 = 'sezzlepay';
				$this->method_title       = __( 'Sezzle', 'woo_sezzlepay' );
				$this->description        = __( 'Buy Now and Pay Later with Sezzle.', 'woo_sezzlepay' );
				$this->method_description = $this->description;
				$this->icon               = 'https://d34uoa9py2cgca.cloudfront.net/branding/sezzle-logos/png/sezzle-logo-sm-100w.png';
				$this->supports           = array( 'products', 'refunds' );
				// Don't check feature flag here - check it lazily when needed
				$this->init_form_fields();
				$this->init_settings();
				// One-time migration: the old two-field installment-widget target
				// (order-total-container-class-name + order-total-container-parent-class-name)
				// was collapsed into a single CSS selector (order-total-container-selector).
				// Compose the old stored values into the new key so a merchant who
				// manually customized the legacy fields keeps their target instead of
				// silently falling back to the auto-detected default. Only runs when the
				// new key is unset and a legacy value was actually saved; since the old
				// fields no longer exist, get_option returns the stored value or '' (no
				// field default), so untouched installs are left on the new default.
				if ( '' === $this->get_option( 'order-total-container-selector', '' )
					&& '' !== $this->get_option( 'order-total-container-class-name', '' ) ) {
					$cls    = $this->get_option( 'order-total-container-class-name' );
					$parent = $this->get_option( 'order-total-container-parent-class-name' );
					$selector = '' !== $parent ? ".$parent .$cls" : ".$cls";
					$this->update_option( 'order-total-container-selector', $selector );
				}
				// Fall back to "Sezzle" when the stored title is empty or unset. The
				// Title field was removed from the settings form (it pushed the logo
				// out of frame and a workable character limit was too small to be
				// useful), so WooCommerce no longer applies a field default and
				// get_option( 'title' ) alone can return ''. Passing the fallback keeps
				// the order's payment-method title and the block aria-label populated;
				// any title a merchant saved before the field was removed still applies.
				$this->title               = $this->get_option( 'title', __( 'Sezzle', 'woo_sezzlepay' ) );
				// US, Canada, and US territories (Guam, Puerto Rico, US Virgin
				// Islands, American Samoa, Northern Mariana Islands). WooCommerce
				// treats each territory as its own country code.
				$this->supported_countries = [ 'US', 'CA', 'GU', 'PR', 'VI', 'AS', 'MP' ];

				// Register the settings-save handler only once per request. The
				// constructor runs multiple times (singleton, gateways list, blocks
				// integration), and each call binds a distinct closure/$this that
				// WordPress can't dedupe. Without this guard the save hook fires once
				// per instantiation, so process_admin_options() runs 3×: validate_keys()
				// re-authenticates 3×, and a failed save stacks up three identical
				// "Unable to validate keys." banners (WC_Admin_Settings::add_error has
				// no dedupe, unlike the transient notice below).
				static $save_hook_registered = false;
				if ( ! $save_hook_registered ) {
					add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
					$save_hook_registered = true;
				}
				add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'sezzle_payment_callback' ) );
				add_action( 'admin_footer', array( $this, 'add_iframe_warning_script' ) );
			}

			/**
			 * Get cache TTL for feature flag results
			 *
			 * @return int Cache TTL in seconds
			 */
			private function get_cache_ttl($use_long_cache = true) {
				// Differentiate cache duration:
				// - Successes: 2 hours (feature flags change infrequently)
				// - Auth errors (401): 2 hours (likely offboarded merchant, no point retrying frequently)
				// - Other errors (network/service): 15 minutes (might recover quickly from brief outages)
				if ($use_long_cache) {
					return 7200; // 2 hours for successes and authentication failures
				}
				return 900; // 15 minutes for service/network failures
			}

			/**
			 * Try to take the feature flag fetch lock without waiting. A MySQL named lock
			 * is atomic (a transient check-then-set let two workers both fetch) and is
			 * held until released or the request ends, so it can't expire mid-fetch.
			 *
			 * @param string $lock_key Lock key
			 * @return string|false The lock name to release, or false if another process holds it
			 */
			private function acquire_feature_flag_lock($lock_key) {
				global $wpdb;
				// MySQL lock names are limited to 64 characters.
				$lock = substr(self::EXPRESS_CHECKOUT_API_CALL_LOCK_KEY . hash('sha256', $wpdb->prefix . $lock_key), 0, 64);
				$acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock));
				// NULL: the database has no named locks (e.g. a SQLite drop-in). Fetch
				// unlocked rather than waiting on a lock no one can take.
				return ($acquired === null || (string) $acquired === '1') ? $lock : false;
			}

			/**
			 * Wait for cache to become available while lock is held
			 *
			 * @param string $cache_key Cache key to check
			 * @param int $max_wait_ms Maximum time to wait in milliseconds
			 * @return mixed Cache result if found, false otherwise
			 */
			private function wait_for_cache($cache_key, $max_wait_ms = 500) {
				$wait_interval_ms = 50000; // 50ms
				$attempts = max(1, intval($max_wait_ms * 1000 / $wait_interval_ms));

				for ($attempt = 0; $attempt < $attempts; $attempt++) {
					usleep($wait_interval_ms);
					$cached_result = get_transient($cache_key);
					if ($cached_result !== false) {
						return $cached_result;
					}
				}
				return false;
			}

			/**
			 * The Express Checkout SDK mode, falling back to popup when unset. The Express
			 * fields are missing from the form until the feature flag is on, so a store
			 * may have no stored mode; an empty mode stalls the SDK before it starts a
			 * session.
			 *
			 * @return string
			 */
			public function get_express_checkout_mode() {
				$mode = $this->get_option('express-checkout-mode');
				return !empty($mode) ? $mode : self::EXPRESS_CHECKOUT_MODE_POPUP;
			}

			/**
			 * Check if express checkout feature flag is enabled
			 *
			 * Uses three-level caching strategy:
			 * 1. Request-level cache (instance variable) - fastest
			 * 2. Persistent cache (WordPress transients) - shared across PHP workers
			 * 3. API call (if cache miss) - slowest, avoided when possible
			 *
			 * @return bool True if express checkout is enabled, false otherwise
			 */
			public function check_express_checkout_feature_flag() {
				$merchant_id = $this->get_option('merchant-id');
				if (empty($merchant_id)) {
					return false;
				}

				// An unconnected account means the merchant has the plugin installed but
				// hasn't finished setup. The feature-flag endpoint authenticates with the
				// API keys, so calling it without them can only fail; bail before any
				// cache, lock, or HTTP work so unconfigured stores never hit the gateway.
				// Saving keys clears the cached flag, so the check runs again once setup
				// finishes.
				if ( ! $this->is_account_connected() ) {
					return false;
				}

				// Level 1: Check request-level cache first (prevents multiple checks in same request)
				$request_cache_key = 'express_checkout_enabled';
				if (isset(self::$request_cache[$request_cache_key])) {
					return self::$request_cache[$request_cache_key];
				}

				$transaction_mode = $this->get_option('transaction-mode');
				$cache_key = self::EXPRESS_CHECKOUT_CACHE_KEY . $merchant_id . '_' . $transaction_mode;

				// Level 2: Check persistent cache (WordPress transient - shared across all PHP workers)
				$cached_result = get_transient($cache_key);
				if ($cached_result !== false) {
					// Cache hit! Store in request cache and return
					// We store as string ('enabled'/'disabled') to differentiate from false/empty cache
					$result = ($cached_result === 'enabled');
					self::$request_cache[$request_cache_key] = $result;
					return $result;
				}

				// Level 3: Cache miss - need to fetch from API
				// Use locking to prevent multiple simultaneous API calls
				$result = $this->fetch_and_cache_feature_flag($cache_key, $merchant_id, $transaction_mode);

				// Store in request cache
				self::$request_cache[$request_cache_key] = $result;

				return $result;
			}

			/**
			 * Fetch feature flag from API and cache the result
			 *
			 * @param string $cache_key Cache key for storing result
			 * @param string $merchant_id Merchant ID
			 * @param string $transaction_mode Transaction mode (live/sandbox)
			 * @return bool True if enabled, false otherwise
			 */
			private function fetch_and_cache_feature_flag($cache_key, $merchant_id, $transaction_mode) {
				$lock_key = self::EXPRESS_CHECKOUT_API_CALL_LOCK_KEY . $merchant_id . '_' . $transaction_mode;

				// Take the lock, or wait for the process that holds it
				$lock = $this->acquire_feature_flag_lock($lock_key);
				if ($lock === false) {
					// Wait for the other process to complete and cache the result
					// Admin: 2s (safe margin), Frontend: 1.2s (balanced with safety)
					$max_wait = is_admin() ? 2000 : 1200;
					$cached_result = $this->wait_for_cache($cache_key, $max_wait);
					if ($cached_result !== false) {
						return ($cached_result === 'enabled');
					}
					// Still no result: use the last status the gateway confirmed. (This
					// used to return the merchant's own toggle, which could show the
					// buttons to a merchant who isn't in the flag.)
					return $this->get_last_confirmed_feature_flag($merchant_id, $transaction_mode);
				}

				try {
					// Make API call
					$service_v2 = new Service_V2($transaction_mode, $this->get_keys());
					$api_result = $this->parse_feature_flag_response($service_v2->is_express_checkout_enabled());

					// Store as string to differentiate false from empty cache
					$cache_value = $api_result ? 'enabled' : 'disabled';
					$ttl = $this->get_cache_ttl();
					set_transient($cache_key, $cache_value, $ttl);
					update_option(self::EXPRESS_CHECKOUT_LAST_CONFIRMED_KEY . $merchant_id . '_' . $transaction_mode, $cache_value, false);

					return $api_result;
			} catch (Exception $e) {
				// Differentiate caching based on error type:
				// - 401 (Unauthorized): 2 hour cache (likely offboarded, keys expired)
				// - Other errors: 15 minutes cache (network issues, brief API outages)
				$is_auth_error = ($e->getCode() === 401);
				$ttl = $this->get_cache_ttl($is_auth_error); // Long cache for auth errors, short for others
				set_transient($cache_key, 'disabled', $ttl);
				$this->log(sprintf('Express checkout feature flag check failed (%d); treating as disabled.', $e->getCode()));

				if ($is_auth_error) {
					// The flag endpoint rejected the public key. Confirm against the
					// authentication endpoint, whose 401 is what disables Sezzle and
					// clears the keys, rather than disabling on this response alone.
					try {
						// Same short timeout as the flag call: this still runs on the
						// cart, product, and checkout render path, with the lock held.
						(new Service_V2($transaction_mode, $this->get_keys()))->authenticate($this->get_keys(), 5);
					} catch (Exception $auth_exception) {
						// Nothing more to do: a 401 has already disabled the gateway.
					}
				}
				return false;

				} finally {
					// Always release lock
					global $wpdb;
					$wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
				}
			}

			/**
			 * Read the express checkout flag out of the gateway response. The
			 * response is JSON-decoded, so an object would be truthy whatever it
			 * says; only an explicit true enables the feature.
			 *
			 * @param mixed $response
			 * @return bool
			 */
			private function parse_feature_flag_response($response) {
				if (is_bool($response)) {
					return $response;
				}
				if (is_object($response)) {
					foreach (['enabled', 'is_enabled', 'value'] as $field) {
						if (isset($response->$field) && is_bool($response->$field)) {
							return $response->$field;
						}
					}
				}
				return false;
			}

			/**
			 * @param string $merchant_id
			 * @param string $transaction_mode
			 * @return bool The last flag value confirmed by the gateway (false if never confirmed).
			 */
			private function get_last_confirmed_feature_flag($merchant_id, $transaction_mode) {
				return get_option(self::EXPRESS_CHECKOUT_LAST_CONFIRMED_KEY . $merchant_id . '_' . $transaction_mode) === 'enabled';
			}

			/**
			 * Add iframe warning script to admin page
			 */
			public function add_iframe_warning_script() {
				if (isset($_GET['section']) && $_GET['section'] === 'sezzlepay') {
					$iframe_warning = __(
						'IFrame mode may cause Content Security Policy (CSP) issues. Please reach out to Sezzle support before using it to make sure your domain is allowlisted.',
						'woo_sezzlepay'
					);
					?>
					<script type="text/javascript">
					jQuery(document).ready(function($) {
						var modeSelect = $('#woocommerce_sezzlepay_express-checkout-mode');
						if (modeSelect.length) {
							function showIframeWarning() {
								if (modeSelect.val() === 'iframe') {
									if (!$('#iframe-warning').length) {
										modeSelect.closest('td').append(
											'<div id="iframe-warning" style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 8px 12px; margin-top: 5px; border-radius: 4px; color: #856404; font-size: 13px; display: inline-block;">⚠️ ' + <?php echo wp_json_encode( esc_html( $iframe_warning ) ); ?> + '</div>'
										);
									}
								} else {
									$('#iframe-warning').remove();
								}
							}
							modeSelect.on('change', showIframeWarning);
							showIframeWarning(); 
						}
					});
					</script>
					<?php
				}
			}

			/**
			 * Instance of WC_Gateway_Sezzlepay
			 *
			 * @return WC_Gateway_Sezzlepay|null
			 */
			public static function instance() {
				if ( is_null( self::$_instance ) ) {
					self::$_instance = new self();
				}
				return self::$_instance;
			}

			public function init_form_fields() {
				// Static flag to track if we're already checking in this request
				// This prevents multiple gateway instances from checking simultaneously
				static $checking_in_progress = false;
				// Static cache to store result across all instances in this request
				static $cached_ff_result = null;

				// Check feature flag on pages where express checkout is relevant
				// Skip AJAX requests to avoid unnecessary checks during cart/checkout updates
				$is_ajax = wp_doing_ajax();

				// Check if we're on Sezzle payment gateway settings page
				// Sanitize $_GET values as per WordPress coding standards
				$is_sezzle_settings_page = is_admin() && !$is_ajax &&
												isset($_GET['page']) && sanitize_text_field(wp_unslash($_GET['page'])) === 'wc-settings' &&
												isset($_GET['tab']) && sanitize_text_field(wp_unslash($_GET['tab'])) === 'checkout' &&
												isset($_GET['section']) && sanitize_text_field(wp_unslash($_GET['section'])) === 'sezzlepay';

				$is_express_checkout_page = is_cart() || is_checkout() || is_product();
				$should_check_feature_flag = $is_sezzle_settings_page || $is_express_checkout_page;

				$express_checkout_enabled = false;

				if ($should_check_feature_flag) {
					// Check feature flag on admin settings, cart, or checkout pages
					// This ensures Sezzle can disable express checkout immediately when needed
					if (!$checking_in_progress) {
						$checking_in_progress = true;
						$express_checkout_enabled = $this->check_express_checkout_feature_flag();
						$cached_ff_result = $express_checkout_enabled;  // Store for other instances
						$checking_in_progress = false;
					} else if ($cached_ff_result !== null) {
						// Another instance already checked - use cached result
						$express_checkout_enabled = $cached_ff_result;
					}
				}
				// Not on relevant pages: skip the feature flag check (no API calls on
				// home, blog, and other admin pages); the Express fields are omitted.

                // ============================================================
                // General
                // ============================================================
                $this->form_fields = array(
					'section-basic'                    => array(
						'title' => __( 'General', 'woo_sezzlepay' ),
						'type'  => 'title',
						'class' => 'sezzle-section-title',
					),
					'transaction-mode'                 => array(
						'title'    => __( 'Transaction Mode', 'woo_sezzlepay' ),
						'type'     => 'select',
						'default'  => 'live',
						'desc_tip' => true,
						'options'  => array(
							self::TRANSACTION_MODE_SANDBOX => __( 'Sandbox', 'woo_sezzlepay' ),
							self::TRANSACTION_MODE_LIVE    => __( 'Live', 'woo_sezzlepay' ),
						),
					),
                    'public-key'                       => array(
                        'title'             => __( 'Public Key', 'woo_sezzlepay' ),
                        'type'              => 'text',
                        'description'       => __(
                            'Find under Settings > API Keys in the Sezzle Dashboard that corresponds to the selected Transaction Mode.',
                            'woo_sezzlepay'
                        ),
                        'desc_tip'          => true,
                        'default'           => '',
                        'custom_attributes' => array( 'required' => 'required' ),
                    ),
					'private-key'                      => array(
						'title'             => __( 'Private Key', 'woo_sezzlepay' ),
						'type'              => 'text',
						'description'       => __(
							'Find under Settings > API Keys in the Sezzle Dashboard that corresponds to the selected Transaction Mode.',
							'woo_sezzlepay'
						),
						'desc_tip'          => true,
						'default'           => '',
						'custom_attributes' => array( 'required' => 'required' ),
					),
					'enabled'                          => array(
						'title'   => __( 'Enable Sezzle', 'woo_sezzlepay' ),
						'type'    => 'checkbox',
						'label'   => __( 'Enabled', 'woo_sezzlepay' ),
						'description' => __(
							'Enables/Disables the Sezzle payment method',
							'woo_sezzlepay'
						),
						'desc_tip'    => true,
						'default' => 'no',
					),
                    'min-checkout-amount'              => array(
						'title'   => __( 'Minimum Checkout Amount', 'woo_sezzlepay' ),
						'type'    => 'number',
						// Without a step the browser only accepts whole numbers (e.g. rejects 35.50).
						'custom_attributes' => array( 'step' => '0.01', 'min' => '0' ),
						'description' => __(
							'Hides the payment method below this amount',
							'woo_sezzlepay'
						),
						'desc_tip'    => true,
						'default' => '',
                    ),
                    'enable-order-creation-post-checkout'        => array(
                        'title'   => __( 'Create order post checkout completion', 'woo_sezzlepay' ),
                        'type'    => 'checkbox',
                        'label'   => __( 'Enabled', 'woo_sezzlepay' ),
						'description' => __(
							'When enabled, abandoned Sezzle checkouts will not show in WooCommerce order table.',
							'woo_sezzlepay'
						),
						'desc_tip'    => true,
                        'default' => 'no',
                    ),
				);

				// ============================================================
				// Express Checkout (only shown when enabled for the merchant)
				// ============================================================
				if ($express_checkout_enabled) {
					// Show button-location defaults that match how each surface is
					// rendered: block themes render the single product via blocks (detected
					// with wp_is_block_theme()), and the cart page may use the Cart Block
					// (detected with CartCheckoutUtils::is_cart_block_default()). The two
					// are detected independently so a mixed setup (e.g. block theme but a
					// shortcode cart) still shows the right default for each field.
					$product_target_is_block = function_exists('wp_is_block_theme') && wp_is_block_theme();
					$cart_target_is_block = class_exists('Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils')
						&& CartCheckoutUtils::is_cart_block_default();
					$product_button_target_default = $product_target_is_block
						? self::EXPRESS_CHECKOUT_DEFAULT_PRODUCT_BUTTON_TARGET_BLOCK
						: self::EXPRESS_CHECKOUT_DEFAULT_PRODUCT_BUTTON_TARGET;
					$cart_button_target_default = $cart_target_is_block
						? self::EXPRESS_CHECKOUT_DEFAULT_BUTTON_TARGET_BLOCK
						: self::EXPRESS_CHECKOUT_DEFAULT_BUTTON_TARGET;

					$this->form_fields['section-express'] = array(
						'title' => __( 'Express Checkout', 'woo_sezzlepay' ),
						'type'  => 'title',
						'class' => 'sezzle-section-title',
					);
					$this->form_fields['express-checkout-mode'] = array(
						'title' => __('Checkout Mode', 'woo_sezzlepay'),
						'type' => 'select',
						'default' => 'popup',
						'description' => __('For security reasons, Sezzle must add your domain(s) to our Content Security Policy to allow iframe usage.', 'woo_sezzlepay'),
						'desc_tip' => true,
						'options' => array(
							self::EXPRESS_CHECKOUT_MODE_POPUP => __('Pop Up', 'woo_sezzlepay'),
							self::EXPRESS_CHECKOUT_MODE_IFRAME => __('IFrame', 'woo_sezzlepay'),
						),
					);
					$this->form_fields['express-checkout-no-shipping'] = array(
						'title' => __('No Shipping', 'woo_sezzlepay'),
						'type' => 'checkbox',
						'label' => __('I never ship physical products', 'woo_sezzlepay'),
						'description' => __('By default, Express Checkout requires shipping options to be configured for your store. Enable this field to bypass this requirement.', 'woo_sezzlepay'),
						'default' => 'no',
						'desc_tip' => true,
					);
					$this->form_fields['section-express-product-button'] = array(
						'title' => __( 'Product Page Button', 'woo_sezzlepay' ),
						'type'  => 'title',
						'class' => 'sezzle-subsection-title',
					);
					$this->form_fields['enable-express-checkout-pdp'] = array(
						'title' => __('Enable Button', 'woo_sezzlepay'),
						'type' => 'checkbox',
						'label' => __('Show the Express Checkout button on product pages', 'woo_sezzlepay'),
						'default' => 'no',
					);
					$this->form_fields['express-checkout-product-button-target'] = array(
						'title' => __('Container Selector', 'woo_sezzlepay'),
						'type' => 'text',
						'description' => sprintf(
							/* translators: %s: example CSS selector matching the store's theme */
							__('Class name or CSS selector of the container the Express Checkout button will be rendered inside on the product page (e.g. %s)', 'woo_sezzlepay'),
							$product_button_target_default
						),
						'desc_tip' => true,
						'default' => $product_button_target_default,
					);
					$this->form_fields['express-checkout-product-button-width'] = array(
						'title' => __('Width', 'woo_sezzlepay'),
						'type' => 'text',
						'description' => __('CSS width of the button on the product page (e.g. 100%, fit-content, 250px).', 'woo_sezzlepay'),
						'desc_tip' => true,
						'default' => self::EXPRESS_CHECKOUT_DEFAULT_BUTTON_WIDTH,
					);
					$this->form_fields['express-checkout-product-button-border-radius'] = array(
						'title' => __('Radius', 'woo_sezzlepay'),
						'type' => 'text',
						'description' => __('CSS border-radius of the button on the product page (e.g. 4px).', 'woo_sezzlepay'),
						'desc_tip' => true,
						'default' => self::EXPRESS_CHECKOUT_DEFAULT_BUTTON_BORDER_RADIUS,
					);
					$this->form_fields['express-checkout-product-button-margin'] = array(
						'title' => __('Margin', 'woo_sezzlepay'),
						'type' => 'text',
						'description' => __('CSS margin around the button on the product page (e.g. 10px 0).', 'woo_sezzlepay'),
						'desc_tip' => true,
						'default' => self::EXPRESS_CHECKOUT_DEFAULT_BUTTON_MARGIN,
					);
					$this->form_fields['section-express-cart-button'] = array(
						'title' => __( 'Cart Page Button', 'woo_sezzlepay' ),
						'type'  => 'title',
						'class' => 'sezzle-subsection-title',
					);
					$this->form_fields['enable-express-checkout'] = array(
						'title' => __('Enable Button', 'woo_sezzlepay'),
						'type' => 'checkbox',
						'label' => __('Show the Express Checkout button on the cart page', 'woo_sezzlepay'),
						'default' => 'no',
					);
					$this->form_fields['express-checkout-button-target'] = array(
						'title' => __('Container Selector', 'woo_sezzlepay'),
						'type' => 'text',
						'description' => sprintf(
							/* translators: %s: example CSS selector matching the store's cart page */
							__('Class name or CSS selector of the container the Express Checkout button will be rendered inside on the cart page (e.g. %s)', 'woo_sezzlepay'),
							$cart_button_target_default
						),
						'desc_tip' => true,
						'default' => $cart_button_target_default,
					);
					$this->form_fields['express-checkout-cart-button-width'] = array(
						'title' => __('Width', 'woo_sezzlepay'),
						'type' => 'text',
						'description' => __('CSS width of the button on the cart page (e.g. 100%, 250px).', 'woo_sezzlepay'),
						'desc_tip' => true,
						'default' => self::EXPRESS_CHECKOUT_DEFAULT_BUTTON_WIDTH,
					);
					$this->form_fields['express-checkout-cart-button-border-radius'] = array(
						'title' => __('Radius', 'woo_sezzlepay'),
						'type' => 'text',
						'description' => __('CSS border-radius of the button on the cart page (e.g. 4px).', 'woo_sezzlepay'),
						'desc_tip' => true,
						'default' => self::EXPRESS_CHECKOUT_DEFAULT_BUTTON_BORDER_RADIUS,
					);
					$this->form_fields['express-checkout-cart-button-margin'] = array(
						'title' => __('Margin', 'woo_sezzlepay'),
						'type' => 'text',
						'description' => __('CSS margin around the button on the cart page (e.g. 10px 0).', 'woo_sezzlepay'),
						'desc_tip' => true,
						'default' => self::EXPRESS_CHECKOUT_DEFAULT_BUTTON_MARGIN,
					);
				}
				// When the flag is off the Express section is simply left out of the form.
				// The merchant's saved Express settings are kept (rendering re-checks the
				// flag), so they apply again as soon as the flag is back on. Overwriting
				// them here turned them off for good after any failed or negative check.

				// ============================================================
				// Widgets
				// ============================================================
				$this->form_fields['section-messaging'] = array(
					'title' => __( 'On-Site Messaging', 'woo_sezzlepay' ),
					'type'  => 'title',
					'class' => 'sezzle-section-title',
				);
				$this->form_fields['section-widgets'] = array(
					'title' => __( 'Widgets', 'woo_sezzlepay' ),
					'type'  => 'title',
					'class' => 'sezzle-subsection-title',
				);
				$this->form_fields['merchant-id'] = array(
					'title'       => __( 'Merchant ID', 'woo_sezzlepay' ),
					'type'        => 'text',
					'description' => __(
						'Find under Settings > Business in the Sezzle Production Dashboard, even while testing in Sandbox mode. Saving valid API keys in Live mode will auto-fill your Live Merchant ID.',
						'woo_sezzlepay'
					),
					'desc_tip' => true,
					'default'     => '',
				);
				$this->form_fields['show-product-page-widget'] = array(
					'title'   => __( 'Product Page Widget', 'woo_sezzlepay' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enabled', 'woo_sezzlepay' ),
					'default' => 'yes',
				);
				$this->form_fields['show-cart-page-widget'] = array(
					'title'   => __( 'Cart Page Widget', 'woo_sezzlepay' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enabled', 'woo_sezzlepay' ),
					'default' => 'yes',
				);
				$this->form_fields['section-checkout-installments'] = array(
					'title' => __( 'Checkout Installments Plan', 'woo_sezzlepay' ),
					'type'  => 'title',
					'class' => 'sezzle-subsection-title',
				);
				$this->form_fields['enable-installment-widget'] = array(
					'title'   => __( 'Enable', 'woo_sezzlepay' ),
					'type'    => 'checkbox',
					'label'   => __(
						'Enabled',
						'woo_sezzlepay'
					),
					'default' => 'yes',
				);
				// One CSS selector for the checkout order-total element the installment
				// widget attaches to (the SDK runs document.querySelector on it). Default
				// to a selector matching how the checkout is rendered: a block checkout and
				// a classic checkout expose the order total under different markup. If the
				// selector matches nothing, the SDK falls back to its platform targeting,
				// so this is a hint rather than a hard requirement.
				$order_total_selector_is_block = class_exists('Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils')
					&& CartCheckoutUtils::is_checkout_block_default();
				$order_total_container_selector_default = $order_total_selector_is_block
					? self::INSTALLMENT_WIDGET_DEFAULT_TARGET_BLOCK
					: self::INSTALLMENT_WIDGET_DEFAULT_TARGET_CLASSIC;
				$this->form_fields['order-total-container-selector'] = array(
					'title'       => __( 'Order Total Container Selector', 'woo_sezzlepay' ),
					'type'        => 'text',
					'description' => sprintf(
						/* translators: %s: example CSS selector matching the store's checkout */
						__( 'CSS selector of the checkout order-total element the installment widget attaches to (e.g. %s). Leave blank to fall back to automatic detection.', 'woo_sezzlepay' ),
						$order_total_container_selector_default
					),
					'desc_tip' => true,
					'default'     => $order_total_container_selector_default,
				);

				// ============================================================
				// Logging
				// ============================================================
				$this->form_fields['section-logging'] = array(
					'title' => __( 'Logging', 'woo_sezzlepay' ),
					'type'  => 'title',
					'class' => 'sezzle-section-title',
				);
				$this->form_fields['logging'] = array(
					'title'   => __( 'Sezzle Logs', 'woo_sezzlepay' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enabled', 'woo_sezzlepay' ),
					'description' => __( 'Resulting logs can be found at WooCommerce > Status > Logs > sezzlepay', 'woo_sezzlepay' ),
					'desc_tip' => true,
					'default' => 'yes',
				);

                if (class_exists('Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils') && CartCheckoutUtils::is_checkout_block_default()) {
                    unset($this->form_fields['enable-order-creation-post-checkout']);
                }
			}

			/**
			 * Whether the gateway still needs setup before it can be enabled.
			 *
			 * WooCommerce calls this for each tile on the Settings > Payments list.
			 * When it returns true, core hides the Enable/Disable toggle and shows a
			 * "Set up"/"Finish setup" link instead, so the gateway can't be switched on
			 * from the list until the API keys are present. This is the inverse of
			 * is_account_connected(), which owns what "connected" means.
			 *
			 * @return bool
			 */
			public function needs_setup() {
				return ! $this->is_account_connected();
			}

			/**
			 * Order-total selector for the installment widget on the checkout being rendered.
			 *
			 * The field default is computed from the checkout type, and WooCommerce writes
			 * defaults into the stored settings on every save. So a store that saved its
			 * settings while on the classic checkout keeps the classic selector after the
			 * checkout page is switched to blocks (or vice versa), and a set-but-unmatched
			 * selector renders nothing. A stored value equal to either built-in default is
			 * therefore treated as "not customized" and swapped for the default matching
			 * the current checkout; any other value is the merchant's and passes through.
			 *
			 * @param bool $is_block_checkout Whether the checkout renders as the Checkout block.
			 * @return string
			 */
			public function get_order_total_container_selector( $is_block_checkout ) {
				$selector = $this->get_option( 'order-total-container-selector' );
				$defaults = array( self::INSTALLMENT_WIDGET_DEFAULT_TARGET_CLASSIC, self::INSTALLMENT_WIDGET_DEFAULT_TARGET_BLOCK );
				if ( in_array( $selector, $defaults, true ) ) {
					return $is_block_checkout
						? self::INSTALLMENT_WIDGET_DEFAULT_TARGET_BLOCK
						: self::INSTALLMENT_WIDGET_DEFAULT_TARGET_CLASSIC;
				}
				return $selector;
			}

			/**
			 * Whether the merchant's Sezzle account is connected (API keys present).
			 *
			 * On the modern Settings > Payments screen, needs_setup() only drives the
			 * "Action needed" badge and the server-side block that stops the toggle
			 * from switching on (WC_AJAX::toggle_gateway_enabled). It does NOT choose
			 * which control the tile renders: WooCommerce shows the plain "Enable"
			 * toggle unless it believes the gateway still needs onboarding, which it
			 * derives from account_connected + onboarding state
			 * (PaymentsProviders\PaymentGateway). Without this method that state falls
			 * back to "connected", so the misleading "Enable" toggle appears even with
			 * no keys. Reporting the account as disconnected until keys are saved makes
			 * the tile render a "Complete setup" button that routes to these settings
			 * instead. Keys are saved together (see validate_keys()), so the presence
			 * of the public key is sufficient to know both are set.
			 *
			 * @return bool
			 */
			public function is_account_connected() {
				return ! empty( $this->get_option( 'public-key' ) );
			}

			/**
			 * Process Sezzle Settings
			 *
			 * @return bool|void
			 */
			public function process_admin_options() {
                if ( ! $this->validate_keys() ) {
                    WC_Admin_Settings::add_error( __( 'Unable to validate keys.', 'woo_sezzlepay' ) );
					return;
				}

				// Persist the newly-entered keys BEFORE sending the admin config.
				// send_admin_configuration() authenticates with the stored keys using
				// the default auto-disable behaviour. If it ran first (while the old,
				// possibly-revoked keys were still stored) a 401 would spuriously
				// disable Sezzle and show an error notice on an otherwise successful
				// key update. Saving first means it authenticates with the new,
				// already-validated keys.
				$saved = parent::process_admin_options();
				// Bail only if the save recorded a real validation error. We can't gate on
				// $saved: parent::process_admin_options() returns update_option()'s result,
				// which is false when the submitted values are byte-for-byte unchanged (a
				// no-op re-save), not only on failure. Gating on $saved would then wrongly
				// skip the config re-sync and cache clear for an unchanged-but-valid save.
				if ( ! empty( $this->get_errors() ) ) {
					return $saved;
				}

				// Sync the merchant-id with the merchant UUID captured during key
				// validation. The Merchant ID is only consumed by the on-site widgets,
				// which run against the Live (production) environment, so:
				//   - Fill an empty field from the validated keys in either mode (older
				//     merchants share the same ID across Sandbox and Live).
				//   - In Live mode, always overwrite with the returned production ID.
				//   - In Sandbox mode, keep a merchant-entered value so a store can hold
				//     its production Merchant ID while testing (widgets then work too).
				// validated_merchant_uuid is set whenever the keys authenticate (see
				// validate_keys()). Done after the parent save (which
				// stores the submitted field value) and before send_admin_configuration()
				// / cache clear, both of which read the merchant-id.
				if ( ! empty( $this->validated_merchant_uuid ) ) {
					$is_live = ( $this->get_option( 'transaction-mode' ) === self::TRANSACTION_MODE_LIVE );
					if ( $is_live || empty( $this->get_option( 'merchant-id' ) ) ) {
						$this->update_option( 'merchant-id', $this->validated_merchant_uuid );
					}
				}

				$this->send_admin_configuration();

				// Working keys are stored again, so the "keys rejected" notice is resolved.
				delete_option( self::API_ERROR_NOTICE_OPTION );

				// The keys validated successfully, so a previously-cached "disabled"
				// feature-flag result (e.g. from a prior auth failure) is now stale.
				// Drop it so the next check re-fetches and express checkout can return
				// without waiting out the cache TTL.
				$this->clear_express_checkout_flag_cache();

				return $saved;
			}

			/**
			 * Validate keys
			 *
			 * @return bool
			 */
			private function validate_keys() {
				// input data
				$public_key       = $this->field_value('public-key');
				$private_key      = $this->field_value('private-key');
				$transaction_mode = $this->field_value('transaction-mode');

				// Always authenticate, even when the keys are unchanged: keys revoked in
				// the Sezzle dashboard would otherwise save successfully and then be
				// rejected by send_admin_configuration() right after the "saved" message.
				// Saving settings is rare, so the extra call is cheap.
				$keys_unchanged = $this->get_option( 'public-key' ) == $public_key
					&& $this->get_option( 'private-key' ) == $private_key
					&& $this->get_option( 'transaction-mode' ) == $transaction_mode;
				$request = [
					'public_key'  => $public_key,
					'private_key' => $private_key
				];

                try {
                    // Don't auto-disable/clear keys here: the merchant is actively
                    // entering keys, and a failed validation simply rejects the save
                    // (leaving any previously-saved keys intact).
                    $service_v2 = new Service_V2($transaction_mode, [], ['disable_on_unauthorized' => false]);
                    $response = $service_v2->authenticate($request);
                    if ( ! isset($response->token) ) {
                        return false;
                    }
                    // The gateway returns the merchant's UUID alongside the token.
                    // Capture it so process_admin_options() can store it as the
                    // merchant-id, keeping it in sync with the validated keys.
                    if ( isset($response->merchant_uuid) ) {
                        $this->validated_merchant_uuid = $response->merchant_uuid;
                    }
                    return true;
                } catch (Exception $e) {
                    $this->log('Unable to validate keys.');
                    $this->log($e->getMessage());
                    // Sezzle unreachable (not a 401): don't block saving other settings,
                    // such as turning Sezzle off during an outage, when the keys are the
                    // ones already stored.
                    return $keys_unchanged && $e->getCode() !== 401;
                }
			}

			public function get_keys() {
				return [
					'public_key'  => $this->get_option( 'public-key' ),
					'private_key' => $this->get_option( 'private-key' )
				];
			}

			/**
			 * Drop the cached express checkout feature-flag result so the next check
			 * re-fetches it from the gateway. The result is keyed by merchant id +
			 * transaction mode; we clear both modes for the current merchant so a
			 * transaction-mode change is covered too. Also resets the request-level
			 * cache so the fresh value is used within the same request.
			 *
			 * @return void
			 */
			private function clear_express_checkout_flag_cache() {
				$merchant_id = $this->get_option( 'merchant-id' );
				if ( ! empty( $merchant_id ) ) {
					foreach ( array( self::TRANSACTION_MODE_LIVE, self::TRANSACTION_MODE_SANDBOX ) as $mode ) {
						delete_transient( self::EXPRESS_CHECKOUT_CACHE_KEY . $merchant_id . '_' . $mode );
					}
				}
				self::$request_cache = array();
			}

			/**
			 * Disable Sezzle and clear the stored API keys after an unrecoverable
			 * authentication failure.
			 *
			 * Triggered whenever the Sezzle gateway /v2/authentication endpoint returns
			 * a 401 — during a standard checkout/API call, or when an express checkout
			 * is cancelled and we re-verify the stored keys server-side. In either case
			 * the stored keys can no longer be used, so we turn off "Enable Sezzle" and
			 * wipe both key fields to keep the broken payment option from being shown to
			 * shoppers. The merchant must enter valid API keys in the plugin settings
			 * (which re-enables the option) before Sezzle is displayed again.
			 *
			 * @return void
			 */
			public function disable_due_to_unauthorized() {
				$already_disabled     = $this->get_option( 'enabled' ) === 'no';
				$keys_already_cleared = $this->get_option( 'public-key' ) === ''
					&& $this->get_option( 'private-key' ) === '';

				// Nothing to do if we've already disabled and cleared keys.
				if ( $already_disabled && $keys_already_cleared ) {
					return;
				}

				$this->update_option( 'enabled', 'no' );
				$this->update_option( 'public-key', '' );
				$this->update_option( 'private-key', '' );

				// Drop the cached express checkout feature-flag result so a stale
				// "enabled" value can't keep express checkout active after the keys
				// were rejected.
				$this->clear_express_checkout_flag_cache();

				$this->log( 'Sezzle authentication failed (401/unauthorized). Disabled the payment method and cleared the stored API keys. The merchant must re-enter valid API keys to re-enable Sezzle.' );

				// Persist until the merchant dismisses it or saves working keys. This
				// usually fires from a shopper's request, so a short-lived transient
				// (the old 60s) expired before any admin could see it.
				update_option( self::API_ERROR_NOTICE_OPTION, time(), false );
			}

			/**
			 * Mask credentials and shopper personal data before a payload reaches
			 * the log (which is also forwarded to Sezzle on payment errors): the API
			 * keys and Bearer token from /v2/authentication, and the shopper's name,
			 * email, phone and street address from session and order payloads. City,
			 * state and country are kept for tax and shipping diagnosis. Returns a
			 * copy; the caller's value is not modified.
			 *
			 * @param mixed $data
			 * @return mixed
			 */
			public static function redact_for_log( $data ) {
				$sensitive = array( 'public_key', 'private_key', 'token', 'first_name', 'last_name', 'email', 'phone', 'street', 'street2', 'postal_code' );
				if ( is_array( $data ) || is_object( $data ) ) {
					$copy = array();
					foreach ( $data as $key => $value ) {
						$copy[ $key ] = ( is_string( $key ) && in_array( $key, $sensitive, true ) && is_scalar( $value ) && '' !== $value )
							? '[redacted]'
							: self::redact_for_log( $value );
					}
					return is_object( $data ) ? (object) $copy : $copy;
				}
				return $data;
			}

			public function log( $message ) {
				if ( $this->get_option( 'logging' ) == 'no' ) {
					return;
				}
				if ( empty( self::$log ) ) {
					self::$log = new WC_Logger();
				}
				self::$log->add( 'sezzlepay', $message );
			}


			public function dump_api_actions( $url, $request = null, $response = null, $status_code = null ) {
				$this->log( $url );
				// Don't log the request body for the logs endpoint, as it will be duplicated.
				if ( strpos( $url, '/v1/logs/' ) === false ) {
					$this->log( 'Request Body' );
					$this->log( json_encode( self::redact_for_log( $request ) ) );
				}
				$this->log( 'Response Body' );
				$decoded = is_string( $response ) ? json_decode( $response ) : null;
				$this->log( is_array( $decoded ) || is_object( $decoded ) ? json_encode( self::redact_for_log( $decoded ) ) : $response );
				$this->log( $status_code );
			}

			private function get_order( $order_id ) {
				return function_exists( 'wc_get_order' ) ?
					wc_get_order( $order_id ) :
					new WC_Order( $order_id );
			}

            public function get_logs()
            {
                $files = glob(WP_CONTENT_DIR . '/uploads/wc-logs/*log');
                $sezzle_log = '';
                $fatal_log = '';
                foreach ($files as $file) {
                    switch (true) {
                        case strpos($file, 'sezzlepay-' . date('Y-m-d')) !== false:
                            $sezzle_log = file_get_contents($file);
                            break;
                        case strpos($file, 'fatal-errors-' . date('Y-m-d')) !== false:
                            $fatal_log = file_get_contents($file);
                            break;
                    }
                }

                return [
                    'sezzle_log' => $sezzle_log,
                    'fatal_log' => $fatal_log
                ];
            }

			/**
			 * Update or create meta data for order
			 * Updating meta data is not saved automatically, the caller needs to save the changes
			 *
			 * @param WC_Order $order
			 * @param string $key
			 * @param string $value
			 * @return void
			 */
			public function update_or_create_meta_data($order, $key, $value) {
				if ($order->meta_exists($key)) {
					$order->update_meta_data($key, $value);
				} else {
					$order->add_meta_data($key, $value);
				}
			}

			public function process_payment( $order_id ) {
				try {
					$this->log( sprintf( 'Processing payment for order #%d.', $order_id ) );
					$order = $this->get_order( $order_id );

					$checkout_data = $this->format_checkout_data( $order );
					$session  = $this->redirect_to_checkout( $checkout_data );
					$redirect_url = $session['redirect_url'];
					$order->set_transaction_id( $checkout_data['order']['reference_id'] );

					// A shopper can restart checkout on the same order (abandon, then
					// pay again, or two tabs). Keep the superseded session ids so the
					// callback can still capture whichever session was actually approved.
					$previous_uuid = $order->get_meta('sezzle_order_uuid', true);
					if ($previous_uuid && $previous_uuid !== $session['order_uuid']) {
						$previous_uuids = (array) $order->get_meta('sezzle_previous_order_uuids', true);
						$previous_uuids[] = $previous_uuid;
						$this->update_or_create_meta_data($order, 'sezzle_previous_order_uuids', array_values(array_unique(array_filter($previous_uuids))));
					}

					$this->update_or_create_meta_data($order, 'sezzle_redirect_url', $redirect_url);
					$this->update_or_create_meta_data($order, 'sezzle_order_uuid', $session['order_uuid']);
					$order->save();

					$this->log( sprintf( 'Payment session created for order #%d. Redirecting shopper to Sezzle checkout.', $order_id ) );
					$result = 'success';
					$redirect = $redirect_url;
				} catch (Exception $e) {
					$this->log( sprintf( 'Failed to start payment for order #%d: %s', $order_id, $e->getMessage() ) );
					$result = 'failure';
					wc_add_notice( __( 'Sorry, there was a problem preparing your payment.', 'woo_sezzlepay' ), 'error' );

					$redirect = isset($order) && $order instanceof WC_Order ?
						$order->get_checkout_payment_url(true) :
						wc_get_checkout_url() ;
				}

				return [
					'result'   => $result,
					'redirect' => $redirect
				];
			}

			/**
			 * @param WC_Order|null $order
			 * @param array|null $post_data
			 *
			 * @return array
			 */
			public function format_checkout_data( $order = null, $post_data = [] ) {
				$order_exist = $order instanceof WC_Order;

				$order_reference_id = uniqid();

				if ( $order_exist ) {
					// The caller stores this as the transaction id once the session is
					// created; storing it here broke completing an older, still-open
					// session when creating the new one failed.
					$order_reference_id = $order_reference_id . '-' . $order->get_id();
					$complete_url_arg = [ 'key' => $order->get_order_key() ];
					$total            = $order->get_total();
					$order_items      = $order->get_items();
					// Label the amounts with the currency they are in: the order's. The
					// store currency can differ (a currency switcher that doesn't apply to
					// this request), which authorized the order's total in the wrong
					// currency, and the capture, sent in the order's currency, was then
					// rejected with invalid_currency_code.
					$currency         = $order->get_currency();
				} else {
					$total = WC()->cart->get_totals()['total'];
					$complete_url_arg = [ 'order_reference_id' => $order_reference_id ];
					$order_items      = WC()->cart->get_cart_contents();
					$currency         = get_woocommerce_currency();
				}

				$amount_in_cents = Sezzle_Utils::formatToCents($total);

				$complete_url = add_query_arg($complete_url_arg, WC()->api_request_url( get_class( $this ) ) );

				$get_product = function ( $id ) {
					return function_exists( 'wc_get_product' ) ?
						wc_get_product( $id ) :
						new WC_Product( $id );
				};

				$items = [];
				foreach ( $order_items as $item ) {
					$product_id = $item['variation_id'] ? $item['variation_id'] : $item['product_id'];
					$product = $get_product( $product_id );

					$item_qty = $order_exist ? $item['qty'] : $item['quantity'];
					$itemData = array(
						'name'     => $product->get_name(),
						'sku'      => $product->get_sku(),
						'quantity' => (int) $item_qty,
						'price'    => array(
							'amount_in_cents' => Sezzle_Utils::formatToCents($item['line_subtotal'] / $item_qty),
							'currency'        => $currency,
						),
					);
					$items[]  = $itemData;
				}

				return [
					'order' => [
						'intent' => 'AUTH',
						'reference_id'         => $order_reference_id,
						'description'          => $order_exist ? (string)$order->get_id() : $order_reference_id,
						'items' => $items,
						'order_amount' => [
							'amount_in_cents'            => $amount_in_cents,
							'currency'              => $currency,
						],
					],
					'cancel_url' => [
						'href' => wc_get_checkout_url(),
					],
					'complete_url' => [
						'href' => $complete_url,
					],
					'customer'           => [
						'first_name' => $order_exist ? $order->get_billing_first_name() : $post_data['billing_first_name'],
						'last_name'  => $order_exist ? $order->get_billing_last_name() : $post_data['billing_last_name'],
						'email'      => $order_exist ? $order->get_billing_email() : $post_data['billing_email'],
						'phone'      => $order_exist ? $order->get_billing_phone() : $post_data['billing_phone'],
						'billing_address' => [
							'street'       => $order_exist ? $order->get_billing_address_1() : $post_data['billing_address_1'],
							'street2'      => $order_exist ? $order->get_billing_address_2() : $post_data['billing_address_2'],
							'city'         => $order_exist ? $order->get_billing_city() : $post_data['billing_city'],
							'state'        => $order_exist ? $order->get_billing_state() : $post_data['billing_state'],
							'postal_code'  => $order_exist ? $order->get_billing_postcode() : $post_data['billing_postcode'],
							'country_code' => $order_exist ? $order->get_billing_country() : $post_data['billing_country'],
							'phone'        => $order_exist ? $order->get_billing_phone() : $post_data['billing_phone'],
						],
						'shipping_address' => [
							'street'       => $order_exist ? $order->get_shipping_address_1() : ($post_data['shipping_address_1'] ?? ''),
							'street2'      => $order_exist ? $order->get_shipping_address_2() : ($post_data['shipping_address_2'] ?? ''),
							'city'         => $order_exist ? $order->get_shipping_city() : ($post_data['shipping_city'] ?? ''),
							'state'        => $order_exist ? $order->get_shipping_state() : ($post_data['shipping_state'] ?? ''),
							'postal_code'  => $order_exist ? $order->get_shipping_postcode() : ($post_data['shipping_postcode'] ?? ''),
							'country_code' => $order_exist ? $order->get_shipping_country() : ($post_data['shipping_country'] ?? ''),
						],
					],
				];
			}

			public function redirect_to_checkout( $data ) {
				$txn_mode   = $this->get_option('transaction-mode');
				$service_v2 = new Service_V2($txn_mode, $this->get_keys());

				$response = $service_v2->create_session($data);
				if ( isset( $response->order ) ) {
					$order_uuid   = $response->order->uuid;
					$redirect_url = $response->order->checkout_url;
					return [
						'redirect_url' => $redirect_url,
						'order_uuid'   => $order_uuid
					];
				}

				// Callers report the failure to the shopper; returning here used to let
				// process_payment() record a null session and report success.
				throw new Exception( 'Sezzle session response did not include an order.' );
			}

            /**
             * Retrieves Sezzle order
             *
             * @param string $sezzle_order_uuid
             * @return mixed|object
             */
            private function get_sezzle_order_details($sezzle_order_uuid)
            {
                $txn_mode = $this->get_option('transaction-mode');
                $service_v2 = new Service_V2($txn_mode, $this->get_keys());
                return $service_v2->get_order_details($sezzle_order_uuid);
            }

			/**
			 * Retrieves Sezzle order for express checkout
			 *
			 * @param string $sezzle_order_uuid
			 * @return mixed|object
			 */
			public function get_sezzle_order($sezzle_order_uuid) {
				return $this->get_sezzle_order_details($sezzle_order_uuid);
			}

            /**
             * Callback to do the capture
             *
             * @return void
             */
            public function sezzle_payment_callback()
            {
				$order = null;
				$sezzle_order_uuid = null;
				$created_order = false;
                try {
                    $_REQUEST = stripslashes_deep($_REQUEST);
                    $order_key = isset($_REQUEST['key']) ? sanitize_text_field($_REQUEST['key']) : '';
                    $order_reference_id = isset($_REQUEST['order_reference_id']) ? sanitize_text_field($_REQUEST['order_reference_id']) : '';

                    if ($order_key === '' && $order_reference_id === '') {
                        throw new Exception(__('Order reference ID not matching', 'woo_sezzlepay'));
                    }

                    // The shopper's browser can hit this URL twice (refresh, back button,
                    // double redirect). Serialize so a second hit sees the first one's result.
                    $this->acquire_completion_lock($order_key ?: $order_reference_id);

                    if ($order_key) {
                        $order = $this->get_order(wc_get_order_id_by_order_key($order_key));
                        if (!$order || $order->get_payment_method() !== $this->id) {
                            throw new Exception(__('Unable to find the order for this payment.', 'woo_sezzlepay'));
                        }
                        $order_reference_id = $order->get_transaction_id();
                        $sezzle_order_uuid = $order->get_meta('sezzle_order_uuid', true);
                    } else {
                        $order = $this->get_order_by_txn_id($order_reference_id);
                        $sezzle_order_uuid = $order ? $order->get_meta('sezzle_order_uuid', true) : '';
                        $sezzle_order_uuid = $sezzle_order_uuid ?: WC()->session->get('sezzle_order_uuid');
                    }

                    if (!$sezzle_order_uuid) {
                        throw new Exception(__('Unable to find the Sezzle checkout for this order.', 'woo_sezzlepay'));
                    }
					$sezzle_order = $this->get_sezzle_order_details($sezzle_order_uuid);

					if ($order && !$order->is_paid() && !$this->is_sezzle_order_authorized($sezzle_order)) {
						$previous_session = $this->find_authorized_previous_session($order);
						if ($previous_session) {
							$sezzle_order = $previous_session;
							$sezzle_order_uuid = $sezzle_order->uuid ?? $sezzle_order_uuid;
							$order_reference_id = $order->get_transaction_id();
						}
					}

					if (isset($sezzle_order->reference_id) && $sezzle_order->reference_id !== $order_reference_id) {
						throw new Exception(__('Order reference ID not matching', 'woo_sezzlepay'));
					}

					// Never create or capture an order for a checkout the shopper didn't
					// approve: anyone holding a reference ID can open this URL. A repeat hit
					// for a paid order still falls through to the receipt redirect.
					if ((!$order || !$order->is_paid()) && !$this->is_sezzle_order_authorized($sezzle_order)) {
						throw new Exception(__('Sezzle was unable to complete your payment. Please try again or choose another payment method.', 'woo_sezzlepay'));
					}

					if (!$order) {
						$order = $this->create_sezzle_order($order_reference_id, $sezzle_order_uuid);
						$created_order = true;
					} elseif ($order->get_meta('sezzle_order_uuid', true) !== $sezzle_order_uuid) {
						$this->update_or_create_meta_data($order, 'sezzle_order_uuid', $sezzle_order_uuid);
						$order->save();
					}

                    $this->process_order_payment($order, $order_reference_id, $sezzle_order, $order_key);
                } catch (Exception $e) {
					// With "create order post checkout completion" the order should only
					// exist once Sezzle has been paid, so don't leave an unpaid one behind.
                    $this->handle_payment_exception($e, $sezzle_order_uuid, $order, $created_order);
                }
            }

			/**
			 * Whether the shopper approved this Sezzle checkout.
			 *
			 * @param object|null $sezzle_order
			 * @return bool
			 */
			public function is_sezzle_order_authorized($sezzle_order) {
				return is_object($sezzle_order)
					&& !empty($sezzle_order->authorization)
					&& (!isset($sezzle_order->authorization->approved) || $sezzle_order->authorization->approved);
			}

			/**
			 * When the order's latest Sezzle session was not approved, look for an
			 * earlier session on the same order that was (the shopper restarted
			 * checkout, then finished the older one).
			 *
			 * @param WC_Order $order
			 * @return object|null The approved Sezzle order, or null.
			 */
			private function find_authorized_previous_session($order) {
				$previous_uuids = array_reverse((array) $order->get_meta('sezzle_previous_order_uuids', true));
				foreach (array_filter($previous_uuids) as $uuid) {
					$candidate = $this->get_sezzle_order_details($uuid);
					if ($this->is_sezzle_order_authorized($candidate)) {
						if (isset($candidate->reference_id)) {
							$order->set_transaction_id($candidate->reference_id);
						}
						return $candidate;
					}
				}
				return null;
			}

			/**
			 * Serialize completion of one checkout across concurrent requests with a
			 * MySQL named lock. It is atomic, needs no cache invalidation, and is held
			 * by the database connection, so it is released when the request ends
			 * however it ends (including exit or a fatal error), even mid-capture.
			 *
			 * @param string $name Identifier of the checkout being completed.
			 * @throws Exception When another request still holds the lock.
			 */
			private function acquire_completion_lock($name) {
				global $wpdb;
				// MySQL lock names are limited to 64 characters.
				$lock = self::COMPLETION_LOCK_PREFIX . substr(hash('sha256', $wpdb->prefix . (string) $name), 0, 48);
				$acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock, self::COMPLETION_LOCK_WAIT));
				if ((string) $acquired !== '1') {
					throw new Exception(__('Your payment is still being processed. Please wait a moment and refresh the page.', 'woo_sezzlepay'));
				}
			}

			/**
			 * Public wrapper so express checkout completion shares the same lock.
			 *
			 * @param string $name
			 * @throws Exception
			 */
			public function lock_completion($name) {
				$this->acquire_completion_lock($name);
			}

            /**
             * Get order by transaction ID (Order Reference ID)
             *
             * @param string $txn_id Order Reference ID
             * @return false|mixed|WC_Order
             */
            private function get_order_by_txn_id($txn_id)
            {
                $orders = wc_get_orders(['transaction_id' => $txn_id, 'orderby' => 'date', 'order' => 'DESC']);
                if (count($orders) == 0) {
                    return false;
                }

                $this->log(sprintf('Order count for order reference ID: %s is %d', $txn_id, count($orders)));

                // ideally there should not be more one order for one order reference ID
                // but, if in case, always take the latest (newest first)
                return $orders[0];
            }

            /**
             * Create Sezzle order
             *
             * @return bool|WC_Order|WC_Order_Refund
             */
            private function create_sezzle_order($order_reference_id, $sezzle_order_uuid)
            {
                $posted_data = WC()->session->get('posted_data');
                if (empty($posted_data)) {
                    // Cleared once an order was created from it (see below), or the session
                    // expired: the shopper has to submit checkout again.
                    throw new Exception(__('Unable to create order.', 'woo_sezzlepay'));
                }
                $sezzle_checkout = Sezzle_Checkout::instance();
                $sezzle_checkout->process_customer($posted_data);

                sezzle_restore_missing_cart_fees();

                // Ensure cart totals (including shipping packages) are calculated
                // even when sezzle_restore_missing_cart_fees() returned early due
                // to no stored fees. Without this, create_order() won't find
                // shipping packages on a fresh page load (return from Sezzle).
                if (empty(WC()->session->get('sezzle_stored_cart_fees'))) {
                    WC()->cart->calculate_totals();
                }

                $order_id = WC()->checkout()->create_order($posted_data);
                sezzle_cleanup_stored_cart_fees();
                $order = $this->get_order($order_id);

                switch (true) {
                    case is_wp_error($order_id):
                        throw new Exception($order_id->get_error_message());
                    case !$order:
                        throw new Exception(__('Unable to create order.', 'woo_sezzlepay'));
                }

                // Make the order findable by a retried callback before anything can fail.
                $order->set_transaction_id($order_reference_id);
                $this->update_or_create_meta_data($order, 'sezzle_order_uuid', $sezzle_order_uuid);
                $order->save();

                // The posted checkout form (address, phone, and any account password) was
                // kept in the session only to build this order; don't leave it there.
                WC()->session->__unset('posted_data');

                // create_order() fires core's attribution hook, but this request is the
                // return from Sezzle and posts no attribution fields: use the stored ones.
                sezzle_save_order_attribution($order, WC()->session->get('sezzle_order_attribution'));
                WC()->session->__unset('sezzle_order_attribution');

                do_action('woocommerce_checkout_order_processed', $order_id, $posted_data, $order);

                $this->log(sprintf('Created WooCommerce order #%d from stored checkout data on return from Sezzle.', $order_id));

                return $order;
            }

            /**
             * Determines if payment should be captured
             *
             * @param object $sezzle_order
             * @param WC_Order $order
             * @return bool
             */
            private function should_capture_payment($sezzle_order, $order)
            {
                if (!empty($sezzle_order->authorization->captures)) {
                    return false;
                }

                $woo_order_amount_in_cents = Sezzle_Utils::formatToCents($order->get_total());
                // Compare against what the shopper actually authorized; fall back to the
                // session amount when the response carries no authorization amount. Amount
                // and currency come from the same object so they describe the same charge.
                $authorized_amount = $sezzle_order->authorization->authorization_amount
                    ?? $sezzle_order->order_amount
                    ?? null;
                $authorized_amount_in_cents = (int) ($authorized_amount->amount_in_cents ?? 0);
                // Sezzle rejects a capture in another currency than the authorization, and
                // matching amounts in different currencies are not the same charge.
                $order_currency = strtoupper($order->get_currency());
                // Absent or blank currency: nothing to compare, so don't block the capture.
                $authorized_currency = strtoupper(($authorized_amount->currency ?? '') ?: $order_currency);

                if ($woo_order_amount_in_cents !== $authorized_amount_in_cents
                    || $authorized_currency !== $order_currency) {
                    // Not wrapped in __(): the string is built at runtime, so it can't be extracted.
                    // The formatted total holds entities (&#36;, &nbsp;); decode them so the
                    // plain-text message, log and JSON response read "2.80 $", not the markup.
                    $order_total = html_entity_decode(wp_strip_all_tags($order->get_formatted_order_total()), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    // The CAPTURE_FAILED events are built from this message, so it carries both
                    // sides of the comparison; a currency mismatch is otherwise invisible.
                    $msg = sprintf(
                        'Unable to complete payment. %s to %s. Authorized %d %s, order %d %s.',
                        self::ERROR_CART_AMOUNT_MISMATCH, $order_total,
                        $authorized_amount_in_cents, $authorized_currency,
                        $woo_order_amount_in_cents, $order_currency
                    );
                    $this->log($msg);
                    throw new Exception($msg);
                }

                return true;
            }

            /**
             * Processes order payment based on its status
             *
             * @param WC_Order $order
             * @param string $order_reference_id
			 * @param object $sezzle_order
             * @param string $order_key
             * @return void
             */
            private function process_order_payment($order, $order_reference_id, $sezzle_order, $order_key) {
                if ($this->should_capture_payment($sezzle_order, $order)) { 
                    $this->capture_payment($order, $order_reference_id, $sezzle_order->uuid, $order_key);
                    return;
                }

                if (!$order->is_paid()) {
                    $this->mark_payment_complete($order, $order_reference_id);
                    return;
                }

                // Already paid (a repeated callback): send the shopper to the receipt.
                if (wp_doing_ajax()) {
                    return;
                }
                wp_redirect($this->get_return_url($order));
                exit;
            }

			/**
			 * Processes order for express checkout
			 *
			 * @param WC_Order $order
			 * @param string $order_reference_id
			 * @param object $sezzle_order
			 * @param string $order_key
			 * @return void
			 */
			public function process_order($order, $order_reference_id, $sezzle_order, $order_key) {
				return $this->process_order_payment($order, $order_reference_id, $sezzle_order, $order_key);
			}

            /**
             * Completes a payment in Woo
             *
             * @param WC_Order $order
             * @param string $order_reference_id
             * @return void
             */
            private function mark_payment_complete($order, $order_reference_id)
            {
                $this->log(sprintf('Order #%d already captured on Sezzle; marking payment complete (reference ID: %s).', $order->get_id(), $order_reference_id));
                $order->add_order_note(__('Payment approved by Sezzle successfully.', 'woo_sezzlepay'));
                $order->payment_complete($order_reference_id);
                WC()->cart->empty_cart();
                if (wp_doing_ajax()) {
                    return;
                }
                wp_redirect($this->get_return_url($order));
                exit;
            }

            /**
             * Captures a Sezzle payment associated with a Woo order
             *
             * @param WC_Order $order
             * @param string $order_reference_id
			 * @param string $sezzle_order_uuid
             * @param string $order_key
             * @return void
             */
            private function capture_payment($order, $order_reference_id, $sezzle_order_uuid, $order_key)
            {
                // From here on Sezzle may have captured even if we see an error.
                $this->capture_attempted = true;
                $txn_mode = $this->get_option('transaction-mode');
                $service_v2 = new Service_V2($txn_mode, $this->get_keys());
				$total = $order->get_total();
				$request = [
					'capture_amount' => [
						'amount_in_cents' => Sezzle_Utils::formatToCents($total),
						'currency'        => $order->get_currency(),
					]
				];
                $this->log(sprintf('Capturing payment for order #%d (Sezzle order UUID: %s, amount: %d %s).', $order->get_id(), $sezzle_order_uuid, $request['capture_amount']['amount_in_cents'], $request['capture_amount']['currency']));
                try {
                    $response = $service_v2->capture($sezzle_order_uuid, $request);
                } catch (Sezzle_Api_Exception $e) {
                    // make_call() throws on any non-2xx response; hand Sezzle's error
                    // (checkout_captured, checkout_expired, ...) to handle_failed_capture()
                    // instead of losing it. Transport errors carry no body: null.
                    $response = $e->get_error();
                    if ($this->is_known_capture_rejection($e->getCode(), $response)) {
                        $this->capture_attempted = false;
                    }
                }

                if (is_object($response) && isset($response->uuid)) {
                    $this->handle_successful_capture($order, $order_reference_id, $order_key);
                } else {
                    $this->handle_failed_capture($order, $response);
                }
            }

            /**
             * Whether a non-2xx capture response means Sezzle refused the capture, so
             * nothing was captured.
             *
             * A 4xx is Sezzle rejecting the request, with two exceptions: a 409 conflict,
             * and sezzle-pay's already_completed (also returned for an authorization an
             * earlier capture already completed) or internal_data_inconsistency codes,
             * none of which rule out a capture. Timeouts and transport errors (status 0)
             * and 5xx responses leave the outcome unknown.
             *
             * @param int         $status HTTP status of the capture response.
             * @param object|null $error  Sezzle's error object from the response body.
             * @return bool
             */
            private function is_known_capture_rejection($status, $error) {
                if ($status < 400 || $status >= 500 || $status === 409) {
                    return false;
                }
                $code = isset($error->code) ? strtolower((string) $error->code) : '';
                return !in_array($code, array('already_completed', 'internal_data_inconsistency'), true);
            }

            /**
             * Handle successful Sezzle payment capture
             *
             * @param WC_Order $order
             * @param string $order_reference_id
             * @param string $order_key
             * @return void
             */
            private function handle_successful_capture($order, $order_reference_id, $order_key) {
                $this->log(sprintf('Capture succeeded for order #%d (reference ID: %s).', $order->get_id(), $order_reference_id));
                $order->add_order_note(__('Payment approved by Sezzle successfully.', 'woo_sezzlepay'));
                $order->payment_complete($order_reference_id);
                WC()->cart->empty_cart();

                if (!$order_key) {
                    $order->add_meta_data('order_reference_id', $order_reference_id);
                    $order->set_transaction_id($order_reference_id);
                    $order->save();
                    apply_filters(
                        'woocommerce_payment_successful_result',
                        ['result' => 'success', 'redirect' => $this->get_return_url($order)],
                        $order->get_id()
                    );
                }

				if(wp_doing_ajax()) {
					return;
				}
				
				wp_redirect($this->get_return_url($order));
				exit;
            }

            /**
             * Handles failed Sezzle payment capture
             *
             * @param WC_Order $order
             * @param null|object $response
             * @return void
             */
            private function handle_failed_capture($order, $response) {
                $this->log(sprintf('Capture failed for order #%d. Sezzle response code: %s.', $order->get_id(), is_object($response) && isset($response->code) ? $response->code : 'unknown'));
                // Sezzle already captured this checkout (e.g. a concurrent callback):
                // the payment went through, so complete the order rather than fail it.
                if (isset($response->code) && strtolower($response->code) === 'checkout_captured') {
                    if (!$order->is_paid()) {
                        $order->add_order_note(__('Payment approved by Sezzle successfully.', 'woo_sezzlepay'));
                        $order->payment_complete();
                        WC()->cart->empty_cart();
                    }
                    if (wp_doing_ajax()) {
                        return;
                    }
                    wp_redirect($this->get_return_url($order));
                    exit;
                }

                if (isset($response->code) && strtolower($response->code) === 'checkout_expired') {
                    // Sezzle explicitly reported it did not capture, so the outcome is known.
                    $this->capture_attempted = false;
                }

                if ($this->capture_attempted) {
                    // The outcome is unknown (timeout, 5xx, or a response that doesn't rule
                    // out a capture): Sezzle may have captured. Hold the order for the
                    // merchant to reconcile; a failed order would let the shopper pay again.
                    $order->update_status('on-hold', __('Sezzle capture result could not be confirmed. Check this order in the Sezzle Merchant Dashboard.', 'woo_sezzlepay'));
                } else {
                    if (isset($response->code)) {
                        // Not wrapped in __(): Sezzle supplies this text at runtime.
                        $order->add_order_note(ucfirst($response->code . ' : ' . ($response->message ?? '')));
                    } else {
                        $order->add_order_note(
                            __('The payment failed because of an unknown error. Please contact Sezzle from the Sezzle merchant dashboard.', 'woo_sezzlepay')
                        );
                    }
                    $order->update_status('failed');
                }

                // Throw so both callers report the failure: the classic callback via
                // handle_payment_exception(), express completion via its JSON error.
                throw new Exception(__('Sezzle was unable to complete your payment. Please try again or choose another payment method.', 'woo_sezzlepay'));
            }

            /**
             * Handles exceptions during payment processing
             *
             * @param Exception $exception
             * @param WC_Order|null $order
             * @param bool $delete_unpaid_order Delete $order if it is unpaid (post-checkout order creation)
             * @return void
             */
            private function handle_payment_exception($exception, $sezzle_order_uuid = null, $order = null, $delete_unpaid_order = false) {
                $message = $exception->getMessage() ?: __('An unknown error occurred during payment processing. Please contact Sezzle support.', 'woo_sezzlepay');
                $this->log($message);

                $txn_mode = $this->get_option('transaction-mode');
                $service_v2 = new Service_V2($txn_mode, $this->get_keys());
                $merchant_uuid = $this->get_option('merchant-id');

                // Add gateway event logging for cart amount mismatch
                if ($order instanceof WC_Order && strpos($message, self::ERROR_CART_AMOUNT_MISMATCH) !== false) {
                    try {
                        $log_data = [
                            // "event" is used here for direct gateway API calls;
                            // the checkout SDK's logEvent uses "status" and converts it to "event" internally
                            'event' => 'CAPTURE_FAILED',
                            'order_uuid' => $sezzle_order_uuid,
                            'mode' => $txn_mode,
                            'message' => $message . ' ' . json_encode($this->get_order_json($order))
                        ];
                        $service_v2->log_event($log_data);
                    } catch (Exception $e) {
                        $this->log('Failed to send gateway log event: ' . $e->getMessage());
                    }
                }

                // The log endpoint is keyed by the Merchant ID, which a Sandbox store may
                // hold as its production UUID (kept for the widgets, see
                // process_admin_options()), so it wouldn't match the Sandbox gateway.
                // Only forward logs in Live mode.
                if ($txn_mode === self::TRANSACTION_MODE_LIVE) {
                    $service_v2->send_logs($merchant_uuid, json_encode($this->get_logs()), $sezzle_order_uuid, $this->get_order_json($order));
                }

                if ($delete_unpaid_order) {
                    $this->release_unpaid_order($order);
                }

                wc_add_notice($message, 'error');
                wp_redirect(wc_get_checkout_url());
                exit;
            }

			/**
			 * Clean up an order created after the shopper returned from Sezzle (post-checkout
			 * order creation) whose payment failed, so no unpaid order is left behind.
			 *
			 * @param WC_Order|null $order
			 * @return void
			 */
			public function release_unpaid_order($order) {
				if (!$order instanceof WC_Order || $order->is_paid()) {
					return;
				}
				if ($this->capture_attempted) {
					// The capture's outcome is unknown (timeout, 5xx, or a response without
					// an explicit non-capture code): keep the order
					// for the merchant to reconcile rather than lose a possible payment.
					$order->update_status('on-hold', __('Sezzle capture result could not be confirmed. Check this order in the Sezzle Merchant Dashboard.', 'woo_sezzlepay'));
				} else {
					$order->delete(true);
				}
			}

			/**
			 * Get order details in JSON format (data related to price only)
			 *
			 * @param WC_Order|int $order Order object or order ID
			 * @return array Order price-related details in JSON format
			 */
			private function get_order_json( $order ) {
				// Convert order ID to order object if needed
				if ( ! $order instanceof WC_Order ) {
					$order = $this->get_order( $order );
				}

				if ( ! $order instanceof WC_Order ) {
					return [];
				}

				// Build line items
				$line_items = [];
				foreach ( $order->get_items() as $item_id => $item ) {
					$product = $item->get_product();
					$line_item = [
						'id'            => $item_id,
						'name'          => $item->get_name(),
						'product_id'    => $item->get_product_id(),
						'variation_id'  => $item->get_variation_id(),
						'quantity'      => $item->get_quantity(),
						'tax_class'     => $item->get_tax_class(),
						'subtotal'      => $item->get_subtotal(),
						'subtotal_tax'  => $item->get_subtotal_tax(),
						'total'         => $item->get_total(),
						'total_tax'     => $item->get_total_tax(),
						'taxes'         => [],
						'sku'           => $product ? $product->get_sku() : '',
						'price'         => $item->get_quantity() > 0 ? $item->get_total() / $item->get_quantity() : $item->get_total(),
					];

					// Add taxes
					$item_taxes = $item->get_taxes();
					if ( ! empty( $item_taxes['total'] ) ) {
						foreach ( $item_taxes['total'] as $tax_id => $tax_amount ) {
							if ( $tax_amount > 0 ) {
								$line_item['taxes'][] = [
									'id'       => $tax_id,
									'total'    => $tax_amount,
									'subtotal' => isset( $item_taxes['subtotal'][ $tax_id ] ) ? $item_taxes['subtotal'][ $tax_id ] : $tax_amount,
								];
							}
						}
					}

					$line_items[] = $line_item;
				}

				// Build tax lines
				$tax_lines = [];
				foreach ( $order->get_tax_totals() as $tax_id => $tax ) {
					$tax_lines[] = [
						'id'               => $tax_id,
						'rate_code'        => $tax->rate_code,
						'rate_id'          => $tax->rate_id,
						'label'            => $tax->label,
						'compound'         => (bool) $tax->is_compound,
						'tax_total'        => $tax->amount,
						'shipping_tax_total' => $tax->shipping_tax_amount,
					];
				}

				// Build shipping lines
				$shipping_lines = [];
				foreach ( $order->get_items( 'shipping' ) as $item_id => $shipping_item ) {
					$shipping_lines[] = [
						'id'          => $item_id,
						'method_title' => $shipping_item->get_method_title(),
						'method_id'   => $shipping_item->get_method_id(),
						'total'       => $shipping_item->get_total(),
						'total_tax'   => $shipping_item->get_total_tax(),
					];
				}

				// Build fee lines
				$fee_lines = [];
				foreach ( $order->get_items( 'fee' ) as $item_id => $fee_item ) {
					$fee_lines[] = [
						'id'         => $item_id,
						'name'       => $fee_item->get_name(),
						'tax_class'  => $fee_item->get_tax_class(),
						'tax_status' => $fee_item->get_tax_status(),
						'total'      => $fee_item->get_total(),
						'total_tax'  => $fee_item->get_total_tax(),
					];
				}

				// Build coupon lines
				$coupon_lines = [];
				foreach ( $order->get_items( 'coupon' ) as $item_id => $coupon_item ) {
					$coupon_lines[] = [
						'id'          => $item_id,
						'code'        => $coupon_item->get_code(),
						'discount'    => $coupon_item->get_discount(),
						'discount_tax' => $coupon_item->get_discount_tax(),
					];
				}

				// Build order JSON with price-related data only
				$order_json = [
					'currency'            => $order->get_currency(),
					'discount_total'      => $order->get_total_discount(),
					'discount_tax'        => $order->get_discount_tax(),
					'shipping_total'      => $order->get_shipping_total(),
					'shipping_tax'        => $order->get_shipping_tax(),
					'cart_tax'            => $order->get_cart_tax(),
					'total'               => $order->get_total(),
					'total_tax'           => $order->get_total_tax(),
					'prices_include_tax'  => $order->get_prices_include_tax(),
					'line_items'          => $line_items,
					'tax_lines'           => $tax_lines,
					'shipping_lines'      => $shipping_lines,
					'fee_lines'           => $fee_lines,
					'coupon_lines'        => $coupon_lines,
				];

				return $order_json;
			}

			public function process_refund( $order_id, $amount = null, $reason = '' ) {
				$order = $this->get_order( $order_id );
				$sezzle_order_uuid = $order->get_meta('sezzle_order_uuid', true);

				// Without the Sezzle order UUID there is nothing to refund against, and
				// the request would resolve to /v2/order//refund. Fail before calling out.
				if ( empty( $sezzle_order_uuid ) ) {
					$this->log( sprintf( 'Refund of %s failed for order #%d: no Sezzle order UUID stored on the order.', $amount, $order_id ) );
					return $this->refund_failure(
						$order,
						__( 'This order has no Sezzle order ID stored against it, so there is nothing to refund.', 'woo_sezzlepay' )
					);
				}

				$request = [
					'amount_in_cents' => Sezzle_Utils::formatToCents($amount),
					'currency'        => $order->get_currency(),
				];
				$txn_mode   = $this->get_option('transaction-mode');
				$service_v2 = new Service_V2($txn_mode, $this->get_keys());
				$this->log( sprintf( 'Processing refund of %s for order #%d (Sezzle order UUID: %s).', $amount, $order_id, $sezzle_order_uuid ) );
				try {
					$response = $service_v2->refund($sezzle_order_uuid, $request);
				} catch ( Exception $e ) {
					// make_call() throws on any non-2xx response. Sezzle's own message says
					// why (e.g. the amount exceeds what can be refunded); fall back to the
					// exception's when the response carried none.
					$error  = $e instanceof Sezzle_Api_Exception ? $e->get_error() : null;
					$detail = ! empty( $error->message ) ? $error->message : $e->getMessage();
					$this->log( sprintf( 'Refund of %s failed for order #%d: %s (HTTP %d)', $amount, $order_id, $detail, $e->getCode() ) );
					return $this->refund_failure(
						$order,
						sprintf(
							/* translators: %s: error message returned by the Sezzle API. */
							__( 'Sezzle rejected the refund: %s', 'woo_sezzlepay' ),
							$detail
						)
					);
				}

				if ( is_object($response) && ! empty( $response->uuid ) ) {
					$this->log( sprintf( 'Refund of %s succeeded for order #%d.', $amount, $order_id ) );
					$order->add_order_note(
						sprintf(
							/* translators: %s: refund amount, formatted with currency */
							__( 'Refund of %s successfully sent to Sezzle.', 'woo_sezzlepay' ),
							wp_strip_all_tags( wc_price( $amount, array( 'currency' => $order->get_currency() ) ) )
						)
					);
					return true;
				}

				$this->log( sprintf( 'Refund of %s failed for order #%d: the response carried no refund uuid.', $amount, $order_id ) );
				return $this->refund_failure(
					$order,
					__( 'Sezzle accepted the request but did not return a refund ID.', 'woo_sezzlepay' )
				);
			}

			/**
			 * Record a failed refund attempt on the order and hand WooCommerce a reason
			 * to show the merchant: wc_refund_payment() surfaces a WP_Error's message in
			 * the refund UI.
			 *
			 * @param WC_Order $order
			 * @param string   $reason Merchant-facing reason, already translated.
			 * @return WP_Error
			 */
			private function refund_failure( $order, $reason ) {
				// Two sentences rather than one format string, so the existing note
				// translation is reused as-is.
				$order->add_order_note(
					__( 'There was an error submitting the refund to Sezzle.', 'woo_sezzlepay' ) . ' ' . $reason
				);
				return new WP_Error( 'sezzle_refund_failed', $reason );
			}

			/**
			 * Read a settings field's effective value (the submitted value during a save,
			 * otherwise the stored option). Thin wrapper over get_field_value() supplying
			 * the field definition and posted data, to cut the repetition at the call sites.
			 *
			 * @param string $key Form field key.
			 * @return mixed
			 */
			private function field_value( $key ) {
				$fields = $this->get_form_fields();
				if ( ! isset( $fields[ $key ] ) ) {
					// No field definition (e.g. a conditionally-skipped section);
					// fall back to the stored option rather than emitting an
					// "Undefined array key" warning on PHP 8.x.
					return $this->get_option( $key );
				}
				return $this->get_field_value( $key, $fields[ $key ], $this->get_post_data() );
			}

			private function get_admin_configuration() {
				$form_fields                = $this->get_form_fields();
				$sezzle_enabled             = ( $this->field_value('enabled') == 'yes' );
				// Read the stored merchant-id rather than the submitted field value: it's
				// populated from the validated keys (see process_admin_options), not typed
				// in, and process_admin_options persists it before this runs.
				$merchant_uuid              = $this->get_option( 'merchant-id' );
				$pdp_widget_enabled         = ( $this->field_value('show-product-page-widget') == 'yes' );
				$cart_widget_enabled        = ( $this->field_value('show-cart-page-widget') == 'yes' );
				$installment_widget_enabled = ( $this->field_value('enable-installment-widget') == 'yes' );

                $response = [
                    'sezzle_enabled' => $sezzle_enabled,
                    'merchant_uuid' => $merchant_uuid,
                    'pdp_widget_enabled' => $pdp_widget_enabled,
                    'cart_widget_enabled' => $cart_widget_enabled,
                    'installment_widget_enabled' => $installment_widget_enabled,
                ];

                if (isset($form_fields['enable-order-creation-post-checkout'])) {
                    $order_post_checkout_enabled             = ( $this->field_value('enable-order-creation-post-checkout') == 'yes' );
                    $response['order_post_checkout_enabled'] = $order_post_checkout_enabled;
                }

                // Minimum checkout amount
                if (isset($form_fields['min-checkout-amount'])) {
                    $min_checkout_amount = $this->field_value('min-checkout-amount');
                    $response['min_checkout_amount'] = !empty($min_checkout_amount) ? intval($min_checkout_amount) : 0;
                }

                // Transaction mode
                if (isset($form_fields['transaction-mode'])) {
                    $transaction_mode = $this->field_value('transaction-mode');
                    $response['transaction_mode'] = $transaction_mode;
                }

                // Order total container selector (installment widget target)
                if (isset($form_fields['order-total-container-selector'])) {
                    $order_total_container_selector = $this->field_value('order-total-container-selector');
                    $response['order_total_container_selector'] = $order_total_container_selector;
                }

                // Logging
                if (isset($form_fields['logging'])) {
                    $logging = ( $this->field_value('logging') == 'yes' );
                    $response['logging'] = $logging;
                }

                // Express checkout (if feature flag is enabled)
                if (isset($form_fields['enable-express-checkout'])) {
                    $enable_express_checkout = ( $this->field_value('enable-express-checkout') == 'yes' );
                    $response['enable_express_checkout'] = $enable_express_checkout;
                }

                // Express checkout mode (if feature flag is enabled)
                if (isset($form_fields['express-checkout-mode'])) {
                    $express_checkout_mode = $this->field_value('express-checkout-mode');
                    $response['express_checkout_mode'] = !empty($express_checkout_mode) ? $express_checkout_mode : self::EXPRESS_CHECKOUT_MODE_POPUP;
                }

                return $response;
			}

			private function send_admin_configuration() {
				try {
					$request   = $this->get_admin_configuration();

					$txn_mode = $this->get_option('transaction-mode');
					$service_v2 = new Service_V2($txn_mode, $this->get_keys());
					$service_v2->post_configuration( $request );
				} catch ( Exception $exception ) {
					$this->log( 'Error sending admin config details: ' . $exception->getMessage() );
				}
			}
		}

		function add_sezzlepay_gateway( $methods ) {
			$methods[] = 'WC_Gateway_Sezzlepay';
			return $methods;
		}

		/**
		 * Add a "Settings" link to the plugin's row on the Plugins page.
		 *
		 * @param array $links Existing action links (e.g. Activate/Deactivate).
		 * @return array
		 */
		function add_sezzlepay_settings_link( $links ) {
			$settings_url  = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=sezzlepay' );
			$settings_link = '<a href="' . esc_url( $settings_url ) . '">' . __( 'Settings', 'woo_sezzlepay' ) . '</a>';
			array_unshift( $links, $settings_link );
			return $links;
		}

        function remove_sezzlepay_gateway_based_on_billing_country($available_gateways)
        {
            if (is_admin()) {
                return $available_gateways;
            }

            $gateway = WC_Gateway_Sezzlepay::instance();
            if (WC()->customer) {
                $country_code = WC()->customer->get_billing_country();
                if (!in_array($country_code, $gateway->supported_countries, true)) {
                    unset($available_gateways[$gateway->id]);
                }
            }

            return $available_gateways;
        }

        /**
         * Remove Sezzle Pay based on checkout total
         *
         * @return array
         */
        function remove_sezzlepay_gateway_based_on_checkout_total($available_gateways)
        {
            if (is_admin() || !isset(WC()->cart)) {
                return $available_gateways;
            }
            $gateway = WC_Gateway_Sezzlepay::instance();
            $min_checkout_amount = $gateway->get_option('min-checkout-amount');
            // Compare against the same amount basis as the express cart gate (cart
            // contents after discounts, plus fees, tax, and selected shipping), so the
            // minimum behaves consistently across the payment option at checkout and
            // the cart express button.
            // Skip only an empty cart (totals not meaningful yet); a cart with items and
            // a zero total (e.g. fully discounted) is compared like any other.
            if (WC()->cart->is_empty() || !is_numeric($min_checkout_amount)) {
                return $available_gateways;
            }
            if (get_express_cart_amount_in_cents() < sezzle_min_checkout_amount_in_cents()) {
                unset($available_gateways[$gateway->id]);
            }
            return $available_gateways;
        }

        function allow_create_order_post_checkout()
        {
            $gateway = WC_Gateway_Sezzlepay::instance();
            // The setting is hidden on Checkout Blocks stores (not compatible), so a
            // value saved before switching to Blocks must not keep applying.
            $is_checkout_block = class_exists('Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils')
                && CartCheckoutUtils::is_checkout_block_default();
            return !$is_checkout_block
                && $gateway->get_option('enabled') === 'yes'
                && $gateway->get_option('enable-order-creation-post-checkout') === 'yes';
        }

        /**
         * Save current cart fees to the WooCommerce session so they can be
         * restored when the order is created after the customer returns from
         * Sezzle checkout.  Third-party fee plugins add fees via the
         * woocommerce_cart_calculate_fees hook, which may not fire correctly
         * outside the original checkout page context.
         */
        function sezzle_save_cart_fees_to_session()
        {
            $fees = WC()->cart->get_fees();
            if (empty($fees)) {
                // Drop any snapshot left by an abandoned session, or it would be
                // restored into this fee-less order and fail the amount check.
                sezzle_cleanup_stored_cart_fees();
                return;
            }

            $fee_snapshot = [];
            foreach ($fees as $fee) {
                $fee_snapshot[] = [
                    'name'      => $fee->name,
                    'amount'    => $fee->amount,
                    'taxable'   => $fee->taxable,
                    'tax_class' => $fee->tax_class,
                ];
            }
            WC()->session->set('sezzle_stored_cart_fees', $fee_snapshot);
        }

        /**
         * Restore any stored cart fees that other plugins failed to re-add.
         *
         * Two-pass approach:
         * 1. Calculate totals normally so ALL other plugins add their fees
         * 2. Compare resulting cart fees to stored snapshot by name+amount counts
         * 3. If any are missing, add only those and recalculate
         *
         * This handles both:
         * - Third-party plugins that already restored their own fees (avoids duplicates)
         * - Legitimate duplicate fees like bundle discounts (preserves multiples)
         */
        function sezzle_restore_missing_cart_fees()
        {
            $stored_fees = WC()->session->get('sezzle_stored_cart_fees');
            if (empty($stored_fees) || !WC()->cart) {
                return;
            }

            // Pass 1: calculate totals so all plugins add their fees
            WC()->cart->calculate_totals();

            // Count occurrences of each name+amount pair in the current cart fees
            $current_counts = [];
            foreach (WC()->cart->get_fees() as $fee) {
                $key = $fee->name . '|' . $fee->amount;
                $current_counts[$key] = ($current_counts[$key] ?? 0) + 1;
            }

            // Count occurrences of each name+amount pair in stored fees
            $stored_counts = [];
            foreach ($stored_fees as $fee) {
                $key = $fee['name'] . '|' . $fee['amount'];
                $stored_counts[$key] = ($stored_counts[$key] ?? 0) + 1;
            }

            // Build lookup of stored fees grouped by name|amount
            $stored_fees_by_key = [];
            foreach ($stored_fees as $fee) {
                $stored_fees_by_key[$fee['name'] . '|' . $fee['amount']][] = $fee;
            }

            // Determine which fees are missing
            $missing_fees = [];
            foreach ($stored_counts as $key => $stored_count) {
                $current_count = $current_counts[$key] ?? 0;
                $missing = $stored_count - $current_count;
                if ($missing > 0) {
                    $missing_fees = array_merge(
                        $missing_fees,
                        array_slice($stored_fees_by_key[$key], 0, $missing)
                    );
                }
            }

            if (empty($missing_fees)) {
                return;
            }

            // Pass 2: register a hook to add only the missing fees, then recalculate
            $add_missing = function () use ($missing_fees, &$add_missing) {
                remove_action('woocommerce_cart_calculate_fees', $add_missing, PHP_INT_MAX);
                foreach ($missing_fees as $fee) {
                    WC()->cart->add_fee($fee['name'], $fee['amount'], $fee['taxable'], $fee['tax_class']);
                }
            };
            add_action('woocommerce_cart_calculate_fees', $add_missing, PHP_INT_MAX);
            try {
				WC()->cart->calculate_totals();
			} finally {
				remove_action('woocommerce_cart_calculate_fees', $add_missing, PHP_INT_MAX);
			}
        }

        /**
         * Clear stored fees from the session.
         */
        function sezzle_cleanup_stored_cart_fees()
        {
            WC()->session->__unset('sezzle_stored_cart_fees');
        }

        function sezzle_checkout()
        {
            $gateway = WC_Gateway_Sezzlepay::instance();
            switch (true) {
                case !allow_create_order_post_checkout():
                case isset($_POST['payment_method']) && $_POST['payment_method'] !== $gateway->id:
                case is_admin():
                    return;
            }

            wc_maybe_define_constant('WOOCOMMERCE_CHECKOUT', true);
            $gateway->log(json_encode([
                'checkout_action' => 'ajax',
                'merchant_uuid' => $gateway->get_option('merchant-id'),
            ]));

            $sezzle_checkout = Sezzle_Checkout::instance();
            $sezzle_checkout->process_checkout();
            wp_die(0);
        }

        /**
         * Process the checkout form.
         */
        function sezzle_checkout_action()
        {
            $gateway = WC_Gateway_Sezzlepay::instance();
            switch (true) {
                case !allow_create_order_post_checkout():
                case isset($_POST['payment_method']) && $_POST['payment_method'] !== $gateway->id:
                case !is_checkout():
                case !isset($_POST['woocommerce_checkout_place_order']) && !isset($_POST['woocommerce_checkout_update_totals']):
                    return;
            }

            wc_nocache_headers();

            if (WC()->cart->is_empty()) {
                wp_safe_redirect(wc_get_cart_url());
                exit;
            }

            wc_maybe_define_constant('WOOCOMMERCE_CHECKOUT', true);
            $gateway->log(json_encode([
                'checkout_action' => 'form',
                'merchant_uuid' => $gateway->get_option('merchant-id'),
                'woocommerce_checkout_place_order' => json_encode($_POST['woocommerce_checkout_place_order'] ?? null),
                'woocommerce_checkout_update_totals' => json_encode($_POST['woocommerce_checkout_update_totals'] ?? null),
            ]));

            $sezzle_checkout = Sezzle_Checkout::instance();
            $sezzle_checkout->process_checkout();
        }

        add_filter('woocommerce_payment_gateways', 'add_sezzlepay_gateway');
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'add_sezzlepay_settings_link');
        add_filter('woocommerce_available_payment_gateways', 'remove_sezzlepay_gateway_based_on_checkout_total');
        add_filter('woocommerce_available_payment_gateways', 'remove_sezzlepay_gateway_based_on_billing_country');
        add_action('wp_footer', 'add_sezzle_price_widget');
        add_action('admin_notices', 'sezzle_render_api_error_notice');
        add_action('admin_init', 'sezzle_dismiss_api_error_notice');

		/**
		 * Tell the merchant Sezzle was disabled because its API keys were rejected.
		 * Registered once here rather than from the gateway constructor, so it shows
		 * on every admin page (not only where the gateway is instantiated) and only once.
		 */
		function sezzle_render_api_error_notice() {
			if ( ! get_option( WC_Gateway_Sezzlepay::API_ERROR_NOTICE_OPTION ) || ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}
			$settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=sezzlepay' );
			$dismiss_url  = wp_nonce_url( add_query_arg( 'sezzle_dismiss_api_error', '1' ), 'sezzle_dismiss_api_error' );
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong></p><p><a href="%s">%s</a> | <a href="%s">%s</a></p></div>',
				esc_html__( 'Sezzle has been disabled because the stored API keys were rejected (unauthorized). Please enter valid Public and Private keys to re-enable Sezzle.', 'woo_sezzlepay' ),
				esc_url( $settings_url ),
				esc_html__( 'Update API keys', 'woo_sezzlepay' ),
				esc_url( $dismiss_url ),
				esc_html__( 'Dismiss', 'woo_sezzlepay' )
			);
		}

		function sezzle_dismiss_api_error_notice() {
			if ( empty( $_GET['sezzle_dismiss_api_error'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}
			check_admin_referer( 'sezzle_dismiss_api_error' );
			delete_option( WC_Gateway_Sezzlepay::API_ERROR_NOTICE_OPTION );
			wp_safe_redirect( remove_query_arg( array( 'sezzle_dismiss_api_error', '_wpnonce' ) ) );
			exit;
		}

        add_action('wp_ajax_sezzle_checkout', 'sezzle_checkout');
        add_action('wp_ajax_nopriv_sezzle_checkout', 'sezzle_checkout');
        add_action('wc_ajax_sezzle_checkout', 'sezzle_checkout');
        add_action('wp_loaded', 'sezzle_checkout_action');

		/**
		 * Emit the Sezzle price widget on product and cart pages, for both classic and
		 * block-based storefronts. Runs on wp_footer, which fires on every theme —
		 * unlike the classic-only woocommerce_single_product_summary /
		 * woocommerce_cart_totals_after_order_total hooks, which never fire on block
		 * (Gutenberg/FSE) themes, so the widget script was absent there. Each surface
		 * is gated by its own setting; the emitted config carries both classic and
		 * block target selectors so the right one matches whatever theme is in use.
		 */
		function add_sezzle_price_widget() {
			if ( ! function_exists( 'is_product' ) || ! function_exists( 'is_cart' ) ) {
				return;
			}
			$gateway = WC_Gateway_Sezzlepay::instance();
			if ( $gateway->get_option( 'enabled' ) == 'no' || ! $gateway->get_option( 'merchant-id' ) ) {
				return;
			}

			if ( is_product() ) {
				if ( 'no' != $gateway->get_option( 'show-product-page-widget' ) ) {
					render_sezzle_price_widget( 'product' );
				}
				return;
			}

			if ( is_cart() || has_block( 'woocommerce/cart' ) ) {
				if ( 'no' != $gateway->get_option( 'show-cart-page-widget' ) ) {
					render_sezzle_price_widget( 'cart' );
				}
			}
		}

		/**
		 * Emit the Sezzle price-widget loader for a given surface. The injected
		 * price-widget script scans the page for the configured target selectors and
		 * renders itself next to the matched element. The config is scoped per context
		 * so product-price groups aren't matched against cart cross-sell prices (and
		 * vice versa); each context carries both classic-theme and block-theme targets.
		 *
		 * @param string $context 'product' or 'cart'.
		 */
		function render_sezzle_price_widget( $context ) {
			$merchant_id = WC_Gateway_Sezzlepay::instance()->get_option( 'merchant-id' );
			if ( ! $merchant_id ) {
				return;
			}

			$widget_url = sprintf( 'https://widget.sezzle.com/v1/javascript/price-widget?uuid=%s', rawurlencode( $merchant_id ) );

			if ( 'cart' === $context ) {
				// Classic cart (order-total table row) + block Cart (totals footer item).
				// No urlMatch: emission is already gated to the cart page server-side, so
				// this also works with plain permalinks where the URL has no 'cart'.
				$config_groups = "{
							'targetXPath': '.order-total/TD-0/STRONG-0/.woocommerce-Price-amount/BDI-0',
							'renderToPath': '../../../../../..'
						},{
							'targetXPath': '.wc-block-components-totals-footer-item/.wc-block-components-totals-item__value',
							'renderToPath': '..'
						}";
			} else {
				// Classic product summary (+ Divi, Elementor) and block product price.
				$config_groups = "{
							'targetXPath': '.summary/.price',
							'renderToPath': '.',
							'ignoredFormattedPriceText': ['From:'],
							'relatedElementActions': [{
								'relatedPath': '.',
								'initialAction': function (r, w) {
									if (r.querySelector('DEL')) {
										w.style.display = 'none';
									}
								}
							}]
						},{
							'targetXPath': '.summary/.price/INS-0',
							'renderToPath': '.'
						},{
							'targetXPath': '.et_pb_module_inner/.price',
							'renderToPath': '.',
							'ignoredFormattedPriceText': ['From:'],
							'relatedElementActions': [{
								'relatedPath': '.',
								'initialAction': function (r, w) {
									if (r.querySelector('DEL')) {
										w.style.display = 'none';
									}
								}
							}]
						},{
							'targetXPath': '.et_pb_module_inner/.price/INS-0',
							'renderToPath': '.'
						},{
							'targetXPath': '.elementor-widget-container/.price',
							'renderToPath': '.',
							'ignoredFormattedPriceText': ['From:'],
							'relatedElementActions': [{
								'relatedPath': '.',
								'initialAction': function (r, w) {
									if (r.querySelector('DEL')) {
										w.style.display = 'none';
									}
								}
							}]
						},{
							'targetXPath': '.elementor-widget-container/.price/INS-0',
							'renderToPath': '.'
						},{
							'targetXPath': '.wp-block-woocommerce-product-price/.wc-block-components-product-price__value',
							'renderToPath': '..'
						}";
			}

			echo "<script type='text/javascript'>
				window.Sezzle = window.Sezzle || {};
				Sezzle.render = function () {
					document.sezzleConfig = {
						'configGroups': [" . $config_groups . "]
					}

					var script = document.createElement('script');
					script.type = 'text/javascript';
					script.src = " . wp_json_encode( esc_url( $widget_url ) ) . ";
					document.head.appendChild(script);

				};
				Sezzle.render();
			</script>";

		}

		function alter_checkout_url() {
            $gateway = WC_Gateway_Sezzlepay::instance();
            if (!allow_create_order_post_checkout()) {
                return;
            }

			echo "<script type='text/javascript'>
				jQuery(document.body).on( 'payment_method_selected', function(){
					const selectedPaymentMethod = document.querySelector('input[name=\"payment_method\"]:checked').value;
					if ( selectedPaymentMethod === '" . $gateway->id . "') {
						wc_checkout_params.checkout_url = '". WC_AJAX::get_endpoint('sezzle_checkout' ) . "';
					} else {
						wc_checkout_params.checkout_url = '". WC_AJAX::get_endpoint('checkout' ) ."';
					}
				});
            </script>";
		}

		function sezzle_get_shopper_country_code() {
			// Memoized: geolocate_ip() can fall through to a blocking external lookup,
			// so never pay for it more than once per request.
			static $country_code = null;
			if ( null !== $country_code ) {
				return $country_code;
			}
			if ( ! class_exists( 'WC_Geolocation' ) ) {
				$country_code = '';
				return $country_code;
			}
			$geo          = WC_Geolocation::geolocate_ip();
			$country_code = ! empty( $geo['country'] ) ? $geo['country'] : '';
			return $country_code;
		}

		function add_installment_widget_script() {
			$gateway = WC_Gateway_Sezzlepay::instance();
			if ( $gateway->get_option( 'enabled' ) == 'no'
				|| $gateway->get_option( 'enable-installment-widget' ) == 'no'
			) {
				return;
			}
			// CSS selector for the checkout order total, passed to the widget's
			// checkoutTotalElemTargetPath (document.querySelector). Only emitted when the
			// admin set one (mirrors the block surface): the SDK falls back to its
			// platform ('woocommerce') targeting when the key is absent, but a set-and-
			// unmatched selector renders nothing, and a blank one would rely on the SDK's
			// own truthiness guard staying in place.
			// This hook only fires for the classic (shortcode) checkout form.
			$order_total_container_selector = $gateway->get_order_total_container_selector( false );
			$target_path_option            = $order_total_container_selector
				? "'checkoutTotalElemTargetPath': '" . esc_js( $order_total_container_selector ) . "',"
				: '';

			// Narrow the shopper's geolocated country to one the widget supports: CA for
			// shoppers in Canada, US otherwise. Drives the SDK's countryCode option, which
			// filters the plans shown to what's available in the shopper's location.
			$shopper_country_code = ( sezzle_get_shopper_country_code() === 'CA' ) ? 'CA' : 'US';

			echo "<script type='text/javascript'>
                // Wait for dependencies to load (deferred scripts)
                (function() {
                    var maxRetries = 50; // 50 * 100ms = 5 seconds max wait
                    var retryCount = 0;

                    function initializeSezzleInstallmentWidget() {
                        if (typeof jQuery === 'undefined' || typeof SezzleInstallmentWidget === 'undefined') {
                            retryCount++;
                            if (retryCount >= maxRetries) {
                                console.error('Sezzle dependencies failed to load after 5 seconds');
                                return;
                            }
                            // Dependencies not loaded yet, try again in 100ms
                            setTimeout(initializeSezzleInstallmentWidget, 100);
                            return;
                        }

                        new SezzleInstallmentWidget({
                            'countryCode': '" . esc_js( $shopper_country_code ) . "',
                            " . $target_path_option . "
                            'platform': 'woocommerce'
                        });

                        // create an observer instance
                        jQuery(document.body).on( 'updated_checkout', function(){
                    var sezzlePaymentLine = document.querySelector('.payment_method_". $gateway->id ."');
                    if (document.getElementById('sezzle-installment-widget-box')) {
                        document.getElementById('sezzle-installment-widget-box').remove();
                        document.querySelector('.sezzle-modal-overlay') && document.querySelector('.sezzle-modal-overlay').remove();
                    }
                    if (sezzlePaymentLine) {
                         var sezzleCheckoutWidget = document.createElement('div');
                         sezzleCheckoutWidget.id = 'sezzle-installment-widget-box';
                         sezzleCheckoutWidget.style.display = 'none';
                         sezzlePaymentLine.parentElement.insertBefore(sezzleCheckoutWidget, sezzlePaymentLine.nextElementSibling);
                    }

                    var sezzleInstallmentPlanBox = document.getElementById('sezzle-installment-widget-box');
                    if (sezzleInstallmentPlanBox) {
                        jQuery('input[type=radio][name=\"payment_method\"]').change(function() {
                            if (jQuery(this).val() === '" . $gateway->id . "' && sezzleInstallmentPlanBox) {
                                sezzleInstallmentPlanBox.style.display = 'flex';
                            } else {
                                sezzleInstallmentPlanBox.style.display = 'none';
                            }
                        });
                        if (jQuery('#payment_method_sezzlepay').is(':checked')) {
                            sezzleInstallmentPlanBox.style.display = 'flex';
                        }
                    }
                        });
                    }

                    // Start initialization (will retry until dependencies load or timeout)
                    initializeSezzleInstallmentWidget();
                })();
            </script>";
		}

		/**
		 * Add scripts to frontend
		 *
		 * @return void
		 */
		function frontend_enqueue_scripts() {
			// Early return if not on checkout page - prevents loading on every page
			if ( ! is_checkout() && ! has_block( 'woocommerce/checkout' ) ) {
				return;
			}
			// is_checkout() is also true on the order-received and order-pay endpoints,
			// where there is no order total to attach the installment widget to.
			if ( is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'order-pay' ) ) {
				return;
			}

            $gateway = WC_Gateway_Sezzlepay::instance();
            if ($gateway->get_option('enabled') == 'no' || $gateway->get_option('enable-installment-widget') == 'no') {
                return;
            }

            $is_checkout_block = class_exists('Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils') && CartCheckoutUtils::is_checkout_block_default();
			if ( is_checkout() || $is_checkout_block ) {
				// Check WordPress version for script loading strategy support
				global $wp_version;
				$supports_script_strategy = version_compare($wp_version, '6.3', '>=');

				if ($supports_script_strategy) {
					// WordPress 6.3+ - Use strategy parameter for defer loading
					wp_enqueue_script(
						'installment_widget_js',
						'https://checkout-sdk.sezzle.com/installment-widget.min.js',
						array(),
						'v1.0.0',
						array('strategy' => 'defer', 'in_footer' => true)
					);
				} else {
					// WordPress < 6.3 - Use defer attribute manually
					wp_enqueue_script(
						'installment_widget_js',
						'https://checkout-sdk.sezzle.com/installment-widget.min.js',
						array(),
						'v1.0.0',
						true // in_footer
					);
					wp_script_add_data('installment_widget_js', 'defer', true);
				}

                if ($is_checkout_block) {
					if ($supports_script_strategy) {
						wp_enqueue_script(
							'installment_widget_renderer_js',
							plugin_dir_url(__FILE__) . 'build/js/frontend/installment-widget.js',
							array('installment_widget_js', 'jquery'),
							'v1.0.0',
							array('strategy' => 'defer', 'in_footer' => true)
						);
					} else {
						wp_enqueue_script(
							'installment_widget_renderer_js',
							plugin_dir_url(__FILE__) . 'build/js/frontend/installment-widget.js',
							array('installment_widget_js', 'jquery'),
							'v1.0.0',
							true // in_footer
						);
						wp_script_add_data('installment_widget_renderer_js', 'defer', true);
					}

					// Expose the shopper's IP-based country (for countryCode) and the
					// configured order-total selector (checkoutTotalElemTargetPath) so the
					// block widget targets the right element (see installment-widget.js).
					wp_localize_script(
						'installment_widget_renderer_js',
						'sezzle_installment_widget',
						array(
							'country_code'                   => sezzle_get_shopper_country_code(),
							'order_total_container_selector' => $gateway->get_order_total_container_selector( true ),
						)
					);
                }
			}
		}

		/**
		 * Enqueue express checkout scripts early for dynamic content
		 *
		 * @return void
		 */
		function enqueue_express_checkout_scripts() {
			// Only check feature flag and enqueue scripts on cart/product/admin pages.
			// Block themes render the cart/checkout via Gutenberg blocks, so also load
			// where those blocks are present (is_cart()/is_product() remain true for the
			// standard block cart/product pages, but has_block() covers pages that embed
			// the blocks without being the designated cart/checkout page).
			if (!is_cart() && !is_admin() && !is_checkout() && !is_product()
				&& !has_block('woocommerce/cart') && !has_block('woocommerce/checkout')) {
				return;
			}

			if (!are_express_checkout_options_enabled('any')) {
				return;
			}

			$gateway = WC_Gateway_Sezzlepay::instance();
			$public_key = $gateway->get_option('public-key');
			if (empty($public_key)) {
				return;
			}

			// Button styling. Enqueued here, on the same gate as the scripts, rather than
			// from the classic-theme render hooks (woocommerce_proceed_to_checkout /
			// woocommerce_after_add_to_cart_button): those never fire on the Cart Block
			// or the block Single Product template, so block storefronts got no baseline
			// styling and their buttons looked different from classic ones.
			add_express_checkout_styles();

			// Only enqueue scripts if not already enqueued
			if (!wp_script_is('sezzle_express_sdk', 'enqueued')) {
				// Check WordPress version for script loading strategy support
				global $wp_version;
				$supports_script_strategy = version_compare($wp_version, '6.3', '>=');

				// Enqueue Express Checkout SDK with defer loading
				if ($supports_script_strategy) {
					// WordPress 6.3+ - Use strategy parameter
					wp_enqueue_script(
						'sezzle_express_sdk',
						$gateway::EXPRESS_SDK_URL,
						array(),
						null,
						array('strategy' => 'defer', 'in_footer' => true)
					);
				} else {
					// WordPress < 6.3 - Use defer attribute manually
					wp_enqueue_script(
						'sezzle_express_sdk',
						$gateway::EXPRESS_SDK_URL,
						array(),
						null,
						true // in_footer
					);
					wp_script_add_data('sezzle_express_sdk', 'defer', true);
				}

				// Load the built express checkout script with defer loading
				$asset_file = include(plugin_dir_path(__FILE__) . 'build/js/frontend/express-checkout.asset.php');
				if ($supports_script_strategy) {
					wp_enqueue_script(
						'sezzle_express_checkout',
						plugins_url('build/js/frontend/express-checkout.js', __FILE__),
						array_merge($asset_file['dependencies'], array('sezzle_express_sdk')),
						$asset_file['version'],
						array('strategy' => 'defer', 'in_footer' => true)
					);
				} else {
					wp_enqueue_script(
						'sezzle_express_checkout',
						plugins_url('build/js/frontend/express-checkout.js', __FILE__),
						array_merge($asset_file['dependencies'], array('sezzle_express_sdk')),
						$asset_file['version'],
						true // in_footer
					);
					wp_script_add_data('sezzle_express_checkout', 'defer', true);
				}

				// The shopper-facing strings in this bundle (button label, error alerts,
				// loading indicator) are wrapped in __(), so point WordPress at the compiled
				// JSON translations.
				wp_set_script_translations('sezzle_express_checkout', 'woo_sezzlepay', plugin_dir_path(__FILE__) . 'i18n/languages');

				$ec_cart_button_target = $gateway->get_option("express-checkout-button-target");
				if (empty($ec_cart_button_target)) {
					$ec_cart_button_target = WC_Gateway_Sezzlepay::EXPRESS_CHECKOUT_DEFAULT_BUTTON_TARGET;
				}

				$ec_product_button_target = $gateway->get_option("express-checkout-product-button-target");
				if (empty($ec_product_button_target)) {
					$ec_product_button_target = WC_Gateway_Sezzlepay::EXPRESS_CHECKOUT_DEFAULT_PRODUCT_BUTTON_TARGET;
				}

				// Expose the current product id only when the product-page button should
				// render, so the JS knows to inject the product container (and what to add).
				// Grouped and external products are never purchasable, so a button there could
				// only fail (after needlessly emptying and rebuilding the shopper's cart).
				$ec_product_id = 0;
				if (is_product() && are_express_checkout_options_enabled('product')) {
					$queried_product = wc_get_product(get_queried_object_id());
					if ($queried_product instanceof WC_Product
						&& $queried_product->is_type(array('simple', 'variable'))
						&& $queried_product->is_purchasable()) {
						$ec_product_id = $queried_product->get_id();
					}
				}

				// Seed the PDP min-amount gate with the product's price. For simple
				// products this is the definitive unit price; for variable (and other
				// multi-price) products it's left null so the JS reads the live
				// per-variation price from WooCommerce's variation events instead.
				// Use wc_get_price_to_display() rather than get_price() so the seed is
				// on the same tax basis as variation.display_price (the variable-product
				// path) and as the tax-inclusive server-side gates; get_price() returns
				// the raw stored price, which excludes tax when the store enters prices
				// without tax.
				$ec_product_price = null;
				if ($ec_product_id) {
					$ec_product = wc_get_product($ec_product_id);
					if ($ec_product instanceof WC_Product && $ec_product->is_type('simple')) {
						$ec_product_price = wc_get_price_to_display($ec_product);
					}
				}

				$express_config = array(
					'mode' => $gateway->get_express_checkout_mode(),
					'public_key' => $public_key,
					'api_mode' => $gateway->get_option("transaction-mode"),
					'ajax_url' => admin_url('admin-ajax.php'),
					'szl_ec_start_nonce' => wp_create_nonce("szl_ec_start_nonce"),
					'szl_ec_complete_nonce' => wp_create_nonce("szl_ec_complete_nonce"),
					'szl_ec_verify_auth_nonce' => wp_create_nonce("szl_ec_verify_auth_nonce"),
					'szl_ec_widget_log_nonce' => wp_create_nonce("szl_ec_widget_log_nonce"),
					'error_cart_amount_mismatch' => WC_Gateway_Sezzlepay::ERROR_CART_AMOUNT_MISMATCH,
					'szl_ec_button_target_class' => $ec_cart_button_target,
					'szl_ec_product_button_target_class' => $ec_product_button_target,
					'product_id' => $ec_product_id,
					// Whether the Cart Express Checkout button is enabled for this
					// merchant. The enqueue gate above uses 'any' (cart OR product), so
					// the script also loads when only the product button is enabled;
					// without this flag the JS would render the cart button on the cart
					// page regardless. Mirrors how product_id gates the product button.
					'cart_enabled' => are_express_checkout_options_enabled('cart'),
					// Minimum-amount gate for the express buttons. min_checkout_amount is
					// in store currency (dollars); cart_above_minimum is the server's
					// authoritative verdict for the current cart at page load (the JS
					// re-checks it via AJAX on cart changes); product_price seeds the PDP
					// gate for simple products (null for variable — read live in JS).
					'min_checkout_amount' => $gateway->get_option('min-checkout-amount'),
					'cart_above_minimum' => is_express_cart_above_minimum() && sezzle_is_gateway_available_for_cart(),
					'product_price' => $ec_product_price,
					'no_shipping' => $gateway->get_option('express-checkout-no-shipping') === 'yes',
					'button_styles' => array(
						'cart' => sezzle_get_express_button_style('cart'),
						'product' => sezzle_get_express_button_style('product'),
					),
				);

				// Not wp_localize_script(): it casts every top-level scalar to a string, so
				// product_id 0 reached the JS as a truthy "0" (rendering the product button
				// with PDP Express off) and cart_above_minimum false as "" (failing the
				// "!== false" check). JSON keeps the types. Decode entities in strings as
				// wp_localize_script did, since kses stores selectors' ">" as "&gt;".
				foreach ($express_config as $key => $value) {
					if (is_string($value)) {
						$express_config[$key] = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
					}
				}
				wp_add_inline_script(
					'sezzle_express_checkout',
					'var sezzle_express_checkout = ' . wp_json_encode($express_config) . ';',
					'before'
				);
			}
		}

		/**
		 * Enqueue the express checkout button CSS: a source-less stylesheet with the
		 * rules attached inline, so WordPress prints it once in the head on every
		 * theme, classic or block.
		 */
		function add_express_checkout_styles() {
			static $styles_added = false;
			if ($styles_added) {
				return;
			}
			$styles_added = true;

			$handle = 'sezzle-express-checkout';
			wp_register_style($handle, false, array(), null);
			wp_enqueue_style($handle);
			wp_add_inline_style($handle, sezzle_express_checkout_css());
		}

		/**
		 * The express checkout button CSS: shared layout baseline plus each location's
		 * merchant-customizable width, radius and margin.
		 *
		 * @return string
		 */
		function sezzle_express_checkout_css() {
			return '
				#sezzle-smart-button-container-cart,
				#sezzle-smart-button-container-cart iframe,
				#sezzle-smart-button-container-cart button,
				#sezzle-smart-button-container-product,
				#sezzle-smart-button-container-product iframe,
				#sezzle-smart-button-container-product button {
					display: flex !important;
					align-items: center !important;
					justify-content: center !important;
					text-align: center !important;
				}
				#sezzle-smart-button-container-cart-sezzle-express-button .template-text,
				#sezzle-smart-button-container-product-sezzle-express-button .template-text {
					text-transform: capitalize;
				}
				.sezzle-smart-button-container-cart-sezzle-express-button-logo-img,
				.sezzle-smart-button-container-product-sezzle-express-button-logo-img {
					vertical-align: baseline;
				}'
				. sezzle_express_button_custom_css('cart')
				. sezzle_express_button_custom_css('product');
		}

		/**
		 * Sanitize a basic CSS value for safe output inside a <style> block.
		 * Strips anything that could break out of the CSS value context.
		 *
		 * @param string $value Raw value from settings
		 * @return string Sanitized value
		 */
		function sezzle_sanitize_css_value($value) {
			$value = trim(wp_strip_all_tags((string) $value));
			// The output already carries !important; a merchant-entered one would double it.
			$value = trim(preg_replace('/!\s*important\s*$/i', '', $value));
			// Allowlist rather than strip: lengths, percentages, keywords and simple
			// calc()/min()/max()/clamp() math. Anything else (comments that could
			// swallow the rest of the <style>, url(), extra declarations, markup) is
			// rejected as a whole, so the caller falls back to the default.
			if (!preg_match('/^[a-zA-Z0-9.%\s+\-*\/(),]*$/', $value) || strpos($value, '/*') !== false) {
				return '';
			}
			$without_functions = preg_replace('/\b(calc|min|max|clamp)\(/i', '', $value);
			if (preg_match('/[a-zA-Z_-]\(/', $without_functions) || substr_count($value, '(') !== substr_count($value, ')')) {
				return '';
			}
			return $value;
		}

		/**
		 * Resolve the merchant-customizable button style (width, border-radius, margin)
		 * for one express checkout location, falling back to defaults.
		 *
		 * @param string $location 'cart' or 'product'
		 * @return array{width:string,border_radius:string,margin:string}
		 */
		function sezzle_get_express_button_style($location) {
			$gateway = WC_Gateway_Sezzlepay::instance();
			$prefix = ($location === 'product') ? 'express-checkout-product-button-' : 'express-checkout-cart-button-';

			$width = sezzle_sanitize_css_value($gateway->get_option($prefix . 'width'));
			if ($width === '') {
				$width = WC_Gateway_Sezzlepay::EXPRESS_CHECKOUT_DEFAULT_BUTTON_WIDTH;
			}
			$border_radius = sezzle_sanitize_css_value($gateway->get_option($prefix . 'border-radius'));
			if ($border_radius === '') {
				$border_radius = WC_Gateway_Sezzlepay::EXPRESS_CHECKOUT_DEFAULT_BUTTON_BORDER_RADIUS;
			}
			$margin = sezzle_sanitize_css_value($gateway->get_option($prefix . 'margin'));
			if ($margin === '') {
				$margin = WC_Gateway_Sezzlepay::EXPRESS_CHECKOUT_DEFAULT_BUTTON_MARGIN;
			}

			return array(
				'width' => $width,
				'border_radius' => $border_radius,
				'margin' => $margin,
			);
		}

		/**
		 * Build the merchant-customizable CSS (width, border-radius, margin) for one
		 * express checkout button location. This is a baseline; the SDK applies inline
		 * styles to the rendered button that only JS can reliably override (see
		 * applyCustomButtonStyles in express-checkout.js).
		 *
		 * @param string $location 'cart' or 'product'
		 * @return string CSS rules
		 */
		function sezzle_express_button_custom_css($location) {
			$style = sezzle_get_express_button_style($location);
			$width = $style['width'];
			$border_radius = $style['border_radius'];
			$margin = $style['margin'];

			$id = 'sezzle-smart-button-container-' . $location;
			$button_id = $id . '-sezzle-express-button';

			// Baseline styling (JS enforces these over the SDK's inline styles):
			// container gets the configured width; the button gets the configured
			// width + border-radius + margin.
			return sprintf(
				'#%1$s { width: %2$s !important; }'
				. '#%5$s, #%1$s iframe, #%1$s button { width: %2$s !important; border-radius: %4$s !important; margin: %3$s !important; }',
				$id,
				$width,
				$margin,
				$border_radius,
				$button_id
			);
		}

        /**
		 * Validate if express checkout should be rendered
		 */
		function are_express_checkout_options_enabled($location = 'cart') {
			$gateway = WC_Gateway_Sezzlepay::instance();

			// Check if main Sezzle gateway is enabled. Treat an unset option as
			// disabled and match the '== no' convention used by the widget gate.
			if ($gateway->get_option('enabled') == 'no') {
				return false;
			}

			// Check if merchant has express checkout enabled for the requested location(s).
			// Both toggles are strictly opt-in ('yes' only). The Express section is only
			// added to the form once the feature flag is on, so the first key save stores
			// no Express options; an unset value must read as off, not on.
			$cart_enabled = $gateway->get_option('enable-express-checkout') === 'yes';
			$pdp_enabled = $gateway->get_option('enable-express-checkout-pdp') === 'yes';
			switch ($location) {
				case 'product':
					$express_enabled = $pdp_enabled;
					break;
				case 'any':
					$express_enabled = $cart_enabled || $pdp_enabled;
					break;
				case 'cart':
				default:
					$express_enabled = $cart_enabled;
					break;
			}
			if (!$express_enabled) {
				return false;
			}

			// Check the feature flag from Sezzle gateway
			// This uses the 3-level cache system (request → transient → API)
			// ensuring we always respect the feature flag state even if the
			// database option is stale due to AJAX request filtering or cache delays
			return (bool) $gateway->check_express_checkout_feature_flag();
		}

		/**
		 * The express checkout amount for the current cart, in cents: cart subtotal
		 * after discounts, plus fees, tax, and the shipping method selected on the cart
		 * page. Kept in one place so the button-render gate, the click-time gate in
		 * start_express_checkout(), and get_express_checkout_data() all agree.
		 *
		 * Shipping is included in the minimum-amount gate. In a multi-step express
		 * checkout the shopper can pick a different (possibly cheaper) shipping option
		 * inside the Sezzle flow, but calculate_address_related_costs() recomputes each
		 * option's total and drops any below the minimum, so an order can't complete
		 * below the merchant's threshold. For a no-shipping checkout the shipping total
		 * is 0, so this term has no effect.
		 *
		 * @return int Amount in cents (0 when no cart is available).
		 */
		function get_express_cart_amount_in_cents($include_shipping = true) {
			if (!WC()->cart) {
				return 0;
			}
			$amount  = WC()->cart->get_cart_contents_total(); // after discounts, before shipping/tax
			$amount += WC()->cart->get_fee_total() + WC()->cart->get_fee_tax();
			$amount += WC()->cart->get_cart_contents_tax();
			if ($include_shipping) {
				$amount += WC()->cart->get_shipping_total() + WC()->cart->get_shipping_tax();
			}
			return Sezzle_Utils::formatToCents($amount);
		}

		/**
		 * Whether the current cart's express checkout amount meets the configured
		 * minimum. Uses the same amount basis and comparison as the click-time gate in
		 * start_express_checkout(), so the express button is shown exactly when a click
		 * would be allowed. Returns true when no minimum is configured.
		 *
		 * @return bool
		 */
		function is_express_cart_above_minimum() {
			$min_in_cents = sezzle_min_checkout_amount_in_cents();
			if ($min_in_cents <= 0) {
				return true;
			}
			return get_express_cart_amount_in_cents(sezzle_express_amount_includes_shipping()) >= $min_in_cents;
		}

		/**
		 * Whether Sezzle is an available payment method for the current cart, by the
		 * same rules as checkout: is_available() and every woocommerce_available_payment_gateways
		 * filter (e.g. WooCommerce Subscriptions removes gateways that can't take a
		 * subscription). Express creates its order outside checkout, so it must ask.
		 * An empty cart has nothing to judge, so it reads as available.
		 *
		 * @return bool
		 */
		function sezzle_is_gateway_available_for_cart() {
			if (!WC()->cart || WC()->cart->is_empty() || !WC()->payment_gateways()) {
				return true;
			}
			// Express collects the address inside Sezzle: don't hide it for a shopper
			// who hasn't given a billing country yet (a known unsupported one still does).
			$skip_country = !WC()->customer || !WC()->customer->get_billing_country();
			if ($skip_country) {
				remove_filter('woocommerce_available_payment_gateways', 'remove_sezzlepay_gateway_based_on_billing_country');
			}
			$available = WC()->payment_gateways()->get_available_payment_gateways();
			if ($skip_country) {
				add_filter('woocommerce_available_payment_gateways', 'remove_sezzlepay_gateway_based_on_billing_country');
			}
			return isset($available[WC_Gateway_Sezzlepay::instance()->id]);
		}

		/**
		 * Whether the express amount includes the cart's shipping. In no-shipping mode
		 * the order never gets a shipping line, so shipping the cart page calculated
		 * is neither charged nor counted toward the minimum.
		 *
		 * @return bool
		 */
		function sezzle_express_amount_includes_shipping() {
			return WC_Gateway_Sezzlepay::instance()->get_option('express-checkout-no-shipping') !== 'yes';
		}

		/**
		 * The configured Minimum Checkout Amount in cents (0 when unset). Converted
		 * once with formatToCents() so every gate compares whole cents; comparing
		 * against min * 100 as a float could be off by one at the boundary.
		 *
		 * @return int
		 */
		function sezzle_min_checkout_amount_in_cents() {
			$min_checkout_amount = WC_Gateway_Sezzlepay::instance()->get_option('min-checkout-amount');
			return is_numeric($min_checkout_amount) ? Sezzle_Utils::formatToCents((float) $min_checkout_amount) : 0;
		}

		/**
		 * Get gateway items for express checkout
		 * 
		 * @param array $items Cart items
		 * @param string $currency Currency code
		 * @return array Formatted items for Sezzle
		 */
		function get_gateway_items_for_express($items, $currency)
		{
			$gateway_items = [];
			
			foreach ($items as $item) {
				$item_id = (!empty($item['variation_id']) && $item['variation_id'] > 0) ? $item['variation_id'] : $item['product_id'];
				$product = function_exists('wc_get_product') ? wc_get_product($item_id) : new WC_Product($item_id);

				// Skip if product doesn't exist
				if (!$product || !$product->exists()) {
					continue;
				}

				$item_data = array(
					"name" => $product->get_name(),
					"sku" => $product->get_sku(),
					"quantity" => $item['quantity'],
					"price" => array(
						"amount_in_cents" => Sezzle_Utils::formatToCents($item['line_subtotal'] / $item['quantity']),
						"currency" => $currency
					)
				);
				$gateway_items[] = $item_data;
			}
			
			return $gateway_items;
		}

		/**
		 * Get gateway discounts for express checkout
		 * 
		 * @return array Applied discounts/coupons
		 */
		function get_gateway_discounts_for_express()
		{
			$gateway_discounts = [];
			
			foreach (WC()->cart->get_applied_coupons() as $coupon_code) {
				$discounts = [
					'name' => $coupon_code,
					'amount' => [
						'amount_in_cents' => Sezzle_Utils::formatToCents(WC()->cart->get_coupon_discount_amount($coupon_code)),
						'currency' => get_woocommerce_currency()
					]
				];
				$gateway_discounts[] = $discounts;
			}
			
			return $gateway_discounts;
		}

		/**
		 * Get express checkout data
		 *
		 * @param string $checkout_source Source of checkout (cart)
		 * @param WC_Order $order Order object or null if not created
		 * @return array Express checkout data ready for Sezzle
		 */
		function get_express_checkout_data($checkout_source = 'cart', $order = null)
		{
			// Basic checkout data - cart subtotal after discounts, plus fees, tax, and
			// the shipping selected on the cart page (see get_express_cart_amount_in_cents).
			$amount_in_cents = get_express_cart_amount_in_cents(sezzle_express_amount_includes_shipping());
			$order_reference_id = $order ? $order->get_order_key() : WC()->cart->get_cart_hash();
			$order_description = 'WooCommerce Express Order. Cart #' . $order_reference_id;
			// The cart totals are in the shopper's currency, which a currency switcher
			// can set apart from the store's base currency; label them with it.
			$currency = get_woocommerce_currency();
			
			// Get cart items
			$items = WC()->cart->get_cart();
			$gateway_items = get_gateway_items_for_express($items, $currency);
			
			// Get applied discounts/coupons
			$gateway_discounts = get_gateway_discounts_for_express();
			
			// Prepare the checkout data for JavaScript. Only the fields the client maps
			// into the SDK's startCheckout payload are included (see startExpressCheckoutFlow
			// in express-checkout.js): reference id, description, items, amount, currency,
			// discounts.
			$checkout_data = array(
				'amount_in_cents' => $amount_in_cents,
				'currency' => $currency,
				'cart_items' => $gateway_items,
				'order_description' => (string)$order_description,
				'order_reference_id' => (string)$order_reference_id,
				'discounts' => $gateway_discounts,
			);
			
			return $checkout_data;
		}

		/**
		 * WooCommerce's order attribution controller (WooCommerce 8.5+ "Origin"), or null
		 * when the feature is off: then nothing listens to woocommerce_order_save_attribution_data.
		 * Also null when it lacks get_field_names(), which both callers rely on.
		 *
		 * @return object|null
		 */
		function sezzle_get_order_attribution_controller()
		{
			if ( ! has_action( 'woocommerce_order_save_attribution_data' ) || ! function_exists( 'wc_get_container' ) ) {
				return null;
			}
			try {
				$controller = wc_get_container()->get( \Automattic\WooCommerce\Internal\Orders\OrderAttributionController::class );
			} catch ( \Throwable $e ) {
				return null;
			}
			// get_field_names() arrived in WC 8.6; 8.5 exposed get_fields() instead.
			return method_exists( $controller, 'get_field_names' ) ? $controller : null;
		}

		/**
		 * Keep the attribution fields posted with the checkout form, for an order created
		 * post checkout completion: it is created on the return from Sezzle, whose
		 * request no longer carries them (see sezzle_save_order_attribution).
		 */
		function sezzle_store_checkout_order_attribution()
		{
			$controller = sezzle_get_order_attribution_controller();
			if ( ! $controller || ! WC()->session ) {
				return;
			}
			$prefix = method_exists( $controller, 'get_prefix' ) ? $controller->get_prefix() : 'wc_order_attribution_';
			$values = array();
			foreach ( $controller->get_field_names() as $field_name ) {
				if ( isset( $_POST[ $prefix . $field_name ] ) && is_scalar( $_POST[ $prefix . $field_name ] ) ) {
					$values[ $field_name ] = (string) $_POST[ $prefix . $field_name ];
				}
			}
			WC()->session->set( 'sezzle_order_attribution', $values );
		}

		/**
		 * Save the shopper's order attribution on an order core checkout didn't attribute.
		 *
		 * Core saves it when woocommerce_checkout_order_created fires, from the fields
		 * posted in that request. Express orders are built by wc_create_order(), which
		 * never fires it, and orders created post checkout completion are created on the
		 * return from Sezzle, which posts no fields. So the data is passed in, and handed
		 * to the same action core uses, which stores the meta and builds the Origin label.
		 *
		 * @param WC_Order $order Order to attribute.
		 * @param mixed    $raw   Unprefixed field values, slashed as in $_POST.
		 */
		function sezzle_save_order_attribution( $order, $raw )
		{
			$controller = sezzle_get_order_attribution_controller();
			if ( ! $controller ) {
				return;
			}
			$field_names = $controller->get_field_names();

			// A reused pending order already carries the attribution of its first attempt.
			if ( method_exists( $controller, 'has_attribution' ) ) {
				if ( $controller->has_attribution( $order ) ) {
					return;
				}
			} else {
				$meta_prefix = '_' . ( method_exists( $controller, 'get_prefix' ) ? $controller->get_prefix() : 'wc_order_attribution_' );
				foreach ( $field_names as $field_name ) {
					if ( $order->meta_exists( $meta_prefix . $field_name ) ) {
						return;
					}
				}
			}

			$raw = is_array( $raw ) ? $raw : array();

			// Core reads every field without an isset check and skips '(none)', so send them
			// all, with a missing or empty value as '(none)'. Values stay slashed, as $_POST
			// is at standard checkout: core runs wp_unslash() and sanitize_text_field() again.
			$data = array();
			foreach ( $field_names as $field_name ) {
				$value = isset( $raw[ $field_name ] ) && is_scalar( $raw[ $field_name ] )
					? sanitize_text_field( (string) $raw[ $field_name ] )
					: '';
				$data[ $field_name ] = '' === $value ? '(none)' : $value;
			}

			// Nothing tracked (no data posted, or tracking not allowed): save nothing.
			if ( ! array_diff( $data, array( '(none)' ) ) ) {
				return;
			}

			do_action( 'woocommerce_order_save_attribution_data', $order, $data );
		}

		/**
		 * Create order from cart data for express checkout
		 *
		 * @param WC_Order|null $order Pending express order to rebuild instead of creating a new one.
		 * @return WC_Order
		 */
		function create_order_from_cart_data($order = null)
		{
			// Ensure WooCommerce cart is available and not empty
			if ( ! WC()->cart || WC()->cart->is_empty() ) {
				throw new Exception( __( 'Cart is empty or not available.', 'woo_sezzlepay' ) );
			}

			// Re-add fees captured when the express session started. Fee plugins that
			// only run on the cart/checkout page don't fire in this AJAX request.
			sezzle_restore_missing_cart_fees();
			if ( empty( WC()->session->get( 'sezzle_stored_cart_fees' ) ) ) {
				WC()->cart->calculate_totals();
			}

			$is_new_order = ! $order;
			if ( $order ) {
				// Abandoned and restarted: rebuild the same order rather than adding another.
				$order->remove_order_items();
				// wc_create_order() sets the currency only on a new order. The shopper may
				// have switched currency since, and the rebuilt totals are in the current one.
				$order->set_currency( get_woocommerce_currency() );
			} else {
				// created_via 'checkout' lets WooCommerce's unpaid-order cleanup
				// (wc_cancel_unpaid_orders) cancel abandoned express orders.
				$order = wc_create_order( array( 'created_via' => 'checkout' ) );
			}
			if ( ! $order ) {
				throw new Exception( __( 'Unable to create order.', 'woo_sezzlepay' ) );
			}

			// Set payment method for WC to identify sezzle order
			$gateway = WC_Gateway_Sezzlepay::instance();
			$order->set_payment_method($gateway->id);
			$order->set_payment_method_title($gateway->get_title());

			// Set customer if logged in
			if ( get_current_user_id() ) {
				$order->set_customer_id( get_current_user_id() );
			}

			// Add items from cart
			foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
				$product       = $cart_item['data'];
				$quantity      = $cart_item['quantity'];
				$variation     = $cart_item['variation'];
				$item_totals   = [
					'subtotal'     => $cart_item['line_subtotal'],
					'subtotal_tax' => $cart_item['line_subtotal_tax'],
					'total'        => $cart_item['line_total'],
					'tax'          => $cart_item['line_tax'],
				];

				$order->add_product( $product, $quantity, [
					'variation' => $variation,
					'totals'    => $item_totals,
				] );
			}

			// Add fees
			foreach ( WC()->cart->get_fees() as $cart_fee ) {
				$item_fee = new WC_Order_Item_Fee();
				$item_fee->set_name( $cart_fee->name );
				$item_fee->set_amount( $cart_fee->amount );
				$item_fee->set_total( $cart_fee->total );
				$item_fee->set_tax_class( $cart_fee->tax_class );
				$item_fee->set_tax_status( $cart_fee->taxable ? 'taxable' : 'none' );
				$order->add_item( $item_fee );
			}

			// Apply coupons
			foreach ( WC()->cart->get_applied_coupons() as $code ) {
				$coupon = new WC_Coupon( $code );
				if ( $coupon->get_id() ) {
					$order->apply_coupon( $coupon );
				}
			}

			// Calculate totals
			$order->calculate_totals();

			sezzle_save_order_attribution( $order, $_POST['sezzle_order_attribution'] ?? array() );

			if ( $is_new_order ) {
				$order->add_order_note( __( 'Order created programmatically from cart through express checkout.', 'woo_sezzlepay' ) );
			}

			sezzle_cleanup_stored_cart_fees();

			return $order;
		}

		/**
		 * Snapshot the shopper's current cart into the WC session so a product-page
		 * express checkout can isolate the cart to a single product and later restore
		 * the original contents (see sezzle_restore_pdp_cart).
		 *
		 * If a snapshot already exists (a previous express flow was abandoned without
		 * restoring — e.g. the shopper closed the popup window), the stranded cart is
		 * restored first so we snapshot the shopper's real cart, not a leftover
		 * single-product cart.
		 */
		function sezzle_snapshot_cart_for_pdp()
		{
			if (!WC()->cart || !WC()->session) {
				return;
			}
			// Self-heal any leftover snapshot from an abandoned flow before taking a new one.
			sezzle_restore_pdp_cart();
			WC()->session->set(
				WC_Gateway_Sezzlepay::PDP_CART_BACKUP_KEY,
				WC()->cart->get_cart_for_session()
			);
			// Stash the applied coupons too. empty_cart() clears them, and the
			// isolated single-product express order must not carry the original
			// cart's discounts — they belong to the stashed cart and are re-applied
			// on restore (see sezzle_restore_pdp_cart).
			WC()->session->set(
				WC_Gateway_Sezzlepay::PDP_COUPONS_BACKUP_KEY,
				WC()->cart->get_applied_coupons()
			);
			WC()->session->set(WC_Gateway_Sezzlepay::PDP_FLOW_STARTED_KEY, time());
			WC()->session->set(WC_Gateway_Sezzlepay::PDP_FLOW_COMPLETED_KEY, null);
		}

		/**
		 * Restore the cart snapshot taken by sezzle_snapshot_cart_for_pdp, replacing
		 * whatever is currently in the cart (the isolated single product) with the
		 * shopper's original contents. No-op when no snapshot exists (e.g. a cart-page
		 * express checkout, which never isolates the cart).
		 *
		 * @return bool True if a snapshot was found and restored.
		 */
		function sezzle_restore_pdp_cart()
		{
			if (!WC()->cart || !WC()->session) {
				return false;
			}
			$backup  = WC()->session->get(WC_Gateway_Sezzlepay::PDP_CART_BACKUP_KEY);
			$coupons = WC()->session->get(WC_Gateway_Sezzlepay::PDP_COUPONS_BACKUP_KEY);
			$pdp_item_key = WC()->session->get(WC_Gateway_Sezzlepay::PDP_ITEM_KEY);
			// Clear the snapshot up front so a failed/partial restore can't loop and a
			// later exit path can't restore it a second time. Clearing the cart backup
			// also stops sezzle_suppress_fees_during_pdp_express from zeroing fees, so
			// the restored cart's fees are recalculated normally below.
			WC()->session->set(WC_Gateway_Sezzlepay::PDP_CART_BACKUP_KEY, null);
			WC()->session->set(WC_Gateway_Sezzlepay::PDP_COUPONS_BACKUP_KEY, null);
			WC()->session->set(WC_Gateway_Sezzlepay::PDP_FLOW_STARTED_KEY, null);
			WC()->session->set(WC_Gateway_Sezzlepay::PDP_FLOW_COMPLETED_KEY, null);
			WC()->session->set(WC_Gateway_Sezzlepay::PDP_ITEM_KEY, null);
			// An empty array is a real snapshot: the shopper's cart was empty, and
			// restoring it means removing the product the flow added (below).
			if (!is_array($backup)) {
				return false;
			}

			// Lines added while the flow was open (another tab, or after an abandon that
			// never restored) would be lost by empty_cart() below: keep them. Only the
			// product the PDP flow added is dropped. After a completed order WooCommerce
			// has already emptied the cart, so nothing is carried over.
			$added_since = array_diff_key(
				WC()->cart->get_cart_for_session(),
				array((string) $pdp_item_key => true)
			);

			// Rebuild the cart by re-adding each snapshotted line. This populates the
			// in-memory cart directly; relying on get_cart_from_session() to reload from
			// the session 'cart' key is fragile because a later calculate_totals() would
			// persist the still-empty in-memory cart back over the restored session.
			WC()->cart->empty_cart();
			foreach (array_merge(array_values($backup), array_values($added_since)) as $item) {
				if (empty($item['product_id'])) {
					continue;
				}
				// Preserve any custom cart-item data added by other plugins (anything
				// beyond the standard keys WooCommerce manages and recomputes itself).
				$custom_data = array_diff_key(
					$item,
					array_flip(array(
						'key', 'product_id', 'variation_id', 'variation', 'quantity',
						'data', 'data_hash', 'line_tax_data', 'line_subtotal',
						'line_subtotal_tax', 'line_total', 'line_tax',
					))
				);
				WC()->cart->add_to_cart(
					$item['product_id'],
					$item['quantity'] ?? 1,
					$item['variation_id'] ?? 0,
					is_array($item['variation'] ?? null) ? $item['variation'] : array(),
					$custom_data
				);
			}

			// Re-apply the stashed coupons — empty_cart() dropped them. apply_coupon()
			// queues "coupon applied" notices, so snapshot the notice list and restore
			// it afterwards: this runs in the background (AJAX cancel or the deferred
			// on-load restore) and must not surface stray coupon messages to the shopper.
			// Restoring the notice list also discards any failure notice apply_coupon()
			// queued (e.g. a coupon that expired or hit its usage limit during the
			// express window), so log those instead — otherwise the coupon silently
			// disappears from the restored cart with no trace for the merchant.
			if (is_array($coupons) && !empty($coupons)
				&& function_exists('wc_coupons_enabled') && wc_coupons_enabled()) {
				$saved_notices = function_exists('wc_get_notices') ? wc_get_notices() : array();
				$failed_coupons = array();
				foreach ($coupons as $code) {
					if (!WC()->cart->has_discount($code)) {
						if (!WC()->cart->apply_coupon($code)) {
							$failed_coupons[] = $code;
						}
					}
				}
				if (function_exists('wc_set_notices')) {
					wc_set_notices($saved_notices);
				}
				if (!empty($failed_coupons)) {
					WC_Gateway_Sezzlepay::instance()->log(sprintf(
						'PDP express cart restore: could not re-apply coupon(s): %s',
						implode(', ', $failed_coupons)
					));
				}
			}

			WC()->cart->calculate_totals();

			// Explicitly persist the rebuilt cart to the session. In this custom AJAX
			// flow the hook that normally writes the cart to the session (set_session on
			// woocommerce_after_calculate_totals) does not reliably fire after our
			// empty_cart()/add_to_cart() sequence, so without this the session is saved at
			// shutdown with the emptied cart and the restore is lost on the next page load.
			WC()->session->set('cart', WC()->cart->get_cart_for_session());
			// Persist the re-applied coupons for the same reason (apply_coupon() only
			// updates the in-memory cart in this AJAX flow).
			WC()->session->set('applied_coupons', WC()->cart->get_applied_coupons());

			return true;
		}

		/**
		 * Restore a product-page express checkout cart snapshot on the first frontend
		 * page load after a completed order.
		 *
		 * The completion flow can't restore the cart itself: the shopper is redirected
		 * to the order-received page, where WooCommerce core (wc_clear_cart_after_payment,
		 * template_redirect priority 20) force-empties the cart for the just-paid order —
		 * undoing any restore done during completion. So we leave the snapshot in the
		 * session and reinstate it here, on the next page that isn't the order-received
		 * page, running after WC's clear. We also clear order_awaiting_payment so that
		 * clear doesn't re-empty the cart on subsequent loads.
		 */
		function sezzle_maybe_restore_pdp_cart_on_load() {
			if (is_admin() || wp_doing_ajax() || !WC()->session || !WC()->cart) {
				return;
			}
			// Nothing to do unless a product-page express flow left a snapshot behind.
			// An empty array counts: a flow started from an empty cart still left its
			// product behind.
			$backup = WC()->session->get(WC_Gateway_Sezzlepay::PDP_CART_BACKUP_KEY);
			if (!is_array($backup)) {
				return;
			}
			// Skip the order-received page — WooCommerce empties the cart there for the
			// just-paid order. Defer to the next page, where the restore sticks.
			if (function_exists('is_order_received_page') && is_order_received_page()) {
				return;
			}
			// Leave a flow that is still open (in the popup/iframe, or with the shopper
			// browsing in another tab) alone: restoring the full cart mid-checkout made
			// completion build the order from the wrong cart. Cancel and failure restore
			// through AJAX; this catches completed flows and ones abandoned long ago.
			$completed  = (bool) WC()->session->get(WC_Gateway_Sezzlepay::PDP_FLOW_COMPLETED_KEY);
			$started_at = (int) WC()->session->get(WC_Gateway_Sezzlepay::PDP_FLOW_STARTED_KEY);
			if (!$completed && $started_at && time() - $started_at < WC_Gateway_Sezzlepay::PDP_FLOW_ABANDON_AFTER) {
				return;
			}
			sezzle_restore_pdp_cart();
			// Stop wc_clear_cart_after_payment from re-emptying the restored cart on later
			// loads via the "order awaiting payment is now paid" check.
			WC()->session->set('order_awaiting_payment', null);
		}
		// Priority 25 so this runs after wc_clear_cart_after_payment (priority 20).
		add_action('template_redirect', 'sezzle_maybe_restore_pdp_cart_on_load', 25);

		/**
		 * Strip all cart fees while a product-page express checkout has the cart
		 * isolated to a single product.
		 *
		 * A PDP express flow snapshots the shopper's cart and empties it down to the
		 * one product being purchased (see sezzle_express_pdp_add_to_cart). Fee plugins
		 * (e.g. order-tip-for-woocommerce) re-add their fee from their own session on
		 * every calculate_totals(), which would otherwise fold the original cart's fees
		 * into the single-product express amount sent to Sezzle and into the WC order
		 * created for it. Those fees belong to the stashed cart, not the express order,
		 * so we drop them for as long as the snapshot is present.
		 *
		 * Runs at PHP_INT_MAX so it clears fees added by every other callback on this
		 * hook; the totals are summed only after the action completes
		 * (WC_Cart_Totals::get_fees_from_cart), so an emptied fee list yields a zero fee
		 * total. Once the snapshot is restored (flow completed/cancelled/failed) the key
		 * is gone and fees are recalculated normally, so the restored cart keeps its fees.
		 *
		 * Gated on the PDP snapshot key, so cart-page express checkout — which never
		 * isolates the cart — keeps its fees, and on the express AJAX actions, so other
		 * requests (e.g. a regular checkout) keep theirs.
		 *
		 * @param WC_Cart $cart Cart passed to the woocommerce_cart_calculate_fees hook.
		 */
		function sezzle_suppress_fees_during_pdp_express($cart)
		{
			if (!WC()->session || !$cart) {
				return;
			}
			// Only in the express requests that price the isolated cart. A snapshot can
			// outlive the flow (closed tab, failed completion), and stripping fees from
			// every request then removed them from checkouts with any payment method.
			$express_actions = array(
				'sezzle_express_pdp_add_to_cart',
				'sezzle_express_checkout_start',
				'sezzle_calculate_address_costs',
				'sezzle_express_checkout_complete',
			);
			if (!wp_doing_ajax() || !in_array($_REQUEST['action'] ?? '', $express_actions, true)) {
				return;
			}
			$backup = WC()->session->get(WC_Gateway_Sezzlepay::PDP_CART_BACKUP_KEY);
			if (is_array($backup) && !empty($backup)) {
				$cart->fees_api()->remove_all_fees();
			}
		}
		add_action('woocommerce_cart_calculate_fees', 'sezzle_suppress_fees_during_pdp_express', PHP_INT_MAX);

		/**
		 * Add the current product to the cart for product-page express checkout.
		 *
		 * Reads the submitted single-product form (quantity, variation id, and any
		 * attribute_* selections). The shopper's existing cart is snapshotted and the
		 * cart is isolated to just this product, so the express checkout covers only
		 * the product being purchased; the original cart is restored when the flow
		 * completes, is cancelled, or fails. Returns JSON success/error.
		 */
		function sezzle_express_pdp_add_to_cart()
		{
			try {
				if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !wp_verify_nonce($_POST['nonce'] ?? '', "szl_ec_start_nonce")) {
					throw new Exception(__('Invalid request', 'woo_sezzlepay'));
				}

				$product_id = absint($_POST['product_id'] ?? 0);
				if (!$product_id) {
					throw new Exception(__('Invalid product.', 'woo_sezzlepay'));
				}

				$quantity = wc_stock_amount(wp_unslash($_POST['quantity'] ?? 1));
				if (!$quantity || $quantity <= 0) {
					$quantity = 1;
				}

				$variation_id = absint($_POST['variation_id'] ?? 0);

				// Collect any variation attributes (attribute_* fields from the product form)
				$variations = array();
				foreach ($_POST as $key => $value) {
					if (strpos($key, 'attribute_') === 0) {
						$variations[sanitize_text_field($key)] = sanitize_text_field(wp_unslash($value));
					}
				}

				// WC()->cart->add_to_cart() expects the parent product id plus an optional
				// variation id; $product_id from the form is always the parent.
				$passed_validation = apply_filters(
					'woocommerce_add_to_cart_validation',
					true,
					$product_id,
					$quantity,
					$variation_id,
					$variations
				);
				if (!$passed_validation) {
					// Surface the most recent WooCommerce error notice if available
					$message = __('This product cannot be added to the cart. Please ensure all options are selected.', 'woo_sezzlepay');
					if (function_exists('wc_get_notices')) {
						$notices = wc_get_notices('error');
						if (!empty($notices)) {
							$last = end($notices);
							$message = is_array($last) && isset($last['notice']) ? wp_strip_all_tags($last['notice']) : $message;
						}
						wc_clear_notices();
					}
					throw new Exception($message);
				}

				// Snapshot the shopper's cart, then isolate it to just this product so the
				// express checkout covers only what's being purchased on the product page.
				sezzle_snapshot_cart_for_pdp();
				WC()->cart->empty_cart();

				$cart_item_key = WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $variations);
				if (!$cart_item_key) {
					// Couldn't add the product after emptying — put the original cart back
					// so a failed express attempt doesn't wipe the shopper's cart.
					sezzle_restore_pdp_cart();
					$message = __('Unable to add product to cart. Please ensure all options are selected.', 'woo_sezzlepay');
					if (function_exists('wc_get_notices')) {
						$notices = wc_get_notices('error');
						if (!empty($notices)) {
							$last = end($notices);
							$message = is_array($last) && isset($last['notice']) ? wp_strip_all_tags($last['notice']) : $message;
						}
						wc_clear_notices();
					}
					throw new Exception($message);
				}

				WC()->session->set(WC_Gateway_Sezzlepay::PDP_ITEM_KEY, $cart_item_key);
				WC()->cart->calculate_totals();

				// cart_item_key lets the client mark this flow active; the original cart
				// is restored server-side on completion/cancel/failure via the snapshot.
				wp_send_json_success(array('added' => true, 'cart_item_key' => $cart_item_key));
			} catch (Exception $e) {
				wp_send_json_error(array('message' => $e->getMessage()));
			}
		}

		/**
		 * Restore the shopper's original cart after a product-page express checkout
		 * ends without completing (cancel/failure). The PDP flow isolates the cart to
		 * the single product being expressed; this reinstates the snapshot taken at
		 * add-to-cart time so the shopper's real cart is not left mutated.
		 */
		function sezzle_express_pdp_restore_cart()
		{
			try {
				if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !wp_verify_nonce($_POST['nonce'] ?? '', "szl_ec_start_nonce")) {
					throw new Exception(__('Invalid request', 'woo_sezzlepay'));
				}

				$restored = sezzle_restore_pdp_cart();

				wp_send_json_success(array('restored' => (bool) $restored));
			} catch (Exception $e) {
				wp_send_json_error(array('message' => $e->getMessage()));
			}
		}

		/**
		 * Return freshly generated express checkout nonces.
		 *
		 * Product pages are prime full-page-cache targets, so the nonces baked into
		 * the localized script can be stale by the time a cached PDP is served to a
		 * logged-out visitor (past the ~12-24h nonce window). The client calls this
		 * uncached admin-ajax endpoint right before the PDP express flow to obtain
		 * fresh nonces. No nonce verification here — this endpoint mints them.
		 */
		function sezzle_express_refresh_nonce()
		{
			wp_send_json_success(array(
				'szl_ec_start_nonce'    => wp_create_nonce('szl_ec_start_nonce'),
				'szl_ec_complete_nonce' => wp_create_nonce('szl_ec_complete_nonce'),
			));
		}

		/**
		 * Report whether the current cart's express checkout amount meets the
		 * configured minimum, so the client can show/hide the cart express button as
		 * the cart total changes (quantity edits, coupons, item removal) without a
		 * page reload. Read-only and server-authoritative — it uses the same amount
		 * basis as the click-time gate, so the button appears exactly when a click
		 * would succeed.
		 *
		 * Verifies the express start nonce to follow WordPress conventions, but fails
		 * open on verification failure: this endpoint only reads cart state, and a
		 * stale nonce (full-page-cached page past the nonce window) must not hide a
		 * valid button. The click-time gate in start_express_checkout() is the
		 * authoritative check either way.
		 */
		function sezzle_express_get_cart_state()
		{
			if (!wp_verify_nonce($_POST['nonce'] ?? '', 'szl_ec_start_nonce')) {
				wp_send_json_success(array('above_minimum' => true));
				return;
			}

			// The JS shows the cart button only while this is true, so it also carries
			// whether Sezzle is available for the cart at all.
			wp_send_json_success(array(
				'above_minimum' => is_express_cart_above_minimum() && sezzle_is_gateway_available_for_cart(),
			));
		}

		/**
		 * The pending order from this shopper's previous, unfinished express
		 * checkout, so restarting rebuilds it instead of leaving a duplicate.
		 *
		 * @return WC_Order|null
		 */
		function sezzle_get_reusable_express_order()
		{
			if (!WC()->session) {
				return null;
			}
			$order = wc_get_order((int) WC()->session->get('sezzle_express_order_id'));
			if (!$order
				|| $order->get_payment_method() !== WC_Gateway_Sezzlepay::instance()->id
				|| !$order->has_status('pending')
				|| $order->is_paid()) {
				return null;
			}
			return $order;
		}

		/**
		 * Start express checkout
		 */
		function start_express_checkout()
		{
			try {
				// Security & validation
				if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !wp_verify_nonce($_POST['nonce'] ?? '', "szl_ec_start_nonce")) {
					throw new Exception('Invalid request');
				}

				$checkout_source = sanitize_text_field($_POST['checkout_source'] ?? 'cart');
				if (!in_array($checkout_source, array('cart', 'product'), true)) {
					throw new Exception('Invalid checkout source');
				}
				
				if (!WC()->cart || WC()->cart->is_empty()) {
					throw new Exception('Cart not available or empty');
				}

				// Check the minimum before creating anything, so a rejected click leaves
				// no order behind. Same basis as the button gate.
				WC()->cart->calculate_totals();
				if (!is_express_cart_above_minimum()) {
					throw new Exception('Cart total below minimum amount');
				}
				// The cart now holds exactly what's being bought (a PDP flow isolated it).
				if (!sezzle_is_gateway_available_for_cart()) {
					throw new Exception('Sezzle is not available for this cart');
				}
				sezzle_save_cart_fees_to_session();

				$order = null;
				if (!allow_create_order_post_checkout()) {
					$order = create_order_from_cart_data(sezzle_get_reusable_express_order());
					WC()->session->set('sezzle_express_order_id', $order->get_id());
				}

				$checkout_data = get_express_checkout_data($checkout_source, $order);

				wp_send_json_success([
					'checkout_data' => $checkout_data
				]);

			} catch (Exception $e) {
				WC_Gateway_Sezzlepay::instance()->log('Express checkout could not be started: ' . $e->getMessage());
				wp_send_json_error([
					'error' => $e->getMessage()
				]);
			}
		}

		/**
		 * Calculate address-related costs for express checkout
		 */
		function calculate_address_related_costs()
		{
			try {
				if ( $_SERVER['REQUEST_METHOD'] != 'POST' || !wp_verify_nonce($_POST['nonce'] ?? '', "szl_ec_start_nonce")) {
					throw new Exception('Invalid request');
				}

				// Get required parameters
				$order_uuid = sanitize_text_field(wp_unslash($_POST['order_uuid'] ?? ''));
				$address_uuid = sanitize_text_field(wp_unslash($_POST['address_uuid'] ?? ''));
				$country_code = sanitize_text_field(wp_unslash($_POST['country_code'] ?? ''));

				// Get full address details from SDK
				$address_data = [
					'street' => sanitize_text_field(wp_unslash($_POST['street'] ?? '')),
					'city' => sanitize_text_field(wp_unslash($_POST['city'] ?? '')),
					'state' => sanitize_text_field(wp_unslash($_POST['state'] ?? '')),
					'postal_code' => sanitize_text_field(wp_unslash($_POST['postal_code'] ?? '')),
					'country_code' => $country_code
				];

				if (empty($order_uuid) || empty($address_uuid) || empty($country_code) || empty($address_data['postal_code'])) {
					throw new Exception('Missing required parameters');
				}

				// Validate postal code
				if (!WC_Validation::is_postcode($address_data['postal_code'], $address_data['country_code'])) {
					throw new Exception(__('Please enter a valid postcode / ZIP.', 'woo_sezzlepay'));
				}

				// Update WooCommerce customer data with shipping address
				WC()->customer->set_shipping_location(
					$address_data['country_code'],
					$address_data['state'],
					$address_data['postal_code'],
					$address_data['city']
				);
				WC()->customer->set_shipping_address_1($address_data['street']);
				WC()->customer->set_calculated_shipping(true);
				WC()->customer->save();

				// Calculate cart totals with shipping
				WC()->cart->calculate_fees();
				WC()->cart->calculate_totals();

				// Get shipping packages and methods
				$packages = WC()->shipping()->get_packages();
				if (empty($packages)) {
					throw new Exception('No shipping packages available');
				}

				$methods = $packages[0]['rates'];
				if (empty($methods)) {
					throw new Exception('Shipping is unavailable for this address.');
				}

				$gateway = WC_Gateway_Sezzlepay::instance();
				$currency = get_woocommerce_currency();
				$totals = WC()->cart->get_totals();
				$fee_total = WC()->cart->get_fee_total() + WC()->cart->get_fee_tax();

				// Build shipping options with WooCommerce order logic including discounts
				$shipping_options = [];
				// Keep the WooCommerce rate behind each option we offer, so that when the
				// shopper picks one inside the Sezzle flow the completion handler can
				// rebuild a proper shipping line (method id, instance id, tax) instead of
				// a bare title + total. See sezzle_resolve_express_shipping_option().
				$shipping_option_map = [];

				foreach ($methods as $method) {
					// Calculate final order amount using WooCommerce logic
					// cart_contents_total is already after discounts (no tax)
					// Tax is calculated on the discounted amount
					$items_after_discounts = (float)$totals['cart_contents_total'];  // Products after discounts (no tax)
					$product_tax = (float)$totals['cart_contents_tax'];  // Tax on discounted products
					$shipping_cost = (float)$method->get_cost();
					$shipping_tax = (float)$method->get_shipping_tax();
					$total_tax = $product_tax + $shipping_tax;

					$final_order_amount = $items_after_discounts + $total_tax + $shipping_cost + $fee_total;

					// Only include methods that meet minimum checkout amount
					if (Sezzle_Utils::formatToCents($final_order_amount) >= sezzle_min_checkout_amount_in_cents()) {
						$shipping_amount_cents = (int)round($shipping_cost * 100);
						$tax_amount_cents = (int)round($total_tax * 100);
						$final_order_amount_cents = (int)round($final_order_amount * 100);

						$option_uuid = uniqid('shipping_');

						$shipping_option = [
							'uuid' => $option_uuid,
							'name' => $method->get_label(),
							'description' => $method->get_label(),
							'shipping_amount_in_cents' => $shipping_amount_cents,
							'tax_amount_in_cents' => $tax_amount_cents,
							'final_order_amount_in_cents' => $final_order_amount_cents
						];

						$shipping_options[] = $shipping_option;
						$shipping_option_map[$option_uuid] = [
							'rate_id'     => $method->get_id(),
							'method_id'   => $method->get_method_id(),
							'instance_id' => $method->get_instance_id(),
							'label'       => $method->get_label(),
							'cost'        => $shipping_cost,
							// tax_rate_id => amount, as WooCommerce stores shipping taxes.
							'taxes'       => $method->get_taxes(),
						];
					}
				}

				if (empty($shipping_options)) {
					throw new Exception('All shipping methods do not meet the Sezzle minimum checkout limit.');
				}

				// Stash the offered options for the completion handler. Overwritten on
				// each address change, so it always describes the options the shopper is
				// actually choosing from. Without a session we simply skip it: completion
				// falls back to the amount Sezzle reports.
				if (WC()->session) {
					WC()->session->set(WC_Gateway_Sezzlepay::EXPRESS_SHIPPING_OPTIONS_KEY, $shipping_option_map);
				}

				// Prepare update request for Sezzle gateway
				$update_request = [
					'currency_code' => $currency,
					'address_uuid' => $address_uuid,
					'shipping_options' => $shipping_options
				];
				$txn_mode = $gateway->get_option('transaction-mode');
				$service_v2 = new Service_V2($txn_mode, $gateway->get_keys());
				$response = $service_v2->update_checkout($order_uuid, $update_request);
				if (isset($response->error)) {
					throw new Exception($response->error->message ?? 'Failed to update checkout');
				}

				wp_send_json_success([
					'shipping_options' => $shipping_options
				]);
			} catch (Exception $e) {
				WC_Gateway_Sezzlepay::instance()->log('Express checkout shipping/address cost calculation failed: ' . $e->getMessage());
				wp_send_json_error([
					'error' => [
						'code' => 'merchant_error',
						'message' => $e->getMessage()
					]
				]);
			}
		}


		/**
		 * Look up the WooCommerce shipping rate behind the option the shopper selected
		 * inside the Sezzle express flow.
		 *
		 * Matches on the option uuid we generated in calculate_address_related_costs when
		 * the gateway echoes it back, otherwise on the method name (the label we sent as
		 * the option's name). Returns null when there is nothing to match against — a
		 * stale/absent session map, or a name we no longer offer — so the caller can fall
		 * back to the amount Sezzle reports.
		 *
		 * The name fallback is corroborated by price. Labels are not unique: two Flat
		 * Rate instances in different zones, or a method offered by more than one
		 * instance, routinely share one. So every label match is checked against the
		 * shipping amount Sezzle authorized, which narrows an ambiguous label to the one
		 * option the shopper can actually have picked, and catches a label that matches
		 * an option from an older generation of the map (it is rewritten on every
		 * address change and only cleared on a successful completion). A label that
		 * still resolves to zero or several options is treated as unresolved and drops
		 * to the caller's fallback: attaching a plausible but wrong instance id, cost
		 * and tax breakdown is worse than not resolving at all, because the order total
		 * then diverges from the authorized amount and should_capture_payment() fails
		 * the whole checkout.
		 *
		 * @param object $shipping_method          The shipping_method object from the Sezzle order.
		 * @param int    $shipping_amount_in_cents Shipping amount Sezzle authorized.
		 * @return array|null Rate details (rate_id, method_id, instance_id, label, cost, taxes).
		 */
		function sezzle_resolve_express_shipping_option($shipping_method, $shipping_amount_in_cents)
		{
			if (!WC()->session) {
				return null;
			}
			$map = WC()->session->get(WC_Gateway_Sezzlepay::EXPRESS_SHIPPING_OPTIONS_KEY);
			if (!is_array($map) || empty($map)) {
				return null;
			}

			// The uuid is generated per option per address calculation, so a hit is the
			// exact option we offered in the map's current generation — no corroboration
			// needed.
			$uuid = isset($shipping_method->uuid) ? (string) $shipping_method->uuid : '';
			if ('' !== $uuid && isset($map[$uuid])) {
				return $map[$uuid];
			}

			$name = isset($shipping_method->name) ? (string) $shipping_method->name : '';
			if ('' === $name) {
				return null;
			}

			$matches = array();
			foreach ($map as $option) {
				if (isset($option['label']) && $option['label'] === $name) {
					$matches[] = $option;
				}
			}

			if (empty($matches)) {
				return null;
			}

			// Same rounding the option was priced with in calculate_address_related_costs,
			// so this compares like for like rather than float against float.
			$authorized_cents = (int) $shipping_amount_in_cents;
			$priced_as_authorized = array();
			foreach ($matches as $option) {
				if ((int) round(((float) $option['cost']) * 100) === $authorized_cents) {
					$priced_as_authorized[] = $option;
				}
			}

			if (1 === count($priced_as_authorized)) {
				return $priced_as_authorized[0];
			}

			WC_Gateway_Sezzlepay::instance()->log(sprintf(
				'Express checkout: %d offered shipping options carry the label "%s" and %d of them '
				. 'cost the %d cents Sezzle authorized, so the selected one cannot be identified. '
				. 'Treating it as unresolved.',
				count($matches),
				$name,
				count($priced_as_authorized),
				$authorized_cents
			));

			return null;
		}

		/**
		 * Build the WooCommerce shipping line for a completed express checkout.
		 *
		 * Reconstructs the real WooCommerce rate the shopper chose — method id, instance
		 * id and per-rate taxes — rather than a title and a total alone. That matters
		 * because sezzle_express_checkout_complete() calls $order->calculate_totals(),
		 * which recalculates taxes from the order's (now Sezzle-supplied) address: a
		 * shipping line with no method id has no tax context, so WooCommerce could arrive
		 * at a different total than the amount Sezzle authorized, and the capture would
		 * then fail the amount check in should_capture_payment(). It also means the order
		 * carries a genuine shipping method for fulfilment and reporting.
		 *
		 * Falls back to the previous behaviour (Sezzle's reported name and amount) when
		 * the rate can't be resolved, so a completed checkout is never blocked by a
		 * missing session map.
		 *
		 * @param object $shipping_method            shipping_method from the Sezzle order.
		 * @param int    $shipping_amount_in_cents   Shipping amount Sezzle authorized.
		 * @return WC_Order_Item_Shipping
		 */
		function sezzle_build_express_shipping_item($shipping_method, $shipping_amount_in_cents)
		{
			$shipping_item = new WC_Order_Item_Shipping();
			$selected = sezzle_resolve_express_shipping_option($shipping_method, $shipping_amount_in_cents);

			if (null === $selected) {
				$name = isset($shipping_method->name) ? (string) $shipping_method->name : '';
				WC_Gateway_Sezzlepay::instance()->log(sprintf(
					'Express checkout: could not match the selected shipping method (%s) to a WooCommerce rate; '
					. 'recording the amount Sezzle reported without a method id or tax breakdown.',
					'' !== $name ? $name : 'unnamed'
				));
				$shipping_item->set_method_title($name);
				$shipping_item->set_total((float) $shipping_amount_in_cents / 100);
				return $shipping_item;
			}

			$shipping_item->set_method_title($selected['label']);
			$shipping_item->set_method_id($selected['method_id']);
			if ('' !== (string) $selected['instance_id']) {
				$shipping_item->set_instance_id($selected['instance_id']);
			}
			// WooCommerce's own figure for the rate, not Sezzle's — the order is built
			// from WooCommerce's view of the merchant's shipping options. The resolver
			// only hands back an option whose cost is the amount Sezzle authorized (or
			// that the uuid identified exactly), so the two agree by the time we get here.
			$shipping_item->set_total($selected['cost']);
			if (!empty($selected['taxes']) && is_array($selected['taxes'])) {
				// WC_Order_Item_Shipping stores taxes as array( 'total' => rate_id => amount ).
				$shipping_item->set_taxes(array('total' => $selected['taxes']));
			}

			return $shipping_item;
		}

		function sezzle_express_checkout_complete()
		{
			$order = null;
			$created_order = false;
			$sezzle_order_uuid = '';
			try {
				if (!wp_verify_nonce($_POST['nonce'] ?? '', "szl_ec_complete_nonce")) {
					throw new Exception('Invalid request');
				}

				// Get sezzle_order_uuid from POST data
				$sezzle_order_uuid = isset($_POST['sezzle_order_uuid']) ? sanitize_text_field($_POST['sezzle_order_uuid']) : '';
				
				if (empty($sezzle_order_uuid)) {
					throw new Exception(__('Sezzle order UUID is required.', 'woo_sezzlepay'));
				}

				$gateway = WC_Gateway_Sezzlepay::instance();
				// A retried or concurrent completion must not create or capture twice.
				$gateway->lock_completion($sezzle_order_uuid);
				$gateway->log('Completing express checkout for Sezzle order UUID: ' . $sezzle_order_uuid);
				
				// Get order details from Sezzle
				$sezzle_order_details = $gateway->get_sezzle_order($sezzle_order_uuid);
				
				if (!$sezzle_order_details || !isset($sezzle_order_details->reference_id)) {
					throw new Exception(__('Unable to retrieve order details from Sezzle.', 'woo_sezzlepay'));
				}

				// Get reference_id which is the order key when create_order_post_checkout is false else it is the cart hash
				$reference_id = $sezzle_order_details->reference_id;

				// Approval comes from the Sezzle API, not the browser: don't create or capture
				// an order for a checkout the shopper didn't approve.
				if (!$gateway->is_sezzle_order_authorized($sezzle_order_details)) {
					throw new Exception(__('Sezzle was unable to complete your payment. Please try again or choose another payment method.', 'woo_sezzlepay'));
				}
				
				// Get or create WooCommerce Order
				$order_id = wc_get_order_id_by_order_key($reference_id);
				// Scenario: If merchant flips the create_order_post_checkout setting while the shopper is at checkout, 
				// then the order will be created again. To avoid this, we check if the order already exists and if it does, 
				// we use the existing order.
				if (!$order_id) {
					// Post-checkout creation: a previous attempt may already have made it.
					// meta_key/meta_value work on both HPOS and legacy post storage
					// (legacy ignores meta_query); re-check the match regardless.
					$existing = wc_get_orders([
						'limit'      => 1,
						'meta_key'   => 'sezzle_order_uuid',
						'meta_value' => $sezzle_order_uuid,
					]);
					if ($existing && $existing[0]->get_meta('sezzle_order_uuid', true) === $sezzle_order_uuid) {
						$order = $existing[0];
					} else {
						$order = create_order_from_cart_data();
						$created_order = true;
					}
				} else {
					$order = wc_get_order($order_id);
				}

				if (!$order) {
					throw new Exception(__('error creating or retrieving WooCommerce order.', 'woo_sezzlepay'));
				}

				$gateway->update_or_create_meta_data($order, 'sezzle_order_uuid', $sezzle_order_uuid);

				// Already completed by an earlier request: send the shopper to the receipt.
				if ($order->is_paid()) {
					wp_send_json_success([
						'redirect_url' => $order->get_checkout_order_received_url(),
						'message' => __('Order details updated successfully.', 'woo_sezzlepay')
					]);
				}

				// check the status of the order
				if ($order->get_status() !== 'pending') {
					throw new Exception(__('Order is not in pending payment status.', 'woo_sezzlepay'));
				}

				// Update WooCommerce order with shipping and customer details from Sezzle
				if (isset($sezzle_order_details->customer)) {
					$customer = $sezzle_order_details->customer;

					// Update customer details. Keep what the order already has (a logged-in
					// shopper's account details) when Sezzle doesn't supply a value: an order
					// without an email gets no confirmation email.
					if ( ! empty( $customer->email ) ) {
						$order->set_billing_email( $customer->email );
					}
					if ( ! empty( $customer->first_name ) ) {
						$order->set_billing_first_name( $customer->first_name );
					}
					if ( ! empty( $customer->last_name ) ) {
						$order->set_billing_last_name( $customer->last_name );
					}
					if ( ! empty( $customer->phone ) ) {
						$order->set_billing_phone( $customer->phone );
					}

					if (isset($customer->billing_address)) {
						$billing_address = $customer->billing_address;
						$order->set_billing_address_1($billing_address->street ?? '');
						$order->set_billing_address_2($billing_address->street2 ?? '');
						$order->set_billing_city($billing_address->city ?? '');
						$order->set_billing_state($billing_address->state ?? '');
						$order->set_billing_postcode($billing_address->postal_code ?? '');
						$order->set_billing_country($billing_address->country_code ?? '');
					}
					
					// Update shipping address
					if (isset($customer->shipping_address)) {
						$shipping_address = $customer->shipping_address;
						$order->set_shipping_first_name($shipping_address->first_name ?? '');
						$order->set_shipping_last_name($shipping_address->last_name ?? '');
						$order->set_shipping_address_1($shipping_address->street ?? '');
						$order->set_shipping_address_2($shipping_address->street2 ?? '');
						$order->set_shipping_city($shipping_address->city ?? '');
						$order->set_shipping_state($shipping_address->state ?? '');
						$order->set_shipping_postcode($shipping_address->postal_code ?? '');
						$order->set_shipping_country($shipping_address->country_code ?? '');
					}

					// Add shipping method (rate + tax)
					if ( isset( $sezzle_order_details->shipping_method ) ) {
						// Replace, don't append: a retried completion would add a second line.
						$order->remove_order_items( 'shipping' );
						$order->add_item(
							sezzle_build_express_shipping_item(
								$sezzle_order_details->shipping_method,
								$sezzle_order_details->shipping_amount->amount_in_cents ?? 0
							)
						);
					}
				}

				$order->calculate_totals();

				// Process Payment
				$gateway->process_order($order, $reference_id, $sezzle_order_details, $order->get_order_key());

				$redirect_url = $order->get_checkout_order_received_url();
				WC()->session->__unset('sezzle_express_order_id');
				// The offered shipping options have served their purpose. Cleared only on
				// success, so a completion that threw can still resolve the rate if the
				// shopper's flow retries.
				WC()->session->__unset(WC_Gateway_Sezzlepay::EXPRESS_SHIPPING_OPTIONS_KEY);
				// Let the next page load restore the shopper's pre-PDP cart.
				WC()->session->set(WC_Gateway_Sezzlepay::PDP_FLOW_COMPLETED_KEY, true);

				// Return success response
				wp_send_json_success([
					'redirect_url' => $redirect_url,
					'message' => __('Order details updated successfully.', 'woo_sezzlepay')
				]);

			} catch (Exception $e) {
				WC_Gateway_Sezzlepay::instance()->log('Express checkout completion failed for Sezzle order UUID ' . $sezzle_order_uuid . ': ' . $e->getMessage());
				$error_data = [
					'message' => $e->getMessage(),
				];

				// If this is a cart amount mismatch error, include order data for logging
				// The exception is thrown from should_capture_payment() which receives $order as parameter
				if (isset($order) && $order instanceof WC_Order && 
					strpos($e->getMessage(), WC_Gateway_Sezzlepay::ERROR_CART_AMOUNT_MISMATCH) !== false) {
					
					// Extract order items
					$items = [];
					foreach ($order->get_items() as $item_id => $item) {
						$product = $item->get_product();
						$items[] = [
							'name' => $item->get_name(),
							'sku' => $product ? $product->get_sku() : '',
							'quantity' => $item->get_quantity(),
							'price' => [
								'amount_in_cents' => Sezzle_Utils::formatToCents($item->get_subtotal() / $item->get_quantity()),
								'currency' => $order->get_currency()
							]
						];
					}
					
					// Get order totals
					$error_data['wc_order_data'] = [
						'items' => $items,
						'price' => floatval($order->get_subtotal()),
						'tax' => floatval($order->get_total_tax()),
						'shipping' => floatval($order->get_shipping_total()),
						'fees' => floatval($order->get_fees() ? array_sum(array_map(function($fee) {
							return $fee->get_amount();
						}, $order->get_fees())) : 0),
						'total' => floatval($order->get_total()),
					];
				}

				// Match the classic callback: an order created here, after the shopper
				// returned from Sezzle, must not be left behind unpaid.
				if ($created_order) {
					WC_Gateway_Sezzlepay::instance()->release_unpaid_order($order);
				}

				wp_send_json_error($error_data);
			}
		}


		/**
		 * Send widget server logs via AJAX
		 */
		function sezzle_send_widget_server_log() {
			try {
				$event_name = isset($_POST['event_name']) ? sanitize_text_field($_POST['event_name']) : '';
				$page_name = isset($_POST['page_name']) ? sanitize_text_field($_POST['page_name']) : '';
				$nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
				
				// Validate nonce for security. Dedicated widget-log nonce (not the shared
				// start nonce) so this action's scope stays narrow; localized as
				// szl_ec_widget_log_nonce.
				if (!wp_verify_nonce($nonce, 'szl_ec_widget_log_nonce')) {
					wp_send_json_error(['message' => __('Invalid nonce', 'woo_sezzlepay')]);
					return;
				}

				if (empty($event_name) || empty($page_name)) {
					wp_send_json_error(['message' => __('Missing required parameters', 'woo_sezzlepay')]);
					return;
				}
				
				$gateway = WC_Gateway_Sezzlepay::instance();
				$txn_mode = $gateway->get_option('transaction-mode');
				$service_v2 = new Service_V2($txn_mode, $gateway->get_keys());
				$description = $page_name . ' page in ' . $txn_mode . ' mode';
				$event = [
					'event_name' => $event_name,
					'description' => $description,
					'merchant_uuid' => $gateway->get_option('merchant-id'),
					'merchant_site' => get_site_url()
				];
				$service_v2->send_widget_server_logs([$event]);
				wp_send_json_success(['message' => 'Widget server log sent successfully']);
			} catch (Exception $e) {
				// This endpoint is nopriv (reachable by unauthenticated guests), so never
				// return the raw exception string — it can leak internal hostnames, endpoint
				// paths, or config values from HTTP client / service-wrapper internals. Log
				// the detail server-side and hand the caller a generic message.
				WC_Gateway_Sezzlepay::instance()->log('Widget server log error: ' . $e->getMessage());
				wp_send_json_error(['message' => __('An error occurred. Please try again.', 'woo_sezzlepay')]);
			}
		}

		/**
		 * Re-verify the stored API keys against the gateway, invoked when an express
		 * checkout ends without completing (the SDK's onCancel callback).
		 *
		 * The express checkout SDK authenticates with the gateway directly from the
		 * browser and only ever reports an empty "cancel" to the page — it never
		 * surfaces the 401 to any catchable callback. So this is the ONLY server-side
		 * signal that can detect that the stored keys were rejected. We re-authenticate
		 * the stored keys here with the default auto-disable behaviour: a genuine 401
		 * from /v2/authentication disables the gateway and clears the keys via
		 * disable_due_to_unauthorized(), so the disablement persists until the merchant
		 * re-enters valid keys (the core MERCHANT-1645 requirement). We then report the
		 * resulting state so the front-end can remove the now-broken express button.
		 *
		 * This endpoint is registered nopriv (express checkout is a guest-capable flow),
		 * so the destructive disable is reachable by unauthenticated callers. That is
		 * acceptable because a 401 here can only occur when the merchant's own
		 * server-side stored keys are genuinely invalid — a caller cannot forge one, as
		 * they do not control the keys. The abuse surface is further bounded by:
		 *   - the 60s success cache below (repeated valid-key checks don't fan out), and
		 *   - self-limiting on failure: the first 401 clears the keys, after which the
		 *     empty-keys early-return short-circuits every later call with no upstream hit.
		 */
		function sezzle_express_checkout_verify_auth() {
			try {
				$nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
				// Dedicated nonce (not the shared start nonce) so this action's scope stays
				// narrow and independently rotatable; localized as szl_ec_verify_auth_nonce.
				if (!wp_verify_nonce($nonce, 'szl_ec_verify_auth_nonce')) {
					wp_send_json_error(['message' => __('Invalid nonce', 'woo_sezzlepay')]);
					return;
				}

				$gateway = WC_Gateway_Sezzlepay::instance();
				$keys = $gateway->get_keys();

				// Nothing to validate if keys are already gone.
				if (empty($keys['public_key']) || empty($keys['private_key'])) {
					wp_send_json_success(['disabled' => $gateway->get_option('enabled') === 'no']);
					return;
				}

				$txn_mode = $gateway->get_option('transaction-mode');

				// Short-lived success cache, keyed by mode + public key so re-entered
				// keys bust it. A shopper opening/cancelling the express popup several
				// times in a row would otherwise fire one live /v2/authentication call
				// each; collapse repeat checks within the window to a single upstream
				// round-trip so we don't fan out to the gateway and exhaust the
				// merchant's rate limit. Only successes are cached (see below).
				$verify_cache_key = 'szl_ec_verify_auth_' . hash('sha256', $txn_mode . '|' . ($keys['public_key'] ?? ''));
				if (get_transient($verify_cache_key) === 'ok') {
					wp_send_json_success(['disabled' => false]);
					return;
				}

				// Default auto-disable behaviour: a 401 from /v2/authentication runs
				// disable_due_to_unauthorized(), persistently disabling the gateway and
				// clearing the stored keys so the disablement survives page reloads (see
				// docblock for why this is safe on a nopriv endpoint).
				$service_v2 = new Service_V2($txn_mode, $keys, ['disable_on_unauthorized' => true]);
				try {
					$service_v2->authenticate($keys);
					// Cache successes only — a bad-key state must never be cached, so it
					// self-heals the moment valid keys start authenticating again.
					set_transient($verify_cache_key, 'ok', 60);
					wp_send_json_success(['disabled' => false]);
				} catch (Exception $e) {
					// A genuine 401 means the keys are rejected: disable_due_to_unauthorized()
					// already ran inside authenticate() (gateway now disabled, keys cleared),
					// so report disabled=true and the front-end removes the now-broken button.
					if ((int)$e->getCode() === 401) {
						wp_send_json_success(['disabled' => true]);
						return;
					}
					// Any other exception (network, DNS, malformed response, a PHP error in
					// Service_V2) means we could NOT verify the keys. Don't report success
					// with disabled=false — that would falsely claim "all good". Surface an
					// error so the caller knows verification was inconclusive; the front-end
					// leaves the button in place rather than hiding it spuriously.
					wp_send_json_error(['message' => __('Verification failed', 'woo_sezzlepay')]);
				}
			} catch (Exception $e) {
				// This endpoint is nopriv (reachable by unauthenticated guests), so never
				// return the raw exception string — it can leak internal hostnames, endpoint
				// paths, or config values from HTTP client / service-wrapper internals. Log
				// the detail server-side and hand the caller a generic message.
				WC_Gateway_Sezzlepay::instance()->log('Express checkout verify_auth error: ' . $e->getMessage());
				wp_send_json_error(['message' => __('An error occurred. Please try again.', 'woo_sezzlepay')]);
			}
		}

		/**
		 * Output scoped admin CSS for the Sezzle settings page.
		 *
		 * Restyles WooCommerce's default settings form into an on-brand, card-based
		 * layout (see sezzle.com/brand-guidelines): each section title renders as the
		 * header of a rounded card and the fields that follow become the card body,
		 * with subsection titles acting as inset group headers within a card. Colours,
		 * radii and spacing follow the Sezzle brand tokens. Responsive down to mobile.
		 *
		 * Only printed on the Sezzle gateway settings page, so the selectors can be
		 * page-global without leaking into the rest of wp-admin.
		 */
		function sezzle_admin_settings_styles() {
			if ( ! is_admin()
				|| ! isset( $_GET['page'], $_GET['tab'], $_GET['section'] )
				|| sanitize_text_field( wp_unslash( $_GET['page'] ) ) !== 'wc-settings'
				|| sanitize_text_field( wp_unslash( $_GET['tab'] ) ) !== 'checkout'
				|| sanitize_text_field( wp_unslash( $_GET['section'] ) ) !== 'sezzlepay' ) {
				return;
			}
			echo '<style>
				/* ---- Brand tokens ---------------------------------------------- */
				:root {
					--sezzle-plum-dark: #201733;   /* headers, primary text   */
					--sezzle-plum-light: #f2e6ff;  /* header text on dark      */
					--sezzle-bold-plum: #8333d4;   /* CTAs / accents on light  */
					--sezzle-card-border: #e7e1f0;
					--sezzle-radius-lg: 16px;      /* cards                    */
					--sezzle-radius-md: 10px;      /* inputs / buttons         */
				}

				/* Page chrome: give the form room to breathe. The page heading carries
				   .wc-admin-header; include #mainform > h2 as a version-safe fallback. */
				#mainform > h2,
				#mainform .wc-admin-header {
					color: var(--sezzle-plum-dark);
					font-size: 1.8em;
					font-weight: 600;
					margin: 0 0 4px;
				}

				/* Distinct heading sizes for section vs subsection titles. Each <h3>
				   carries the core .wc-settings-sub-title class AND a Sezzle type class
				   on the same element, so the two are written as a compound selector
				   (no descendant space); the #mainform prefix raises specificity above
				   the WooCommerce core rule for .wc-settings-sub-title. */
				#mainform .wc-settings-sub-title.sezzle-section-title { font-size: 1.4em; }
				#mainform .wc-settings-sub-title.sezzle-subsection-title { font-size: 1.2em; }

				/* Hide the empty leading .form-table WooCommerce emits before the very
				   first title (now that every table is a bordered card body it would
				   otherwise render as an empty box). */
				#mainform table.form-table:not(:has(tr)) { display: none; }

				/* ---- Section header = top of a card ---------------------------- */
				.sezzle-section-title {
					margin: 32px 0 0 !important;
					padding: 16px 24px;
					background: var(--sezzle-plum-dark);
					color: var(--sezzle-plum-light);
					font-weight: 600;
					line-height: 1.3;
					border-radius: var(--sezzle-radius-lg) var(--sezzle-radius-lg) 0 0;
				}
				.sezzle-section-title:first-of-type { margin-top: 8px !important; }

				/* ---- Card body = the table that follows a title ---------------- */
				#mainform table.form-table {
					margin: 0;
					background: #fff;
					border-left: 1px solid var(--sezzle-card-border);
					border-right: 1px solid var(--sezzle-card-border);
					border-bottom: 1px solid var(--sezzle-card-border);
					border-radius: 0 0 var(--sezzle-radius-lg) var(--sezzle-radius-lg);
					padding: 4px 24px 12px;
					box-shadow: 0 1px 2px rgba(32, 23, 51, 0.05);
				}
				/* A table immediately followed by a subsection header sits mid-card:
				   square its bottom and drop the border/shadow so it flows into the
				   subsection header below it. */
				#mainform table.form-table:has(+ .sezzle-subsection-title) {
					border-bottom: none;
					border-radius: 0;
					box-shadow: none;
					padding-bottom: 0;
				}

				/* ---- Subsection header = inset group header inside a card ------ */
				.sezzle-subsection-title {
					margin: 0 !important;
					padding: 18px 24px 2px;
					background: #fff;
					border-left: 1px solid var(--sezzle-card-border);
					border-right: 1px solid var(--sezzle-card-border);
					color: var(--sezzle-bold-plum);
					font-weight: 600;
					letter-spacing: 0.06em;
					text-transform: uppercase;
				}
				/* Hairline above a subsection header that follows another group, to
				   separate stacked groups within the same card. */
				.sezzle-subsection-title + table.form-table + .sezzle-subsection-title {
					box-shadow: inset 0 1px 0 #f0ecf6;
					padding-top: 22px;
				}

				/* ---- Field rows ------------------------------------------------ */
				#mainform table.form-table tbody {
					display: block;
					margin: 8px 24px;
				}
				#mainform table.form-table th {
					color: var(--sezzle-plum-dark);
					font-weight: 600;
					padding: 16px 20px 16px 0;
				}
				#mainform table.form-table td.forminp { padding: 12px 0; }

				/* Help tips stick just below the admin bar while their row scrolls.
				   The current WooCommerce class is .woocommerce-help-tip (older builds
				   used .help_tip); target both so it works regardless of WC version. */
				#mainform table.form-table .woocommerce-help-tip,
				#mainform table.form-table .help_tip {
					position: relative;
					top: 2px;
				}

				/* Inputs and selects: brand radius + plum focus ring. */
				#mainform table.form-table input[type=text],
				#mainform table.form-table input[type=number],
				#mainform table.form-table select {
					border-radius: var(--sezzle-radius-md);
					border: 1px solid #c7c2d2;
					box-shadow: none;
				}
				#mainform table.form-table input[type=text]:focus,
				#mainform table.form-table input[type=number]:focus,
				#mainform table.form-table select:focus {
					border-color: var(--sezzle-bold-plum);
					box-shadow: 0 0 0 2px rgba(131, 51, 212, 0.2);
					outline: none;
				}
				#mainform table.form-table input[type=checkbox]:checked {
					background: var(--sezzle-bold-plum);
					border-color: var(--sezzle-bold-plum);
				}
				/* WordPress draws the checkmark as an SVG data-URI with the admin theme
				   colour (blue #3582c4) baked into the path fill attribute, which fails
				   contrast on our plum fill. It ignores the CSS `color` property because
				   the fill is hard-coded (not currentColor), so replace the whole glyph
				   with the same path re-encoded with a white fill. The fill is left
				   URL-encoded (%23ffffff) so no literal apostrophe lands in the
				   single-quoted PHP style string. */
				#mainform table.form-table input[type=checkbox]:checked::before {
					content: url("data:image/svg+xml;utf8,%3Csvg%20xmlns%3D%27http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%27%20viewBox%3D%270%200%2020%2020%27%3E%3Cpath%20d%3D%27M14.83%204.89l1.34.94-5.81%208.38H9.02L5.78%209.67l1.34-1.25%202.57%202.4z%27%20fill%3D%27%23ffffff%27%2F%3E%3C%2Fsvg%3E");
					height: 1rem;
					width: 1rem;
					margin: -1px -2px;
				}

				/* ---- Save button: pill, Bold Plum ------------------------------ */
				/* Scoped to the submit row only. WordPress injects third-party admin
				   notices into #mainform, so a broader #mainform .button-primary would
				   brand their buttons (e.g. the .notice call-to-action buttons) too.
				   The Save button lives in the p.submit row. */
				#mainform p.submit .button-primary,
				#mainform p.submit .woocommerce-save-button {
					background: var(--sezzle-bold-plum) !important;
					border-color: var(--sezzle-bold-plum) !important;
					border-radius: 999px !important;
					box-shadow: none !important;
					color: #fff !important;
					padding: 12px 24px !important;
					height: auto !important;
					font-weight: 600;
					text-shadow: none !important;
				}
				#mainform p.submit .button-primary:not(:disabled):hover,
				#mainform p.submit .woocommerce-save-button:not(:disabled):hover {
					background: #6f27bd !important;
					border-color: #6f27bd !important;
				}
				/* Disabled Save button: WooCommerce keeps the button disabled until a
				   setting is changed, so a fresh (unmodified) form cannot be submitted.
				   Mute the plum branding so the button reads as inactive rather than a
				   live call-to-action. */
				#mainform p.submit .button-primary:disabled,
				#mainform p.submit .woocommerce-save-button:disabled {
					background: #e4e0ec !important;
					border-color: #e4e0ec !important;
					color: #a29bb0 !important;
					cursor: not-allowed !important;
				}

				/* WooCommerce sets #wpfooter to position:absolute on its admin pages,
				   which overlaps the Sezzle settings content. Hide it on this page. */
				#wpfooter { display: none !important; }

				/* Fields with a two-line title and a help tip: WooCommerce absolutely
				   positions the tip at the vertical centre of the (two-line) title, but
				   the control (checkbox or text input) sits on the first line, so the tip
				   ends up ~half a line below the control. vertical-align cannot fix this
				   because the control fieldset already fills the cell height, so nudge the
				   fieldset down by half a line to centre the control on the title, level
				   with the tip. Scoped to the affected rows via :has(). */
				.form-table tr:has(#woocommerce_sezzlepay_enable-order-creation-post-checkout) td.forminp fieldset,
				.form-table tr:has(#woocommerce_sezzlepay_order-total-container-selector) td.forminp fieldset {
					margin-top: 0.7em;
				}

				/* ---- Mobile ---------------------------------------------------- */
				@media (max-width: 782px) {
					.sezzle-section-title,
					.sezzle-subsection-title,
					#mainform table.form-table { padding-left: 16px; padding-right: 16px; }
					#mainform table.form-table th { padding-right: 0; }
				}
			</style>';
		}
		add_action( 'admin_head', 'sezzle_admin_settings_styles' );

		add_action( 'woocommerce_after_checkout_form', 'add_installment_widget_script' );
        add_action( 'woocommerce_after_checkout_form', 'alter_checkout_url' );
		add_action( 'wp_enqueue_scripts', 'frontend_enqueue_scripts' );
		add_action( 'wp_enqueue_scripts', 'enqueue_express_checkout_scripts' );

		// The express checkout buttons are injected by express-checkout.js inside the
		// merchant-configured container, and their styles ride along with the script
		// enqueue above, so there are no classic-only render hooks here.

		// Add product to cart for product-page express checkout
		add_action('wp_ajax_sezzle_express_pdp_add_to_cart', 'sezzle_express_pdp_add_to_cart');
		add_action('wp_ajax_nopriv_sezzle_express_pdp_add_to_cart', 'sezzle_express_pdp_add_to_cart');

		add_action('wp_ajax_sezzle_express_pdp_restore_cart', 'sezzle_express_pdp_restore_cart');
		add_action('wp_ajax_nopriv_sezzle_express_pdp_restore_cart', 'sezzle_express_pdp_restore_cart');

		add_action('wp_ajax_sezzle_express_refresh_nonce', 'sezzle_express_refresh_nonce');
		add_action('wp_ajax_nopriv_sezzle_express_refresh_nonce', 'sezzle_express_refresh_nonce');

		add_action('wp_ajax_sezzle_express_get_cart_state', 'sezzle_express_get_cart_state');
		add_action('wp_ajax_nopriv_sezzle_express_get_cart_state', 'sezzle_express_get_cart_state');
		// Add express checkout start action
		add_action('wp_ajax_sezzle_express_checkout_start', 'start_express_checkout');
		add_action('wp_ajax_nopriv_sezzle_express_checkout_start', 'start_express_checkout');
		// actions to calculate shipping,tax costs for express checkout
		add_action('wp_ajax_sezzle_calculate_address_costs', 'calculate_address_related_costs');
		add_action('wp_ajax_nopriv_sezzle_calculate_address_costs', 'calculate_address_related_costs');
		add_action('wp_ajax_sezzle_send_widget_server_log', 'sezzle_send_widget_server_log');
		add_action('wp_ajax_nopriv_sezzle_send_widget_server_log', 'sezzle_send_widget_server_log');
		// action to complete express checkout
		add_action('wp_ajax_sezzle_express_checkout_complete', 'sezzle_express_checkout_complete');
		add_action('wp_ajax_nopriv_sezzle_express_checkout_complete', 'sezzle_express_checkout_complete');
		// action to re-verify keys when an express checkout is cancelled (SDK hides 401s client-side)
		add_action('wp_ajax_sezzle_express_checkout_verify_auth', 'sezzle_express_checkout_verify_auth');
		add_action('wp_ajax_nopriv_sezzle_express_checkout_verify_auth', 'sezzle_express_checkout_verify_auth');
	}
}

// Activation hook - called when plugin is activated
register_activation_hook( __FILE__, 'sezzle_activated' );
function sezzle_activated( $network_wide ) {
	global $wpdb;

	if ( ! $network_wide ) {
		sezzle_activate_single_site();
		return;
	}

	// Retrieve all site IDs from this network (WordPress >= 4.6 provides easy to use functions for that).
	if ( function_exists( 'get_sites' ) && function_exists( 'get_current_network_id' ) ) {
		$site_ids = get_sites(
			array(
				'fields'     => 'ids',
				'network_id' => get_current_network_id(),
			)
		);
	} else {
		$site_ids = $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs WHERE site_id = $wpdb->siteid;" );
	}

	// Install the plugin for all these sites.
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		sezzle_activate_single_site();
		restore_current_blog();
	}
}

// Deactivation hook - called when plugin is deactivated
register_deactivation_hook( __FILE__, 'sezzle_deactivated' );
function sezzle_deactivated( $network_wide ) {
	global $wpdb;

	if ( ! $network_wide ) {
		sezzle_deactivate_single_site();
		return;
	}

	// Retrieve all site IDs from this network (WordPress >= 4.6 provides easy to use functions for that).
	if ( function_exists( 'get_sites' ) && function_exists( 'get_current_network_id' ) ) {
		$site_ids = get_sites(
			array(
				'fields'     => 'ids',
				'network_id' => get_current_network_id(),
			)
		);
	} else {
		$site_ids = $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs WHERE site_id = $wpdb->siteid;" );
	}
	// Install the plugin for all these sites.
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		sezzle_deactivate_single_site();
		restore_current_blog();
	}
}

function sezzle_activate_single_site() {
	// The Analytical Data Sync feature and its daily cron were removed; clear any
	// event a previous version left scheduled so it doesn't linger.
	sezzle_clear_legacy_data_sync_cron();
}

function sezzle_deactivate_single_site() {
	sezzle_clear_legacy_data_sync_cron();
}

/**
 * Unschedule the daily analytical-data-sync cron left behind by versions that
 * shipped the removed feature. Runs on plugins_loaded so in-place upgrades (the
 * common update path, which never fires the activation hook) also clean it up.
 */
function sezzle_clear_legacy_data_sync_cron() {
	if ( wp_next_scheduled( 'sezzle_daily_data_send_event_cron' ) ) {
		wp_clear_scheduled_hook( 'sezzle_daily_data_send_event_cron' );
	}
}
add_action( 'plugins_loaded', 'sezzle_clear_legacy_data_sync_cron' );

function sezzle_on_activate_blog_from_wp_site( $blog ) {
	if ( is_object( $blog ) && isset( $blog->blog_id ) ) {
		sezzle_on_activate_blog( (int) $blog->blog_id );
	}
}

function sezzle_on_activate_blog( $blog_id ) {
	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( is_plugin_active_for_network( 'sezzle-woocommerce-payment/woocommerce-gateway-sezzle.php' ) ) {
		switch_to_blog( $blog_id );
		sezzle_activate_single_site();
		restore_current_blog();
	}
}

// Wpmu_new_blog has been deprecated in 5.1 and replaced by wp_insert_site.
global $wp_version;
if ( version_compare( $wp_version, '5.1', '<' ) ) {
	add_action( 'wpmu_new_blog', 'sezzle_on_activate_blog' );
} else {
	add_action( 'wp_initialize_site', 'sezzle_on_activate_blog_from_wp_site', 99 );
}

add_action( 'activate_blog', 'sezzle_on_activate_blog' );
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
});
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});
add_action( 'woocommerce_blocks_loaded', function() {
    if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        return;
    }

    require_once plugin_dir_path(__FILE__) . 'woocommerce-gateway-sezzle-blocks.php';
    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
            $payment_method_registry->register( new WC_Gateway_Sezzlepay_Blocks() );
        }
    );
});
