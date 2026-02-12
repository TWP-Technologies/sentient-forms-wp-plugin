<?php
/**
 * Tests for Form Action Config Controller
 *
 * Tests the form-level action configuration storage for hierarchical spam examples.
 *
 * @package Sentient_Forms
 */

class Tests_Form_Action_Config_Controller extends WP_UnitTestCase {
	private Sentient_Forms_Form_Action_Config_Controller $controller;
	private string $option_key = 'sentient_forms_form_config_gravity_forms_999';

	protected function setUp(): void {
		parent::setUp();
		$this->controller = new Sentient_Forms_Form_Action_Config_Controller();

		// Clean up any existing form config.
		delete_option( $this->option_key );
	}

	protected function tearDown(): void {
		delete_option( $this->option_key );
		parent::tearDown();
	}

	public function test_get_form_configs_returns_empty_array_when_no_config(): void {
		$request = new WP_REST_Request( 'GET', '/sentient-forms/v1/forms/gravity_forms/999/action-config' );
		$request->set_param( 'form_source', 'gravity_forms' );
		$request->set_param( 'form_id', 999 );

		$response = $this->controller->get_form_configs( $request );
		$data     = $response->get_data();

		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'configs', $data );
		$this->assertEmpty( $data['configs'] );
	}

	public function test_update_action_config_stores_spam_examples(): void {
		$request = new WP_REST_Request( 'POST', '/sentient-forms/v1/forms/gravity_forms/999/action-config/spam_detection_v1' );
		$request->set_param( 'form_source', 'gravity_forms' );
		$request->set_param( 'form_id', 999 );
		$request->set_param( 'action_id', 'spam_detection_v1' );
		$request->set_param( 'spam_positive_examples', [ 'Legitimate inquiry', 'Need assistance' ] );
		$request->set_param( 'spam_negative_examples', [ 'Buy now!!!', 'Free crypto' ] );

		$response = $this->controller->update_action_config( $request );
		$data     = $response->get_data();

		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'config', $data );
		$this->assertArrayHasKey( 'updated_at', $data['config'] );

		// Verify persistence.
		$stored = get_option( $this->option_key, [] );
		$this->assertArrayHasKey( 'spam_detection_v1', $stored );
		$this->assertSame(
			[ 'Legitimate inquiry', 'Need assistance' ],
			$stored['spam_detection_v1']['spam_positive_examples']
		);
	}

	public function test_update_action_config_merges_with_existing_actions(): void {
		// Pre-populate with existing config.
		update_option( $this->option_key, [
			'spam_detection_v1' => [
				'spam_positive_examples' => [ 'Original positive' ],
			],
			'summary_v1' => [
				'some_setting' => 'value',
			],
		] );

		$request = new WP_REST_Request( 'POST', '/sentient-forms/v1/forms/gravity_forms/999/action-config/spam_detection_v1' );
		$request->set_param( 'form_source', 'gravity_forms' );
		$request->set_param( 'form_id', 999 );
		$request->set_param( 'action_id', 'spam_detection_v1' );
		$request->set_param( 'spam_positive_examples', [ 'Updated positive' ] );
		$request->set_param( 'spam_negative_examples', [ 'New negative' ] );

		$this->controller->update_action_config( $request );

		// Verify the update worked.
		$stored = get_option( $this->option_key, [] );

		// spam_detection_v1 should be updated.
		$this->assertSame(
			[ 'Updated positive' ],
			$stored['spam_detection_v1']['spam_positive_examples']
		);
		$this->assertSame(
			[ 'New negative' ],
			$stored['spam_detection_v1']['spam_negative_examples']
		);

		// summary_v1 should be preserved.
		$this->assertArrayHasKey( 'summary_v1', $stored );
		$this->assertSame( 'value', $stored['summary_v1']['some_setting'] );
	}

	public function test_get_action_config_returns_action_specific_config(): void {
		$config = [
			'spam_detection_v1' => [
				'spam_positive_examples' => [ 'Example 1', 'Example 2' ],
				'spam_negative_examples' => [ 'Spam 1' ],
			],
		];
		update_option( $this->option_key, $config );

		$request = new WP_REST_Request( 'GET', '/sentient-forms/v1/forms/gravity_forms/999/action-config/spam_detection_v1' );
		$request->set_param( 'form_source', 'gravity_forms' );
		$request->set_param( 'form_id', 999 );
		$request->set_param( 'action_id', 'spam_detection_v1' );

		$response = $this->controller->get_action_config( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'config', $data );
		$this->assertSame(
			[ 'Example 1', 'Example 2' ],
			$data['config']['spam_positive_examples']
		);
	}

	public function test_delete_action_config_removes_only_target_action(): void {
		update_option( $this->option_key, [
			'spam_detection_v1' => [
				'spam_positive_examples' => [ 'Keep me deleted' ],
			],
			'summary_v1' => [
				'some_setting' => 'keep me',
			],
		] );

		$request = new WP_REST_Request( 'DELETE', '/sentient-forms/v1/forms/gravity_forms/999/action-config/spam_detection_v1' );
		$request->set_param( 'form_source', 'gravity_forms' );
		$request->set_param( 'form_id', 999 );
		$request->set_param( 'action_id', 'spam_detection_v1' );

		$response = $this->controller->delete_action_config( $request );
		$data     = $response->get_data();
		$stored   = get_option( $this->option_key, [] );

		$this->assertTrue( (bool) ( $data['deleted'] ?? false ) );
		$this->assertArrayNotHasKey( 'spam_detection_v1', $stored );
		$this->assertArrayHasKey( 'summary_v1', $stored );
	}
}
