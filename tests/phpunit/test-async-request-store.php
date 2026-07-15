<?php

final class Sentient_Forms_Test_Async_Request_Insert_Race_Wpdb extends wpdb
{
    private int $reads = 0;

    private bool $first_read_misses;

    /** @var array<string, mixed> */
    private array $owner;

    public function __construct( array $owner, bool $first_read_misses = true )
    {
        $this->prefix            = 'wp_';
        $this->owner             = $owner;
        $this->first_read_misses = $first_read_misses;
    }

    public function prepare( $query, ...$args )
    {
        return $query;
    }

    public function query( $query )
    {
        return 0;
    }

    public function get_row( $query = null, $output = OBJECT, $y = 0 )
    {
        ++$this->reads;

        return $this->first_read_misses && 1 === $this->reads ? null : $this->owner;
    }

    public function update( $table, $data, $where, $format = null, $where_format = null )
    {
        return 1;
    }
}

class AsyncRequestStoreTest extends WP_UnitTestCase
{
    private Sentient_Forms_Async_Request_Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        Sentient_Forms_Installer::maybe_upgrade();
        $this->store = new Sentient_Forms_Async_Request_Store( $wpdb );
    }

    public function test_record_and_should_block(): void
    {
        $hash = wp_generate_password( 64, false, false );
        $this->assertFalse( $this->store->should_block( $hash ) );
        $this->store->record( $hash, [ 'action_id' => 'spam_check', 'status' => 'queued' ] );
        $this->assertTrue( $this->store->should_block( $hash ) );
    }

    public function test_purge_removes_old_rows(): void
    {
        $hash = 'abc123';
        $this->store->record( $hash, [ 'action_id' => 'summary', 'status' => 'success' ] );
        $removed = $this->store->purge_older_than( time() + DAY_IN_SECONDS );
        $this->assertSame( 1, $removed );
        $this->assertFalse( $this->store->should_block( $hash ) );
    }

    public function test_claim_execution_rejects_missing_or_mismatched_payload_digest(): void
    {
        $request_hash = 'accepted-sync-' . wp_generate_password( 32, false, false );
        $first_digest = hash( 'sha256', 'first-payload' );
        $other_digest = hash( 'sha256', 'other-payload' );

        $missing = $this->store->claim_execution(
            $request_hash . '-missing',
            [ 'action_id' => 'entry_summary_v1', 'adapter' => 'fixture_forms' ]
        );
        $this->assertSame( 'digest_conflict', $missing['state'] );
        $this->assertNull( $missing['record'] );

        $claimed = $this->store->claim_execution(
            $request_hash,
            [
                'action_id'      => 'entry_summary_v1',
                'adapter'        => 'fixture_forms',
                'payload_digest' => $first_digest,
            ]
        );
        $this->assertSame( 'claimed', $claimed['state'] );

        $this->store->mark_status( $request_hash, 'success', null, 'accepted_sync' );

        $mismatch = $this->store->claim_execution(
            $request_hash,
            [
                'action_id'      => 'entry_summary_v1',
                'adapter'        => 'fixture_forms',
                'payload_digest' => $other_digest,
            ]
        );
        $this->assertSame( 'digest_conflict', $mismatch['state'] );
        $this->assertSame( $first_digest, $mismatch['record']['payload_digest'] ?? null );
    }

    public function test_failed_execution_retry_requires_the_original_payload_digest(): void
    {
        $request_hash = 'accepted-retry-' . wp_generate_password( 32, false, false );
        $first_digest = hash( 'sha256', 'retryable-payload' );

        $claimed = $this->store->claim_execution(
            $request_hash,
            [
                'action_id'      => 'entry_summary_v1',
                'adapter'        => 'fixture_forms',
                'payload_digest' => $first_digest,
            ]
        );
        $this->assertSame( 'claimed', $claimed['state'] );
        $this->store->mark_status( $request_hash, 'failed', 'safe failure', 'accepted_sync' );

        $mismatch = $this->store->claim_execution(
            $request_hash,
            [
                'action_id'      => 'entry_summary_v1',
                'adapter'        => 'fixture_forms',
                'payload_digest' => hash( 'sha256', 'changed-payload' ),
            ],
            true
        );

        $this->assertSame( 'digest_conflict', $mismatch['state'] );
        $this->assertSame( 'failed', $mismatch['record']['status'] ?? null );
        $this->assertSame( $first_digest, $mismatch['record']['payload_digest'] ?? null );
    }

    public function test_record_types_cannot_share_one_global_execution_identity(): void
    {
        $job_request = 'global-job-' . wp_generate_password( 32, false, false );
        $this->assertTrue(
            $this->store->record(
                $job_request,
                [
                    'action_id'      => 'entry_summary_v1',
                    'adapter'        => 'fixture_forms',
                    'payload_digest' => hash( 'sha256', 'job-payload' ),
                ]
            )
        );

        $sync_claim = $this->store->claim_execution(
            $job_request,
            [
                'action_id'      => 'entry_summary_v1',
                'adapter'        => 'fixture_forms',
                'payload_digest' => hash( 'sha256', 'sync-payload' ),
            ]
        );

        $this->assertSame( 'record_type_conflict', $sync_claim['state'] );
        $this->assertSame( 'job', $sync_claim['record']['record_type'] ?? null );
        $this->assertNotNull( $this->store->get( $job_request, 'job' ) );
        $this->assertNull( $this->store->get( $job_request, 'accepted_sync' ) );

        $sync_request = 'global-sync-' . wp_generate_password( 32, false, false );
        $claimed      = $this->store->claim_execution(
            $sync_request,
            [
                'action_id'      => 'entry_summary_v1',
                'adapter'        => 'fixture_forms',
                'payload_digest' => hash( 'sha256', 'accepted-sync-payload' ),
            ]
        );
        $this->assertSame( 'claimed', $claimed['state'] );

        $job_record = $this->store->record(
            $sync_request,
            [
                'action_id'      => 'entry_summary_v1',
                'adapter'        => 'fixture_forms',
                'payload_digest' => hash( 'sha256', 'job-payload' ),
            ]
        );

        $this->assertWPError( $job_record );
        $this->assertSame( 'sentient_forms_async_request_record_type_conflict', $job_record->get_error_code() );
        $this->assertNotNull( $this->store->get( $sync_request, 'accepted_sync' ) );
        $this->assertNull( $this->store->get( $sync_request, 'job' ) );
    }

    public function test_same_type_insert_race_does_not_grant_a_second_scheduling_claim(): void
    {
        $request_hash = 'same-type-race';
        $digest       = hash( 'sha256', 'same-payload' );
        $database     = new Sentient_Forms_Test_Async_Request_Insert_Race_Wpdb(
            [
                'request_hash'   => $request_hash,
                'record_type'    => 'job',
                'status'         => 'queued',
                'payload_digest' => $digest,
            ]
        );
        $store        = new Sentient_Forms_Async_Request_Store( $database );

        $claim = $store->record(
            $request_hash,
            [
                'action_id'      => 'entry_summary_v1',
                'record_type'    => 'job',
                'payload_digest' => $digest,
            ]
        );

        $this->assertFalse( $claim );
    }

    public function test_same_type_owner_visible_on_first_record_read_does_not_grant_a_second_claim(): void
    {
        $request_hash = 'same-type-owner-visible';
        $digest       = hash( 'sha256', 'same-payload' );
        $database     = new Sentient_Forms_Test_Async_Request_Insert_Race_Wpdb(
            [
                'request_hash'   => $request_hash,
                'record_type'    => 'job',
                'status'         => 'queued',
                'first_seen_at'  => current_time( 'mysql' ),
                'last_seen_at'   => current_time( 'mysql' ),
                'payload_digest' => $digest,
            ],
            false
        );
        $store        = new Sentient_Forms_Async_Request_Store( $database );

        $claim = $store->record(
            $request_hash,
            [
                'action_id'      => 'entry_summary_v1',
                'record_type'    => 'job',
                'payload_digest' => $digest,
            ]
        );

        $this->assertFalse( $claim );
    }

    public function test_visible_same_type_owner_rejects_a_different_payload_digest(): void
    {
        $request_hash = 'same-type-digest-conflict';
        $database     = new Sentient_Forms_Test_Async_Request_Insert_Race_Wpdb(
            [
                'request_hash'   => $request_hash,
                'record_type'    => 'job',
                'status'         => 'queued',
                'first_seen_at'  => current_time( 'mysql' ),
                'last_seen_at'   => current_time( 'mysql' ),
                'payload_digest' => hash( 'sha256', 'original-payload' ),
            ],
            false
        );
        $store        = new Sentient_Forms_Async_Request_Store( $database );

        $claim = $store->record(
            $request_hash,
            [
                'action_id'      => 'entry_summary_v1',
                'record_type'    => 'job',
                'payload_digest' => hash( 'sha256', 'different-payload' ),
            ]
        );

        $this->assertWPError( $claim );
        $this->assertSame( 'sentient_forms_async_request_digest_conflict', $claim->get_error_code() );
    }

    public function test_malformed_active_timestamp_fails_closed(): void
    {
        $request_hash = 'malformed-active-timestamp';
        $database     = new Sentient_Forms_Test_Async_Request_Insert_Race_Wpdb(
            [
                'request_hash' => $request_hash,
                'record_type'  => 'job',
                'status'       => 'queued',
                'last_seen_at' => 'not-a-mysql-datetime',
            ],
            false
        );
        $store        = new Sentient_Forms_Async_Request_Store( $database );

        $this->assertTrue( $store->should_block( $request_hash ) );
    }

    public function test_failed_same_type_request_has_one_atomic_retry_winner(): void
    {
        $request_hash = 'failed-retry-' . wp_generate_password( 24, false, false );
        $context      = [
            'action_id'      => 'entry_summary_v1',
            'record_type'    => 'job',
            'status'         => 'queued',
            'payload_digest' => hash( 'sha256', 'retry-payload' ),
        ];

        $this->assertTrue( $this->store->record( $request_hash, $context ) );
        $this->store->mark_status( $request_hash, 'failed', 'Safe retryable failure.' );

        $this->assertTrue( $this->store->record( $request_hash, $context ) );
        $this->assertFalse( $this->store->record( $request_hash, $context ) );
        $this->assertSame( 'queued', $this->store->get( $request_hash )['status'] ?? null );
    }

    public function test_expired_same_type_request_can_be_reclaimed_once(): void
    {
        global $wpdb;

        $request_hash = 'expired-retry-' . wp_generate_password( 24, false, false );
        $context      = [
            'action_id'      => 'entry_summary_v1',
            'record_type'    => 'job',
            'status'         => 'queued',
            'payload_digest' => hash( 'sha256', 'expired-payload' ),
        ];

        $this->assertTrue( $this->store->record( $request_hash, $context ) );
        $wpdb->update(
            $wpdb->prefix . 'sentient_async_requests',
            [ 'last_seen_at' => wp_date( 'Y-m-d H:i:s', time() - ( 2 * DAY_IN_SECONDS ), wp_timezone() ) ],
            [ 'request_hash' => $request_hash ],
            [ '%s' ],
            [ '%s' ]
        );

        $this->assertTrue( $this->store->record( $request_hash, $context ) );
        $this->assertFalse( $this->store->record( $request_hash, $context ) );
    }

    public function test_fresh_requests_use_the_site_timezone_for_expiry_and_purge_cutoffs(): void
    {
        global $wpdb;

        $request_hash     = 'site-timezone-' . wp_generate_password( 24, false, false );
        $original_timezone = get_option( 'timezone_string' );
        $original_offset   = get_option( 'gmt_offset' );
        $original_default  = date_default_timezone_get();
        $ttl_filter        = static fn(): int => HOUR_IN_SECONDS;

        try
        {
            update_option( 'timezone_string', 'Pacific/Honolulu' );
            update_option( 'gmt_offset', -10 );
            date_default_timezone_set( 'UTC' );
            add_filter( 'sentient_forms_async_request_ttl', $ttl_filter );

            $this->assertTrue(
                $this->store->record(
                    $request_hash,
                    [
                        'action_id' => 'entry_summary_v1',
                        'status'    => 'queued',
                    ]
                )
            );
            $this->assertTrue( $this->store->should_block( $request_hash ) );
            $this->assertSame( 0, $this->store->purge_older_than( time() - MINUTE_IN_SECONDS ) );
            $this->assertTrue( $this->store->should_block( $request_hash ) );
        }
        finally
        {
            remove_filter( 'sentient_forms_async_request_ttl', $ttl_filter );
            date_default_timezone_set( $original_default );
            update_option( 'timezone_string', $original_timezone );
            update_option( 'gmt_offset', $original_offset );
            $wpdb->delete(
                $wpdb->prefix . 'sentient_async_requests',
                [ 'request_hash' => $request_hash ],
                [ '%s' ]
            );
        }
    }
}
