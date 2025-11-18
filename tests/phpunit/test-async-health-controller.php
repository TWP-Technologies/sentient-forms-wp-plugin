<?php

class AsyncHealthControllerTest extends WP_UnitTestCase
{
    public function test_rest_endpoint_requires_permission(): void
    {
        $request  = new WP_REST_Request( 'GET', '/sentient-forms/v1/async-health' );
        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( 401, $response->get_status() );
    }

    public function test_rest_endpoint_returns_payload_for_admin(): void
    {
        $user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $user_id );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/async-health' );
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertArrayHasKey( 'queue_depth', $data );
    }
}
