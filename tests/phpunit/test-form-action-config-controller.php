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

	protected function setUp(): void {
		parent::setUp();
		$this->controller = new Sentient_Forms_Form_Action_Config_Controller();

		// Clean up any existing form config
		delete_option( 'sentient_forms_form_config_gravity_forms_999' );
	}

	protected function tearDown(): void {
		delete_option( 'sentient_forms_form_config_gravity_forms_999' );
		parent::tearDown();
	}

	public function test_get_form_config_returns_empty_array_when_no_config(): void {
		$request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/999/config' );
		$request->set_param( 'form_source_slug', 'gravity_forms' );
		$request->set_param( 'form_id', 999 );

		$response = $this->controller->get_form_config( $request );
		$data     = $response->get_data();

		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'config', $data );
		$this->assertEmpty( $data['config'] );
	}

	public function test_update_form_config_stores_spam_examples(): void {
		$request = new WP_REST_Request( 'PUT', '/sentient-forms/v1/gravity_forms/forms/999/config' );
		$request->set_param( 'form_source_slug', 'gravity_forms' );
		$request->set_param( 'form_id', 999 );
		$request->set_body_params([
			'config' => [
				'spam_detection_v1' => [
					'spam_positive_examples' => [ 'Legitimate inquiry', 'Need assistance' ],
					'spam_negative_examples' => [ 'Buy now!!!', 'Free crypto' ],
				],
			],
		]);

		$response = $this->controller->update_form_config( $request );
		$data     = $response->get_data();

		$this->assertIsArray( $data );
		$this->assertTrue( $data['success'] ?? false );

		// Verify persistence
		$stored = get_option( 'sentient_forms_form_config_gravity_forms_999', [] );
		$this->assertArrayHasKey( 'spam_detection_v1', $stored );
		$this->assertSame(
			[ 'Legitimate inquiry', 'Need assistance' ],
			$stored['spam_detection_v1']['spam_positive_examples']
		);
	}

	public function test_update_form_config_merges_with_existing(): void {
		// Pre-populate with existing config
		update_option( 'sentient_forms_form_config_gravity_forms_999', [
			'spam_detection_v1' => [
				'spam_positive_examples' => [ 'Original positive' ],
			],
			'summary_v1' => [
				'some_setting' => 'value',
			],
		]);

		$request = new WP_REST_Request( 'PUT', '/sentient-forms/v1/gravity_forms/forms/999/config' );
		$request->set_param( 'form_source_slug', 'gravity_forms' );
		$request->set_param( 'form_id', 999 );
		$request->set_body_params([
			'config' => [
				'spam_detection_v1' => [
					'spam_positive_examples' => [ 'Updated positive' ],
					'spam_negative_examples' => [ 'New negative' ],
				],
			],
		]);

		$response = $this->controller->update_form_config( $request );

		// Verify the update worked
		$stored = get_option( 'sentient_forms_form_config_gravity_forms_999', [] );

		// spam_detection_v1 should be updated
		$this->assertSame(
			[ 'Updated positive' ],
			$stored['spam_detection_v1']['spam_positive_examples']
		);
		$this->assertSame(
			[ 'New negative' ],
			$stored['spam_detection_v1']['spam_negative_examples']
		);

		// summary_v1 should be preserved
		$this->assertArrayHasKey( 'summary_v1', $stored );
		$this->assertSame( 'value', $stored['summary_v1']['some_setting'] );
	}

	public function test_get_form_config_returns_action_specific_config(): void {
		$config = [
			'spam_detection_v1' => [
				'spam_positive_examples' => [ 'Example 1', 'Example 2' ],
				'spam_negative_examples' => [ 'Spam 1' ],
			],
		];
		update_option( 'sentient_forms_form_config_gravity_forms_999', $config );

		$request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/999/config' );
		$request->set_param( 'form_source_slug', 'gravity_forms' );
		$request->set_param( 'form_id', 999 );
		$request->set_param( 'action_id', 'spam_detection_v1' );

		$response = $this->controller->get_form_config( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'config', $data );
		$this->assertArrayHasKey( 'spam_detection_v1', $data['config'] );
		$this->assertSame(
			[ 'Example 1', 'Example 2' ],
			$data['config']['spam_detection_v1']['spam_positive_examples']
		);
	}

	public function test_option_key_is_properly_sanitized(): void {
		$request = new WP_REST_Request( 'PUT', '/sentient-forms/v1/gravity_forms/forms/999/config' );
		$request->set_param( 'form_source_slug', 'Gravity_Forms' ); // Mixed case
		$request->set_param( 'form_id', 999 );
		$request->set_body_params([
			'config' => [
				'spam_detection_v1' => [
					'spam_positive_examples' => [ 'Test' ],
				],
			],
		]);

		$this->controller->update_form_config( $request );

		// Should be stored with sanitized key (lowercase)
		$stored = get_option( 'sentient_forms_form_config_gravity_forms_999', [] );
		$this->assertNotEmpty( $stored );
	}
}
