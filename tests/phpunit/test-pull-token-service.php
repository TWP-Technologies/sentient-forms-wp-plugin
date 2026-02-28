<?php

class PullTokenServiceTest extends WP_UnitTestCase {
	private Sentient_Forms_Plugin $plugin;

	protected function setUp(): void {
		parent::setUp();
		$this->plugin = Sentient_Forms_Plugin::instance();
		$this->plugin->set_license_data(
			[
				'license_id'    => '9b2fec4b-16f0-405b-b2df-95e4380de730',
				'site_id'       => '96e7f03e-bca7-4964-82a9-93a32addaf27',
				'proxy_api_key' => 'proxy-security-token',
			]
		);
	}

	public function test_issue_and_consume_token_allows_single_use_only(): void {
		$service = new Sentient_Forms_Pull_Token_Service( $this->plugin );
		$issued  = $service->issue_token(
			[
				'file_ref_id'            => wp_generate_uuid4(),
				'source_type'            => 'media',
				'locator_type'           => 'absolute_path',
				'locator_value'          => '/tmp/fake.pdf',
				'filename'               => 'fake.pdf',
				'content_type'           => 'application/pdf',
				'size_bytes'             => 100,
				'license_id'             => '9b2fec4b-16f0-405b-b2df-95e4380de730',
				'site_id'                => '96e7f03e-bca7-4964-82a9-93a32addaf27',
				'local_site_identifier'  => $this->plugin->get_local_site_identifier(),
				'execution_request_id'   => 'req-1',
			]
		);

		$this->assertNotEmpty( $issued['token'] ?? '' );
		$this->assertNotEmpty( $issued['expires_at'] ?? '' );

		$first = $service->consume_token( (string) $issued['token'] );
		$this->assertIsArray( $first );
		$this->assertSame( 'application/pdf', $first['content_type'] ?? null );

		$second = $service->consume_token( (string) $issued['token'] );
		$this->assertWPError( $second );
		$this->assertSame( 'sf_pull_unauthorized', $second->get_error_code() );
	}

	public function test_consume_token_rejects_tampered_signature(): void {
		$service = new Sentient_Forms_Pull_Token_Service( $this->plugin );
		$issued  = $service->issue_token(
			[
				'file_ref_id'            => wp_generate_uuid4(),
				'source_type'            => 'media',
				'locator_type'           => 'absolute_path',
				'locator_value'          => '/tmp/fake.pdf',
				'filename'               => 'fake.pdf',
				'content_type'           => 'application/pdf',
				'size_bytes'             => 100,
				'license_id'             => '9b2fec4b-16f0-405b-b2df-95e4380de730',
				'site_id'                => '96e7f03e-bca7-4964-82a9-93a32addaf27',
				'local_site_identifier'  => $this->plugin->get_local_site_identifier(),
				'execution_request_id'   => 'req-1',
			]
		);

		$tampered = substr( (string) $issued['token'], 0, -1 ) . 'x';
		$result   = $service->consume_token( $tampered );
		$this->assertWPError( $result );
		$this->assertSame( 'sf_pull_unauthorized', $result->get_error_code() );
	}

	public function test_consume_token_rejects_expired_ticket_record(): void {
		$service = new Sentient_Forms_Pull_Token_Service( $this->plugin );
		$issued  = $service->issue_token(
			[
				'file_ref_id'            => wp_generate_uuid4(),
				'source_type'            => 'media',
				'locator_type'           => 'absolute_path',
				'locator_value'          => '/tmp/fake.pdf',
				'filename'               => 'fake.pdf',
				'content_type'           => 'application/pdf',
				'size_bytes'             => 100,
				'license_id'             => '9b2fec4b-16f0-405b-b2df-95e4380de730',
				'site_id'                => '96e7f03e-bca7-4964-82a9-93a32addaf27',
				'local_site_identifier'  => $this->plugin->get_local_site_identifier(),
				'execution_request_id'   => 'req-1',
			]
		);

		$token_parts = explode( '.', (string) $issued['token'], 2 );
		$this->assertCount( 2, $token_parts );
		$payload = json_decode( $this->base64url_decode( $token_parts[0] ), true );
		$this->assertIsArray( $payload );
		$token_id = (string) ( $payload['tid'] ?? '' );
		$this->assertNotSame( '', $token_id );

		$transient_key = 'sentient_forms_pull_ticket_' . md5( $token_id );
		$ticket        = get_transient( $transient_key );
		$this->assertIsArray( $ticket );
		$ticket['expires_at_epoch'] = time() - 999;
		set_transient( $transient_key, $ticket, 60 );

		$result = $service->consume_token( (string) $issued['token'] );
		$this->assertWPError( $result );
		$this->assertSame( 'sf_pull_unauthorized', $result->get_error_code() );
	}

	public function test_consume_token_rejects_site_context_mismatch(): void {
		$service = new Sentient_Forms_Pull_Token_Service( $this->plugin );
		$issued  = $service->issue_token(
			[
				'file_ref_id'            => wp_generate_uuid4(),
				'source_type'            => 'media',
				'locator_type'           => 'absolute_path',
				'locator_value'          => '/tmp/fake.pdf',
				'filename'               => 'fake.pdf',
				'content_type'           => 'application/pdf',
				'size_bytes'             => 100,
				'license_id'             => '9b2fec4b-16f0-405b-b2df-95e4380de730',
				'site_id'                => '96e7f03e-bca7-4964-82a9-93a32addaf27',
				'local_site_identifier'  => $this->plugin->get_local_site_identifier(),
				'execution_request_id'   => 'req-1',
			]
		);

		$this->plugin->set_license_data(
			[
				'license_id' => '9b2fec4b-16f0-405b-b2df-95e4380de730',
				'site_id'    => 'ffffffff-ffff-ffff-ffff-ffffffffffff',
			]
		);

		$result = $service->consume_token( (string) $issued['token'] );
		$this->assertWPError( $result );
		$this->assertSame( 'sf_pull_unauthorized', $result->get_error_code() );
	}

	private function base64url_decode( string $value ): string {
		$padded = $value;
		$remainder = strlen( $padded ) % 4;
		if ( 0 !== $remainder ) {
			$padded .= str_repeat( '=', 4 - $remainder );
		}

		$decoded = base64_decode( strtr( $padded, '-_', '+/' ), true );
		return is_string( $decoded ) ? $decoded : '';
	}
}
