<?php
/*
Plugin Name: بازبینی وضعیت پرداخت سفارشات زرین پال
Version: 1.0.0
Description: این افزونه کمک میکند تا سفارشاتی که در زرین پال پرداخت شده اند ولی در سایت وضعیت آنها به دلیل اختلال گسترده اینترنت تغییر نکرده اند، اصلاح شوند
Plugin URI: https://github.com/hamidrezayazdani/zarinpal-auto-verifier
Author: Hamid Reza Yazdani
Author URI: https://github.com/hamidrezayazdani/
*/

defined( 'ABSPATH' ) || exit;

/**
 * Check zarinpal installed and activated
 */
include_once ABSPATH . 'wp-admin/includes/plugin.php';

if ( ! is_plugin_active( 'zarinpal-woocommerce-payment-gateway/index.php' ) ) {
	return;
}

if ( ! function_exists( 'ywp_zpav_activation' ) ) {

	/**
	 * Schedule the event on plugin activation
	 */
	function ywp_zpav_activation() {
		if ( ! wp_next_scheduled( 'my_custom_cron_job' ) ) {
			wp_schedule_event( time(), 'thirty_minutes', 'my_custom_cron_job' );
		}

		update_option( 'ywp_zpav_install_date', time() );
	}

	register_activation_hook( __FILE__, 'ywp_zpav_activation' );
}

if ( ! function_exists( 'ywp_zpav_deactivation' ) ) {

	/**
	 * Clear the scheduled event on plugin deactivation
	 */
	function ywp_zpav_deactivation() {
		wp_clear_scheduled_hook( 'ywp_zpav_cron_job' );
	}

	register_deactivation_hook( __FILE__, 'ywp_zpav_deactivation' );
}

if ( ! function_exists( 'ywp_zpav_cron_schedules' ) ) {

	/**
	 * Add a custom interval for 30 minutes
	 */
	function ywp_zpav_cron_schedules( $schedules ) {
		$schedules['thirty_minutes'] = array(
			'interval' => 1800,
			'display'  => __( 'Every 30 Minutes' ),
		);

		return $schedules;
	}

	add_filter( 'cron_schedules', 'ywp_zpav_cron_schedules' );
}

if ( ! function_exists( 'ywp_zpav_cronjob_callback' ) ) {

	/**
	 * Define the cronjob callback function
	 */
	function ywp_zpav_cronjob_callback() {
		$merchant_code = ywp_zpav_get_merchant_code();
		$install_date  = get_option( 'ywp_zpav_install_date' );

		if ( empty( $merchant_code ) ) {
			return;
		}

		$response = wp_remote_post(
			'https://api.zarinpal.com/pg/v4/payment/unVerified.json',
			array(
				'method'  => 'POST',
				'body'    => array(
					'merchant_id' => $merchant_code,
				),
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
			),
		);

		if ( is_wp_error( $response ) ) {
			error_log( 'Error: ' . $response->get_error_message() );

			return;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( 100 == $data['data']['code'] ) {
			$unverified_orders = array();

			foreach ( $data['data']['authorities'] as $authority ) {

				// Get the order id from callback_url
				$order_id            = absint( substr( $authority['callback_url'], strrpos( $authority['callback_url'], '=' ) + 1 ) );
				$unverified_orders[] = array(
					'order_id'  => $order_id,
					'amount'    => $authority['amount'],
					'authority' => $authority['authority']
				);
			}

			foreach ( $unverified_orders as $order ) {
				$order_obj = wc_get_order( $order['order_id'] );

				if ( $order_obj && $order_obj->needs_payment() ) {

					// Get the order date
					$order_date = $order_obj->get_date_created()->getTimestamp();

					// Checking whether the order has already been verified or not?
					$already_verified = $order_obj->meta_exists( '_ywp_zp_verified' );

					if ( $order_date > $install_date && ! $already_verified ) {
						$verify_response = wp_remote_post(
							'https://payment.zarinpal.com/pg/v4/payment/verify.json',
							array(
								'method'  => 'POST',
								'body'    => json_encode(
									array(
										'merchant_id' => $merchant_code,
										'amount'      => $order['amount'],
										'authority'   => $order['authority'],
									),
								),
								'headers' => array(
									'Content-Type' => 'application/json',
									'Accept'       => 'application/json',
								),
							),
						);

						if ( is_wp_error( $verify_response ) ) {
							error_log( 'Error: ' . $verify_response->get_error_message() );

							continue;
						}

						$verify_body = wp_remote_retrieve_body( $verify_response );
						$verify_data = json_decode( $verify_body, true );

						if ( in_array( $verify_data['data']['code'], array( 100, 101 ) ) ) {

							// Change the order status to 'processing' with a note
							$order_obj->update_status(
								'processing',
								sprintf(
									'وضعیت سفارش توسط بازبینی خودکار زرین پال تغییر کرد. - شماره پیگیری: %s',
									esc_html( $verify_data['data']['ref_id'] ),
								),
							);

							$order_obj->update_meta_data( '_ywp_zp_verified', 1 );
							$order_obj->update_meta_data( '_card_hash', $verify_data['data']['card_hash'] );
							$order_obj->update_meta_data( '_card_pan', $verify_data['data']['card_pan'] );
							$order_obj->update_meta_data( '_ref_id', $verify_data['data']['ref_id'] );
							$order_obj->save();
						}
					}
				}
			}
		}
	}

	add_action( 'ywp_zpav_cron_job', 'ywp_zpav_cronjob_callback' );
}

if ( ! function_exists( 'ywp_zpav_get_merchant_code' ) ) {

	/**
	 * Get zarinpal merchant code
	 *
	 * @return mixed|string
	 */
	function ywp_zpav_get_merchant_code() {
		$zarinpal_config = get_option( 'woocommerce_WC_ZPal_settings', '' );

		if ( ! empty( $zarinpal_config ) && isset( $zarinpal_config['merchantcode'] ) ) {
			return $zarinpal_config['merchantcode'];
		}

		return '';
	}
}


if ( ! function_exists( 'ywp_zpav_add_wc_hpos_compatibility' ) ) {

	/**
	 * Adds WooCommerce HPOS compatibility
	 *
	 * @return void
	 */
	function ywp_zpav_add_wc_hpos_compatibility() {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__ );
		}
	}

	add_action( 'before_woocommerce_init', 'ywp_zpav_add_wc_hpos_compatibility' );
}