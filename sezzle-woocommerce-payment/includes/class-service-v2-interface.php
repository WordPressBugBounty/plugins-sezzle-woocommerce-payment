<?php

interface Service_V2_Interface {

	public function authenticate( $keys );

	public function create_session( $request );

	public function get_order_details( $sezzle_order_uuid );

	public function capture( $sezzle_order_uuid, $request );

	public function log_event( $request );

    public function send_logs( $merchant_uuid, $logs, $sezzle_order_uuid = null, $order_details = null);

	public function refund( $sezzle_order_uuid, $request );

	public function post_configuration( $request );

	public function send_merchant_orders( $request );

	public function is_express_checkout_enabled();

	public function update_checkout( $sezzle_order_uuid, $request );

	public function send_widget_server_logs( $logs );
}
