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

    public function set_up(): void
    {
        parent::set_up();

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
        $this->assertSame( [ '1', 'date_created' ], RGFormsModel::$grid_column_meta[4] );
    }

    public function test_column_picker_prunes_storage_field_from_default_active_columns(): void
    {
        $form = $this->form_fixture();
        GFAPI::$forms[4] = $form;
        RGFormsModel::$grid_columns[4] = [
            '1'            => [ 'label' => 'Name' ],
            '2'            => [ 'label' => 'Email' ],
            '5'            => [ 'label' => 'Sentient Forms Realtime Q&A' ],
            'date_created' => [ 'label' => 'Entry Date' ],
        ];

        $_GET['gf_page'] = 'select_columns';
        $_GET['id']      = '4';

        $this->display->maybe_prune_storage_columns_for_column_picker();

        $this->assertSame( [ '1', '2', 'date_created' ], RGFormsModel::$grid_column_meta[4] );
    }

    public function test_column_picker_recovers_from_wp_list_table_column_ids(): void
    {
        $form = $this->form_fixture();
        GFAPI::$forms[4] = $form;
        RGFormsModel::$grid_column_meta[4] = [ 'cb', 'is_starred', 'field_id-1', 'field_id-5', 'column_selector' ];

        $_GET['gf_page'] = 'select_columns';
        $_GET['id']      = '4';

        $this->display->maybe_prune_storage_columns_for_column_picker();

        $this->assertSame( [ '1' ], RGFormsModel::$grid_column_meta[4] );
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
