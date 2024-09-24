<?php

require_once WC_GATEWAY_SEZZLEPAY_PATH . '/includes/class-service-v1-interface.php';
require_once WC_GATEWAY_SEZZLEPAY_PATH . '/includes/class-sezzle-utils.php';

class Service_V1 implements Service_V1_Interface
{
    const GATEWAY_URL = 'https://%sgateway.sezzle.com';
    const AUTH_ENDPOINT = '/v1/authentication';
    const CHECKOUT_ENDPOINT = '/v1/checkouts';
    const ORDER_ENDPOINT = '/v1/orders/%s';
    const CAPTURE_ENDPOINT = '/v1/checkouts/%s/complete';
    const REFUND_ENDPOINT = '/v1/orders/%s/refund';
    const CONFIGURATION_ENDPOINT = '/v2/configuration';
    const MERCHANT_ORDERS_ENDPOINT = '/v1/merchant_data/woocommerce/merchant_orders';
    const LOG_ENDPOINT = '/v1/logs/%s';

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
    public function create_checkout($request)
    {
        $url = $this->get_url(self::CHECKOUT_ENDPOINT);

        return $this->make_call($url, 'POST', $request, []);
    }

    /**
     * @param string $order_reference_id
     *
     * @return mixed
     * @throws Exception
     */
    public function retrieve_order($order_reference_id)
    {
        $url = $this->get_url(sprintf(self::ORDER_ENDPOINT, $order_reference_id));

        return $this->make_call($url, 'GET');
    }

    /**
     * @param string $order_reference_id
     *
     * @return mixed
     * @throws Exception
     */
    public function capture($order_reference_id)
    {
        $url = $this->get_url(sprintf(self::CAPTURE_ENDPOINT, $order_reference_id));

        return $this->make_call($url, 'POST');
    }

    public function send_logs($merchant_uuid, $logs)
    {
        try {
            $url = $this->get_url(sprintf(self::LOG_ENDPOINT, $merchant_uuid));

            $request = [
                'start_time' => date('Y-m-d'),
                'end_time' => date('Y-m-d'),
                'log' => $logs
            ];

            return $this->make_call($url, 'POST', $request);
        } catch (Exception $e) {
        }
        return false;
    }

    /**
     * @param string $order_reference_id
     * @param array $request
     *
     * @return mixed
     * @throws Exception
     */
    public function refund($order_reference_id, $request)
    {
        $url = $this->get_url(sprintf(self::REFUND_ENDPOINT, $order_reference_id));

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
        if (!strpos($url, 'authentication')) {
            $checkout_api = strpos($url, 'checkouts') !== false && !strpos($url, 'complete');
            $response = $this->authenticate($this->keys, $checkout_api ? wc_get_checkout_url() : '');

            if ($checkout_api && !isset($response->token) && ('unauthed_checkout_url' === $response->id)) {
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
        $unauthed = isset($response_body->id) && $response_body->id === 'unauthed_checkout_url';
        $accepted_response_codes = ['200', '201', '204'];

        if (!in_array($response_code, $accepted_response_codes) && !$unauthed) {
            throw new Exception('Error processing the request', $response_code);
        }

        return $response_body;
    }
}
