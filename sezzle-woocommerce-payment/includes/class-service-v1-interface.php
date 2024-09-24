<?php

interface Service_V1_Interface {

	public function authenticate( $keys );

	public function create_checkout( $request );

	public function retrieve_order( $order_reference_id );

	public function capture( $order_reference_id );

    public function send_logs( $merchant_uuid, $logs );

	public function refund( $order_reference_id, $request );

	public function post_configuration( $request );

	public function send_merchant_orders( $request );
}
