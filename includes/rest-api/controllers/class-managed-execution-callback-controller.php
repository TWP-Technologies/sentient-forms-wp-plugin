<?php
/**
 * REST API controller for CPS managed async execution callbacks.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Managed_Execution_Callback_Controller extends Sentient_Forms_Abstract_Base_Controller
{
    protected string $rest_base = 'managed';

    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/execution-callback',
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'receive_callback' ],
                    'permission_callback' => '__return_true',
                ],
            ]
        );
    }

    public function receive_callback( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $body = (string) $request->get_body();
        if ( '' === trim( $body ) )
        {
            return $this->prepare_error_response(
                'sentient_forms_callback_empty_body',
                __( 'Callback body is required.', 'sentient-forms' ),
                400
            );
        }

        $signature_check = $this->verify_signature( $request, $body );
        if ( is_wp_error( $signature_check ) )
        {
            return $signature_check;
        }

        $payload = json_decode( $body, true );
        if ( ! is_array( $payload ) )
        {
            return $this->prepare_error_response(
                'sentient_forms_callback_invalid_json',
                __( 'Callback body must be valid JSON.', 'sentient-forms' ),
                400
            );
        }

        $data = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : [];
        $execution_request_id = sanitize_text_field( (string) ( $data['execution_request_id'] ?? '' ) );
        if ( '' === $execution_request_id )
        {
            return $this->prepare_error_response(
                'sentient_forms_callback_missing_request_id',
                __( 'Callback execution request ID is required.', 'sentient-forms' ),
                400
            );
        }

        $header_request_id = sanitize_text_field( (string) $request->get_header( 'x-sentient-forms-execution-request-id' ) );
        if ( '' !== $header_request_id && $header_request_id !== $execution_request_id )
        {
            return $this->prepare_error_response(
                'sentient_forms_callback_request_id_mismatch',
                __( 'Callback request ID header does not match the payload.', 'sentient-forms' ),
                401
            );
        }

        $context = isset( $data['context'] ) && is_array( $data['context'] ) ? $data['context'] : [];
        $result  = isset( $data['result'] ) && is_array( $data['result'] ) ? $data['result'] : [];
        $status  = sanitize_key( (string) ( $data['status'] ?? '' ) );

        $async_handler = Sentient_Forms_Plugin::instance()->get_async_handler();
        if ( true === (bool) ( $payload['success'] ?? false ) && in_array( $status, [ 'succeeded', 'partial', 'success' ], true ) )
        {
            $async_handler->complete_remote_cps_async_success( $execution_request_id, $context, $result );
        }
        else
        {
            $error_payload = isset( $result['error'] ) && is_array( $result['error'] ) ? $result['error'] : [];
            $error_code    = sanitize_key( (string) ( $error_payload['code'] ?? 'sentient_forms_cps_async_failed' ) );
            $message       = sanitize_text_field(
                (string) ( $error_payload['message'] ?? __( 'CPS async execution failed.', 'sentient-forms' ) )
            );
            $async_handler->complete_remote_cps_async_failure(
                $execution_request_id,
                $context,
                new WP_Error( $error_code, $message )
            );
        }

        return $this->prepare_item_for_response(
            [
                'received'             => true,
                'execution_request_id' => $execution_request_id,
            ]
        );
    }

    private function verify_signature( WP_REST_Request $request, string $body ): true | WP_Error
    {
        $timestamp_raw = trim( (string) $request->get_header( 'x-sentient-forms-timestamp' ) );
        $signature     = trim( (string) $request->get_header( 'x-sentient-forms-signature' ) );
        $request_id    = trim( (string) $request->get_header( 'x-sentient-forms-execution-request-id' ) );

        if ( '' === $timestamp_raw || '' === $signature || '' === $request_id )
        {
            return $this->prepare_error_response(
                'sentient_forms_callback_missing_signature_headers',
                __( 'Signed callback headers are required.', 'sentient-forms' ),
                401
            );
        }

        if ( ! ctype_digit( $timestamp_raw ) )
        {
            return $this->prepare_error_response(
                'sentient_forms_callback_invalid_timestamp',
                __( 'Callback timestamp is invalid.', 'sentient-forms' ),
                401
            );
        }

        $timestamp = (int) $timestamp_raw;
        $window    = (int) apply_filters( 'sentient_forms_cps_callback_signature_window', 600 );
        if ( abs( time() - $timestamp ) > max( 60, $window ) )
        {
            return $this->prepare_error_response(
                'sentient_forms_callback_expired',
                __( 'Callback signature timestamp is outside the allowed window.', 'sentient-forms' ),
                401
            );
        }

        $proxy_api_key = Sentient_Forms_Plugin::instance()->get_proxy_api_key();
        if ( '' === trim( $proxy_api_key ) )
        {
            return $this->prepare_error_response(
                'sentient_forms_callback_proxy_key_missing',
                __( 'Proxy API key is not configured for callback verification.', 'sentient-forms' ),
                401
            );
        }

        $fingerprint = hash( 'sha256', $proxy_api_key );
        $expected    = hash_hmac( 'sha256', $timestamp . '.' . $request_id . '.' . $body, $fingerprint );
        if ( ! hash_equals( $expected, $signature ) )
        {
            return $this->prepare_error_response(
                'sentient_forms_callback_bad_signature',
                __( 'Callback signature could not be verified.', 'sentient-forms' ),
                401
            );
        }

        return true;
    }
}
