<?php

class Sezzle_Utils {

    /**
     * Money format
     */
    const MONEY_FORMAT = "%.2f";

	/**
	 * @return string
	 */
	public function get_encoded_platform_details() {
		try {
			$encoded_platform_details = '';
			global $wp_version, $woocommerce;
			$platform_details = array(
				'id'             => 'WooCommerce',
				'version'        => 'WP ' . $wp_version . ' | WC ' . $woocommerce->version,
				'plugin_version' => $this->get_sezzle_version(),
			);

			$encoded_platform_details = base64_encode( json_encode( $platform_details ) );
		} catch ( Exception $exception ) {
			WC_Gateway_Sezzlepay::instance()->log( 'Error getting platform details: ' . $exception->getMessage() );
		}

		return $encoded_platform_details;
	}

	/**
	 * @return mixed|string
	 */
	private function get_sezzle_version() {
		// If get_plugins() isn't available, require it
		if ( ! function_exists( 'get_plugins' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_folder = get_plugins( '/sezzle-woocommerce-payment' );
		$plugin_file   = 'woocommerce-gateway-sezzle.php';

		return $plugin_folder[ $plugin_file ]['Version'] ?? '';
	}

    /**
     * Format to cents
     *
     * @param float $amount
     * @return int
     */
    public static function formatToCents($amount = 0.00)
    {
        $negative = false;
        $str = self::formatMoney($amount);
        if (strcmp($str[0], '-') === 0) {
            // treat it like a positive. then prepend a '-' to the return value.
            $str = substr($str, 1);
            $negative = true;
        }

        $parts = explode('.', $str, 2);
        if (($parts === false) || empty($parts)) {
            return 0;
        }

        if ((strcmp($parts[0], '0') === 0) && (strcmp($parts[1], '00') === 0)) {
            return 0;
        }

        $retVal = '';
        if ($negative) {
            $retVal .= '-';
        }
        $retVal .= ltrim($parts[0] . substr($parts[1], 0, 2), '0');
        return intval($retVal);
    }

    /**
     * Format money
     *
     * @param float $amount
     * @return string
     */
    private static function formatMoney($amount)
    {
        return sprintf(self::MONEY_FORMAT, $amount);
    }

}
