<?php

class RestPermissionTestDouble
{
    use Trait_Sentient_Forms_Permission_Utils;
}

class RestNonceIntegrationTest extends WP_UnitTestCase
{
    protected static $admin_id;
    private RestPermissionTestDouble $permission;

    public static function wpSetUpBeforeClass( $factory ): void
    {
        self::$admin_id = $factory->user->create( [ 'role' => 'administrator' ] );
    }

    public function setUp(): void
    {
        parent::setUp();
        wp_set_current_user( self::$admin_id );
        update_option( 'sentient_forms_settings', [ 'enforce_nonce_verification' => true ] );
        $this->permission = new RestPermissionTestDouble();
    }

    public function tearDown(): void
    {
        update_option( 'sentient_forms_settings', [] );
        parent::tearDown();
    }

    public function test_missing_nonce_rejected(): void
    {
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/settings' );
        $allowed = $this->permission->permission_callback_with_nonce( $request );
        $this->assertFalse( $allowed );
    }

    public function test_valid_nonce_allows_request(): void
    {
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/settings' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $allowed = $this->permission->permission_callback_with_nonce( $request );
        $this->assertTrue( $allowed );
    }

    public function test_flag_disables_nonce_check(): void
    {
        update_option( 'sentient_forms_settings', [ 'enforce_nonce_verification' => false ] );
        $request  = new WP_REST_Request( 'POST', '/sentient-forms/v1/settings' );
        $allowed = $this->permission->permission_callback_with_nonce( $request );
        $this->assertTrue( $allowed );
    }
}
