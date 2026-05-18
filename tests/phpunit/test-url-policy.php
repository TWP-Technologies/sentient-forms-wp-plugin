<?php

class Tests_Url_Policy extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        add_filter( 'sentient_forms_allow_insecure_outbound_url', '__return_false', PHP_INT_MAX );
    }

    protected function tearDown(): void
    {
        remove_filter( 'sentient_forms_allow_insecure_outbound_url', '__return_false', PHP_INT_MAX );
        remove_all_filters( 'sentient_forms_trusted_service_hosts' );
        parent::tearDown();
    }

    public function test_service_urls_must_use_trusted_public_hosts(): void
    {
        $this->assertSame(
            'https://api.sentientforms.com/v2/health',
            Sentient_Forms_Url_Policy::validate_outbound_url( 'https://api.sentientforms.com/v2/health', 'service' )
        );

        $private = Sentient_Forms_Url_Policy::validate_outbound_url( 'https://127.0.0.1:8080/v1/health', 'service' );
        $this->assertInstanceOf( WP_Error::class, $private );
        $this->assertSame( 'sentient_forms_outbound_url_private_host', $private->get_error_code() );

        $untrusted = Sentient_Forms_Url_Policy::validate_outbound_url( 'https://example.com/api', 'service' );
        $this->assertInstanceOf( WP_Error::class, $untrusted );
        $this->assertSame( 'sentient_forms_untrusted_service_host', $untrusted->get_error_code() );
    }

    public function test_service_urls_require_https_even_for_trusted_hosts(): void
    {
        $plaintext = Sentient_Forms_Url_Policy::validate_outbound_url( 'http://api.sentientforms.com/v1/health', 'service' );

        $this->assertInstanceOf( WP_Error::class, $plaintext );
        $this->assertSame( 'sentient_forms_service_url_requires_https', $plaintext->get_error_code() );
    }

    public function test_webhook_urls_allow_public_arbitrary_hosts_but_reject_private_targets(): void
    {
        $this->assertSame(
            'https://hooks.example.com/sentient',
            Sentient_Forms_Url_Policy::validate_outbound_url( 'https://hooks.example.com/sentient', 'webhook' )
        );

        $private = Sentient_Forms_Url_Policy::validate_outbound_url( 'http://10.0.0.5/hook', 'webhook' );
        $this->assertInstanceOf( WP_Error::class, $private );
        $this->assertSame( 'sentient_forms_outbound_url_private_host', $private->get_error_code() );
    }

    public function test_development_filter_can_allow_local_service_urls(): void
    {
        remove_filter( 'sentient_forms_allow_insecure_outbound_url', '__return_false', PHP_INT_MAX );
        add_filter( 'sentient_forms_allow_insecure_outbound_url', '__return_true', PHP_INT_MAX );

        $this->assertSame(
            'http://127.0.0.1:3000/v1/health',
            Sentient_Forms_Url_Policy::validate_outbound_url( 'http://127.0.0.1:3000/v1/health', 'service' )
        );

        remove_filter( 'sentient_forms_allow_insecure_outbound_url', '__return_true', PHP_INT_MAX );
        add_filter( 'sentient_forms_allow_insecure_outbound_url', '__return_false', PHP_INT_MAX );
    }

    public function test_development_filter_uses_normal_http_transport_for_local_urls(): void
    {
        remove_filter( 'sentient_forms_allow_insecure_outbound_url', '__return_false', PHP_INT_MAX );
        add_filter( 'sentient_forms_allow_insecure_outbound_url', '__return_true', PHP_INT_MAX );

        $seen_args = null;
        $mock      = static function ( $preempt, array $args, string $url ) use ( &$seen_args ) {
            $seen_args = $args;

            return [
                'headers'  => [],
                'body'     => '{"success":true}',
                'response' => [
                    'code'    => 200,
                    'message' => 'OK',
                ],
                'cookies'  => [],
                'filename' => null,
            ];
        };

        add_filter( 'pre_http_request', $mock, 10, 3 );

        try
        {
            $response = Sentient_Forms_Url_Policy::remote_request( 'http://127.0.0.1:3000/v1/health', [], 'service' );

            $this->assertIsArray( $response );
            $this->assertIsArray( $seen_args );
            $this->assertFalse( $seen_args['reject_unsafe_urls'] );
        }
        finally
        {
            remove_filter( 'pre_http_request', $mock, 10 );
            remove_filter( 'sentient_forms_allow_insecure_outbound_url', '__return_true', PHP_INT_MAX );
            add_filter( 'sentient_forms_allow_insecure_outbound_url', '__return_false', PHP_INT_MAX );
        }
    }
}
