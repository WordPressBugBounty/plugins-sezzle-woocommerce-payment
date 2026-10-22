<?php

require_once WC_GATEWAY_SEZZLEPAY_PATH . '/includes/class-service-v2-interface.php';
require_once WC_GATEWAY_SEZZLEPAY_PATH . '/includes/class-sezzle-utils.php';

/**
 * A non-2xx response from Sezzle. Carries the decoded body so callers can act on
 * Sezzle's error code (e.g. checkout_captured, checkout_expired).
 */
class Sezzle_Api_Exception extends Exception
{
    private $response_body;

    public function __construct($message, $code, $response_body)
    {
        parent::__construct($message, $code);
        $this->response_body = $response_body;
    }

    /**
     * The first error object in the response body, or null if there is none.
     * Sezzle may return a single error object or a list of them.
     *
     * @return object|null
     */
    public function get_error()
    {
        $body = $this->response_body;
        if (is_array($body)) {
            $body = reset($body);
        }
        return is_object($body) ? $body : null;
    }
}

class Service_V2 implements Service_V2_Interface
{
    const GATEWAY_URL = 'https://%sgateway.sezzle.com';
    const AUTH_ENDPOINT = '/v2/authentication';
    const CHECKOUT_ENDPOINT = '/v2/session';
    const ORDER_ENDPOINT = '/v2/order/%s';
    const CAPTURE_ENDPOINT = '/v2/order/%s/capture';
    const REFUND_ENDPOINT = '/v2/order/%s/refund';
    const CONFIGURATION_ENDPOINT = '/v2/configuration';
    const EVENT_LOGGING_ENDPOINT = '/sdk-event-logging';
    const LOG_ENDPOINT = '/v1/logs/%s';
    const EXPRESS_CHECKOUT_FLAG_ENDPOINT = '/v2/feature-flags/is-express-checkout';
    const UPDATE_CHECKOUT_ENDPOINT = '/v2/order/%s/checkout';
    const WIDGET_SERVER_URL = 'https://widget.sezzle.com';
    const STAGING_WIDGET_SERVER_URL = 'https://staging.widget.sezzle.com';
    const WIDGET_SERVER_LOGS_ENDPOINT = '/v1/event/log';

    private $api_mode;

    private $utils;

    private $keys;

    private $disable_on_unauthorized;

    /**
     * @param string $api_mode
     * @param array|null $keys
     * @param array $options Behaviour toggles. Supported keys:
     *        - 'disable_on_unauthorized' (bool, default true): when true, a 401 from
     *          the /v2/authentication endpoint disables the gateway and clears the
     *          stored keys. Pass false from flows where the merchant is actively
     *          entering keys (settings validation), so a failed attempt rejects the
     *          save instead of destructively disabling the gateway.
     */
    public function __construct($api_mode, $keys = [], array $options = [])
    {
        $this->api_mode = $api_mode;
        $this->keys = $keys;
        $this->disable_on_unauthorized = $options['disable_on_unauthorized'] ?? true;
        $this->utils = new Sezzle_Utils();
    }

    /**
     * @param array $keys
     * @param int $timeout Request timeout in seconds
     *
     * @return mixed
     * @throws Exception
     */
    public function authenticate($keys, $timeout = 80)
    {
        $request = [
            'public_key' => $keys['public_key'],
            'private_key' => $keys['private_key']
        ];

        $headers = [];
        $platform_details = $this->utils->get_encoded_platform_details();
        if ($platform_details) {
            $headers['Sezzle-Platform'] = $platform_details;
        }

        $url = $this->get_url(self::AUTH_ENDPOINT);

        return $this->make_call($url, 'POST', $request, $headers, $timeout);
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

    /**
     * Log event to Sezzle SDK event logging endpoint
     *
     * @param array $request The event data to log
     * @return mixed
     * @throws Exception
     */
    public function log_event($request)
    {
        $url = $this->get_url(self::EVENT_LOGGING_ENDPOINT);

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
            // Log shipping is best-effort and must not interrupt the caller's flow,
            // but record the failure locally rather than swallowing it silently.
            WC_Gateway_Sezzlepay::instance()->log(
                'Failed to send logs to Sezzle: ' . $e->getMessage()
            );
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
     * Check if express checkout feature flag is enabled
     *
     * Returns false without calling the gateway when no public key is configured,
     * so the endpoint is never hit with an empty Basic auth credential. This is the
     * normal state for a store that installed the plugin but never entered keys, and
     * the check runs on cart, product, and checkout views, so it stays silent rather
     * than logging the same non-actionable message on every page view.
     *
     * @return bool
     */
    public function is_express_checkout_enabled()
    {
        $public_key = isset($this->keys['public_key']) ? $this->keys['public_key'] : '';
        if (empty($public_key)) {
            return false;
        }

        $url = $this->get_url(self::EXPRESS_CHECKOUT_FLAG_ENDPOINT);

        // Use Basic auth with public key for feature flag endpoint
        $headers = ['Authorization' => 'Basic ' . base64_encode($public_key)];

        // Short timeout: this runs while cart, product, and checkout pages render,
        // so a slow gateway must not hold the page (a failure is cached briefly).
        return $this->make_call($url, 'GET', [], $headers, 5);
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
        // Let make_call's exception propagate unchanged so the caller keeps the real
        // HTTP status code and Sezzle's error; dump_api_actions has already logged
        // the failing endpoint.
        $url = $this->get_url(sprintf(self::UPDATE_CHECKOUT_ENDPOINT, $sezzle_order_uuid));
        return $this->make_call($url, 'PATCH', $request);
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
     * Whether a 401 from this URL should disable the gateway and clear keys.
     *
     * Covers the gateway authentication endpoint, used directly and to mint the
     * Bearer token for the other API calls.
     *
     * @param string $url
     * @return bool
     */
    private function is_auth_failure_url($url)
    {
        // Path-only, suffix comparison so query strings or sibling paths like
        // /v2/authentication-tokens can't create false positives that would fire
        // the auto-disable on an unrelated endpoint.
        $path = parse_url($url, PHP_URL_PATH);
        return $path !== null && substr($path, -strlen(self::AUTH_ENDPOINT)) === self::AUTH_ENDPOINT;
    }

    /**
     * @param string $url
     * @param string $method
     * @param array $request
     * @param array $addl_headers
     * @param int $timeout Request timeout in seconds
     *
     * @return mixed
     * @throws Exception
     */
    private function make_call($url, $method, $request = [], $addl_headers = [], $timeout = 80)
    {
        $headers = ['Content-Type' => 'application/json'];
        
        if ($this->needs_bearer_auth($url)) {
            $response = $this->authenticate($this->keys);
            // A 2xx authentication response without a token leaves nothing to authorize
            // with. Fail here rather than send the request with an empty Bearer header,
            // which would come back as a 401 that looks like rejected credentials.
            if (!is_object($response) || empty($response->token)) {
                throw new Exception('Sezzle authentication succeeded but returned no token');
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
            'timeout' => $timeout,
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
        $accepted_response_codes = ['200', '201', '204'];

        // A 401 from the authentication endpoint means the stored API keys are no
        // longer valid. Disable Sezzle and clear the keys so shoppers aren't shown a
        // broken payment option until the merchant re-enters valid keys.
        if ($this->disable_on_unauthorized
            && (int)$response_code === 401
            && $this->is_auth_failure_url($url)
        ) {
            $gateway->disable_due_to_unauthorized();
        }

        if (!in_array($response_code, $accepted_response_codes)) {
            throw new Sezzle_Api_Exception('Error processing the request', (int)$response_code, $response_body);
        }

        return $response_body;
    }
}
