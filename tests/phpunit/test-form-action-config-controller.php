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
	private string $action_defaults_option_key = 'sentient_forms_action_defaults_spam_detection_v1';

	protected function setUp(): void {
		parent::setUp();
		$this->controller = new Sentient_Forms_Form_Action_Config_Controller();

		// Clean up any existing form config.
		delete_option( $this->option_key );
	}

	protected function tearDown(): void {
		delete_option( $this->option_key );
		delete_option( $this->action_defaults_option_key );
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

	public function test_update_action_config_stores_structured_model_selection(): void {
		$request = new WP_REST_Request( 'POST', '/sentient-forms/v1/forms/gravity_forms/999/action-config/summary_v1' );
		$request->set_param( 'form_source', 'gravity_forms' );
		$request->set_param( 'form_id', 999 );
		$request->set_param( 'action_id', 'summary_v1' );
		$request->set_param(
			'model_selection',
			[
				'primary'       => 'gemini-3-flash-preview',
				'backup'        => 'gemini-3-pro-preview',
				'is_preset'     => false,
				'provider'      => 'sentient_managed',
				'credential_id' => 123,
				'reasoning'     => 'high',
			]
		);

		$response = $this->controller->update_action_config( $request );
		$data     = $response->get_data();
		$stored   = get_option( $this->option_key, [] );

		$this->assertSame( 'gemini-3-flash-preview', $data['config']['model_selection']['primary'] );
		$this->assertSame( 'gemini-3-pro-preview', $data['config']['model_selection']['backup'] );
		$this->assertFalse( $data['config']['model_selection']['is_preset'] );
		$this->assertSame( 'sentient_managed', $data['config']['model_selection']['provider'] );
		$this->assertSame( 123, $data['config']['model_selection']['credential_id'] );
		$this->assertSame( 'high', $data['config']['model_selection']['reasoning'] );
		$this->assertSame( 'gemini-3-flash-preview', $stored['summary_v1']['model_selection']['primary'] );
		$this->assertSame( 'sentient_managed', $stored['summary_v1']['model_selection']['provider'] );
		$this->assertSame( 123, $stored['summary_v1']['model_selection']['credential_id'] );
		$this->assertArrayNotHasKey( 'model_override', $stored['summary_v1'] );
	}

	public function test_update_action_config_stores_spam_policy_booleans(): void {
		$request = new WP_REST_Request( 'POST', '/sentient-forms/v1/forms/gravity_forms/999/action-config/spam_detection_v1' );
		$request->set_param( 'form_source', 'gravity_forms' );
		$request->set_param( 'form_id', 999 );
		$request->set_param( 'action_id', 'spam_detection_v1' );
		$request->set_param( 'suppress_notifications_on_spam', false );
		$request->set_param( 'skip_downstream_on_spam', true );

		$response = $this->controller->update_action_config( $request );
		$data     = $response->get_data();
		$stored   = get_option( $this->option_key, [] );

		$this->assertFalse( $data['config']['suppress_notifications_on_spam'] ?? true );
		$this->assertTrue( $data['config']['skip_downstream_on_spam'] ?? false );
		$this->assertFalse( $stored['spam_detection_v1']['suppress_notifications_on_spam'] ?? true );
		$this->assertTrue( $stored['spam_detection_v1']['skip_downstream_on_spam'] ?? false );
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

	public function test_get_action_config_normalizes_legacy_model_override_to_model_selection(): void {
		update_option(
			$this->option_key,
			[
				'summary_v1' => [
					'model_override' => 'sf_fast',
				],
			]
		);

		$request = new WP_REST_Request( 'GET', '/sentient-forms/v1/forms/gravity_forms/999/action-config/summary_v1' );
		$request->set_param( 'form_source', 'gravity_forms' );
		$request->set_param( 'form_id', 999 );
		$request->set_param( 'action_id', 'summary_v1' );

		$response = $this->controller->get_action_config( $request );
		$data     = $response->get_data();

		$this->assertSame( 'sf_fast', $data['config']['model_selection']['primary'] );
		$this->assertTrue( $data['config']['model_selection']['is_preset'] );
	}

	public function test_get_action_config_restores_canonical_spam_note_settings(): void {
		update_option(
			$this->option_key,
			[
				'spam_detection_v1' => [
					'spam_result_display_mode' => 'entry_note',
					'spam_indicators_display'  => 'verbose',
				],
			]
		);

		$request = new WP_REST_Request( 'GET', '/sentient-forms/v1/forms/gravity_forms/999/action-config/spam_detection_v1' );
		$request->set_param( 'form_source', 'gravity_forms' );
		$request->set_param( 'form_id', 999 );
		$request->set_param( 'action_id', 'spam_detection_v1' );

		$response = $this->controller->get_action_config( $request );
		$data     = $response->get_data();

		$this->assertSame( 'all_results', $data['config']['spam_result_display_mode'] ?? null );
		$this->assertSame( 'simple', $data['config']['spam_indicators_display'] ?? null );
	}

	public function test_update_action_defaults_stores_spam_policy_booleans(): void {
		$request = new WP_REST_Request( 'POST', '/sentient-forms/v1/actions/spam_detection_v1/defaults' );
		$request->set_param( 'action_id', 'spam_detection_v1' );
		$request->set_param( 'suppress_notifications_on_spam', true );
		$request->set_param( 'skip_downstream_on_spam', false );

		$response = $this->controller->update_action_defaults( $request );
		$data     = $response->get_data();
		$stored   = get_option( $this->action_defaults_option_key, [] );

		$this->assertTrue( $data['config']['suppress_notifications_on_spam'] ?? false );
		$this->assertFalse( $data['config']['skip_downstream_on_spam'] ?? true );
		$this->assertTrue( $stored['suppress_notifications_on_spam'] ?? false );
		$this->assertFalse( $stored['skip_downstream_on_spam'] ?? true );
	}

	public function test_update_action_defaults_stores_spam_note_controls(): void {
		$request = new WP_REST_Request( 'POST', '/sentient-forms/v1/actions/spam_detection_v1/defaults' );
		$request->set_param( 'action_id', 'spam_detection_v1' );
		$request->set_param( 'spam_result_display_mode', 'entry_note' );
		$request->set_param( 'spam_indicators_display', 'detailed' );

		$response = $this->controller->update_action_defaults( $request );
		$data     = $response->get_data();
		$stored   = get_option( $this->action_defaults_option_key, [] );

		$this->assertSame( 'all_results', $data['config']['spam_result_display_mode'] ?? null );
		$this->assertSame( 'detailed', $data['config']['spam_indicators_display'] ?? null );
		$this->assertSame( 'entry_note', $stored['spam_result_display_mode'] ?? null );
		$this->assertSame( 'detailed', $stored['spam_indicators_display'] ?? null );
	}

	public function test_get_action_defaults_restores_canonical_spam_note_settings(): void {
		update_option(
			$this->action_defaults_option_key,
			[
				'spam_result_display_mode' => 'silent',
				'spam_indicators_display'  => 'detailed',
			]
		);

		$request = new WP_REST_Request( 'GET', '/sentient-forms/v1/actions/spam_detection_v1/defaults' );
		$request->set_param( 'action_id', 'spam_detection_v1' );

		$response = $this->controller->get_action_defaults( $request );
		$data     = $response->get_data();

		$this->assertSame( 'none', $data['config']['spam_result_display_mode'] ?? null );
		$this->assertSame( 'detailed', $data['config']['spam_indicators_display'] ?? null );
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
