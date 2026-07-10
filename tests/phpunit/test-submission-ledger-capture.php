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
        delete_option( 'sentient_forms_submission_ledger_retention_days' );
    }

    protected function tearDown(): void
    {
        delete_option( 'sentient_forms_submission_ledger_retention_days' );

        parent::tearDown();
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

    public function test_capture_reuses_legacy_uuid_for_an_existing_native_submission(): void
    {
        $settings           = new Sentient_Forms_Submission_Ledger_Settings_Repository( $this->wpdb );
        $service            = new Sentient_Forms_Submission_Ledger_Capture_Service( $this->wpdb );
        $ledger             = new Sentient_Forms_Submission_Ledger_Repository( $this->wpdb );
        $legacy_uuid        = wp_generate_uuid4();
        $deterministic_uuid = wp_generate_uuid4();

        $settings->set_enabled( 'gravity_forms', '405', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $now     = current_time( 'mysql', true );
        $created = $this->wpdb->insert(
            $this->wpdb->prefix . 'sentient_submission_ledger',
            [
                'submission_uuid'        => $legacy_uuid,
                'form_source'            => 'gravity_forms',
                'form_id'                => '405',
                'native_entry_id'        => 'legacy-native-505',
                'captured_at'            => $now,
                'logical_fields_json'    => '{"email":"legacy@example.test"}',
                'created_at'             => $now,
                'updated_at'             => $now,
            ]
        );
        $this->assertSame( 1, $created );

        $replay = $service->capture(
            [
                'submission_uuid' => $deterministic_uuid,
                'form_source'     => 'gravity_forms',
                'form_id'         => '405',
                'native_entry_id' => 'legacy-native-505',
                'logical_fields'  => [ 'email' => 'replayed@example.test' ],
            ]
        );

        $this->assertIsArray( $replay );
        $this->assertSame( $legacy_uuid, $replay['submission_uuid'] ?? null );
        $this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', (string) ( $replay['native_correlation_hash'] ?? '' ) );
        $this->assertNull( $ledger->get_by_submission_uuid( $deterministic_uuid ) );
        $this->assertCount( 1, $ledger->list_for_form( 'gravity_forms', '405' ) );
    }

    public function test_capture_recovers_the_native_correlation_winner_after_a_concurrent_insert(): void
    {
        $settings    = new Sentient_Forms_Submission_Ledger_Settings_Repository( $this->wpdb );
        $winner_uuid = wp_generate_uuid4();
        $winner      = [
            'submission_uuid' => $winner_uuid,
            'form_source'     => 'gravity_forms',
            'form_id'         => '406',
            'native_entry_id' => 'concurrent-native-506',
        ];
        $ledger      = new class( $this->wpdb, $winner ) extends Sentient_Forms_Submission_Ledger_Repository
        {
            private bool $insert_attempted = false;

            public function __construct( wpdb $wpdb, private array $winner )
            {
                parent::__construct( $wpdb );
            }

            public function get_by_submission_uuid( string $submission_uuid ): ?array
            {
                return null;
            }

            public function get_by_native_entry_id( string $form_source, string $form_id, string $native_entry_id ): ?array
            {
                return null;
            }

            public function get_by_native_correlation_hash( string $native_correlation_hash ): ?array
            {
                return $this->insert_attempted ? $this->winner : null;
            }

            public function create( array $data ): int | WP_Error
            {
                $this->insert_attempted = true;
                return new WP_Error( 'sentient_forms_db_insert_failed', 'Concurrent insert lost the uniqueness race.' );
            }
        };

        $settings->set_enabled( 'gravity_forms', '406', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $service = new Sentient_Forms_Submission_Ledger_Capture_Service( $this->wpdb, $settings, $ledger );
        $result  = $service->capture(
            [
                'submission_uuid' => wp_generate_uuid4(),
                'form_source'     => 'gravity_forms',
                'form_id'         => '406',
                'native_entry_id' => 'concurrent-native-506',
                'logical_fields'  => [ 'email' => 'winner@example.test' ],
            ]
        );

        $this->assertIsArray( $result );
        $this->assertSame( $winner_uuid, $result['submission_uuid'] ?? null );
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

    public function test_capture_assigns_expiry_from_the_current_ledger_retention_policy(): void
    {
        $settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $this->wpdb );
        $service  = new Sentient_Forms_Submission_Ledger_Capture_Service( $this->wpdb );

        $settings->set_enabled( 'gravity_forms', 'retained-entry', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        Sentient_Forms_Local_Data_Governance::update_submission_ledger_retention_days( 7 );

        $result = $service->capture(
            [
                'form_source'     => 'gravity_forms',
                'form_id'         => 'retained-entry',
                'native_entry_id' => 'retained-1',
                'logical_fields'  => [ 'email' => 'retained@example.test' ],
                'expires_at'      => '2099-01-01 00:00:00',
            ]
        );

        $this->assertIsArray( $result );
        $expires_at = strtotime( (string) ( $result['expires_at'] ?? '' ) );
        $this->assertGreaterThanOrEqual( time() + ( 7 * DAY_IN_SECONDS ) - 5, $expires_at );
        $this->assertLessThanOrEqual( time() + ( 7 * DAY_IN_SECONDS ) + 5, $expires_at );
        $this->assertNotSame( '2099-01-01 00:00:00', $result['expires_at'] );
    }

    public function test_manual_ledger_retention_leaves_new_captures_unexpired(): void
    {
        $settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $this->wpdb );
        $service  = new Sentient_Forms_Submission_Ledger_Capture_Service( $this->wpdb );

        $settings->set_enabled( 'contact_form_7', 'manual-retention', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        Sentient_Forms_Local_Data_Governance::update_submission_ledger_retention_days( 0 );

        $result = $service->capture(
            [
                'form_source'     => 'contact_form_7',
                'form_id'         => 'manual-retention',
                'native_entry_id' => 'manual-1',
                'logical_fields'  => [ 'email' => 'manual@example.test' ],
            ]
        );

        $this->assertIsArray( $result );
        $this->assertNull( $result['expires_at'] );
    }

    public function test_retention_setting_changes_only_affect_future_captures(): void
    {
        $settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $this->wpdb );
        $service  = new Sentient_Forms_Submission_Ledger_Capture_Service( $this->wpdb );

        $settings->set_enabled( 'wpforms', 'future-only', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        Sentient_Forms_Local_Data_Governance::update_submission_ledger_retention_days( 7 );
        $first = $service->capture(
            [
                'form_source'     => 'wpforms',
                'form_id'         => 'future-only',
                'native_entry_id' => 'future-only-1',
                'logical_fields'  => [ 'email' => 'first@example.test' ],
            ]
        );

        Sentient_Forms_Local_Data_Governance::update_submission_ledger_retention_days( 30 );
        $second = $service->capture(
            [
                'form_source'     => 'wpforms',
                'form_id'         => 'future-only',
                'native_entry_id' => 'future-only-2',
                'logical_fields'  => [ 'email' => 'second@example.test' ],
            ]
        );

        $this->assertIsArray( $first );
        $this->assertIsArray( $second );
        $this->assertSame(
            7 * DAY_IN_SECONDS,
            strtotime( $first['expires_at'] . ' UTC' ) - strtotime( $first['captured_at'] . ' UTC' )
        );
        $this->assertSame(
            30 * DAY_IN_SECONDS,
            strtotime( $second['expires_at'] . ' UTC' ) - strtotime( $second['captured_at'] . ' UTC' )
        );

        $stored_first = ( new Sentient_Forms_Submission_Ledger_Repository( $this->wpdb ) )
            ->get_by_submission_uuid( $first['submission_uuid'] );
        $this->assertSame( $first['expires_at'], $stored_first['expires_at'] );
    }
}
