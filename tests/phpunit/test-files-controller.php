<?php

class FilesControllerTest extends WP_UnitTestCase {
	private Sentient_Forms_Plugin $plugin;

	protected function setUp(): void {
		parent::setUp();
		$this->plugin = Sentient_Forms_Plugin::instance();
		$this->plugin->set_license_data(
			[
				'license_id'    => '9b2fec4b-16f0-405b-b2df-95e4380de730',
				'site_id'       => '96e7f03e-bca7-4964-82a9-93a32addaf27',
				'proxy_api_key' => 'proxy-files-controller',
			]
		);
	}

	public function test_pull_file_rejects_invalid_token(): void {
		$controller = new Sentient_Forms_Files_Controller();
		$request    = new WP_REST_Request( 'GET', '/sentient-forms/v1/files/pull/bad-token' );
		$request->set_param( 'token', 'bad-token' );

		$response = $controller->pull_file( $request );
		$this->assertWPError( $response );
		$this->assertSame( 'sf_pull_unauthorized', $response->get_error_code() );
	}

	public function test_pull_file_returns_stream_marker_for_valid_ticket(): void {
		$tmp_file = wp_tempnam( 'sentient-forms-pull' );
		file_put_contents( $tmp_file, 'hello attachment' );

		$service = new Sentient_Forms_Pull_Token_Service( $this->plugin );
		$issued  = $service->issue_token(
			[
				'file_ref_id'            => wp_generate_uuid4(),
				'source_type'            => 'media',
				'locator_type'           => 'absolute_path',
				'locator_value'          => $tmp_file,
				'filename'               => 'attachment.txt',
				'content_type'           => 'text/plain',
				'size_bytes'             => filesize( $tmp_file ),
				'license_id'             => '9b2fec4b-16f0-405b-b2df-95e4380de730',
				'site_id'                => '96e7f03e-bca7-4964-82a9-93a32addaf27',
				'local_site_identifier'  => $this->plugin->get_local_site_identifier(),
				'execution_request_id'   => 'req-files-controller',
			]
		);

		$controller = new Sentient_Forms_Files_Controller();
		$request    = new WP_REST_Request( 'GET', '/sentient-forms/v1/files/pull/' . $issued['token'] );
		$request->set_param( 'token', $issued['token'] );

		$response = $controller->pull_file( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( '__sf_file_stream', $data );
		$this->assertSame( 'text/plain', $data['__sf_file_stream']['content_type'] ?? null );

		@unlink( $tmp_file );
	}
}
