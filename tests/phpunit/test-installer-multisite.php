<?php

class Tests_Installer_Multisite extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if ( ! is_multisite() )
        {
            $this->markTestSkipped( 'Multisite-only installer lifecycle coverage.' );
        }

        $this->restore_main_blog_context();
    }

    protected function tearDown(): void
    {
        $this->restore_main_blog_context();
        parent::tearDown();
    }

    public function test_network_activation_and_deactivation_cover_existing_sites(): void
    {
        $main_blog_id   = get_current_blog_id();
        $second_blog_id = self::factory()->blog->create();
        $blog_ids       = [ $main_blog_id, $second_blog_id ];

        foreach ( $blog_ids as $blog_id )
        {
            $this->reset_blog_lifecycle_state( (int) $blog_id );
        }
        $this->set_blog_option_value( $main_blog_id, 'sentient_forms_submission_ledger_retention_days', 7 );
        $this->set_blog_option_value( (int) $second_blog_id, 'sentient_forms_submission_ledger_retention_days', 0 );

        try
        {
            Sentient_Forms_Installer::activate( true );

            foreach ( $blog_ids as $blog_id )
            {
                $this->assert_blog_tables_exist( (int) $blog_id, true );
                $this->assertSame( SENTIENT_FORMS_DB_VERSION, $this->get_blog_option_value( (int) $blog_id, 'sentient_forms_db_version' ) );
                $this->assertSame( '2026.07.10.v1', $this->get_blog_option_value( (int) $blog_id, 'sentient_forms_submission_ledger_retention_backfill_version' ) );
                $this->assertTrue( $this->blog_retention_scheduled( (int) $blog_id ) );
            }
            $this->assertSame( 7, $this->get_blog_option_value( $main_blog_id, 'sentient_forms_submission_ledger_retention_days' ) );
            $this->assertSame( 0, $this->get_blog_option_value( (int) $second_blog_id, 'sentient_forms_submission_ledger_retention_days' ) );

            Sentient_Forms_Installer::deactivate( true );

            foreach ( $blog_ids as $blog_id )
            {
                $this->assertFalse( $this->blog_retention_scheduled( (int) $blog_id ) );
            }

            $this->assertSame( $main_blog_id, get_current_blog_id() );
        }
        finally
        {
            Sentient_Forms_Installer::activate( true );
            $this->assertSame( $main_blog_id, get_current_blog_id() );
            $this->delete_blog( (int) $second_blog_id );
        }
    }

    public function test_network_activation_migrates_elementor_identifiers_for_every_existing_site(): void
    {
        $main_blog_id   = get_current_blog_id();
        $second_blog_id = (int) self::factory()->blog->create();
        $blog_ids       = [ $main_blog_id, $second_blog_id ];

        try
        {
            foreach ( $blog_ids as $blog_id )
            {
                $this->with_blog(
                    (int) $blog_id,
                    function (): void {
                        update_option( 'sentient_forms_db_version', '2026.06.28.execution_event_identity', false );
                        update_option( 'sentient_forms_actions_elementor_forms_401_formabc', [ 'site' => get_current_blog_id() ], false );
                    }
                );
            }

            Sentient_Forms_Installer::activate( true );

            foreach ( $blog_ids as $blog_id )
            {
                $this->with_blog(
                    (int) $blog_id,
                    function () use ( $blog_id ): void {
                        $this->assertFalse( get_option( 'sentient_forms_actions_elementor_forms_401_formabc', false ) );
                        $this->assertSame(
                            [ 'site' => $blog_id ],
                            get_option( 'sentient_forms_actions_elementor_pro_forms_401_formabc' )
                        );
                    }
                );
            }
        }
        finally
        {
            foreach ( $blog_ids as $blog_id )
            {
                $this->with_blog(
                    (int) $blog_id,
                    static function (): void {
                        delete_option( 'sentient_forms_actions_elementor_forms_401_formabc' );
                        delete_option( 'sentient_forms_actions_elementor_pro_forms_401_formabc' );
                    }
                );
            }
            $this->delete_blog( $second_blog_id );
            $this->restore_main_blog_context();
        }
    }

    public function test_new_site_initialization_runs_when_plugin_is_network_active(): void
    {
        $plugin_basename = plugin_basename( SENTIENT_FORMS_PLUGIN_FILE );
        $previous_active = get_site_option( 'active_sitewide_plugins', [] );
        $new_blog_id     = 0;
        $hook_ran        = false;
        $initialized_id  = 0;
        $initializer     = static function ( WP_Site $site ) use ( &$hook_ran, &$initialized_id ): void {
            $hook_ran = true;
            $initialized_id = (int) $site->blog_id;
            Sentient_Forms_Installer::initialize_new_site( $site );
        };

        try
        {
            update_site_option( 'active_sitewide_plugins', [ $plugin_basename => time() ] );
            $this->assertTrue( Sentient_Forms_Installer::is_network_active() );

            add_action( 'wp_initialize_site', $initializer );
            $new_blog_id = (int) self::factory()->blog->create();

            $this->assertTrue( $hook_ran );
            $this->assertSame( $new_blog_id, $initialized_id );
            $this->assertSame( SENTIENT_FORMS_DB_VERSION, $this->get_blog_option_value( $new_blog_id, 'sentient_forms_db_version' ) );
            $this->assert_blog_tables_exist( $new_blog_id, true );
            $this->assertTrue( $this->blog_retention_scheduled( $new_blog_id ) );
        }
        finally
        {
            remove_action( 'wp_initialize_site', $initializer );
            update_site_option( 'active_sitewide_plugins', $previous_active );

            if ( $new_blog_id > 0 )
            {
                $this->delete_blog( $new_blog_id );
                Sentient_Forms_Installer::activate( true );
            }
        }
    }

    public function test_network_uninstall_respects_each_site_delete_option(): void
    {
        $main_blog_id   = get_current_blog_id();
        $second_blog_id = self::factory()->blog->create();
        $blog_ids       = [ $main_blog_id, (int) $second_blog_id ];

        try
        {
            foreach ( $blog_ids as $blog_id )
            {
                $this->reset_blog_lifecycle_state( (int) $blog_id );
            }

            $this->without_temporary_table_filters(
                function () use ( $main_blog_id, $second_blog_id ): void {
                    Sentient_Forms_Installer::activate( true );
                    $this->set_blog_option_value( $main_blog_id, 'sentient_forms_delete_data_on_uninstall', false );
                    $this->set_blog_option_value( (int) $second_blog_id, 'sentient_forms_delete_data_on_uninstall', true );

                    $this->assertFalse( $this->get_blog_option_value( $main_blog_id, 'sentient_forms_delete_data_on_uninstall', true ) );
                    $this->assertTrue( $this->get_blog_option_value( (int) $second_blog_id, 'sentient_forms_delete_data_on_uninstall', false ) );

                    Sentient_Forms_Installer::uninstall();

                    $this->assert_blog_tables_exist( $main_blog_id, true );
                    $this->assert_blog_tables_exist( (int) $second_blog_id, false );
                    $this->assertSame( SENTIENT_FORMS_DB_VERSION, $this->get_blog_option_value( $main_blog_id, 'sentient_forms_db_version' ) );
                    $this->assertFalse( $this->get_blog_option_value( (int) $second_blog_id, 'sentient_forms_db_version', false ) );
                }
            );
        }
        finally
        {
            $this->without_temporary_table_filters(
                function (): void {
                    Sentient_Forms_Installer::activate( true );
                }
            );
            $this->delete_blog( (int) $second_blog_id );
        }
    }

    private function reset_blog_lifecycle_state( int $blog_id ): void
    {
        $this->with_blog(
            $blog_id,
            function (): void {
                $this->without_temporary_table_filters(
                    function (): void {
                        global $wpdb;

                        foreach ( Sentient_Forms_Local_Data_Governance::local_table_suffixes() as $suffix )
                        {
                            $wpdb->query( 'DROP TABLE IF EXISTS ' . esc_sql( $wpdb->prefix . $suffix ) );
                        }
                    }
                );

                delete_option( 'sentient_forms_db_version' );
                delete_option( 'sentient_forms_delete_data_on_uninstall' );
                delete_option( 'sentient_forms_submission_ledger_retention_days' );
                delete_option( 'sentient_forms_submission_ledger_retention_backfill_version' );
                delete_option( 'sentient_forms_submission_ledger_retention_backfill_snapshot_v1' );
                delete_option( 'sentient_forms_submission_ledger_retention_backfill_cursor_v1' );
                Sentient_Forms_Local_Data_Governance::unschedule_retention_cleanup();
            }
        );
    }

    private function assert_blog_tables_exist( int $blog_id, bool $expected ): void
    {
        $this->with_blog(
            $blog_id,
            function () use ( $expected ): void {
                global $wpdb;

                foreach ( Sentient_Forms_Local_Data_Governance::local_table_suffixes() as $suffix )
                {
                    $table  = $wpdb->prefix . $suffix;
                    $suppress = $wpdb->suppress_errors();
                    $exists = ! empty( $wpdb->get_results( 'DESCRIBE `' . str_replace( '`', '``', $table ) . '`' ) );
                    $wpdb->suppress_errors( $suppress );
                    $matching_tables = implode( ', ', $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', '%' . $wpdb->esc_like( $suffix ) ) ) );
                    $this->assertSame(
                        $expected,
                        $exists,
                        sprintf(
                            'Unexpected table state for %s. Current blog: %d. Prefix: %s. Matching tables: %s.',
                            $table,
                            get_current_blog_id(),
                            $wpdb->prefix,
                            $matching_tables
                        )
                    );
                }
            }
        );
    }

    private function blog_retention_scheduled( int $blog_id ): bool
    {
        $scheduled = false;

        $this->with_blog(
            $blog_id,
            static function () use ( &$scheduled ): void {
                $scheduled = false !== wp_next_scheduled( Sentient_Forms_Local_Data_Governance::RETENTION_HOOK );
            }
        );

        return $scheduled;
    }

    private function get_blog_option_value( int $blog_id, string $option, mixed $default = null ): mixed
    {
        $value = $default;

        $this->with_blog(
            $blog_id,
            static function () use ( $option, $default, &$value ): void {
                $value = get_option( $option, $default );
            }
        );

        return $value;
    }

    private function set_blog_option_value( int $blog_id, string $option, mixed $value ): void
    {
        $this->with_blog(
            $blog_id,
            static function () use ( $option, $value ): void {
                delete_option( $option );
                add_option( $option, $value );
            }
        );
    }

    /**
     * Run a callback inside a switched blog context.
     *
     * @param callable(): void $callback Callback to execute in the target blog.
     */
    private function with_blog( int $blog_id, callable $callback ): void
    {
        switch_to_blog( $blog_id );

        try
        {
            $callback();
        }
        finally
        {
            restore_current_blog();
        }
    }

    private function delete_blog( int $blog_id ): void
    {
        if ( $blog_id <= 1 )
        {
            return;
        }

        if ( function_exists( 'wpmu_delete_blog' ) )
        {
            wpmu_delete_blog( $blog_id, true );
        }
        else
        {
            wp_delete_site( $blog_id );
        }

        $this->restore_main_blog_context();
    }

    /**
     * Run a callback without the WordPress test-suite temporary-table query filters.
     *
     * @param callable(): void $callback Callback to execute with real table DDL.
     */
    private function without_temporary_table_filters( callable $callback ): void
    {
        $create_filter_active = false !== has_filter( 'query', [ $this, '_create_temporary_tables' ] );
        $drop_filter_active   = false !== has_filter( 'query', [ $this, '_drop_temporary_tables' ] );

        if ( $create_filter_active )
        {
            remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
        }

        if ( $drop_filter_active )
        {
            remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
        }

        try
        {
            $callback();
        }
        finally
        {
            if ( $create_filter_active )
            {
                add_filter( 'query', [ $this, '_create_temporary_tables' ] );
            }

            if ( $drop_filter_active )
            {
                add_filter( 'query', [ $this, '_drop_temporary_tables' ] );
            }
        }
    }

    private function restore_main_blog_context(): void
    {
        if ( ! is_multisite() )
        {
            return;
        }

        while ( function_exists( 'ms_is_switched' ) && ms_is_switched() )
        {
            restore_current_blog();
        }

        $main_blog_id = function_exists( 'get_main_site_id' ) ? (int) get_main_site_id() : 1;
        if ( get_current_blog_id() === $main_blog_id )
        {
            return;
        }

        global $wpdb;
        $wpdb->set_blog_id( $main_blog_id );
        $GLOBALS['blog_id']      = $main_blog_id;
        $GLOBALS['table_prefix'] = $wpdb->get_blog_prefix( $main_blog_id );

        if ( function_exists( 'wp_cache_switch_to_blog' ) )
        {
            wp_cache_switch_to_blog( $main_blog_id );
        }

        $GLOBALS['_wp_switched_stack'] = [];
        $GLOBALS['switched']           = false;
    }
}
