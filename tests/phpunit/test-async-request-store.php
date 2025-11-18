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
}
