<?php

require_once WC_GATEWAY_SEZZLEPAY_PATH . '/includes/class-service-v2-interface.php';
require_once WC_GATEWAY_SEZZLEPAY_PATH . '/includes/class-sezzle-utils.php';

class Service_V2 implements Service_V2_Interface
{
    const GATEWAY_URL = 'https://%sgateway.sezzle.com';
    const AUTH_ENDPOINT = '/v2/authentication';
    const CHECKOUT_ENDPOINT = '/v2/session';
    const ORDER_ENDPOINT = '/v2/order/%s';
    const CAPTURE_ENDPOINT = '/v2/order/%s/capture';
    const REFUND_ENDPOINT = '/v2/order/%s/refund';
    const CONFIGURATION_ENDPOINT = '/v2/configuration';
    const MERCHANT_ORDERS_ENDPOINT = '/v1/merchant_data/woocommerce/merchant_orders';
    const LOG_ENDPOINT = '/v1/logs/%s';
    const EXPRESS_CHECKOUT_FLAG_ENDPOINT = '/v2/feature-flags/is-express-checkout';
    const UPDATE_CHECKOUT_ENDPOINT = '/v2/order/%s/checkout';
    const WIDGET_SERVER_URL = 'https://widget.sezzle.com';
    const STAGING_WIDGET_SERVER_URL = 'https://staging.widget.sezzle.com';
    const WIDGET_SERVER_LOGS_ENDPOINT = '/v1/event/log';

    private $api_mode;

    private $utils;

    private $keys;

    /**
     * @param string $api_mode
     * @param array|null $keys
     */
    public function __construct($api_mode, $keys = [])
    {
        $this->api_mode = $api_mode;
        $this->keys = $keys;
        $this->utils = new Sezzle_Utils();
    }

    /**
     * @param array $keys
     * @param string $cancel_url
     *
     * @return mixed
     * @throws Exception
     */
    public function authenticate($keys, $cancel_url = '')
    {
        $request = [
            'public_key' => $keys['public_key'],
            'private_key' => $keys['private_key']
        ];

        if (!empty($cancel_url)) {
            $request['cancel_url'] = $cancel_url;
        }

        $headers = [];
        $platform_details = $this->utils->get_encoded_platform_details();
        if ($platform_details) {
            $headers['Sezzle-Platform'] = $platform_details;
        }

        $url = $this->get_url(self::AUTH_ENDPOINT);

        return $this->make_call($url, 'POST', $request, $headers);
    }

    /**
     * @param array $request
     *
     * @return mixed
     * @throws Exception
     */
    public function create_session($request)
    {
        $url = $this->get_url(self::CHECKOUT_ENDPOINT);

        return $this->make_call($url, 'POST', $request, []);
    }

    /**
     * @param string $sezzle_order_uuid
     *
     * @return mixed
     * @throws Exception
     */
    public function get_order_details($sezzle_order_uuid)
    {
        $url = $this->get_url(sprintf(self::ORDER_ENDPOINT, $sezzle_order_uuid));

        return $this->make_call($url, 'GET');
    }

    /**
     * @param string $sezzle_order_uuid
     *
     * @return mixed
     * @throws Exception
     */
    public function capture($sezzle_order_uuid, $request)
    {
        $url = $this->get_url(sprintf(self::CAPTURE_ENDPOINT, $sezzle_order_uuid));

        return $this->make_call($url, 'POST', $request);
    }

    public function send_logs($merchant_uuid, $logs, $sezzle_order_uuid = null, $order_details = null)
    {
        try {
            $url = $this->get_url(sprintf(self::LOG_ENDPOINT, $merchant_uuid));

            $request = [
                'start_time' => date('Y-m-d'),
                'end_time' => date('Y-m-d'),
                'log' => $logs,
                'order_details' => $order_details ? json_encode($order_details) : '',
                'order_uuid' => $sezzle_order_uuid ? $sezzle_order_uuid : ''
            ];

            return $this->make_call($url, 'POST', $request);
        } catch (Exception $e) {
        }
        return false;
    }

    /**
     * @param string $sezzle_order_uuid
     * @param array $request
     *
     * @return mixed
     * @throws Exception
     */
    public function refund($sezzle_order_uuid, $request)
    {
        $url = $this->get_url(sprintf(self::REFUND_ENDPOINT, $sezzle_order_uuid));

        return $this->make_call($url, 'POST', $request);
    }

    /**
     * @param array $request
     *
     * @return mixed
     * @throws Exception
     */
    public function post_configuration($request)
    {
        $url = $this->get_url(self::CONFIGURATION_ENDPOINT);

        return $this->make_call($url, 'POST', $request);
    }

    /**
     * @param array $request
     *
     * @return mixed
     * @throws Exception
     */
    public function send_merchant_orders($request)
    {
        $url = $this->get_url(self::MERCHANT_ORDERS_ENDPOINT);

        return $this->make_call($url, 'POST', $request);
    }

     /**
     * Check if express checkout feature flag is enabled
     * 
     * @return bool
     */
    public function is_express_checkout_enabled()
    {  
        $url = $this->get_url(self::EXPRESS_CHECKOUT_FLAG_ENDPOINT);
        
        // Use Basic auth with public key for feature flag endpoint
        $headers = ['Authorization' => 'Basic ' . base64_encode($this->keys['public_key'])];
        
        return $this->make_call($url, 'GET', [], $headers);
    }

    /**
     * Update checkout with shipping address and costs
     *
     * @param string $sezzle_order_uuid Sezzle order UUID
     * @param array $request Update request data
     * @return mixed
     * @throws Exception
     */
    public function update_checkout($sezzle_order_uuid, $request)
    {
        try {
            $url = $this->get_url(sprintf(self::UPDATE_CHECKOUT_ENDPOINT, $sezzle_order_uuid));
            return $this->make_call($url, 'PATCH', $request);
        } catch (Exception $e) {
            throw new Exception('Error calling sezzle order update request');
        }
    }

    public function send_widget_server_logs($logs)
    {
        $base_url = $this->api_mode === 'sandbox' ? self::STAGING_WIDGET_SERVER_URL : self::WIDGET_SERVER_URL;
        $url = $base_url . self::WIDGET_SERVER_LOGS_ENDPOINT;
        $result = $this->make_call($url, 'POST', $logs);
        
        return $result;
    }

    /**
     * @param string $endpoint
     *
     * @return string
     */
    private function get_url($endpoint)
    {
        $base_url = $this->api_mode === 'sandbox' ?
            sprintf(self::GATEWAY_URL, $this->api_mode . '.') :
            sprintf(self::GATEWAY_URL, '');

        return $base_url . $endpoint;
    }

    /**
     * Check if the URL requires Bearer authentication
     * 
     * @param string $url
     * @return bool
     */
    private function needs_bearer_auth($url)
    {
        // Don't use Bearer auth for authentication, widget server, or feature flag calls
        $skip_bearer_patterns = [
            'authentication',
            self::STAGING_WIDGET_SERVER_URL,
            self::WIDGET_SERVER_URL,
            self::EXPRESS_CHECKOUT_FLAG_ENDPOINT
        ];
        
        foreach ($skip_bearer_patterns as $pattern) {
            if (strpos($url, $pattern) !== false) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * @param string $url
     * @param string $method
     * @param array $request
     * @param array $addl_headers
     *
     * @return mixed
     * @throws Exception
     */
    private function make_call($url, $method, $request = [], $addl_headers = [])
    {
        $headers = ['Content-Type' => 'application/json'];
        
        if ($this->needs_bearer_auth($url)) {
            $checkout_api = strpos($url, 'checkouts') !== false && !strpos($url, 'complete');
            $response = $this->authenticate($this->keys, $checkout_api ? wc_get_checkout_url() : '');

            if ($checkout_api && !isset($response->token) && ('unauthed_checkout_url' === $response->code)) {
                return (object)['checkout_url' => $response->message];
            }

            $headers = array_merge($headers, ['Authorization' => 'Bearer ' . $response->token]);
        }

        if ($addl_headers) {
            $headers = array_merge($headers, $addl_headers);
        }

        $body = count($request) > 0 ? json_encode($request) : null;

        $args = [
            'headers' => $headers,
            'body' => $body,
            'timeout' => 80,
            'redirection' => 35,
        ];

        $response = [];
        switch ($method) {
            case 'POST':
                $response = wp_remote_post($url, $args);
                break;
            case 'GET':
                $response = wp_remote_get($url, $args);
                break;
            case 'PATCH':
                $args['method'] = 'PATCH';
                $response = wp_remote_request($url, $args);
                break;
        }

        $encoded_response_body = wp_remote_retrieve_body($response);
        $response_code = wp_remote_retrieve_response_code($response);

        $gateway = WC_Gateway_Sezzlepay::instance();
        $gateway->dump_api_actions(
            $url,
            $request,
            $encoded_response_body,
            $response_code
        );

        $response_body = json_decode($encoded_response_body);
        $unauthed = isset($response_body->code) && $response_body->code === 'unauthed_checkout_url';
        $accepted_response_codes = ['200', '201', '204'];

        if (!in_array($response_code, $accepted_response_codes) && !$unauthed) {
            throw new Exception('Error processing the request', (int)$response_code);
        }

        return $response_body;
    }
}
