<?php
/**
 * REST API controller for signed attachment pull URLs.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sentient_Forms_Files_Controller extends Sentient_Forms_Abstract_Base_Controller {
	protected string $rest_base = 'files';

	private Sentient_Forms_Pull_Token_Service $pull_token_service;

	public function __construct() {
		parent::__construct();
		$this->pull_token_service = new Sentient_Forms_Pull_Token_Service( Sentient_Forms_Plugin::instance() );
		add_filter( 'rest_pre_serve_request', array( $this, 'maybe_stream_pull_file' ), 10, 4 );
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/pull/(?P<token>[A-Za-z0-9\\-_.]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'pull_file' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'token' => array(
							'description' => __( 'Signed pull token.', 'sentient-forms' ),
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
			)
		);
	}

	public function pull_file( WP_REST_Request $request ): WP_Error | WP_REST_Response {
		$token = sanitize_text_field( (string) $request->get_param( 'token' ) );
		$ticket = $this->pull_token_service->consume_token( $token );
		if ( is_wp_error( $ticket ) ) {
			$status = (int) ( $ticket->get_error_data()['status'] ?? 401 );
			return $this->prepare_error_response(
				'sf_pull_unauthorized',
				__( 'Unauthorized pull token.', 'sentient-forms' ),
				$status
			);
		}

		$path = isset( $ticket['locator_value'] ) ? sanitize_text_field( (string) $ticket['locator_value'] ) : '';
		if ( '' === $path || ! is_readable( $path ) || ! is_file( $path ) ) {
			return $this->prepare_error_response(
				'sf_pull_not_found',
				__( 'Requested file could not be found.', 'sentient-forms' ),
				404
			);
		}

		$response = new WP_REST_Response(
			array(
				'__sf_file_stream' => array(
					'path'         => $path,
					'filename'     => sanitize_file_name( (string) ( $ticket['filename'] ?? wp_basename( $path ) ) ),
					'content_type' => sanitize_text_field( (string) ( $ticket['content_type'] ?? 'application/octet-stream' ) ),
					'size_bytes'   => max( 0, (int) ( $ticket['size_bytes'] ?? @filesize( $path ) ) ),
				),
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );

		return $response;
	}

	/**
	 * @param bool             $served
	 * @param WP_HTTP_Response $result
	 */
	public function maybe_stream_pull_file( $served, $result, $request, $server ): bool {
		if ( $served ) {
			return true;
		}

		if ( ! $request instanceof WP_REST_Request ) {
			return (bool) $served;
		}

		$route = $request->get_route();
		if ( ! is_string( $route ) || ! str_starts_with( $route, '/' . $this->namespace . '/files/pull/' ) ) {
			return (bool) $served;
		}

		if ( ! $result instanceof WP_HTTP_Response ) {
			return (bool) $served;
		}

		$data = $result->get_data();
		if ( ! is_array( $data ) || ! isset( $data['__sf_file_stream'] ) || ! is_array( $data['__sf_file_stream'] ) ) {
			return (bool) $served;
		}

		$stream = $data['__sf_file_stream'];
		$path   = isset( $stream['path'] ) ? (string) $stream['path'] : '';
		if ( '' === $path || ! is_readable( $path ) || ! is_file( $path ) ) {
			status_header( 404 );
			echo wp_json_encode(
				array(
					'code'    => 'sf_pull_not_found',
					'message' => __( 'Requested file could not be found.', 'sentient-forms' ),
				)
			);
			return true;
		}

		$content_type = isset( $stream['content_type'] ) ? (string) $stream['content_type'] : 'application/octet-stream';
		$filename     = isset( $stream['filename'] ) ? sanitize_file_name( (string) $stream['filename'] ) : wp_basename( $path );
		$size_bytes   = isset( $stream['size_bytes'] ) ? (int) $stream['size_bytes'] : 0;
		if ( $size_bytes <= 0 ) {
			$size_bytes = (int) @filesize( $path );
		}

		nocache_headers();
		header( 'Content-Type: ' . $content_type );
		if ( $size_bytes > 0 ) {
			header( 'Content-Length: ' . $size_bytes );
		}
		header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $filename ) . '"' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'X-Content-Type-Options: nosniff' );

		if ( 'HEAD' === $request->get_method() ) {
			return true;
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		global $wp_filesystem;
		if ( ! WP_Filesystem() || ! $wp_filesystem ) {
			status_header( 500 );
			echo wp_json_encode(
				array(
					'code'    => 'sf_pull_stream_failed',
					'message' => __( 'Unable to stream the requested file.', 'sentient-forms' ),
				)
			);
			return true;
		}

		$content = $wp_filesystem->get_contents( $path );
		if ( false === $content ) {
			status_header( 500 );
			echo wp_json_encode(
				array(
					'code'    => 'sf_pull_stream_failed',
					'message' => __( 'Unable to stream the requested file.', 'sentient-forms' ),
				)
			);
			return true;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Authorized binary attachment content must be streamed as-is.
		echo $content;

		return true;
	}
}
