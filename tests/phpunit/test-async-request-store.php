<?php

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
}
