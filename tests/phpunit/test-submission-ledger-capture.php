<?php

class Tests_Submission_Ledger_Capture extends WP_UnitTestCase
{
    private wpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;
        $this->wpdb = $wpdb;

        Sentient_Forms_Installer::maybe_upgrade();
    }

    public function test_capture_refuses_to_store_logical_snapshot_when_ledger_is_disabled(): void
    {
        $service = new Sentient_Forms_Submission_Ledger_Capture_Service( $this->wpdb );
        $ledger  = new Sentient_Forms_Submission_Ledger_Repository( $this->wpdb );

        $result = $service->capture(
            [
                'form_source'     => 'gravity_forms',
                'form_id'         => '401',
                'native_entry_id' => '501',
                'logical_fields'  => [
                    'email'   => 'person@example.test',
                    'message' => 'Please contact me.',
                ],
            ]
        );

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_forms_submission_ledger_disabled', $result->get_error_code() );
        $this->assertSame( [], $ledger->list_for_form( 'gravity_forms', '401' ) );
    }

    public function test_capture_stores_redacted_logical_snapshot_and_file_references_when_enabled(): void
    {
        $settings        = new Sentient_Forms_Submission_Ledger_Settings_Repository( $this->wpdb );
        $service         = new Sentient_Forms_Submission_Ledger_Capture_Service( $this->wpdb );
        $ledger          = new Sentient_Forms_Submission_Ledger_Repository( $this->wpdb );
        $submission_uuid = wp_generate_uuid4();

        $settings->set_enabled( 'gravity_forms', '402', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $result = $service->capture(
            [
                'submission_uuid'     => $submission_uuid,
                'form_source'         => 'gravity_forms',
                'form_id'             => '402',
                'native_entry_id'     => '502',
                'logical_fields'      => [
                    'email'         => 'person@example.test',
                    'message'       => 'Please contact me.',
                    'captcha_token' => 'do-not-store',
                    'password'      => 'do-not-store',
                ],
                'provider_metadata'   => [
                    'entry_type'   => 'gravity_forms_entry',
                    'raw_request'  => [ 'secret' => 'do-not-store' ],
                ],
                'files'               => [
                    [
                        'field_id' => '9',
                        'filename' => 'proposal.pdf',
                        'tmp_name' => 'C:\\private\\proposal.pdf',
                        'contents' => 'binary-content',
                    ],
                ],
            ]
        );

        $this->assertIsArray( $result );
        $this->assertSame( $submission_uuid, $result['submission_uuid'] ?? null );

        $stored = $ledger->get_by_submission_uuid( $submission_uuid );
        $this->assertSame( 'person@example.test', $stored['logical_fields_json']['email'] ?? null );
        $this->assertSame( '[redacted]', $stored['logical_fields_json']['captcha_token'] ?? null );
        $this->assertSame( '[redacted]', $stored['logical_fields_json']['password'] ?? null );
        $this->assertSame( 'gravity_forms_entry', $stored['provider_metadata_json']['entry_type'] ?? null );
        $this->assertSame( '[redacted]', $stored['provider_metadata_json']['raw_request'] ?? null );
        $this->assertSame( 'proposal.pdf', $stored['file_refs_json'][0]['filename'] ?? null );
        $this->assertArrayNotHasKey( 'contents', $stored['file_refs_json'][0] ?? [] );
        $this->assertArrayNotHasKey( 'tmp_name', $stored['file_refs_json'][0] ?? [] );
        $this->assertContains( 'captcha_token', $stored['redaction_summary_json']['redacted_fields'] ?? [] );
        $this->assertContains( 'password', $stored['redaction_summary_json']['redacted_fields'] ?? [] );
        $this->assertContains( 'raw_request', $stored['redaction_summary_json']['redacted_fields'] ?? [] );
    }

    public function test_capture_generates_submission_uuid_when_payload_uuid_is_invalid(): void
    {
        $settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $this->wpdb );
        $service  = new Sentient_Forms_Submission_Ledger_Capture_Service( $this->wpdb );
        $ledger   = new Sentient_Forms_Submission_Ledger_Repository( $this->wpdb );

        $settings->set_enabled( 'gravity_forms', '403', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $result = $service->capture(
            [
                'submission_uuid' => 'not-a-uuid',
                'form_source'     => 'gravity_forms',
                'form_id'         => '403',
                'native_entry_id' => '503',
                'logical_fields'  => [
                    'email' => 'person@example.test',
                ],
            ]
        );

        $this->assertIsArray( $result );
        $this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $result['submission_uuid'] ?? '' );
        $this->assertNotSame( 'not-a-uuid', $result['submission_uuid'] ?? null );
        $this->assertNotNull( $ledger->get_by_submission_uuid( (string) $result['submission_uuid'] ) );
    }

    public function test_capture_rejects_reused_submission_uuid_for_a_different_native_submission(): void
    {
        $settings        = new Sentient_Forms_Submission_Ledger_Settings_Repository( $this->wpdb );
        $service         = new Sentient_Forms_Submission_Ledger_Capture_Service( $this->wpdb );
        $ledger          = new Sentient_Forms_Submission_Ledger_Repository( $this->wpdb );
        $submission_uuid = wp_generate_uuid4();

        $settings->set_enabled( 'gravity_forms', '404', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $first = $service->capture(
            [
                'submission_uuid' => $submission_uuid,
                'form_source'     => 'gravity_forms',
                'form_id'         => '404',
                'native_entry_id' => '504',
                'logical_fields'  => [
                    'email' => 'first@example.test',
                ],
            ]
        );
        $replay = $service->capture(
            [
                'submission_uuid' => $submission_uuid,
                'form_source'     => 'gravity_forms',
                'form_id'         => '404',
                'native_entry_id' => '505',
                'logical_fields'  => [
                    'email' => 'second@example.test',
                ],
            ]
        );

        $this->assertIsArray( $first );
        $this->assertWPError( $replay );
        $this->assertSame( 'sentient_forms_submission_ledger_replay_conflict', $replay->get_error_code() );

        $stored = $ledger->get_by_submission_uuid( $submission_uuid );
        $this->assertSame( '504', $stored['native_entry_id'] ?? null );
        $this->assertSame( 'first@example.test', $stored['logical_fields_json']['email'] ?? null );
        $this->assertCount( 1, $ledger->list_for_form( 'gravity_forms', '404' ) );
    }

    public function test_capture_preserves_json_serializable_structured_logical_values(): void
    {
        $settings        = new Sentient_Forms_Submission_Ledger_Settings_Repository( $this->wpdb );
        $service         = new Sentient_Forms_Submission_Ledger_Capture_Service( $this->wpdb );
        $ledger          = new Sentient_Forms_Submission_Ledger_Repository( $this->wpdb );
        $submission_uuid = wp_generate_uuid4();

        $settings->set_enabled( 'future_forms', 'structured-entry', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $address             = new stdClass();
        $address->street     = '100 Future Adapter Way';
        $address->city       = 'Austin';
        $address->csrf_token = 'do-not-store';

        $result = $service->capture(
            [
                'submission_uuid' => $submission_uuid,
                'form_source'     => 'future_forms',
                'form_id'         => 'structured-entry',
                'native_entry_id' => 'opaque-123',
                'logical_fields'  => [
                    'address' => $address,
                ],
            ]
        );

        $this->assertIsArray( $result );

        $stored = $ledger->get_by_submission_uuid( $submission_uuid );
        $this->assertSame( '100 Future Adapter Way', $stored['logical_fields_json']['address']['street'] ?? null );
        $this->assertSame( 'Austin', $stored['logical_fields_json']['address']['city'] ?? null );
        $this->assertSame( '[redacted]', $stored['logical_fields_json']['address']['csrf_token'] ?? null );
    }
}
