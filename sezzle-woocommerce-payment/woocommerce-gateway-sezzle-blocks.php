<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * WC_Gateway_Sezzlepay_Blocks
 */
final class WC_Gateway_Sezzlepay_Blocks extends AbstractPaymentMethodType {

    /**
     * @var WC_Gateway_Sezzlepay
     */
    private $gateway;

    /**
     * @var string
     */
    protected $name = 'sezzlepay';

    /**
     * @return void
     */
    public function initialize() {
        $this->settings = get_option( 'woocommerce_'. $this->name .'_settings', [] );
        $this->gateway = new WC_Gateway_Sezzlepay();
    }

    /**
     * @return bool
     */
    public function is_active() {
        return $this->gateway->is_available();
    }

    /**
     * @return string[]
     */
    public function get_payment_method_script_handles() {
        $handle = $this->name . '-blocks-integration';
        $payment_block_asset_path = plugin_dir_url(__FILE__) . 'build/js/frontend/payment-block.asset.php';
        $payment_block_version = '';
        $payment_block_dependencies = [];

        if (file_exists($payment_block_asset_path)) {
            $asset = require $payment_block_asset_path;
            $payment_block_version = is_array($asset) && isset($asset['version'])
                ? $asset['version']
                : $payment_block_version;
            $payment_block_dependencies = is_array($asset) && isset($asset['dependencies'])
                ? $asset['dependencies']
                : $payment_block_dependencies;
        }

        wp_register_script(
            $handle,
            plugin_dir_url(__FILE__) . 'build/js/frontend/payment-block.js',
            $payment_block_dependencies,
            $payment_block_version,
            true
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations($handle);
        }

        return [$handle];
    }

    /**
     * @return array
     */
    public function get_payment_method_data() {
        return [
            'title' => $this->gateway->title,
            'description' => $this->gateway->description,
            'icon' => $this->gateway->icon,
            'placeOrderButtonLabel' => __('Continue to Sezzle', 'woo_sezzlepay'),
            'supports' => $this->get_supported_features()
        ];
    }

    /**
     * @return array|string[]
     */
    public function get_supported_features() {
        return $this->gateway->supports;
    }
}
