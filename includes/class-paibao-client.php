<?php
/**
 * HTTP client for the Paibao AI Operator service.
 *
 * @package Paibao_AI_Operator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Paibao_Operator_Client {

	/**
	 * Call the operator service with the site's license key.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   API path (e.g. /api/operator/generate).
	 * @param array|null $body   JSON body, or null for GET.
	 * @return array|WP_Error    Decoded JSON on success, WP_Error on failure.
	 */
	public static function request( $method, $path, $body = null ) {
		$s = paibao_op_settings();
		if ( empty( $s['license_key'] ) ) {
			return new WP_Error( 'paibao_no_license', __( 'License key is not configured.', 'paibao-ai-operator' ) );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 120,
			'headers' => array(
				'X-License-Key' => $s['license_key'],
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$res = wp_remote_request( trailingslashit( $s['api_base'] ) . ltrim( $path, '/' ), $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( $code >= 400 ) {
			$detail = is_array( $data ) && isset( $data['detail'] ) ? $data['detail'] : 'HTTP ' . $code;
			if ( 402 === $code ) {
				$detail = __( 'No active subscription for this license key.', 'paibao-ai-operator' ) . ' ' . $detail;
			}
			return new WP_Error( 'paibao_api_error', is_string( $detail ) ? $detail : wp_json_encode( $detail ), array( 'status' => $code ) );
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Capabilities of the configured license key.
	 *
	 * @return array|WP_Error
	 */
	public static function entitlements() {
		return self::request( 'GET', '/api/operator/entitlements' );
	}

	/**
	 * Generate GEO drafts (pull mode — content is returned, not published remotely).
	 *
	 * @param string $topic     Optional topic; empty -> service auto-suggests.
	 * @param array  $languages Locale codes.
	 * @param string $market    Target export market.
	 * @return array|WP_Error
	 */
	public static function generate( $topic, $languages, $market ) {
		return self::request(
			'POST',
			'/api/operator/generate',
			array(
				'topic'     => $topic,
				'languages' => array_values( $languages ),
				'market'    => $market,
			)
		);
	}
}
