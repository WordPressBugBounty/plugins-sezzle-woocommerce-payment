<?php
/*
Plugin Name: Sezzle WooCommerce Payment
Description: Buy Now Pay Later with Sezzle
Version: 6.1.7
Author: Sezzle
Author URI: https://www.sezzle.com/
Tested up to: 6.7.3
Copyright: © 2025 Sezzle
WC requires at least: 7.8.0
WC tested up to: 10.1.2
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
				
			const TRANSACTION_MODE_LIVE    = 'live';
			const TRANSACTION_MODE_SANDBOX = 'sandbox';
			const EXPRESS_CHECKOUT_MODE_POPUP = 'popup';
			const EXPRESS_CHECKOUT_MODE_IFRAME = 'iframe';
			const EXPRESS_CHECKOUT_API_CALL_LOCK_KEY = 'sezzle_api_call_lock_';
			const EXPRESS_CHECKOUT_CACHE_KEY = 'sezzle_express_checkout_enabled_';
			const ERROR_CART_AMOUNT_MISMATCH = 'Cart amount has been updated';
			const EXPRESS_SDK_URL = "https://checkout-sdk.sezzle.com/express_checkout.min.js";

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
				$this->title               = $this->get_option( 'title' );
				$this->supported_countries = [ 'US', 'CA' ];

				add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
				add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'sezzle_payment_callback' ) );
				add_action( 'admin_footer', array( $this, 'add_iframe_warning_script' ) );
				add_action(
					'admin_notices',
					function () {
						$message = get_transient( 'sezzle_api_error' );
						if ( ! empty( $message ) ) {
							echo '<div class="notice error is-dismissible"> <p><strong>' . esc_html( $message ) . '</strong></p> </div>';
						}
					}
				);
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
			 * Check if a lock exists for the feature flag check
			 *
			 * @param string $lock_key Lock key to check
			 * @return bool True if locked, false otherwise
			 */
			private function is_feature_flag_locked($lock_key) {
				return get_transient($lock_key) !== false;
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

				// Check if another process is already fetching
				if ($this->is_feature_flag_locked($lock_key)) {
					// Wait for the other process to complete and cache the result
					// Admin: 2s (safe margin), Frontend: 1.2s (balanced with safety)
					$max_wait = is_admin() ? 2000 : 1200;
					$cached_result = $this->wait_for_cache($cache_key, $max_wait);
					if ($cached_result !== false) {
						return ($cached_result === 'enabled');
					}
					// If still no result after waiting, preserve current state
					// Return current option value to prevent resetting during cross-worker race condition
					$current_option = $this->get_option('enable-express-checkout');
					return ($current_option === 'yes');
				}

				// Acquire lock (30 second expiration as safety net)
				set_transient($lock_key, time(), 30);

				try {
					// Make API call
					$service_v2 = new Service_V2($transaction_mode, $this->get_keys());
					$api_result = $service_v2->is_express_checkout_enabled();

					// Store as string to differentiate false from empty cache
					$cache_value = $api_result ? 'enabled' : 'disabled';
					$ttl = $this->get_cache_ttl();
					set_transient($cache_key, $cache_value, $ttl);


					return $api_result;
			} catch (Exception $e) {
				// Differentiate caching based on error type:
				// - 401 (Unauthorized): 1 hour cache (likely offboarded, keys expired)
				// - Other errors: 15 minutes cache (network issues, brief API outages)
				$is_auth_error = ($e->getCode() === 401);
				$ttl = $this->get_cache_ttl($is_auth_error); // Long cache for auth errors, short for others
				set_transient($cache_key, 'disabled', $ttl);
				return false;

				} finally {
					// Always release lock
					delete_transient($lock_key);
				}
			}

			/**
			 * Add iframe warning script to admin page
			 */
			public function add_iframe_warning_script() {
				if (isset($_GET['section']) && $_GET['section'] === 'sezzlepay') {
					?>
					<script type="text/javascript">
					jQuery(document).ready(function($) {
						var modeSelect = $('#woocommerce_sezzlepay_express-checkout-mode');
						if (modeSelect.length) {
							function showIframeWarning() {
								if (modeSelect.val() === 'iframe') {
									if (!$('#iframe-warning').length) {
										modeSelect.closest('td').append(
											'<div id="iframe-warning" style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 8px 12px; margin-top: 5px; border-radius: 4px; color: #856404; font-size: 13px; display: inline-block;">⚠️ IFrame mode may cause Content Security Policy (CSP) issues. Please reach out to Sezzle support before using it to make sure your domain is allowlisted.</div>'
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

				$is_express_checkout_page = is_cart() || is_checkout();
				$should_check_feature_flag = $is_sezzle_settings_page || $is_express_checkout_page;

				$express_checkout_enabled = false;
				$skipped_check = false;

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
					} else {
						// Check is in progress and no result yet - don't update option
						$skipped_check = true;
					}
				} else {
					// Not on relevant pages - skip feature flag check
					// This eliminates API calls on home, product, blog, and other admin pages
					$skipped_check = true;
				}

                $this->form_fields = array(
					'enabled'                          => array(
						'title'   => __( 'Enable/Disable', 'woo_sezzlepay' ),
						'type'    => 'checkbox',
						'label'   => __( 'Enable Sezzle', 'woo_sezzlepay' ),
						'default' => 'no',
					),
					'payment-option-availability'      => array(
						'title'       => __( 'Payment option availability in other countries', 'woo_sezzlepay' ),
						'type'        => 'checkbox',
						'label'       => __( 'Enable', 'woo_sezzlepay' ),
						'description' => __(
							'Enable Sezzle gateway in countries other than the US and Canada.',
							'woo_sezzlepay'
						),
						'default'     => 'yes',
					),
					'title'                            => array(
						'title'       => __( 'Title', 'woo_sezzlepay' ),
						'type'        => 'text',
						'description' => __(
							'This controls the payment method title which the user sees during checkout.',
							'woo_sezzlepay'
						),
						'default'     => __( 'Sezzle', 'woo_sezzlepay' ),
					),
					'merchant-id'                      => array(
						'title'       => __( 'Merchant ID', 'woo_sezzlepay' ),
						'type'        => 'text',
						'description' => __(
							'Look for your Sezzle merchant ID in your Sezzle Dashboard.',
							'woo_sezzlepay'
						),
						'default'     => '',
					),
                    'public-key'                       => array(
                        'title'   => __( 'Public Key', 'woo_sezzlepay' ),
                        'type'    => 'text',
                        'default' => '',
                    ),
					'private-key'                      => array(
						'title'   => __( 'Private Key', 'woo_sezzlepay' ),
						'type'    => 'text',
						'default' => '',
					),
                    'enable-order-creation-post-checkout'        => array(
                        'title'   => __( 'Create order post checkout completion', 'woo_sezzlepay' ),
                        'type'    => 'checkbox',
                        'label'   => __( 'Enable/Disable', 'woo_sezzlepay' ),
                        'default' => 'no',
                    ),
                    'min-checkout-amount'              => array(
						'title'   => __( 'Minimum Checkout Amount', 'woo_sezzlepay' ),
						'type'    => 'number',
						'default' => '',
                    ),
					'transaction-mode'                 => array(
						'title'    => __( 'Transaction Mode', 'woo_sezzlepay' ),
						'type'     => 'select',
						'default'  => 'live',
						'desc_tip' => true,
						'options'  => array(
							self::TRANSACTION_MODE_SANDBOX => __( 'Sandbox', 'woocommerce' ),
							self::TRANSACTION_MODE_LIVE    => __( 'Live', 'woocommerce' ),
						),
					),
					'show-product-page-widget'         => array(
						'title'   => __( 'Show Sezzle widget in product pages', 'woo_sezzlepay' ),
						'type'    => 'checkbox',
						'label'   => __( 'Show the sezzle widget under price label in product pages', 'woo_sezzlepay' ),
						'default' => 'yes',
					),
					'enable-installment-widget'        => array(
						'title'   => __( 'Installment Plan Widget Configuration', 'woo_sezzlepay' ),
						'type'    => 'checkbox',
						'label'   => __(
							'Enable Installment Widget Plan in Checkout page',
							'woo_sezzlepay'
						),
						'default' => 'yes',
					),
					'order-total-container-class-name' => array(
						'type'        => 'text',
						'description' => __(
							'Order Total Container Class Name(e.g. ' . $this->get_order_total_container_class_desc() . ')',
							'woo_sezzlepay'
						),
						'default'     => 'woocommerce-Price-amount',
					),
					'order-total-container-parent-class-name' => array(
						'type'        => 'text',
						'description' => __(
							'Order Total Container Parent Class Name(e.g. ' . $this->get_order_total_container_parent_class_desc() . ')',
							'woo_sezzlepay'
						),
						'default'     => 'order-total',
					),
					'sync-all-orders'                  => array(
						'title'       => __( 'Analytical Data Sync', 'woo_sezzlepay' ),
						'type'        => 'checkbox',
						'label'       => __( 'Sync the last 24 hours\' orders', 'woo_sezzlepay' ),
						'description' => __( 'Used for internal analytics only. Data is not shared externally. Disabling this option will not affect payment processing.', 'woo_sezzlepay' ),
						'default'     => 'yes',
					),
					'logging'                          => array(
						'title'   => __( 'Enable Logging', 'woo_sezzlepay' ),
						'type'    => 'checkbox',
						'label'   => __( 'Enable Logging', 'woo_sezzlepay' ),
						'default' => 'yes',
					),
				);

				// Only add express checkout options if express checkout is enabled for the merchant
				if ($express_checkout_enabled) {
					// Insert express checkout fields after enable-order-creation-post-checkout
					$post_checkout_key = 'enable-order-creation-post-checkout';
					$form_fields_array = $this->form_fields;
					$new_form_fields = array();
					$express_fields_added = false;
					
					foreach ($form_fields_array as $key => $field) {
						$new_form_fields[$key] = $field;
						
						// Add express checkout fields right after enable-order-creation-post-checkout
						if ($key === $post_checkout_key && !$express_fields_added) {
							$new_form_fields['enable-express-checkout'] = array(
								'title' => __('Express Checkout', 'woo_sezzlepay'),
								'type' => 'checkbox',
								'label' => __('Enable Express Checkout in Cart Page', 'woo_sezzlepay'),
								'default' => 'no',
							);
							$new_form_fields['express-checkout-mode'] = array(
								'title' => __('Express Checkout Mode', 'woo_sezzlepay'),
								'type' => 'select',
								'default' => 'popup',
								'desc_tip' => true,
								'options' => array(
									self::EXPRESS_CHECKOUT_MODE_POPUP => __('Pop Up', 'woocommerce'),
									self::EXPRESS_CHECKOUT_MODE_IFRAME => __('IFrame', 'woocommerce'),
								),
							);
							$express_fields_added = true;
						}
					}
					
					$this->form_fields = $new_form_fields;
				} else if (!$skipped_check) {
					// Only clear options if we actually performed a check and it returned false
					// Don't update if we skipped the check (another instance is still checking)
					if ($this->get_option('enable-express-checkout') !== null) {
						$this->update_option('enable-express-checkout', 'no');
					}
				} else {
					// Skipped check - preserve existing option value
				}

                if (class_exists('Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils') && CartCheckoutUtils::is_checkout_block_default()) {
                    unset($this->form_fields['enable-order-creation-post-checkout']);
                }
			}

			/**
			 * Process Sezzle Settings
			 *
			 * @return bool|void
			 */
			public function process_admin_options() {
                if ( ! $this->validate_keys() ) {
                    WC_Admin_Settings::add_error('Unable to validate keys.');
					return;
				}

				$this->send_admin_configuration();
				return parent::process_admin_options();
			}

			/**
			 * Validate keys
			 *
			 * @return bool
			 */
			private function validate_keys() {
				// stored data
				$stored_public_key       = $this->get_option( 'public-key' );
				$stored_private_key      = $this->get_option( 'private-key' );
				$stored_transaction_mode = $this->get_option( 'transaction-mode' );

				// input data
				$form_fields      = $this->get_form_fields();
				$public_key       = $this->get_field_value(
					'public-key',
					$form_fields['public-key'],
					$this->get_post_data()
				);
				$private_key      = $this->get_field_value(
					'private-key',
					$form_fields['private-key'],
					$this->get_post_data()
				);
				$transaction_mode = $this->get_field_value(
					'transaction-mode',
					$form_fields['transaction-mode'],
					$this->get_post_data()
				);

				// return true if the keys match
				if ( $stored_public_key == $public_key
					&& $stored_private_key == $private_key
					&& $stored_transaction_mode == $transaction_mode
				) {
					return true;
				}
				$request = [
					'public_key'  => $public_key,
					'private_key' => $private_key
				];

                try {
                    $service_v2 = new Service_V2($transaction_mode);
                    $response = $service_v2->authenticate($request);
                    return isset($response->token);
                } catch (Exception $e) {
                    $this->log('Unable to validate keys.');
                    $this->log($e->getMessage());
                    return false;
                }
			}

			public function get_keys() {
				return [
					'public_key'  => $this->get_option( 'public-key' ),
					'private_key' => $this->get_option( 'private-key' )
				];
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
					$this->log( json_encode( $request ) );
				}
				$this->log( 'Response Body' );
				$this->log( $response );
				$this->log( $status_code );
			}

			private function get_order_total_container_class_desc() {
				return htmlspecialchars( '<span class="woocommerce-Price-amount amount"></span>', ENT_QUOTES );
			}

			private function get_order_total_container_parent_class_desc() {
				return htmlspecialchars( '<tr class="order-total"></tr>', ENT_QUOTES );
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
					$order = $this->get_order( $order_id );

					$checkout_data = $this->format_checkout_data( $order );
					$session  = $this->redirect_to_checkout( $checkout_data );
					$redirect_url = $session['redirect_url'];

					$this->update_or_create_meta_data($order, 'sezzle_redirect_url', $redirect_url);
					$this->update_or_create_meta_data($order, 'sezzle_order_uuid', $session['order_uuid']);
					$order->save();

					$result = 'success';
					$redirect = $redirect_url;
				} catch (Exception $e) {
					$result = 'failure';

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
					$order_reference_id = $order_reference_id . '-' . $order->get_id();
					$order->set_transaction_id( $order_reference_id );
					$order->save();
					$complete_url_arg = [ 'key' => $order->get_order_key() ];
					$total            = $order->get_total();
					$order_items      = $order->get_items();
				} else {
					$total = WC()->cart->get_totals()['total'];
					$complete_url_arg = [ 'order_reference_id' => $order_reference_id ];
					$order_items      = WC()->cart->get_cart_contents();
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
							'currency'        => get_woocommerce_currency(),
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
							'currency'              => get_woocommerce_currency(),
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
							'street'       => $order_exist ? $order->get_shipping_address_1() : $post_data['shipping_address_1'],
							'street2'      => $order_exist ? $order->get_shipping_address_2() : $post_data['shipping_address_2'],
							'city'         => $order_exist ? $order->get_shipping_city() : $post_data['shipping_city'],
							'state'        => $order_exist ? $order->get_shipping_state() : $post_data['shipping_state'],
							'postal_code'  => $order_exist ? $order->get_shipping_postcode() : $post_data['shipping_postcode'],
							'country_code' => $order_exist ? $order->get_shipping_country() : $post_data['shipping_country'],
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

				wc_add_notice( __( 'Sorry, there was a problem preparing your payment.', 'woo_sezzlepay' ), 'error' );
				return [
					'redirect_url' => wc_get_checkout_url(),
					'order_uuid'   => null
				];
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
                try {
                    $_REQUEST = stripslashes_deep($_REQUEST);
                    $order_key = isset($_REQUEST['key']) ? sanitize_text_field($_REQUEST['key']) : '';

                    if ($order_key) {
                        $order_id = wc_get_order_id_by_order_key($order_key);
                        $order = $this->get_order($order_id);
                        $order_reference_id = $order->get_transaction_id();
                    } else {
                        $order_reference_id = isset($_REQUEST['order_reference_id']) ? sanitize_text_field($_REQUEST['order_reference_id']) : '';
                        if ($order_reference_id === '') {
                            throw new Exception(__('Order reference ID not matching', 'woo_sezzlepay'));
                        }

                        $order = $this->get_order_by_txn_id($order_reference_id) ?: $this->create_sezzle_order();
                    }
					$sezzle_order_uuid = $order->get_meta('sezzle_order_uuid', true);
					if( !$sezzle_order_uuid ) {
						$sezzle_order_uuid = WC()->session->get('sezzle_order_uuid');
						$order->add_meta_data('sezzle_order_uuid', $sezzle_order_uuid);
						$order->save();
					}
					$sezzle_order = $this->get_sezzle_order_details($sezzle_order_uuid);
                    $this->process_order_payment($order, $order_reference_id, $sezzle_order, $order_key);
                } catch (Exception $e) {
                    $this->handle_payment_exception($e, $sezzle_order_uuid, $order);
                }
            }

            /**
             * Get order by transaction ID (Order Reference ID)
             *
             * @param string $txn_id Order Reference ID
             * @return false|mixed|WC_Order
             */
            private function get_order_by_txn_id($txn_id)
            {
                $orders = wc_get_orders(['transaction_id' => $txn_id]);
                if (count($orders) == 0) {
                    return false;
                }

                $this->log(sprintf('Order count for order reference ID: %s is %d', $txn_id, count($orders)));

                // ideally there should not be more one order for one order reference ID
                // but, if in case, always take the latest
                return $orders[count($orders) - 1];
            }

            /**
             * Create Sezzle order
             *
             * @return bool|WC_Order|WC_Order_Refund
             */
            private function create_sezzle_order()
            {
                $posted_data = WC()->session->get('posted_data');
                $sezzle_checkout = Sezzle_Checkout::instance();
                $sezzle_checkout->process_customer($posted_data);

                sezzle_restore_missing_cart_fees();

                $order_id = WC()->checkout()->create_order($posted_data);
                sezzle_cleanup_stored_cart_fees();
                $order = $this->get_order($order_id);

                switch (true) {
                    case is_wp_error($order_id):
                        throw new Exception($order_id->get_error_message());
                    case !$order:
                        throw new Exception(__('Unable to create order.', 'woo_sezzlepay'));
                }

                do_action('woocommerce_checkout_order_processed', $order_id, $posted_data, $order);

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
                if ($sezzle_order?->authorization?->captures) {
                    return false;
                }

                $woo_order_amount_in_cents = Sezzle_Utils::formatToCents($order->get_total());

                if ($woo_order_amount_in_cents !== $sezzle_order->order_amount->amount_in_cents) {
                    $msg = sprintf('Unable to complete payment. %s to %s.', self::ERROR_CART_AMOUNT_MISMATCH, $order->get_formatted_order_total());
                    $this->log($msg);
                    throw new Exception(__($msg, 'woo_sezzlepay'));
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

                wp_redirect(wc_get_checkout_url());
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
                $order->payment_complete($order_reference_id);
                WC()->cart->empty_cart();
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
                $txn_mode = $this->get_option('transaction-mode');
                $service_v2 = new Service_V2($txn_mode, $this->get_keys());
				$total = $order->get_total();
				$request = [
					'capture_amount' => [
						'amount_in_cents' => Sezzle_Utils::formatToCents($total),
						'currency'        => $order->get_currency(),
					]
				];
                $response = $service_v2->capture($sezzle_order_uuid, $request);

                if (is_object($response) && isset($response->uuid)) {
                    $this->handle_successful_capture($order, $order_reference_id, $order_key);
                } else {
                    $this->handle_failed_capture($order, $response);
                }
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
                $order->add_order_note(__('Payment approved by Sezzle successfully.', 'woo_sezzlepay'));
                $order->payment_complete($order_reference_id);
                WC()->cart->empty_cart();

                if (!$order_key) {
                    $order->add_meta_data('order_reference_id', $order_reference_id);
                    $order->set_transaction_id($order_reference_id);
                    $order->save();
                    apply_filters('woocommerce_payment_successful_result', '', $order->get_id());
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
                $order_failed = true;

                if (is_null($response) || !isset($response->code)) {
                    $order->add_order_note(
                        __('The payment failed because of an unknown error. Please contact Sezzle from the Sezzle merchant dashboard.', 'woo_sezzlepay')
                    );
                } elseif (strtolower($response->code) === 'checkout_expired') {
                    $order->add_order_note(__(ucfirst("$response->code : $response->message"), 'woo_sezzlepay'));
                } elseif (strtolower($response->code) === 'checkout_captured') {
                    $order_failed = false;
                }

                if ($order_failed) {
                    $order->update_status('failed');
                }

                if(wp_doing_ajax()) {
					return;
				}

                wp_redirect(wc_get_checkout_url());
                exit;
            }

            /**
             * Handles exceptions during payment processing
             *
             * @param Exception $exception
             * @param WC_Order|null $order
             * @return void
             */
            private function handle_payment_exception($exception, $sezzle_order_uuid = null, $order = null) {
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

                $service_v2->send_logs($merchant_uuid, json_encode($this->get_logs()), $sezzle_order_uuid, $this->get_order_json($order));

                wc_add_notice($message, 'error');
                wp_redirect(wc_get_checkout_url());
                exit;
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
				$order_reference_id = $order->get_transaction_id();
				$sezzle_order_uuid = $order->get_meta('sezzle_order_uuid', true);
				$request = [
					'amount_in_cents' => Sezzle_Utils::formatToCents($amount),
					'currency'        => $order->get_currency(),
				];
				$txn_mode   = $this->get_option('transaction-mode');
				$service_v2 = new Service_V2($txn_mode, $this->get_keys());
				$response = $service_v2->refund($sezzle_order_uuid, $request);

				if ( is_object($response) && $response->uuid ) {
					$order->add_order_note(
						sprintf(
							/* translators: %s: $amount */
							__( 'Refund of %s successfully sent to Sezzle.', 'woo_sezzlepay' ),
							$amount
						)
					);
					return true;
				}

                $order->add_order_note(
                    __(
                        'There was an error submitting the refund to Sezzle.',
                        'woo_sezzlepay'
                    )
                );
				return false;
			}

			private function get_last_day_orders() {
				$yesterday = gmdate( 'Y-m-d H:i:s', strtotime( '-24 hours' ) );

				return wc_get_orders(
					[
						'type'       => 'shop_order',
						'status'     => array( 'processing', 'completed' ),
						'limit'      => -1,
						'date_after' => "$yesterday"
					]
				);
			}

			private function get_order_details_from_order( $order ) {
				return [
					'order_number'     => $order->get_order_number(),
					'payment_method'   => $order->get_payment_method(),
					'amount'           => Sezzle_Utils::formatToCents($order->calculate_totals()),
					'currency'         => $order->get_currency(),
					'sezzle_reference' => $order->get_transaction_id(),
					'customer_email'   => $order->get_billing_email(),
					'customer_phone'   => $order->get_billing_phone(),
					'billing_address1' => $order->get_billing_address_1(),
					'billing_address2' => $order->get_billing_address_2(),
					'billing_city'     => $order->get_billing_city(),
					'billing_state'    => $order->get_billing_state(),
					'billing_postcode' => $order->get_billing_postcode(),
					'billing_country'  => $order->get_billing_country(),
					'merchant_id'      => $this->get_option( 'merchant-id' )
				];
			}

			private function get_order_details_from_orders( $orders ) {
				$orders_details = [];
				foreach ( $orders as $order ) {
					$order_details    = $this->get_order_details_from_order( $order );
					$orders_details[] = $order_details;
				}
				return $orders_details;
			}

			public function send_merchant_last_day_orders() {
				$orders             = $this->get_last_day_orders();
				$request = $this->get_order_details_from_orders( $orders );

                if ( count($request) == 0 ) {
                    return;
                }

				$txn_mode = $this->get_option( 'transaction-mode' );
				$service_v2 = new Service_V2($txn_mode, $this->get_keys());
				$response = $service_v2->send_merchant_orders( $request );

				if ( empty((array)$response) ) {
					$this->log( "Orders sent to Sezzle" );
				} else {
					$this->log( "Could not send orders to Sezzle. Error Response : $response" );
				}
			}

			private function get_admin_configuration() {
				$form_fields                = $this->get_form_fields();
				$sezzle_enabled             = ( $this->get_field_value(
					'enabled',
					$form_fields['enabled'],
					$this->get_post_data()
				) == 'yes' );
				$merchant_uuid              = $this->get_field_value(
					'merchant-id',
					$form_fields['merchant-id'],
					$this->get_post_data()
				);
				$pdp_widget_enabled         = ( $this->get_field_value(
					'show-product-page-widget',
					$form_fields['show-product-page-widget'],
					$this->get_post_data()
				) == 'yes' );
				$installment_widget_enabled = ( $this->get_field_value(
					'enable-installment-widget',
					$form_fields['enable-installment-widget'],
					$this->get_post_data()
				) == 'yes' );

                $response = [
                    'sezzle_enabled' => $sezzle_enabled,
                    'merchant_uuid' => $merchant_uuid,
                    'pdp_widget_enabled' => $pdp_widget_enabled,
                    'installment_widget_enabled' => $installment_widget_enabled,
                ];

                if (isset($form_fields['enable-order-creation-post-checkout'])) {
                    $order_post_checkout_enabled             = ( $this->get_field_value(
                            'enable-order-creation-post-checkout',
                            $form_fields['enable-order-creation-post-checkout'],
                            $this->get_post_data()
                        ) == 'yes' );
                    $response['order_post_checkout_enabled'] = $order_post_checkout_enabled;
                }

                // Payment option availability
                if (isset($form_fields['payment-option-availability'])) {
                    $payment_option_availability = ( $this->get_field_value(
                        'payment-option-availability',
                        $form_fields['payment-option-availability'],
                        $this->get_post_data()
                    ) == 'yes' );
                    $response['payment_option_availability'] = $payment_option_availability;
                }

                // Title
                if (isset($form_fields['title'])) {
                    $title = $this->get_field_value(
                        'title',
                        $form_fields['title'],
                        $this->get_post_data()
                    );
                    $response['title'] = $title;
                }

                // Minimum checkout amount
                if (isset($form_fields['min-checkout-amount'])) {
                    $min_checkout_amount = $this->get_field_value(
                        'min-checkout-amount',
                        $form_fields['min-checkout-amount'],
                        $this->get_post_data()
                    );
                    $response['min_checkout_amount'] = !empty($min_checkout_amount) ? intval($min_checkout_amount) : 0;
                }

                // Transaction mode
                if (isset($form_fields['transaction-mode'])) {
                    $transaction_mode = $this->get_field_value(
                        'transaction-mode',
                        $form_fields['transaction-mode'],
                        $this->get_post_data()
                    );
                    $response['transaction_mode'] = $transaction_mode;
                }

                // Order total container class name
                if (isset($form_fields['order-total-container-class-name'])) {
                    $order_total_container_class_name = $this->get_field_value(
                        'order-total-container-class-name',
                        $form_fields['order-total-container-class-name'],
                        $this->get_post_data()
                    );
                    $response['order_total_container_class_name'] = $order_total_container_class_name;
                }

                // Order total container parent class name
                if (isset($form_fields['order-total-container-parent-class-name'])) {
                    $order_total_container_parent_class_name = $this->get_field_value(
                        'order-total-container-parent-class-name',
                        $form_fields['order-total-container-parent-class-name'],
                        $this->get_post_data()
                    );
                    $response['order_total_container_parent_class_name'] = $order_total_container_parent_class_name;
                }

                // Sync all orders
                if (isset($form_fields['sync-all-orders'])) {
                    $sync_all_orders = ( $this->get_field_value(
                        'sync-all-orders',
                        $form_fields['sync-all-orders'],
                        $this->get_post_data()
                    ) == 'yes' );
                    $response['sync_all_orders'] = $sync_all_orders;
                }

                // Logging
                if (isset($form_fields['logging'])) {
                    $logging = ( $this->get_field_value(
                        'logging',
                        $form_fields['logging'],
                        $this->get_post_data()
                    ) == 'yes' );
                    $response['logging'] = $logging;
                }

                // Express checkout (if feature flag is enabled)
                if (isset($form_fields['enable-express-checkout'])) {
                    $enable_express_checkout = ( $this->get_field_value(
                        'enable-express-checkout',
                        $form_fields['enable-express-checkout'],
                        $this->get_post_data()
                    ) == 'yes' );
                    $response['enable_express_checkout'] = $enable_express_checkout;
                }

                // Express checkout mode (if feature flag is enabled)
                if (isset($form_fields['express-checkout-mode'])) {
                    $express_checkout_mode = $this->get_field_value(
                        'express-checkout-mode',
                        $form_fields['express-checkout-mode'],
                        $this->get_post_data()
                    );
                    $response['express_checkout_mode'] = $express_checkout_mode;
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

        function remove_sezzlepay_gateway_based_on_billing_country($available_gateways)
        {
            if (is_admin()) {
                return $available_gateways;
            }

            $gateway = WC_Gateway_Sezzlepay::instance();
            $enable_sezzlepay_outside_usa = $gateway->get_option('payment-option-availability') == 'yes';
            if (!$enable_sezzlepay_outside_usa && WC()->customer) {
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
            $cart_total = WC()->cart->total;
            $gateway = WC_Gateway_Sezzlepay::instance();
            $min_checkout_amount = $gateway->get_option('min-checkout-amount');
            if ($cart_total && $min_checkout_amount && ($cart_total < $min_checkout_amount)) {
                unset($available_gateways[$gateway->id]);
            }
            return $available_gateways;
        }

        function allow_create_order_post_checkout()
        {
            $gateway = WC_Gateway_Sezzlepay::instance();
            return $gateway->get_option('enabled') === 'yes'
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
                'woocommerce_checkout_place_order' => json_encode($_POST['woocommerce_checkout_place_order']),
                'woocommerce_checkout_update_totals' => json_encode($_POST['woocommerce_checkout_update_totals']),
            ]));

            $sezzle_checkout = Sezzle_Checkout::instance();
            $sezzle_checkout->process_checkout();
        }

        add_filter('woocommerce_payment_gateways', 'add_sezzlepay_gateway');
        add_filter('woocommerce_available_payment_gateways', 'remove_sezzlepay_gateway_based_on_checkout_total');
        add_filter('woocommerce_available_payment_gateways', 'remove_sezzlepay_gateway_based_on_billing_country');
        add_action('woocommerce_single_product_summary', 'add_sezzle_product_banner');

        add_action('wp_ajax_sezzle_checkout', 'sezzle_checkout');
        add_action('wp_ajax_nopriv_sezzle_checkout', 'sezzle_checkout');
        add_action('wc_ajax_sezzle_checkout', 'sezzle_checkout');
        add_action('wp_loaded', 'sezzle_checkout_action');

		function add_sezzle_product_banner() {
			$gateway     = WC_Gateway_Sezzlepay::instance();
			$show_widget = $gateway->get_option( 'show-product-page-widget' );
			$merchant_id = $gateway->get_option( 'merchant-id' );
			if ( 'no' == $show_widget || ! $merchant_id ) {
				return;
			}

			$widget_url = sprintf( 'https://widget.sezzle.com/v1/javascript/price-widget?uuid=%s', $merchant_id );
			echo "<script type='text/javascript'>
				Sezzle = {}
				Sezzle.render = function () {
					document.sezzleConfig = {
						'configGroups': [{
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
							'targetXPath': '.order-total/TD-0/STRONG-0/.woocommerce-Price-amount/BDI-0',
							'renderToPath': '../../../../../..',
							'urlMatch': 'cart',
						}]
					}

					var script = document.createElement('script');
					script.type = 'text/javascript';
					script.src = '" . esc_html( $widget_url ) . "';
					document.head.appendChild(script);

				};
				Sezzle.render();
			</script>";

		}

		function sezzle_daily_data_send_event() {
			$gateway = WC_Gateway_Sezzlepay::instance();
			if ( $gateway->get_option( 'sync-all-orders' ) !== 'yes' ) {
				return;
			}

			$gateway->send_merchant_last_day_orders();
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

		function add_installment_widget_script() {
			$gateway = WC_Gateway_Sezzlepay::instance();
			if ( $gateway->get_option( 'enabled' ) == 'no'
				|| $gateway->get_option( 'enable-installment-widget' ) == 'no'
			) {
				return;
			}
			$order_total_container_class_name        = $gateway->get_option( 'order-total-container-class-name' );
			$order_total_container_parent_class_name = $gateway->get_option( 'order-total-container-parent-class-name' );
			if ( ! $order_total_container_class_name || ! $order_total_container_parent_class_name ) {
				return;
			}

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
                            'merchantLocale': 'US',
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
							array('installment_widget_js'),
							'v1.0.0',
							array('strategy' => 'defer', 'in_footer' => true)
						);
					} else {
						wp_enqueue_script(
							'installment_widget_renderer_js',
							plugin_dir_url(__FILE__) . 'build/js/frontend/installment-widget.js',
							array('installment_widget_js'),
							'v1.0.0',
							true // in_footer
						);
						wp_script_add_data('installment_widget_renderer_js', 'defer', true);
					}
                }
			}
		}

		/**
		 * Enqueue express checkout scripts early for dynamic content
		 *
		 * @return void
		 */
		function enqueue_express_checkout_scripts() {
			// Only check feature flag and enqueue scripts on cart/admin pages
			if (!is_cart() && !is_admin() && !is_checkout()) {
				return;
			}
			
			if (!are_express_checkout_options_enabled()) {
				return;
			}

			$gateway = WC_Gateway_Sezzlepay::instance();
			$public_key = $gateway->get_option('public-key');
			if (empty($public_key)) {
				return;
			}

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

				wp_localize_script('sezzle_express_checkout', 'sezzle_express_checkout',
					array(
						'mode' => $gateway->get_option("express-checkout-mode"),
						'public_key' => $public_key,
						'api_mode' => $gateway->get_option("transaction-mode"),
						'ajax_url' => admin_url('admin-ajax.php'),
						'szl_ec_start_nonce' => wp_create_nonce("szl_ec_start_nonce"),
						'szl_ec_complete_nonce' => wp_create_nonce("szl_ec_complete_nonce"),
						'error_cart_amount_mismatch' => WC_Gateway_Sezzlepay::ERROR_CART_AMOUNT_MISMATCH,
					)
				);
			}
		}

		/**
         * Add express checkout CSS styles
         */
        function add_express_checkout_styles() {
			static $styles_added = false;
			if (!$styles_added) {
				echo '<style>
				#sezzle-smart-button-container-cart,
				#sezzle-smart-button-container-cart iframe,
				#sezzle-smart-button-container-cart button {
					width: 100% !important;
					display: flex !important;
					align-items: center !important;
					justify-content: center !important;
					text-align: center !important;
				}
				#sezzle-smart-button-container-cart-sezzle-express-button .template-text {
					text-transform: capitalize;
				}
				.sezzle-smart-button-container-cart-sezzle-express-button-logo-img {
					vertical-align: baseline;
				}
				</style>';
				$styles_added = true;
			}
		}

        /**
		 * Validate if express checkout should be rendered
		 */
		function are_express_checkout_options_enabled() {
			$gateway = WC_Gateway_Sezzlepay::instance();

			// Check if main Sezzle gateway is enabled
			$enabled = $gateway->get_option('enabled') !== 'no';
			if (!$enabled) {
				return false;
			}

			// Check if merchant has express checkout setting enabled in database
			$express_enabled = $gateway->get_option('enable-express-checkout') !== 'no';
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
		 * Check if cart total is above minimum amount
		 */
		function is_cart_total_above_minimum_amount() {
			$gateway = WC_Gateway_Sezzlepay::instance();
			$cart_total = WC()->cart->get_cart_contents_total();
			$min_checkout_amount = $gateway->get_option('min-checkout-amount');
			// if min checkout amount is not set, return true
			if (!is_numeric($min_checkout_amount)) {
				return true;
			}
			return $cart_total >= $min_checkout_amount;
		}

		/**
		 * Setup express checkout button
		 */
		function express_button_setup() {
			add_express_checkout_styles();
		}

		/**
		 * Render express checkout button in Cart Page
		 */
		function render_cart_page_elements() {
			if (!are_express_checkout_options_enabled() || !is_cart_total_above_minimum_amount()) {
				return;
			}
			express_button_setup();
			echo '
			<tr>
				<td colspan="100%" style="text-align: center; padding: 10px;">
                    <div id="sezzle-smart-button-container-cart" 
                         checkoutSource="cart" 
                         borderType="semi-rounded" 
						 templateText="%%logo%% Express Checkout"
							style="font-size: medium; text-align: center; -webkit-font-smoothing: initial; width: 100%; min-height: 50px; display: flex; align-items: center; justify-content: center;">
					</div>
				</td>
			</tr>';
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
						"amount_in_cents" => (int)(round(($item['line_subtotal'] / $item['quantity']), 2) * 100),
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
						'amount_in_cents' => (int)(round(WC()->cart->get_coupon_discount_amount($coupon_code), 2) * 100),
						'currency' => get_option('woocommerce_currency')
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
			$gateway = WC_Gateway_Sezzlepay::instance();
			
			// Basic checkout data - use cart subtotal without shipping and tax
			$amount = WC()->cart->get_cart_contents_total(); // Cart subtotal after discounts, before shipping and tax
			$fee_total = WC()->cart->get_fee_total() + WC()->cart->get_fee_tax();
			$product_tax_total = WC()->cart->get_cart_contents_tax();
			$amount = $amount + $fee_total + $product_tax_total;
			$order_reference_id = $order ? $order->get_order_key() : WC()->cart->get_cart_hash();
			$order_description = 'WooCommerce Express Order. Cart #' . $order_reference_id;
			$display_order_reference_id = $order_reference_id;
			$currency = get_option('woocommerce_currency');
			$checkout_mode = $gateway->get_option('express-checkout-mode') . '.express';
			
			// Get cart items
			$items = WC()->cart->get_cart();
			$gateway_items = get_gateway_items_for_express($items, $currency);
			
			// Get applied discounts/coupons
			$gateway_discounts = get_gateway_discounts_for_express();
			
			// Prepare the checkout data for JavaScript
			$checkout_data = array(
				'amount_in_cents' => (int)(round($amount, 2) * 100),
				'currency' => $currency, 
				'cart_items' => $gateway_items, 
				'order_description' => (string)$order_description,
				'order_reference_id' => (string)$order_reference_id,
				'checkout_mode' => $checkout_mode,
				'merchant_completes' => true,
				'customer_details' => [], // Empty for express checkout
				'billing_address' => [],  // Empty for express checkout
				'shipping_address' => [], // Empty for express checkout
				'discounts' => $gateway_discounts,
				'source' => $checkout_source
			);
			
			return $checkout_data;
		}

		/**
		 * Create order from cart data for express checkout
		 *
		 * @return WC_Order
		 */
		function create_order_from_cart_data()
		{
			// Ensure WooCommerce cart is available and not empty
			if ( ! WC()->cart || WC()->cart->is_empty() ) {
				throw new Exception( __( 'Cart is empty or not available.', 'woo_sezzlepay' ) );
			}

			WC()->cart->calculate_totals();

			// Create a new order
			$order = wc_create_order();
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

			// Add note
			$order->add_order_note( __( 'Order created programmatically from cart through express checkout.', 'woo_sezzlepay' ) );

			sezzle_cleanup_stored_cart_fees();

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
				if ($checkout_source !== 'cart') {
					throw new Exception('Invalid checkout source');
				}
				
				if (!WC()->cart || WC()->cart->is_empty()) {
					throw new Exception('Cart not available or empty');
				}

				$order = null;
				if (!allow_create_order_post_checkout()) {
					$order = create_order_from_cart_data();
				} else {
					WC()->cart->calculate_totals();
					sezzle_save_cart_fees_to_session();
				}

				$checkout_data = get_express_checkout_data($checkout_source, $order);
				$min_amount = WC_Gateway_Sezzlepay::instance()->get_option('min-checkout-amount');

				if ($min_amount && ($checkout_data['amount_in_cents'] < ($min_amount * 100))) {
					throw new Exception('Cart total below minimum amount');
				}

				wp_send_json_success([
					'checkout_data' => $checkout_data
				]);

			} catch (Exception $e) {
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
				if ( $_SERVER['REQUEST_METHOD'] != 'POST' || !wp_verify_nonce($_POST['nonce'], "szl_ec_start_nonce")) {
					throw new Exception('Invalid request');
				}

				// Get required parameters
				$order_uuid = sanitize_text_field($_POST['order_uuid']);
				$address_uuid = sanitize_text_field($_POST['address_uuid']);
				$country_code = sanitize_text_field($_POST['country_code']);

				// Get full address details from SDK
				$address_data = [
					'street' => sanitize_text_field($_POST['street']),
					'city' => sanitize_text_field($_POST['city']),
					'state' => sanitize_text_field($_POST['state']),
					'postal_code' => sanitize_text_field($_POST['postal_code']),
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

				// Get gateway instance for minimum checkout amount
				$gateway = WC_Gateway_Sezzlepay::instance();
				$min_checkout_amount = floatval($gateway->get_option('min-checkout-amount'));
				$currency = get_option('woocommerce_currency');
				$totals = WC()->cart->get_totals();
				$fee_total = WC()->cart->get_fee_total() + WC()->cart->get_fee_tax();

				// Build shipping options with WooCommerce order logic including discounts
				$shipping_options = [];

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
					if ($final_order_amount >= $min_checkout_amount) {
						$shipping_amount_cents = (int)round($shipping_cost * 100);
						$tax_amount_cents = (int)round($total_tax * 100);
						$final_order_amount_cents = (int)round($final_order_amount * 100);

						$shipping_option = [
							'uuid' => uniqid('shipping_'),
							'name' => $method->get_label(),
							'description' => $method->get_label(),
							'shipping_amount_in_cents' => $shipping_amount_cents,
							'tax_amount_in_cents' => $tax_amount_cents,
							'final_order_amount_in_cents' => $final_order_amount_cents
						];

						$shipping_options[] = $shipping_option;
					}
				}

				if (empty($shipping_options)) {
					throw new Exception('All shipping methods do not meet the Sezzle minimum checkout limit.');
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
				wp_send_json_error([
					'error' => [
						'code' => 'merchant_error',
						'message' => $e->getMessage()
					]
				]);
			}
		}


		function sezzle_express_checkout_complete()
		{
			$order = null;
			$sezzle_order_uuid = '';
			try {
				if (!wp_verify_nonce($_POST['nonce'], "szl_ec_complete_nonce")) {
					throw new Exception('Invalid request');
				}

				// Get sezzle_order_uuid from POST data
				$sezzle_order_uuid = isset($_POST['sezzle_order_uuid']) ? sanitize_text_field($_POST['sezzle_order_uuid']) : '';
				
				if (empty($sezzle_order_uuid)) {
					throw new Exception(__('Sezzle order UUID is required.', 'woo_sezzlepay'));
				}

				$gateway = WC_Gateway_Sezzlepay::instance();
				
				// Get order details from Sezzle
				$sezzle_order_details = $gateway->get_sezzle_order($sezzle_order_uuid);
				
				if (!$sezzle_order_details || !isset($sezzle_order_details->reference_id)) {
					throw new Exception(__('Unable to retrieve order details from Sezzle.', 'woo_sezzlepay'));
				}

				// Get reference_id which is the order key when create_order_post_checkout is false else it is the cart hash
				$reference_id = $sezzle_order_details->reference_id;
				
				// Get or create WooCommerce Order
				$order_id = wc_get_order_id_by_order_key($reference_id);
				// Scenario: If merchant flips the create_order_post_checkout setting while the shopper is at checkout, 
				// then the order will be created again. To avoid this, we check if the order already exists and if it does, 
				// we use the existing order.
				if(!$order_id) {
					$order = create_order_from_cart_data();
				} else {
					$order = wc_get_order($order_id);
				}

				// Set order uuid to meta data
				$order->add_meta_data('sezzle_order_uuid', $sezzle_order_uuid);
				
				if (!$order) {
					throw new Exception(__('error creating or retrieving WooCommerce order.', 'woo_sezzlepay'));
				}

				// check the status of the order
				if ($order->get_status() !== 'pending') {
					throw new Exception(__('Order is not in pending payment status.', 'woo_sezzlepay'));
				}

				// Update WooCommerce order with shipping and customer details from Sezzle
				if (isset($sezzle_order_details->customer)) {
					$customer = $sezzle_order_details->customer;

					// Update customer details
					$order->set_billing_email( $customer->email ?? '' );
					$order->set_billing_first_name( $customer->first_name ?? '' );
					$order->set_billing_last_name( $customer->last_name ?? '' );
					$order->set_billing_phone( $customer->phone ?? '' );

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
						$shipping_item = new WC_Order_Item_Shipping();
						$shipping_item->set_method_title($sezzle_order_details->shipping_method->name);
						$shipping_item->set_total($sezzle_order_details->shipping_amount->amount_in_cents / 100);
						$order->add_item($shipping_item);
					}
				}

				$order->calculate_totals();

				// Process Payment
				$gateway->process_order($order, $reference_id, $sezzle_order_details, $order->get_order_key());

				$redirect_url = $order->get_checkout_order_received_url();

				// Return success response
				wp_send_json_success([
					'redirect_url' => $redirect_url,
					'message' => __('Order details updated successfully.', 'woo_sezzlepay')
				]);

			} catch (Exception $e) {
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
				
				// Validate nonce for security
				if (!wp_verify_nonce($nonce, 'szl_ec_start_nonce')) {
					wp_send_json_error(['message' => 'Invalid nonce']);
					return;
				}
				
				if (empty($event_name) || empty($page_name)) {
					wp_send_json_error(['message' => 'Missing required parameters']);
					return;
				}
				
				$gateway = WC_Gateway_Sezzlepay::instance();
				$txn_mode = $gateway->get_option('transaction-mode');
				$service_v2 = new Service_V2($txn_mode, $gateway->get_keys());
				$description = $page_name . 'page in ' . $txn_mode . ' mode';
				$event = [
					'event_name' => $event_name,
					'description' => $description,
					'merchant_uuid' => $gateway->get_option('merchant-id'),
					'merchant_site' => get_site_url()
				];
				$service_v2->send_widget_server_logs([$event]);
				wp_send_json_success(['message' => 'Widget server log sent successfully']);
			} catch (Exception $e) {
				wp_send_json_error(['message' => $e->getMessage()]);
			}
		}

		add_action( 'sezzle_daily_data_send_event', 'sezzle_daily_data_send_event' );
		add_action( 'woocommerce_after_checkout_form', 'add_installment_widget_script' );
        add_action( 'woocommerce_after_checkout_form', 'alter_checkout_url' );
		add_action( 'wp_enqueue_scripts', 'frontend_enqueue_scripts' );
		add_action( 'wp_enqueue_scripts', 'enqueue_express_checkout_scripts' );

		// Add express checkout button for Cart page
		add_action( 'woocommerce_proceed_to_checkout', 'render_cart_page_elements' );
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
	// Schedule cron
	if ( ! wp_next_scheduled( 'sezzle_daily_data_send_event_cron' ) ) {
		wp_schedule_event( time(), 'daily', 'sezzle_daily_data_send_event_cron' );
	}
}

function sezzle_deactivate_single_site() {
	wp_clear_scheduled_hook( 'sezzle_daily_data_send_event_cron' );
}

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
