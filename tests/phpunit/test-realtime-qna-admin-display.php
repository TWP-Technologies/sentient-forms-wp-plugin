<?php

if ( ! class_exists( 'GFAPI' ) )
{
    class GFAPI
    {
        /** @var array<int,array<string,mixed>> */
        public static array $forms = [];

        public static function get_form( $form_id )
        {
            return self::$forms[ (int) $form_id ] ?? false;
        }
    }
}

if ( ! class_exists( 'RGFormsModel' ) )
{
    class RGFormsModel
    {
        /** @var array<int,array<int,string>> */
        public static array $grid_column_meta = [];

        /** @var array<int,array<string,array<string,string>>> */
        public static array $grid_columns = [];

        /**
         * @return array<int,string>|false
         */
        public static function get_grid_column_meta( $form_id )
        {
            return self::$grid_column_meta[ (int) $form_id ] ?? false;
        }

        /**
         * @return array<string,array<string,string>>
         */
        public static function get_grid_columns( $form_id, $input_label_only = false ): array
        {
            return self::$grid_columns[ (int) $form_id ] ?? [];
        }

        /**
         * @param array<int,string> $columns
         */
        public static function update_grid_column_meta( $form_id, $columns ): void
        {
            self::$grid_column_meta[ (int) $form_id ] = array_values( $columns );
        }
    }
}

final class RealtimeQnaAdminDisplayTest extends WP_UnitTestCase
{
    private Sentient_Forms_Realtime_Qna_Admin_Display $display;

    /** @var array<string,int|null> */
    private array $original_action_counts = [];

    /** @var WP_Screen|null */
    private $original_current_screen = null;

    private bool $had_original_current_screen = false;

    /** @var array<int,int> */
    private array $original_ajax_filter_priorities = [];

    /** @var array<string,array<string,mixed>> */
    private array $original_qna_asset_state = [];

    public function set_up(): void
    {
        parent::set_up();

        $this->original_action_counts = $this->capture_action_counts();
        $this->capture_global_state();
        $this->capture_qna_asset_state();

        if ( property_exists( 'GFAPI', 'forms' ) )
        {
            GFAPI::$forms = [];
        }
        if ( property_exists( 'RGFormsModel', 'grid_column_meta' ) )
        {
            RGFormsModel::$grid_column_meta = [];
        }
        if ( property_exists( 'RGFormsModel', 'grid_columns' ) )
        {
            RGFormsModel::$grid_columns = [];
        }

        $_GET = [];
        $this->display = new Sentient_Forms_Realtime_Qna_Admin_Display();
    }

    public function tear_down(): void
    {
        $_GET = [];
        $this->restore_qna_asset_state();
        $this->restore_action_counts();
        $this->restore_global_state();

        parent::tear_down();
    }

    public function test_hides_realtime_storage_field_from_entry_list_columns(): void
    {
        $form = $this->form_fixture();
        GFAPI::$forms[4] = $form;
        RGFormsModel::$grid_column_meta[4] = [ '1', '5', 'date_created' ];

        $this->assertFalse(
            $this->display->maybe_hide_storage_field_from_entry_columns_selector( true, $form['fields'][1], $form )
        );

        $columns = $this->display->remove_storage_columns_from_entry_list(
            [
                'id'         => 'Entry Id',
                'field_id-1' => 'Name',
                'field_id-5' => 'Sentient Forms Realtime Q&A',
            ],
            4
        );

        $this->assertArrayHasKey( 'field_id-1', $columns );
        $this->assertArrayNotHasKey( 'field_id-5', $columns );
        $this->assertSame( [ '1', '5', 'date_created' ], RGFormsModel::$grid_column_meta[4] );
    }

    public function test_entry_list_storage_column_gets_compact_summary_instead_of_json(): void
    {
        $form = $this->form_fixture();
        GFAPI::$forms[4] = $form;

        $html = $this->display->format_entry_list_field_value(
            $this->payload_fixture(),
            4,
            5,
            $this->entry_fixture()
        );

        $this->assertIsString( $html );
        $this->assertStringContainsString( 'sentient-forms-qna-cell-summary', $html );
        $this->assertStringContainsString( '1 of 2 answered', $html );
        $this->assertStringNotContainsString( 'sentient_forms_realtime_clarification_qna.v1', $html );
    }

    public function test_legacy_labeled_qna_field_is_rendered_instead_of_raw_json(): void
    {
        $form = $this->form_fixture();
        $form['fields'][1]->label      = 'Sentient Forms Clarification Q&A';
        $form['fields'][1]->adminLabel = 'Sentient Forms Clarification Q&A';
        $form['fields'][1]->inputName  = '';
        $form['fields'][1]->cssClass   = '';
        unset( $form['fields'][1]->sentientFormsRealtimeStorage );

        $html = $this->display->format_entry_detail_field_value(
            $this->payload_fixture(),
            $form['fields'][1],
            $this->entry_fixture(),
            $form
        );

        $this->assertIsString( $html );
        $this->assertStringContainsString( 'data-sf-qna-panel', $html );
        $this->assertStringContainsString( 'What budget range should we plan around?', $html );
        $this->assertStringContainsString( 'data-sf-qna-view-panel="json" hidden', $html );
    }

    public function test_entry_list_first_column_adds_friendly_preview_and_copy_controls(): void
    {
        $form = $this->form_fixture();
        GFAPI::$forms[4] = $form;

        ob_start();
        $this->display->render_entry_list_first_column_summary( 4, 1, 'Jane Example', $this->entry_fixture(), '' );
        $html = ob_get_clean();

        $this->assertStringContainsString( 'data-sf-qna-row', $html );
        $this->assertStringContainsString( 'What budget range should we plan around?', $html );
        $this->assertStringContainsString( 'Blue widgets under $5,000', $html );
        $this->assertStringContainsString( 'Copy table', $html );
        $this->assertStringContainsString( 'View Q&amp;A', $html );
        $this->assertStringNotContainsString( '{"schema"', $html );
    }

    public function test_entry_detail_replaces_raw_json_with_cards_table_json_toggle(): void
    {
        $form  = $this->form_fixture();
        $entry = $this->entry_fixture();

        $html = $this->display->format_entry_detail_field_value(
            $entry['5'],
            $form['fields'][1],
            $entry,
            $form
        );

        $this->assertIsString( $html );
        $this->assertStringContainsString( 'data-sf-qna-panel', $html );
        $this->assertStringContainsString( 'data-sf-qna-view-tab="cards"', $html );
        $this->assertStringContainsString( 'sentient-forms-qna-tab sentient-forms-qna-icon-button is-active', $html );
        $this->assertStringContainsString( 'aria-label="Cards"', $html );
        $this->assertStringContainsString( 'data-sf-qna-view-panel="table" hidden', $html );
        $this->assertStringContainsString( 'data-sf-qna-view-panel="json" hidden', $html );
        $this->assertStringContainsString( 'What budget range should we plan around?', $html );
        $this->assertStringContainsString( 'Blue widgets under $5,000', $html );
        $this->assertStringContainsString( 'sentient-forms-qna-action-button', $html );
        $this->assertStringContainsString( 'data-sf-qna-button-label>Summary</span>', $html );
        $this->assertStringContainsString( 'data-sf-qna-button-label>Table</span>', $html );
        $this->assertStringContainsString( 'data-sf-qna-button-label>CSV</span>', $html );
        $this->assertStringContainsString( 'aria-label="Copy Q&amp;A summary"', $html );
        $this->assertStringContainsString( 'aria-label="Copy Q&amp;A table"', $html );
        $this->assertStringContainsString( 'aria-label="Download Q&amp;A CSV"', $html );
        $this->assertStringContainsString( "Question\tAnswer\tStatus", $html );
        $this->assertStringNotContainsString( 'sentient-forms-qna-panel--high-volume', $html );
        $this->assertStringNotContainsString( 'data-sf-qna-review-toolbar', $html );
        $this->assertStringNotContainsString( 'data-sf-qna-detail-toolbar', $html );
        $this->assertStringContainsString( 'sentient-forms-qna-tabs-row', $html );
        $this->assertStringContainsString( 'data-sf-qna-card-view-actions', $html );
        $this->assertStringContainsString( 'data-sf-qna-expand-all', $html );
        $this->assertSame( 1, substr_count( $html, 'data-sf-qna-expand-all' ) );
        $this->assertStringContainsString( 'sentient-forms-qna-review-button__label" data-sf-qna-button-label>Expand all', $html );
        $this->assertStringContainsString( 'data-sf-qna-card-toggle', $html );
        $this->assertStringContainsString( 'aria-label="Hide details for What budget range should we plan around?"', $html );
        $this->assertSame( 2, substr_count( $html, 'data-sf-qna-card ' ) );
    }

    public function test_entry_detail_late_realtime_clarification_questions_show_timing_tip(): void
    {
        $form    = $this->form_fixture();
        $entry   = $this->entry_fixture();
        $payload = json_decode( $this->payload_fixture(), true );

        $payload['mappings'][0]['submitted_at']          = '2026-05-03T11:30:00Z';
        $payload['mappings'][0]['returned_at']           = '2026-05-03T11:30:06.250Z';
        $payload['mappings'][0]['returned_after_ms']     = 6250;
        $payload['mappings'][0]['late_after_submission'] = true;
        $payload['mappings'][0]['execution_request_id']  = 'rt-late-display';
        $payload['mappings'][0]['pre_submit_timeout_ms'] = 2500;
        $payload['mappings'][0]['timeout_source']        = 'pre_submit';
        $payload['mappings'][0]['questions'][1]['returned_after_ms']     = 6250;
        $payload['mappings'][0]['questions'][1]['late_after_submission'] = true;
        $payload['mappings'][0]['questions'][1]['execution_request_id']  = 'rt-late-display';
        $entry['5'] = wp_json_encode( $payload );

        $html = $this->display->format_entry_detail_field_value(
            $entry['5'],
            $form['fields'][1],
            $entry,
            $form
        );

        $this->assertIsString( $html );
        $this->assertStringContainsString( 'Returned 6 seconds after submission', $html );
        $this->assertStringContainsString( 'Increase the pre-submit timeout', $html );
        $this->assertStringContainsString( 'choose a faster model', $html );
    }

    public function test_entry_detail_admin_render_enqueues_qna_assets(): void
    {
        $_GET['page'] = 'gf_entries';
        set_current_screen( 'dashboard' );
        $this->reset_qna_assets();

        $form  = $this->form_fixture();
        $entry = $this->entry_fixture();

        $html = $this->display->format_entry_detail_field_value(
            $entry['5'],
            $form['fields'][1],
            $entry,
            $form
        );

        $this->assertIsString( $html );
        $this->assertStringContainsString( 'data-sf-qna-panel', $html );
        $this->assertTrue( wp_style_is( 'sentient-forms-gravity-qna-admin', 'enqueued' ) );
        $this->assertTrue( wp_script_is( 'sentient-forms-gravity-qna-admin', 'enqueued' ) );
    }

    public function test_entry_detail_late_admin_render_includes_qna_assets_around_panel(): void
    {
        $_GET['page'] = 'gf_entries';
        set_current_screen( 'dashboard' );
        $this->reset_qna_assets();
        $this->fire_admin_print_styles_without_emoji();
        $this->mark_qna_script_done();

        $form  = $this->form_fixture();
        $entry = $this->entry_fixture();

        $html = $this->display->format_entry_detail_field_value(
            $entry['5'],
            $form['fields'][1],
            $entry,
            $form
        );

        $this->assertIsString( $html );
        $stylesheet_position = strpos( $html, 'sentient-forms-gravity-qna-admin-css' );
        $panel_position      = strpos( $html, 'data-sf-qna-panel' );
        $script_position     = strpos( $html, 'sentientFormsGravityQnaAdminInit' );

        $this->assertIsInt( $stylesheet_position );
        $this->assertIsInt( $panel_position );
        $this->assertIsInt( $script_position );
        $this->assertLessThan( $panel_position, $stylesheet_position );
        $this->assertGreaterThan( $panel_position, $script_position );
    }

    public function test_entry_detail_late_styles_print_script_without_global_script_action(): void
    {
        $_GET['page'] = 'gf_entries';
        set_current_screen( 'dashboard' );
        $this->reset_qna_assets();
        $this->fire_admin_print_styles_without_emoji();

        $form  = $this->form_fixture();
        $entry = $this->entry_fixture();
        $script_prints_before = did_action( 'wp_print_scripts' );

        $html = $this->display->format_entry_detail_field_value(
            $entry['5'],
            $form['fields'][1],
            $entry,
            $form
        );

        $this->assertIsString( $html );
        $this->assertStringContainsString( 'sentient-forms-gravity-qna-admin-css', $html );
        $this->assertStringContainsString( 'sentient-forms-gravity-qna-admin-js', $html );
        $this->assertStringContainsString( 'sentientFormsGravityQnaAdminInit', $html );
        $this->assertTrue( wp_script_is( 'sentient-forms-gravity-qna-admin', 'done' ) );
        $this->assertSame( $script_prints_before, did_action( 'wp_print_scripts' ) );
    }

    public function test_entry_detail_late_script_fallback_uses_registered_loader_pipeline(): void
    {
        $_GET['page'] = 'gf_entries';
        set_current_screen( 'dashboard' );
        $this->reset_qna_assets();
        $this->fire_admin_print_styles_without_emoji();

        $loader_filter = static function ( string $tag, string $handle, string $src ): string {
            if ( 'sentient-forms-gravity-qna-admin' !== $handle )
            {
                return $tag;
            }

            return str_replace( '<script ', '<script data-qna-loader-filter="applied" ', $tag );
        };
        add_filter( 'script_loader_tag', $loader_filter, 10, 3 );

        $form  = $this->form_fixture();
        $entry = $this->entry_fixture();

        try
        {
            $html = $this->display->format_entry_detail_field_value(
                $entry['5'],
                $form['fields'][1],
                $entry,
                $form
            );
        }
        finally
        {
            remove_filter( 'script_loader_tag', $loader_filter, 10 );
        }

        $this->assertIsString( $html );
        $this->assertStringContainsString( 'sentient-forms-gravity-qna-admin-js', $html );
        $this->assertStringContainsString( 'data-qna-loader-filter="applied"', $html );
    }

    public function test_entry_detail_multiple_late_panels_each_get_qna_script_bootstrap(): void
    {
        $_GET['page'] = 'gf_entries';
        set_current_screen( 'dashboard' );
        $this->reset_qna_assets();
        $this->fire_admin_print_styles_without_emoji();
        $this->mark_qna_script_done();

        $form       = $this->form_fixture();
        $first      = $this->entry_fixture();
        $second     = $this->entry_fixture();
        $second['id'] = 183;

        $html = $this->display->format_entry_detail_field_value(
            $first['5'],
            $form['fields'][1],
            $first,
            $form
        );
        $html .= $this->display->format_entry_detail_field_value(
            $second['5'],
            $form['fields'][1],
            $second,
            $form
        );

        $this->assertIsString( $html );
        $first_panel_position      = strpos( $html, 'data-sf-qna-panel' );
        $first_bootstrap_position  = strpos( $html, 'sentientFormsGravityQnaAdminInit' );
        $second_panel_position     = strpos( $html, 'data-sf-qna-panel', $first_panel_position + 1 );
        $second_bootstrap_position = strpos( $html, 'sentientFormsGravityQnaAdminInit', $first_bootstrap_position + 1 );

        $this->assertIsInt( $first_panel_position );
        $this->assertIsInt( $first_bootstrap_position );
        $this->assertIsInt( $second_panel_position );
        $this->assertIsInt( $second_bootstrap_position );
        $this->assertGreaterThan( $first_panel_position, $first_bootstrap_position );
        $this->assertGreaterThan( $second_panel_position, $second_bootstrap_position );
    }

    public function test_entry_detail_ajax_render_includes_qna_script_without_footer_prints(): void
    {
        $_GET['page'] = 'gf_entries';
        set_current_screen( 'dashboard' );
        $this->reset_qna_assets();
        add_filter( 'wp_doing_ajax', '__return_true' );
        $this->fire_admin_print_styles_without_emoji();

        $form  = $this->form_fixture();
        $entry = $this->entry_fixture();
        $script_prints_before = did_action( 'wp_print_scripts' );

        $html = $this->display->format_entry_detail_field_value(
            $entry['5'],
            $form['fields'][1],
            $entry,
            $form
        );

        $this->assertIsString( $html );
        $this->assertStringContainsString( 'sentient-forms-gravity-qna-admin-css', $html );
        $this->assertStringContainsString( 'sentient-forms-gravity-qna-admin-js', $html );
        $this->assertStringContainsString( 'sentientFormsGravityQnaAdminInit', $html );
        $this->assertSame( $script_prints_before, did_action( 'wp_print_scripts' ) );
    }

    public function test_entry_detail_ajax_fragment_before_admin_styles_includes_qna_script(): void
    {
        $_GET['page'] = 'gf_entries';
        set_current_screen( 'dashboard' );
        $this->reset_qna_assets();
        add_filter( 'wp_doing_ajax', '__return_true' );

        $form  = $this->form_fixture();
        $entry = $this->entry_fixture();
        $script_prints_before = did_action( 'wp_print_scripts' );

        $this->assertSame( 0, did_action( 'admin_print_styles' ) );

        $html = $this->display->format_entry_detail_field_value(
            $entry['5'],
            $form['fields'][1],
            $entry,
            $form
        );

        $this->assertIsString( $html );
        $stylesheet_position = strpos( $html, 'sentient-forms-gravity-qna-admin-css' );
        $panel_position      = strpos( $html, 'data-sf-qna-panel' );

        $this->assertIsInt( $stylesheet_position );
        $this->assertIsInt( $panel_position );
        $this->assertLessThan( $panel_position, $stylesheet_position );
        $this->assertStringContainsString( 'sentient-forms-gravity-qna-admin-js', $html );
        $this->assertStringContainsString( 'sentientFormsGravityQnaAdminInit', $html );
        $this->assertTrue( wp_style_is( 'sentient-forms-gravity-qna-admin', 'enqueued' ) );
        $this->assertTrue( wp_script_is( 'sentient-forms-gravity-qna-admin', 'done' ) );
        $this->assertSame( $script_prints_before, did_action( 'wp_print_scripts' ) );
    }

    public function test_entry_detail_ajax_multiple_panels_load_external_script_once(): void
    {
        $_GET['page'] = 'gf_entries';
        set_current_screen( 'dashboard' );
        $this->reset_qna_assets();
        add_filter( 'wp_doing_ajax', '__return_true' );
        $this->fire_admin_print_styles_without_emoji();

        $form       = $this->form_fixture();
        $first      = $this->entry_fixture();
        $second     = $this->entry_fixture();
        $second['id'] = 183;

        $html = $this->display->format_entry_detail_field_value(
            $first['5'],
            $form['fields'][1],
            $first,
            $form
        );
        $html .= $this->display->format_entry_detail_field_value(
            $second['5'],
            $form['fields'][1],
            $second,
            $form
        );

        $this->assertIsString( $html );
        $this->assertSame( 1, substr_count( $html, 'sentient-forms-gravity-qna-admin-js' ) );
        $this->assertSame( 2, substr_count( $html, 'sentientFormsGravityQnaAdminInit' ) );
    }

    public function test_entry_detail_late_script_fallback_does_not_fire_global_script_print_action(): void
    {
        $_GET['page'] = 'gf_entries';
        set_current_screen( 'dashboard' );
        $this->reset_qna_assets();
        $this->fire_admin_print_styles_without_emoji();
        $GLOBALS['wp_actions']['admin_print_footer_scripts'] = 1;

        $form  = $this->form_fixture();
        $entry = $this->entry_fixture();
        $script_prints_before = did_action( 'wp_print_scripts' );

        $html = $this->display->format_entry_detail_field_value(
            $entry['5'],
            $form['fields'][1],
            $entry,
            $form
        );

        $this->assertIsString( $html );
        $this->assertStringContainsString( 'sentient-forms-gravity-qna-admin-js', $html );
        $this->assertSame( $script_prints_before, did_action( 'wp_print_scripts' ) );
    }

    public function test_entry_detail_render_outside_gf_entries_does_not_load_qna_assets(): void
    {
        $_GET['page'] = 'plugins';
        set_current_screen( 'dashboard' );
        $this->reset_qna_assets();

        $form  = $this->form_fixture();
        $entry = $this->entry_fixture();

        $html = $this->display->format_entry_detail_field_value(
            $entry['5'],
            $form['fields'][1],
            $entry,
            $form
        );

        $this->assertIsString( $html );
        $this->assertStringContainsString( 'data-sf-qna-panel', $html );
        $this->assertStringNotContainsString( 'sentient-forms-gravity-qna-admin-css', $html );
        $this->assertFalse( wp_style_is( 'sentient-forms-gravity-qna-admin', 'enqueued' ) );
        $this->assertFalse( wp_script_is( 'sentient-forms-gravity-qna-admin', 'enqueued' ) );
    }

    public function test_entry_detail_high_volume_qna_gets_review_controls_without_losing_questions(): void
    {
        $form    = $this->form_fixture();
        $payload = $this->high_volume_payload_fixture( 19 );
        $entry   = [
            'id'      => 242,
            'form_id' => 4,
            '1'       => 'High Volume Example',
            '5'       => $payload,
        ];

        $html = $this->display->format_entry_detail_field_value(
            $payload,
            $form['fields'][1],
            $entry,
            $form
        );

        $this->assertIsString( $html );
        $this->assertStringContainsString( 'sentient-forms-qna-panel--high-volume', $html );
        $this->assertStringContainsString( 'data-sf-qna-total-questions="19"', $html );
        $this->assertStringContainsString( 'data-sf-qna-review-toolbar', $html );
        $this->assertStringContainsString( 'data-sf-qna-filter="open"', $html );
        $this->assertStringContainsString( 'aria-label="Open: 6"', $html );
        $this->assertStringContainsString( 'data-sf-qna-tooltip="Open"', $html );
        $this->assertStringContainsString( 'data-sf-qna-search-input', $html );
        $this->assertStringContainsString( 'data-sf-qna-sort', $html );
        $this->assertStringNotContainsString( 'data-sf-qna-density', $html );
        $this->assertStringNotContainsString( 'Compact density', $html );
        $this->assertStringNotContainsString( 'Comfortable density', $html );
        $this->assertStringContainsString( 'data-sf-qna-card-view-actions', $html );
        $this->assertStringContainsString( 'data-sf-qna-expand-all', $html );
        $this->assertSame( 1, substr_count( $html, 'data-sf-qna-expand-all' ) );
        $this->assertStringContainsString( 'data-sf-qna-active-label="Collapse all"', $html );
        $this->assertStringContainsString( 'sentient-forms-qna-review-button__label" data-sf-qna-button-label>Expand all', $html );
        $this->assertStringContainsString( 'data-sf-qna-card-toggle', $html );
        $this->assertStringContainsString( 'aria-label="Hide details for Synthetic stress question 4?"', $html );
        $this->assertStringContainsString( 'data-sf-qna-tooltip="Hide details"', $html );
        $this->assertStringContainsString( 'data-sf-qna-icon-active', $html );
        $this->assertStringContainsString( 'data-sf-qna-icon-inactive', $html );
        $this->assertSame( 19, substr_count( $html, 'data-sf-qna-card ' ) );
        $this->assertStringContainsString( 'Synthetic stress question 19?', $html );
        $this->assertStringContainsString( 'data-sf-qna-copy-source="csv"', $html );
        $this->assertStringContainsString( 'Synthetic answer 19', $html );
        $this->assertStringNotContainsString( 'data-sf-qna-card-body hidden', $html );

        $priority_position = strpos( $html, 'Synthetic stress question 4?' );
        $original_position = strpos( $html, 'Synthetic stress question 1?' );

        $this->assertIsInt( $priority_position );
        $this->assertIsInt( $original_position );
        $this->assertLessThan( $original_position, $priority_position );
    }

    public function test_entry_detail_action_falls_back_when_hidden_field_row_is_not_rendered(): void
    {
        $form  = $this->form_fixture();
        $entry = $this->entry_fixture();

        ob_start();
        $this->display->render_entry_detail_fallback( $form, $entry );
        $html = ob_get_clean();

        $this->assertStringContainsString( 'sentient-forms-qna-entry-detail-fallback', $html );
        $this->assertStringContainsString( 'data-sf-qna-panel', $html );
        $this->assertStringContainsString( 'What budget range should we plan around?', $html );
        $this->assertStringNotContainsString( '{"schema"', $html );
    }

    public function test_entry_detail_fallback_does_not_duplicate_field_render(): void
    {
        $form  = $this->form_fixture();
        $entry = $this->entry_fixture();

        $this->display->format_entry_detail_field_value(
            $entry['5'],
            $form['fields'][1],
            $entry,
            $form
        );

        ob_start();
        $this->display->render_entry_detail_fallback( $form, $entry );
        $html = ob_get_clean();

        $this->assertSame( '', $html );
    }

    public function test_print_view_renders_table_without_interactive_controls_or_duplicate_footer(): void
    {
        $form  = $this->form_fixture();
        $entry = $this->entry_fixture();
        $_GET['gf_page'] = 'print-entry';

        $html = $this->display->format_entry_detail_field_value(
            $entry['5'],
            $form['fields'][1],
            $entry,
            $form
        );

        $this->assertIsString( $html );
        $this->assertStringContainsString( 'sentient-forms-qna-panel--print', $html );
        $this->assertStringContainsString( '<table', $html );
        $this->assertStringNotContainsString( 'data-sf-qna-view-tab', $html );
        $this->assertStringNotContainsString( 'data-sf-qna-export', $html );

        ob_start();
        $this->display->render_print_entry_footer( $form, $entry );
        $footer = ob_get_clean();

        $this->assertSame( '', $footer );
    }

    public function test_print_view_high_volume_qna_remains_complete_without_review_controls(): void
    {
        $form    = $this->form_fixture();
        $payload = $this->high_volume_payload_fixture( 19 );
        $entry   = [
            'id'      => 243,
            'form_id' => 4,
            '1'       => 'High Volume Print Example',
            '5'       => $payload,
        ];
        $_GET['gf_page'] = 'print-entry';

        $html = $this->display->format_entry_detail_field_value(
            $payload,
            $form['fields'][1],
            $entry,
            $form
        );

        $this->assertIsString( $html );
        $this->assertStringContainsString( 'sentient-forms-qna-panel--print', $html );
        $this->assertStringContainsString( 'Synthetic stress question 19?', $html );
        $this->assertStringNotContainsString( 'data-sf-qna-review-toolbar', $html );
        $this->assertStringNotContainsString( 'data-sf-qna-card-toggle', $html );
        $this->assertStringNotContainsString( 'sentient-forms-qna-icon-button', $html );
        $this->assertStringNotContainsString( 'data-sf-qna-filter', $html );
        $this->assertStringNotContainsString( 'data-sf-qna-export', $html );
    }

    public function test_print_footer_falls_back_when_hidden_field_row_is_not_rendered(): void
    {
        $form  = $this->form_fixture();
        $entry = $this->entry_fixture();

        ob_start();
        $this->display->render_print_entry_footer( $form, $entry );
        $html = ob_get_clean();

        $this->assertStringContainsString( 'sentient-forms-qna-panel--print', $html );
        $this->assertStringContainsString( 'What budget range should we plan around?', $html );
        $this->assertStringNotContainsString( 'data-sf-qna-view-tab', $html );
    }

    public function test_malformed_storage_value_gets_small_error_not_fatal(): void
    {
        $form = $this->form_fixture();
        $bad_json = '{"schema":"sentient_forms_realtime_clarification_qna.v1",';

        $html = $this->display->format_entry_detail_field_value(
            $bad_json,
            $form['fields'][1],
            [ 'id' => 25, 'form_id' => 4, '5' => $bad_json ],
            $form
        );

        $this->assertIsString( $html );
        $this->assertStringContainsString( 'Realtime Q&amp;A could not be displayed.', $html );
        $this->assertStringContainsString( 'Raw data', $html );
    }

    public function test_malformed_entry_detail_admin_render_includes_qna_assets_around_panel(): void
    {
        $_GET['page'] = 'gf_entries';
        set_current_screen( 'dashboard' );
        $this->reset_qna_assets();
        $this->fire_admin_print_styles_without_emoji();

        $form     = $this->form_fixture();
        $bad_json = '{"schema":"sentient_forms_realtime_clarification_qna.v1",';

        $html = $this->display->format_entry_detail_field_value(
            $bad_json,
            $form['fields'][1],
            [ 'id' => 27, 'form_id' => 4, '5' => $bad_json ],
            $form
        );

        $this->assertIsString( $html );
        $stylesheet_position = strpos( $html, 'sentient-forms-gravity-qna-admin-css' );
        $panel_position      = strpos( $html, 'sentient-forms-qna-panel--error' );

        $this->assertIsInt( $stylesheet_position );
        $this->assertIsInt( $panel_position );
        $this->assertLessThan( $panel_position, $stylesheet_position );
        $this->assertStringContainsString( 'sentientFormsGravityQnaAdminInit', $html );
    }

    public function test_empty_storage_value_stays_empty_instead_of_erroring(): void
    {
        $form = $this->form_fixture();

        $html = $this->display->format_entry_detail_field_value(
            '',
            $form['fields'][1],
            [ 'id' => 26, 'form_id' => 4, '5' => '' ],
            $form
        );

        $this->assertSame( '', $html );
    }

    /**
     * @return array<string,mixed>
     */
    private function form_fixture(): array
    {
        return [
            'id'     => 4,
            'title'  => 'Admin Q&A Form',
            'fields' => [
                (object) [
                    'id'    => 1,
                    'type'  => 'text',
                    'label' => 'Name',
                ],
                (object) [
                    'id'                           => 5,
                    'type'                         => 'hidden',
                    'label'                        => 'Sentient Forms Realtime Q&A',
                    'adminLabel'                   => 'Sentient Forms Realtime Q&A',
                    'inputName'                    => 'sentient_forms_realtime_qna',
                    'cssClass'                     => 'sentient-forms-realtime-qna-storage',
                    'sentientFormsRealtimeStorage' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function entry_fixture(): array
    {
        return [
            'id'      => 182,
            'form_id' => 4,
            '1'       => 'Jane Example',
            '5'       => $this->payload_fixture(),
        ];
    }

    private function payload_fixture(): string
    {
        return (string) wp_json_encode(
            [
                'schema'     => 'sentient_forms_realtime_clarification_qna.v1',
                'form_id'    => '4',
                'source'     => 'gravity_forms',
                'updated_at' => '2026-05-03T11:30:00Z',
                'mappings'   => [
                    [
                        'mapping_id'        => 'mapping-rt-1',
                        'central_action_id' => 'clarification_assistant_v1',
                        'action_name_label' => 'Realtime verification assistant',
                        'questions'         => [
                            [
                                'question_id'     => 'budget',
                                'question'        => 'What budget range should we plan around?',
                                'reason'          => 'The request mentions multiple options but no budget.',
                                'target_field_id' => '3',
                                'required'        => true,
                                'answer_type'     => 'short_text',
                                'answer'          => 'Blue widgets under $5,000',
                                'completed'       => true,
                            ],
                            [
                                'question_id'     => 'timeline',
                                'question'        => 'When do you need the first follow-up?',
                                'reason'          => 'Timeline affects routing.',
                                'target_field_id' => '4',
                                'required'        => true,
                                'answer_type'     => 'short_text',
                                'answer'          => '',
                                'completed'       => false,
                            ],
                        ],
                    ],
                ],
            ]
        );
    }

    private function reset_qna_assets(): void
    {
        wp_dequeue_style( 'sentient-forms-gravity-qna-admin' );
        wp_deregister_style( 'sentient-forms-gravity-qna-admin' );
        if ( $this->can_use_wp_scripts_registry() )
        {
            wp_dequeue_script( 'sentient-forms-gravity-qna-admin' );
            wp_deregister_script( 'sentient-forms-gravity-qna-admin' );
        }

        wp_styles()->done  = array_values( array_diff( wp_styles()->done, [ 'sentient-forms-gravity-qna-admin' ] ) );
        if ( $this->can_use_wp_scripts_registry() )
        {
            wp_scripts()->done = array_values( array_diff( wp_scripts()->done, [ 'sentient-forms-gravity-qna-admin' ] ) );
        }
    }

    /**
     * @return array<string,int|null>
     */
    private function capture_action_counts(): array
    {
        $counts = [];
        foreach ( $this->qna_asset_timing_actions() as $action )
        {
            $counts[ $action ] = $GLOBALS['wp_actions'][ $action ] ?? null;
        }

        return $counts;
    }

    private function restore_action_counts(): void
    {
        foreach ( $this->original_action_counts as $action => $count )
        {
            if ( null === $count )
            {
                unset( $GLOBALS['wp_actions'][ $action ] );
                continue;
            }

            $GLOBALS['wp_actions'][ $action ] = $count;
        }
    }

    private function capture_global_state(): void
    {
        $this->had_original_current_screen    = array_key_exists( 'current_screen', $GLOBALS );
        $this->original_current_screen        = $this->had_original_current_screen ? $GLOBALS['current_screen'] : null;
        $this->original_ajax_filter_priorities = $this->capture_ajax_filter_priorities();
    }

    private function restore_global_state(): void
    {
        foreach ( $this->capture_ajax_filter_priorities() as $priority )
        {
            remove_filter( 'wp_doing_ajax', '__return_true', $priority );
        }

        foreach ( $this->original_ajax_filter_priorities as $priority )
        {
            add_filter( 'wp_doing_ajax', '__return_true', $priority );
        }

        if ( $this->had_original_current_screen )
        {
            $GLOBALS['current_screen'] = $this->original_current_screen;
            return;
        }

        unset( $GLOBALS['current_screen'] );
    }

    private function capture_qna_asset_state(): void
    {
        $this->original_qna_asset_state = [
            'styles'  => $this->capture_dependency_handle_state( wp_styles(), 'sentient-forms-gravity-qna-admin' ),
            'scripts' => $this->can_use_wp_scripts_registry()
                ? $this->capture_dependency_handle_state( wp_scripts(), 'sentient-forms-gravity-qna-admin' )
                : null,
        ];
    }

    private function restore_qna_asset_state(): void
    {
        $this->reset_qna_assets();
        $this->restore_dependency_handle_state(
            wp_styles(),
            'sentient-forms-gravity-qna-admin',
            $this->original_qna_asset_state['styles']
        );
        if ( $this->can_use_wp_scripts_registry() && is_array( $this->original_qna_asset_state['scripts'] ?? null ) )
        {
            $this->restore_dependency_handle_state(
                wp_scripts(),
                'sentient-forms-gravity-qna-admin',
                $this->original_qna_asset_state['scripts']
            );
        }
    }

    private function can_use_wp_scripts_registry(): bool
    {
        return file_exists( ABSPATH . WPINC . '/assets/script-loader-react-refresh-entry.php' );
    }

    /**
     * @return array<int,int>
     */
    private function capture_ajax_filter_priorities(): array
    {
        $priorities = [];
        $hook       = $GLOBALS['wp_filter']['wp_doing_ajax'] ?? null;
        $callbacks  = is_object( $hook ) && isset( $hook->callbacks ) ? $hook->callbacks : [];

        foreach ( $callbacks as $priority => $priority_callbacks )
        {
            if ( isset( $priority_callbacks['__return_true'] ) )
            {
                $priorities[] = (int) $priority;
            }
        }

        return $priorities;
    }

    /**
     * @param WP_Dependencies $dependencies Dependencies registry.
     * @return array<string,mixed>
     */
    private function capture_dependency_handle_state( $dependencies, string $handle ): array
    {
        return [
            'registered' => isset( $dependencies->registered[ $handle ] ) ? clone $dependencies->registered[ $handle ] : null,
            'queue'      => in_array( $handle, $dependencies->queue, true ),
            'to_do'      => in_array( $handle, $dependencies->to_do, true ),
            'done'       => in_array( $handle, $dependencies->done, true ),
            'args'       => $this->capture_dependency_handle_metadata( $dependencies, 'args', $handle ),
            'groups'     => $this->capture_dependency_handle_metadata( $dependencies, 'groups', $handle ),
        ];
    }

    /**
     * @param WP_Dependencies     $dependencies Dependencies registry.
     * @param array<string,mixed> $state Captured dependency state.
     */
    private function restore_dependency_handle_state( $dependencies, string $handle, array $state ): void
    {
        if ( null === $state['registered'] )
        {
            unset( $dependencies->registered[ $handle ] );
        }
        else
        {
            $dependencies->registered[ $handle ] = clone $state['registered'];
        }

        $dependencies->queue = $this->restore_handle_membership( $dependencies->queue, $handle, (bool) $state['queue'] );
        $dependencies->to_do = $this->restore_handle_membership( $dependencies->to_do, $handle, (bool) $state['to_do'] );
        $dependencies->done  = $this->restore_handle_membership( $dependencies->done, $handle, (bool) $state['done'] );
        $this->restore_dependency_handle_metadata( $dependencies, 'args', $handle, $state['args'] );
        $this->restore_dependency_handle_metadata( $dependencies, 'groups', $handle, $state['groups'] );
    }

    /**
     * @param WP_Dependencies $dependencies Dependencies registry.
     * @return array{exists:bool,value:mixed}
     */
    private function capture_dependency_handle_metadata( $dependencies, string $property, string $handle ): array
    {
        $values = property_exists( $dependencies, $property ) && is_array( $dependencies->{$property} )
            ? $dependencies->{$property}
            : [];

        return [
            'exists' => array_key_exists( $handle, $values ),
            'value'  => $values[ $handle ] ?? null,
        ];
    }

    /**
     * @param WP_Dependencies              $dependencies Dependencies registry.
     * @param array{exists:bool,value:mixed} $state Captured property state.
     */
    private function restore_dependency_handle_metadata( $dependencies, string $property, string $handle, array $state ): void
    {
        if ( ! property_exists( $dependencies, $property ) || ! is_array( $dependencies->{$property} ) )
        {
            return;
        }

        if ( $state['exists'] )
        {
            $dependencies->{$property}[ $handle ] = $state['value'];
            return;
        }

        unset( $dependencies->{$property}[ $handle ] );
    }

    /**
     * @param array<int,string> $handles Existing handles.
     * @return array<int,string>
     */
    private function restore_handle_membership( array $handles, string $handle, bool $should_exist ): array
    {
        $handles = array_values( array_diff( $handles, [ $handle ] ) );

        if ( $should_exist )
        {
            $handles[] = $handle;
        }

        return $handles;
    }

    /**
     * @return array<int,string>
     */
    private function qna_asset_timing_actions(): array
    {
        return [
            'admin_print_styles',
            'admin_print_footer_scripts',
            'wp_print_footer_scripts',
            'wp_print_scripts',
        ];
    }

    private function mark_qna_script_done(): void
    {
        $wp_scripts = wp_scripts();
        if ( ! in_array( 'sentient-forms-gravity-qna-admin', $wp_scripts->done, true ) )
        {
            $wp_scripts->done[] = 'sentient-forms-gravity-qna-admin';
        }
    }

    private function fire_admin_print_styles_without_emoji(): void
    {
        $emoji_styles_priority = has_action( 'admin_print_styles', 'print_emoji_styles' );
        if ( false !== $emoji_styles_priority )
        {
            remove_action( 'admin_print_styles', 'print_emoji_styles', $emoji_styles_priority );
        }

        ob_start();
        do_action( 'admin_print_styles' );
        ob_end_clean();

        if ( false !== $emoji_styles_priority )
        {
            add_action( 'admin_print_styles', 'print_emoji_styles', $emoji_styles_priority );
        }
    }

    private function high_volume_payload_fixture( int $count ): string
    {
        $questions = [];

        for ( $index = 1; $index <= $count; $index++ )
        {
            $required_open = 0 === $index % 4;
            $plain_open    = 0 === $index % 7;
            $completed     = ! $required_open && ! $plain_open && 1 === $index % 3;
            $answer        = $required_open || $plain_open ? '' : sprintf( 'Synthetic answer %d', $index );

            $questions[] = [
                'question_id'     => sprintf( 'stress-%d', $index ),
                'question'        => sprintf( 'Synthetic stress question %d?', $index ),
                'reason'          => sprintf( 'Synthetic reason %d keeps this card representative of real assistant context.', $index ),
                'target_field_id' => (string) ( 10 + $index ),
                'required'        => $required_open,
                'answer_type'     => 'short_text',
                'answer'          => $answer,
                'completed'       => $completed,
            ];
        }

        return (string) wp_json_encode(
            [
                'schema'     => 'sentient_forms_realtime_clarification_qna.v1',
                'form_id'    => '4',
                'source'     => 'gravity_forms',
                'updated_at' => '2026-05-03T19:30:00Z',
                'mappings'   => [
                    [
                        'mapping_id'        => 'mapping-stress-1',
                        'central_action_id' => 'clarification_assistant_v1',
                        'action_name_label' => 'Realtime verification assistant',
                        'questions'         => $questions,
                    ],
                ],
            ]
        );
    }
}
