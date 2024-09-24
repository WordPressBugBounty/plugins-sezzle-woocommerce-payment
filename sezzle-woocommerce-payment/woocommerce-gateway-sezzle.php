<?php
/*
Plugin Name: Sezzle WooCommerce Payment
Description: Buy Now Pay Later with Sezzle
Version: 5.0.15
Author: Sezzle
Author URI: https://www.sezzle.com/
Tested up to: 6.5.3
Copyright: © 2024 Sezzle
WC requires at least: 3.0.0
WC tested up to: 9.1.4
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
require_once WC_GATEWAY_SEZZLEPAY_PATH . '/includes/class-service-v1.php';
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

			const TRANSACTION_MODE_LIVE    = 'live';
			const TRANSACTION_MODE_SANDBOX = 'sandbox';

			public function __construct() {
				$this->id                 = 'sezzlepay';
				$this->method_title       = __( 'Sezzle', 'woo_sezzlepay' );
				$this->description        = __( 'Buy Now and Pay Later with Sezzle.', 'woo_sezzlepay' );
				$this->method_description = $this->description;
				$this->icon               = 'https://d34uoa9py2cgca.cloudfront.net/branding/sezzle-logos/png/sezzle-logo-sm-100w.png';
				$this->supports           = array( 'products', 'refunds' );
				$this->init_form_fields();
				$this->init_settings();
				$this->title               = $this->get_option( 'title' );
				$this->supported_countries = [ 'US', 'CA' ];

				add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
				add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'sezzle_payment_callback' ) );
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
                    $service_v1 = new Service_V1($transaction_mode);
                    $response = $service_v1->authenticate($request);
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
				self::$log->add( 'Sezzlepay', $message );
			}

			public function dump_api_actions( $url, $request = null, $response = null, $status_code = null ) {
				$this->log( $url );
				$this->log( 'Request Body' );
				$this->log( json_encode( $request ) );
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
                        case strpos($file, 'Sezzlepay-' . date('Y-m-d')) !== false:
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

			public function process_payment( $order_id ) {
				try {
					$order = $this->get_order( $order_id );

					$checkout_data = $this->get_checkout_data( $order );
					$redirect_url  = $this->get_redirect_url( $checkout_data );

                    $order->add_meta_data('sezzle_redirect_url', $redirect_url);
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
			public function get_checkout_data( $order = null, $post_data = [] ) {
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
					'amount_in_cents'            => $amount_in_cents,
					'currency_code'              => get_woocommerce_currency(),
					'order_description'          => $order_reference_id,
					'order_reference_id'         => $order_reference_id,
					'display_order_reference_id' => $order_exist ? (string)$order->get_id() : '',
					'checkout_complete_url'      => $complete_url,
					'checkout_cancel_url'        => wc_get_checkout_url(),
					'customer_details'           => [
						'first_name' => $order_exist ? $order->get_billing_first_name() : $post_data['billing_first_name'],
						'last_name'  => $order_exist ? $order->get_billing_last_name() : $post_data['billing_last_name'],
						'email'      => $order_exist ? $order->get_billing_email() : $post_data['billing_email'],
						'phone'      => $order_exist ? $order->get_billing_phone() : $post_data['billing_phone'],
					],

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

					'items' => $items,

					'merchant_completes' => true
				];
			}

			public function get_redirect_url( $data ) {
				$txn_mode   = $this->get_option('transaction-mode');
				$service_v1 = new Service_V1($txn_mode, $this->get_keys());

				$response = $service_v1->create_checkout($data);
				if ( isset( $response->checkout_url ) ) {
					return $response->checkout_url;
				}

				wc_add_notice( __( 'Sorry, there was a problem preparing your payment.', 'woo_sezzlepay' ), 'error' );
				return wc_get_checkout_url();
			}

			private function payment_captured( $order_reference_id ) {
				$txn_mode   = $this->get_option('transaction-mode');

				$service_v1 = new Service_V1($txn_mode, $this->get_keys());

				$response = $service_v1->retrieve_order($order_reference_id);

				return isset( $response->captured_at ) && $response->captured_at;
			}

			public function sezzle_payment_callback() {
				try {
					$_REQUEST           = stripslashes_deep( $_REQUEST );
					$order_key = isset( $_REQUEST['key'] ) ? sanitize_text_field( $_REQUEST['key'] ) : '';

					if ( $order_key ) {
						$order_id = wc_get_order_id_by_order_key( $order_key );
						$order = $this->get_order( $order_id );
						$order_reference_id = $order->get_transaction_id();
					} else {
						$order_reference_id = isset( $_REQUEST['order_reference_id'] ) ? sanitize_text_field( $_REQUEST['order_reference_id'] ) : '';
						if ( $order_reference_id === '' ) {
							throw new Exception(__('Order reference ID not matching', 'woo_sezzlepay'));
						}

						$posted_data     = WC()->session->get( 'posted_data' );
						$sezzle_checkout = Sezzle_Checkout::instance();
						$sezzle_checkout->process_customer( $posted_data );
						WC()->cart->calculate_totals();

						$order_id = WC()->checkout()->create_order( $posted_data );
						$order = $this->get_order( $order_id );

						switch ( true ) {
							case is_wp_error( $order_id ):
								throw new Exception( $order_id->get_error_message() );
							case ! $order:
								throw new Exception( __( 'Unable to create order.', 'woo_sezzlepay' ) );
						}

						do_action( 'woocommerce_checkout_order_processed', $order_id, $posted_data, $order );
					}

					$redirect_url = wc_get_checkout_url();
					if ( ! $this->payment_captured( $order_reference_id ) ) {

						$txn_mode   = $this->get_option( 'transaction-mode' );

						$service_v1 = new Service_V1($txn_mode, $this->get_keys());

						$response = $service_v1->capture( $order_reference_id );

						if ( is_object( $response ) && $response->amount_in_cents ) {
							$order->add_order_note( __( 'Payment approved by Sezzle successfully.', 'woo_sezzlepay' ) );
							$order->payment_complete( $order_reference_id );
							WC()->cart->empty_cart();
							$redirect_url = $this->get_return_url( $order );
							if ( ! $order_key ) {
								$order->add_meta_data( 'order_reference_id', $order_reference_id );
								$order->set_transaction_id( $order_reference_id );
								$order->save();
								apply_filters( 'woocommerce_payment_successful_result', '', $order_id );
							}
						} else {
							$orderFailed = true;

							// if it is not a json
							if ( is_null( $response ) ) {
								// return a generic error
								$order->add_order_note(
									__(
										'The payment failed because of an unknown error. Please contact Sezzle from the Sezzle merchant dashboard.',
										'woo_sezzlepay'
									)
								);
							} else {
								// if the body is not valid json
								if ( ! isset( $response->id ) ) {
									// return a generic error
									$order->add_order_note(
										__(
											'The payment failed because of an unknown error. Please contact Sezzle from the Sezzle merchant dashboard.',
											'woo_sezzlepay'
										)
									);
								} else {
									if ( strtolower( $response->id ) == 'checkout_expired' ) {
										// show the message received from sezzle
										$order->add_order_note( __( ucfirst( "$response->id : $response->message" ), 'woo_sezzlepay' ) );
									} else {
										if ( strtolower( $response->id ) == 'checkout_captured' ) {
											$orderFailed = false;
										}
									}
								}
							}

							if ( $orderFailed ) {
								$order->update_status( 'failed' );
							}
							$redirect_url = wc_get_checkout_url();
						}
					} else if ( ! $order->is_paid() ) {
						$order->payment_complete( $order_reference_id );
						WC()->cart->empty_cart();
						$redirect_url = $this->get_return_url( $order );
					}

					wp_redirect( $redirect_url );
					if ( $order_reference_id ) {
						exit;
					}
				} catch (Exception $e) {
                    $this->log($e->getMessage());

                    $txn_mode   = $this->get_option( 'transaction-mode' );
                    $service_v1 = new Service_V1($txn_mode, $this->get_keys());
                    $merchant_uuid = $this->get_option( 'merchant-id' );
                    $service_v1->send_logs( $merchant_uuid, json_encode( $this->get_logs()) );

					wc_add_notice( $e->getMessage(), 'error' );
					wp_redirect( wc_get_checkout_url() );
					exit;
				}
			}

			public function process_refund( $order_id, $amount = null, $reason = '' ) {
				$order = $this->get_order( $order_id );
				$order_reference_id = $order->get_transaction_id();
				$request = [
					'amount' => [
						'amount_in_cents' => Sezzle_Utils::formatToCents($amount),
						'currency'        => $order->get_currency(),
					]
				];

				$txn_mode   = $this->get_option('transaction-mode');

				$service_v1 = new Service_V1($txn_mode, $this->get_keys());

				$response = $service_v1->refund($order_reference_id, $request);


				if ( is_object($response) && $response->refund_id ) {
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
				$service_v1 = new Service_V1($txn_mode, $this->get_keys());
				$response = $service_v1->send_merchant_orders( $request );

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
                return $response;
			}

			private function send_admin_configuration() {
				try {
					$request   = $this->get_admin_configuration();

					$txn_mode = $this->get_option('transaction-mode');
					$service_v1 = new Service_V1($txn_mode, $this->get_keys());
					$service_v1->post_configuration( $request );
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
                new SezzleInstallmentWidget({
                    'merchantLocale': 'US',
                    'platform': 'woocommerce'
                });

                // create an observer instance
                jQuery(document.body).on( 'updated_checkout', function(){
                    var sezzlePaymentLine = document.querySelector('.payment_method_". $gateway->id ."');
                    if (document.getElementById('sezzle-installment-widget-box')) {
                        document.getElementById('sezzle-installment-widget-box').remove();
                        document.querySelector('.sezzle-modal-overlay').remove();
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
            </script>";
		}

		/**
		 * Add scripts to frontend
		 *
		 * @return void
		 */
		function frontend_enqueue_scripts() {
            $gateway = WC_Gateway_Sezzlepay::instance();
            if ($gateway->get_option('enabled') == 'no' || $gateway->get_option('enable-installment-widget') == 'no') {
                return;
            }

            $is_checkout_block = class_exists('Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils') && CartCheckoutUtils::is_checkout_block_default();
			if ( is_checkout() || $is_checkout_block ) {
				wp_enqueue_script(
					'installment_widget_js',
					'https://checkout-sdk.sezzle.com/installment-widget.min.js',
					array(),
					'v1.0.0'
				);

                if ($is_checkout_block) {
                    wp_enqueue_script(
                        'installment_widget_renderer_js',
                        plugin_dir_url(__FILE__) . 'build/js/frontend/installment-widget.js',
                        [], 'v1.0.0'
                    );
                }
			}
		}

		add_action( 'sezzle_daily_data_send_event', 'sezzle_daily_data_send_event' );
		add_action( 'woocommerce_after_checkout_form', 'add_installment_widget_script' );
        add_action( 'woocommerce_after_checkout_form', 'alter_checkout_url' );
		add_action( 'wp_enqueue_scripts', 'frontend_enqueue_scripts' );
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
