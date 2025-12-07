<?php
/**
 * Dev-only CORS allowance for Sentient Forms REST namespace.
 * Allow Playwright preview origin (127.0.0.1:4175) to call /wp-json/sentient-forms/v1/* during E2E runs.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( getenv( 'SENTIENT_E2E_DISABLE_DEV_CORS' ) ) {
	return;
}

add_action( 'rest_api_init', function () {
	// Ensure our headers are sent for REST responses.
	remove_filter( 'rest_send_cors_headers', 'rest_send_cors_headers' );
	add_filter( 'rest_send_cors_headers', function ( $headers ) {
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? trim( $_SERVER['HTTP_ORIGIN'] ) : '';
		$allowed_origin = 'http://127.0.0.1:4175';
		if ( $origin === $allowed_origin ) {
			$headers['Access-Control-Allow-Origin']      = $allowed_origin;
			$headers['Access-Control-Allow-Credentials'] = 'false';
			$headers['Vary']                             = 'Origin';
			$headers['Access-Control-Allow-Headers']     = 'Authorization, Content-Type, X-WP-Nonce';
			$headers['Access-Control-Allow-Methods']     = 'GET, POST, PUT, PATCH, DELETE, OPTIONS';
		}
		return $headers;
	} );
}, 15 );

// Respond to OPTIONS preflight for our namespace to avoid 403s.
add_filter( 'rest_pre_serve_request', function ( $served, $result, $request ) {
	$namespace = $request->get_route();
	if ( $_SERVER['REQUEST_METHOD'] === 'OPTIONS' && str_starts_with( $namespace, '/sentient-forms/' ) ) {
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? trim( $_SERVER['HTTP_ORIGIN'] ) : '';
		$allowed_origin = 'http://127.0.0.1:4175';
		if ( $origin === $allowed_origin ) {
			header( 'Access-Control-Allow-Origin: ' . $allowed_origin );
			header( 'Access-Control-Allow-Credentials: false' );
			header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce' );
			header( 'Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS' );
			header( 'Vary: Origin' );
			status_header( 200 );
			echo '';
			return true;
		}
	}
	return $served;
}, 10, 3 );
