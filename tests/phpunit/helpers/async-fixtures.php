<?php
/**
 * Test fixtures for async ledger cleanup.
 */

if ( ! function_exists( 'sentient_forms_tests_reset_async_state' ) ) {
    function sentient_forms_tests_reset_async_state(): void {
        global $wpdb;
        if ( ! $wpdb instanceof wpdb ) {
            return;
        }

        $table = $wpdb->prefix . 'sentient_async_requests';
        // Truncate async request ledger if it exists.
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists === $table ) {
            $wpdb->query( "TRUNCATE TABLE {$table}" );
        }

        // Clear in-memory queue shim used by tests.
        $GLOBALS['__sentient_forms_async_queue'] = [ 'enqueued' => [] ];

        // Clear Action Scheduler group if tables exist (best-effort).
        $as_table    = $wpdb->prefix . 'actionscheduler_actions';
        $group_table = $wpdb->prefix . 'actionscheduler_groups';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $as_table ) ) === $as_table ) {
            $group_id = $wpdb->get_var( $wpdb->prepare( "SELECT group_id FROM {$group_table} WHERE slug = %s", 'sentient_forms_async' ) );
            if ( $group_id ) {
                $wpdb->query( $wpdb->prepare( "DELETE FROM {$as_table} WHERE group_id = %d", $group_id ) );
            }
        }

        // Reset async settings to defaults.
        delete_option( 'sentient_forms_async_settings' );
    }
}
