<?php
/**
 * Gravity Forms admin display helpers for realtime clarification Q&A.
 *
 * @package SentientForms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Presents stored realtime virtual questions as readable admin UI.
 */
class Sentient_Forms_Realtime_Qna_Admin_Display
{
    private const SCHEMA = 'sentient_forms_realtime_clarification_qna.v1';
    private const STORAGE_FIELD_LABEL = 'Sentient Forms Realtime Q&A';
    private const STORAGE_FIELD_LEGACY_LABEL = 'Sentient Forms Clarification Q&A';
    private const STORAGE_FIELD_INPUT_NAME = 'sentient_forms_realtime_qna';
    private const STORAGE_FIELD_CLASS = 'sentient-forms-realtime-qna-storage';

    private static bool $hooks_registered = false;

    /**
     * Tracks entry-detail pages that already rendered the Q&A through the field-value filter.
     *
     * @var array<int,bool>
     */
    private array $entry_detail_ids_rendered = [];

    /**
     * Tracks print pages that already rendered the Q&A through the field-value filter.
     *
     * @var array<int,bool>
     */
    private array $print_entry_ids_rendered = [];

    public function register_hooks(): void
    {
        if ( self::$hooks_registered )
        {
            return;
        }

        self::$hooks_registered = true;

        add_filter( 'gform_display_field_select_columns_entry_list', [ $this, 'maybe_hide_storage_field_from_entry_columns_selector' ], 10, 3 );
        add_filter( 'gform_entry_list_columns', [ $this, 'remove_storage_columns_from_entry_list' ], 10, 2 );
        add_filter( 'gform_entries_field_value', [ $this, 'format_entry_list_field_value' ], 10, 4 );
        add_action( 'gform_entries_first_column', [ $this, 'render_entry_list_first_column_summary' ], 10, 5 );
        add_filter( 'gform_entry_field_value', [ $this, 'format_entry_detail_field_value' ], 10, 4 );
        add_action( 'gform_entry_detail', [ $this, 'render_entry_detail_fallback' ], 10, 2 );
        add_action( 'gform_print_entry_footer', [ $this, 'render_print_entry_footer' ], 10, 2 );
        add_action( 'admin_init', [ $this, 'maybe_prune_storage_columns_for_column_picker' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_print_assets' ] );
    }

    /**
     * @param bool  $display Current Gravity Forms display decision.
     * @param mixed $field   Current field object/array.
     * @param array $form    Current form.
     */
    public function maybe_hide_storage_field_from_entry_columns_selector( bool $display, mixed $field, array $form ): bool
    {
        return $this->is_storage_field( $field ) ? false : $display;
    }

    /**
     * @param array<string,string> $table_columns Entry list columns.
     *
     * @return array<string,string>
     */
    public function remove_storage_columns_from_entry_list( array $table_columns, int $form_id ): array
    {
        $form = $this->get_form( $form_id );
        if ( null === $form )
        {
            return $this->remove_storage_columns_by_label( $table_columns );
        }

        $storage_field_ids = $this->get_storage_field_ids( $form );
        if ( empty( $storage_field_ids ) )
        {
            return $this->remove_storage_columns_by_label( $table_columns );
        }

        $this->prune_storage_columns_from_grid_meta( $form_id, $form );

        foreach ( $table_columns as $column_id => $label )
        {
            if ( $this->is_storage_column_id( (string) $column_id, $storage_field_ids ) )
            {
                unset( $table_columns[ $column_id ] );
            }
        }

        return $this->remove_storage_columns_by_label( $table_columns );
    }

    public function maybe_prune_storage_columns_for_column_picker(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page detection; pruning hides an internal storage column from Gravity Forms' picker.
        $gf_page = isset( $_GET['gf_page'] ) && is_scalar( $_GET['gf_page'] )
            ? sanitize_key( wp_unslash( $_GET['gf_page'] ) )
            : '';
        if ( 'select_columns' !== $gf_page )
        {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page detection; see note above.
        $form_id = isset( $_GET['id'] ) && is_scalar( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
        $form    = $this->get_form( $form_id );
        if ( null === $form )
        {
            return;
        }

        $fallback_columns = [];
        if ( class_exists( 'RGFormsModel' ) && method_exists( 'RGFormsModel', 'get_grid_columns' ) )
        {
            $grid_columns = RGFormsModel::get_grid_columns( $form_id, true );
            if ( is_array( $grid_columns ) )
            {
                $fallback_columns = array_keys( $grid_columns );
            }
        }

        $this->prune_storage_columns_from_grid_meta( $form_id, $form, $fallback_columns );
    }

    /**
     * @param mixed $value    Current field value.
     * @param mixed $field_id Current field id.
     * @param array $entry    Current entry.
     */
    public function format_entry_list_field_value( mixed $value, int $form_id, mixed $field_id, array $entry ): mixed
    {
        $form = $this->get_form( $form_id );
        $summary = $this->parse_stored_payload( $value );
        if ( null === $summary )
        {
            return $value;
        }

        return $this->render_entry_list_cell_summary( $summary );
    }

    /**
     * @param mixed $field_id Current field id.
     * @param mixed $value    First-column value.
     * @param array $entry    Current entry.
     */
    public function render_entry_list_first_column_summary( int $form_id, mixed $field_id, mixed $value, array $entry, string $query_string = '' ): void
    {
        $summary = $this->get_entry_summary( $entry, $this->get_form( $form_id ) );
        if ( null === $summary )
        {
            return;
        }

        echo $this->render_entry_list_row_insert( $summary, $entry, $form_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup is escaped by renderer methods.
    }

    /**
     * @param mixed $value Current entry detail value.
     * @param mixed $field Current field object/array.
     * @param array $entry Current entry.
     * @param array $form  Current form.
     */
    public function format_entry_detail_field_value( mixed $value, mixed $field, array $entry, array $form ): mixed
    {
        $is_storage_field = $this->is_storage_field( $field );
        $summary          = $this->parse_stored_payload( $value );

        if ( ! $is_storage_field && null === $summary )
        {
            return $value;
        }

        if ( null === $summary )
        {
            if ( ! is_scalar( $value ) || '' === trim( (string) $value ) )
            {
                return '';
            }

            return $this->render_malformed_payload_notice( $value );
        }

        if ( $this->is_print_entry_request() )
        {
            $entry_id = $this->get_entry_id( $entry );
            if ( $entry_id > 0 )
            {
                $this->print_entry_ids_rendered[ $entry_id ] = true;
            }

            return $this->render_print_panel( $summary );
        }

        $entry_id = $this->get_entry_id( $entry );
        if ( $entry_id > 0 )
        {
            $this->entry_detail_ids_rendered[ $entry_id ] = true;
        }

        return $this->render_entry_detail_panel( $summary );
    }

    /**
     * @param array $form  Current form.
     * @param array $entry Current entry.
     */
    public function render_entry_detail_fallback( array $form, array $entry ): void
    {
        if ( $this->is_print_entry_request() )
        {
            return;
        }

        $entry_id = $this->get_entry_id( $entry );
        if ( $entry_id > 0 && ! empty( $this->entry_detail_ids_rendered[ $entry_id ] ) )
        {
            return;
        }

        $summary = $this->get_entry_summary( $entry, $form );
        if ( null === $summary )
        {
            return;
        }

        if ( $entry_id > 0 )
        {
            $this->entry_detail_ids_rendered[ $entry_id ] = true;
        }

        echo '<div class="sentient-forms-qna-entry-detail-fallback">' . $this->render_entry_detail_panel( $summary ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup is escaped by renderer methods.
    }

    /**
     * @param array $form  Current form.
     * @param array $entry Current entry.
     */
    public function render_print_entry_footer( array $form, array $entry ): void
    {
        $entry_id = $this->get_entry_id( $entry );
        if ( $entry_id > 0 && ! empty( $this->print_entry_ids_rendered[ $entry_id ] ) )
        {
            return;
        }

        $summary = $this->get_entry_summary( $entry, $form );
        if ( null === $summary )
        {
            return;
        }

        echo $this->render_print_panel( $summary ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup is escaped by renderer methods.
    }

    public function enqueue_admin_assets( string $hook_suffix ): void
    {
        if ( ! $this->is_gravity_entries_admin_request() )
        {
            return;
        }

        $this->enqueue_assets( true );
    }

    public function enqueue_print_assets(): void
    {
        if ( ! $this->is_print_entry_request() )
        {
            return;
        }

        $this->enqueue_assets( false );
    }

    /**
     * @param array<string,string> $table_columns Entry list columns.
     *
     * @return array<string,string>
     */
    private function remove_storage_columns_by_label( array $table_columns ): array
    {
        foreach ( $table_columns as $column_id => $label )
        {
            if ( in_array( wp_strip_all_tags( (string) $label ), [ self::STORAGE_FIELD_LABEL, self::STORAGE_FIELD_LEGACY_LABEL ], true ) )
            {
                unset( $table_columns[ $column_id ] );
            }
        }

        return $table_columns;
    }

    /**
     * @param array<string,mixed>    $form
     * @param array<int,int|string> $fallback_columns
     */
    private function prune_storage_columns_from_grid_meta( int $form_id, array $form, array $fallback_columns = [] ): void
    {
        if (
            $form_id <= 0
            || ! class_exists( 'RGFormsModel' )
            || ! method_exists( 'RGFormsModel', 'get_grid_column_meta' )
            || ! method_exists( 'RGFormsModel', 'update_grid_column_meta' )
        )
        {
            return;
        }

        $storage_field_ids = $this->get_storage_field_ids( $form );
        if ( empty( $storage_field_ids ) )
        {
            return;
        }

        $grid_columns       = RGFormsModel::get_grid_column_meta( $form_id );
        $raw_source_columns = is_array( $grid_columns ) ? $grid_columns : $fallback_columns;
        $source_columns     = $this->normalize_grid_column_ids( $raw_source_columns );
        if ( empty( $source_columns ) )
        {
            return;
        }

        $filtered_columns = array_values(
            array_filter(
                $source_columns,
                fn ( mixed $column_id ): bool => ! $this->is_storage_column_id( (string) $column_id, $storage_field_ids )
            )
        );

        if (
            $filtered_columns === array_values( $source_columns )
            && $filtered_columns === $this->stringify_scalar_column_ids( $raw_source_columns )
        )
        {
            return;
        }

        RGFormsModel::update_grid_column_meta( $form_id, $filtered_columns );
    }

    /**
     * @param array<int,mixed> $column_ids
     *
     * @return array<int,string>
     */
    private function stringify_scalar_column_ids( array $column_ids ): array
    {
        $string_ids = [];
        foreach ( $column_ids as $column_id )
        {
            if ( is_scalar( $column_id ) )
            {
                $string_ids[] = (string) $column_id;
            }
        }

        return $string_ids;
    }

    /**
     * @param array<int,mixed> $column_ids
     *
     * @return array<int,string>
     */
    private function normalize_grid_column_ids( array $column_ids ): array
    {
        $normalized = [];
        foreach ( $column_ids as $column_id )
        {
            if ( ! is_scalar( $column_id ) )
            {
                continue;
            }

            $column_id_string = (string) $column_id;
            if ( in_array( $column_id_string, [ 'cb', 'column_selector', 'is_starred' ], true ) )
            {
                continue;
            }

            if ( str_starts_with( $column_id_string, 'field_id-' ) )
            {
                $column_id_string = substr( $column_id_string, 9 );
            }

            if ( '' === $column_id_string || in_array( $column_id_string, $normalized, true ) )
            {
                continue;
            }

            $normalized[] = $column_id_string;
        }

        return $normalized;
    }

    /**
     * @param array<int,string> $storage_field_ids
     */
    private function is_storage_column_id( string $column_id, array $storage_field_ids ): bool
    {
        foreach ( $storage_field_ids as $field_id )
        {
            if (
                $column_id === $field_id
                || $column_id === 'field_id-' . $field_id
                || str_starts_with( $column_id, 'field_id-' . $field_id . '.' )
            )
            {
                return true;
            }
        }

        return false;
    }

    private function enqueue_assets( bool $include_script ): void
    {
        if ( ! defined( 'SENTIENT_FORMS_PLUGIN_URL' ) || ! defined( 'SENTIENT_FORMS_PLUGIN_DIR' ) )
        {
            return;
        }

        $style_path = 'assets/css/gravity-forms-qna-admin.css';
        wp_enqueue_style(
            'sentient-forms-gravity-qna-admin',
            SENTIENT_FORMS_PLUGIN_URL . $style_path,
            [],
            $this->get_asset_version( $style_path )
        );

        if ( ! $include_script )
        {
            return;
        }

        $script_path = 'assets/js/gravity-forms-qna-admin.js';
        wp_enqueue_script(
            'sentient-forms-gravity-qna-admin',
            SENTIENT_FORMS_PLUGIN_URL . $script_path,
            [],
            $this->get_asset_version( $script_path ),
            true
        );
    }

    private function get_asset_version( string $relative_path ): string
    {
        $fallback = defined( 'SENTIENT_FORMS_VERSION' ) ? SENTIENT_FORMS_VERSION : '0.1.0';
        if ( ! defined( 'SENTIENT_FORMS_PLUGIN_DIR' ) )
        {
            return $fallback;
        }

        $path = trailingslashit( SENTIENT_FORMS_PLUGIN_DIR ) . ltrim( $relative_path, '/\\' );
        if ( ! file_exists( $path ) )
        {
            return $fallback;
        }

        $mtime = filemtime( $path );

        return false === $mtime ? $fallback : $fallback . '-' . base_convert( (string) $mtime, 10, 36 );
    }

    private function is_gravity_entries_admin_request(): bool
    {
        if ( ! is_admin() )
        {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page detection for scoped asset loading.
        $page = isset( $_GET['page'] ) && is_scalar( $_GET['page'] )
            ? sanitize_key( wp_unslash( $_GET['page'] ) )
            : '';

        return 'gf_entries' === $page;
    }

    private function is_print_entry_request(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only Gravity Forms print page detection.
        $gf_page = isset( $_GET['gf_page'] ) && is_scalar( $_GET['gf_page'] )
            ? sanitize_key( wp_unslash( $_GET['gf_page'] ) )
            : '';

        return 'print-entry' === $gf_page;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function get_form( int $form_id ): ?array
    {
        if ( $form_id <= 0 || ! class_exists( 'GFAPI' ) || ! method_exists( 'GFAPI', 'get_form' ) )
        {
            return null;
        }

        $form = GFAPI::get_form( $form_id );

        return is_array( $form ) ? $form : null;
    }

    /**
     * @param array<string,mixed>|null $form
     *
     * @return array<string,mixed>|null
     */
    private function get_entry_summary( array $entry, ?array $form ): ?array
    {
        $payloads = $this->extract_entry_payloads( $entry, $form );
        if ( empty( $payloads ) )
        {
            return null;
        }

        if ( count( $payloads ) === 1 )
        {
            return $payloads[0];
        }

        return $this->merge_summaries( $payloads );
    }

    /**
     * @param array<string,mixed>|null $form
     *
     * @return array<int,array<string,mixed>>
     */
    private function extract_entry_payloads( array $entry, ?array $form ): array
    {
        $payloads = [];
        $seen     = [];

        if ( null !== $form )
        {
            foreach ( $this->get_storage_field_ids( $form ) as $field_id )
            {
                if ( ! array_key_exists( $field_id, $entry ) )
                {
                    continue;
                }

                $summary = $this->parse_stored_payload( $entry[ $field_id ] );
                if ( null === $summary )
                {
                    continue;
                }

                $fingerprint = md5( $summary['raw_json'] );
                if ( isset( $seen[ $fingerprint ] ) )
                {
                    continue;
                }

                $payloads[]            = $summary;
                $seen[ $fingerprint ] = true;
            }
        }

        if ( ! empty( $payloads ) )
        {
            return $payloads;
        }

        foreach ( $entry as $value )
        {
            if ( ! is_scalar( $value ) )
            {
                continue;
            }

            $value_string = (string) $value;
            if ( ! str_contains( $value_string, self::SCHEMA ) )
            {
                continue;
            }

            $summary = $this->parse_stored_payload( $value_string );
            if ( null === $summary )
            {
                continue;
            }

            $fingerprint = md5( $summary['raw_json'] );
            if ( isset( $seen[ $fingerprint ] ) )
            {
                continue;
            }

            $payloads[]            = $summary;
            $seen[ $fingerprint ] = true;
        }

        return $payloads;
    }

    /**
     * @param array<string,mixed> $form
     *
     * @return array<int,string>
     */
    private function get_storage_field_ids( array $form ): array
    {
        $field_ids = [];
        $fields    = isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : [];

        foreach ( $fields as $field )
        {
            if ( ! $this->is_storage_field( $field ) )
            {
                continue;
            }

            $field_id = $this->extract_field_property( $field, 'id' );
            if ( '' !== $field_id )
            {
                $field_ids[] = $field_id;
            }
        }

        return $field_ids;
    }

    /**
     * @param array<string,mixed> $form
     */
    private function is_storage_field_id( array $form, string $field_id ): bool
    {
        return in_array( $field_id, $this->get_storage_field_ids( $form ), true );
    }

    private function is_storage_field( mixed $field ): bool
    {
        $field_type = strtolower( $this->extract_field_property( $field, 'type' ) );
        if ( '' !== $field_type && ! in_array( $field_type, [ 'hidden', 'textarea' ], true ) )
        {
            return false;
        }

        if ( self::STORAGE_FIELD_INPUT_NAME === $this->extract_field_property( $field, 'inputName' ) )
        {
            return true;
        }

        if ( '1' === $this->extract_field_property( $field, 'sentientFormsRealtimeStorage' ) )
        {
            return true;
        }

        $css_class = ' ' . $this->extract_field_property( $field, 'cssClass' ) . ' ';
        if ( str_contains( $css_class, ' ' . self::STORAGE_FIELD_CLASS . ' ' ) )
        {
            return true;
        }

        return self::STORAGE_FIELD_LABEL === $this->extract_field_property( $field, 'adminLabel' )
            || self::STORAGE_FIELD_LABEL === $this->extract_field_property( $field, 'label' )
            || self::STORAGE_FIELD_LEGACY_LABEL === $this->extract_field_property( $field, 'adminLabel' )
            || self::STORAGE_FIELD_LEGACY_LABEL === $this->extract_field_property( $field, 'label' );
    }

    private function extract_field_property( mixed $field, string $property ): string
    {
        if ( is_array( $field ) && isset( $field[ $property ] ) && is_scalar( $field[ $property ] ) )
        {
            if ( is_bool( $field[ $property ] ) )
            {
                return $field[ $property ] ? '1' : '0';
            }

            return sanitize_text_field( (string) $field[ $property ] );
        }

        if ( is_object( $field ) && isset( $field->{$property} ) && is_scalar( $field->{$property} ) )
        {
            if ( is_bool( $field->{$property} ) )
            {
                return $field->{$property} ? '1' : '0';
            }

            return sanitize_text_field( (string) $field->{$property} );
        }

        return '';
    }

    /**
     * @return array<string,mixed>|null
     */
    private function parse_stored_payload( mixed $raw_value ): ?array
    {
        $raw_json = '';
        $decoded  = null;

        if ( is_array( $raw_value ) )
        {
            $decoded  = $raw_value;
            $raw_json = (string) wp_json_encode( $raw_value );
        }
        elseif ( is_scalar( $raw_value ) )
        {
            $raw_json = trim( (string) $raw_value );
            if ( '' === $raw_json || ! str_contains( $raw_json, self::SCHEMA ) )
            {
                return null;
            }

            $decoded = json_decode( $raw_json, true );
            if ( ! is_array( $decoded ) )
            {
                $unslashed = stripslashes( $raw_json );
                $decoded   = json_decode( $unslashed, true );
                if ( is_array( $decoded ) )
                {
                    $raw_json = $unslashed;
                }
            }

            if ( ! is_array( $decoded ) )
            {
                $entity_decoded = html_entity_decode( $raw_json, ENT_QUOTES, 'UTF-8' );
                $decoded        = json_decode( $entity_decoded, true );
                if ( is_array( $decoded ) )
                {
                    $raw_json = $entity_decoded;
                }
            }
        }

        if ( ! is_array( $decoded ) || ( $decoded['schema'] ?? '' ) !== self::SCHEMA )
        {
            return null;
        }

        return $this->build_summary_from_payload( $decoded, $raw_json );
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @return array<string,mixed>
     */
    private function build_summary_from_payload( array $payload, string $raw_json ): array
    {
        $mappings = isset( $payload['mappings'] ) && is_array( $payload['mappings'] )
            ? $payload['mappings']
            : [];

        if ( empty( $mappings ) && isset( $payload['questions'] ) && is_array( $payload['questions'] ) )
        {
            $mappings = [
                [
                    'mapping_id'        => '',
                    'central_action_id' => '',
                    'action_name_label' => __( 'Realtime assistant', 'sentient-forms' ),
                    'questions'         => $payload['questions'],
                ],
            ];
        }

        $normalized_mappings = [];
        $questions           = [];
        $answered_count      = 0;
        $completed_count     = 0;
        $required_open_count = 0;
        $question_index      = 1;

        foreach ( $mappings as $mapping )
        {
            if ( ! is_array( $mapping ) )
            {
                continue;
            }

            $mapping_questions = isset( $mapping['questions'] ) && is_array( $mapping['questions'] )
                ? $mapping['questions']
                : [];
            $action_label = $this->stringify_value( $mapping['action_name_label'] ?? '' );
            if ( '' === $action_label )
            {
                $action_label = __( 'Realtime assistant', 'sentient-forms' );
            }

            $normalized_mapping = [
                'mapping_id'        => $this->stringify_value( $mapping['mapping_id'] ?? '' ),
                'central_action_id' => $this->stringify_value( $mapping['central_action_id'] ?? '' ),
                'action_label'      => $action_label,
                'questions'         => [],
            ];

            foreach ( $mapping_questions as $question )
            {
                if ( ! is_array( $question ) )
                {
                    continue;
                }

                $answer     = $this->stringify_value( $question['answer'] ?? '' );
                $has_answer = '' !== trim( $answer );
                $completed  = $this->to_bool( $question['completed'] ?? false );
                $required   = $this->to_bool( $question['required'] ?? false );
                $status     = $completed ? 'completed' : ( $has_answer ? 'answered' : 'open' );

                if ( $has_answer )
                {
                    $answered_count++;
                }
                if ( $completed )
                {
                    $completed_count++;
                }
                if ( $required && ! $has_answer )
                {
                    $required_open_count++;
                }

                $normalized_question = [
                    'index'           => $question_index,
                    'question_id'     => $this->stringify_value( $question['question_id'] ?? '' ),
                    'question'        => $this->stringify_value( $question['question'] ?? '' ),
                    'reason'          => $this->stringify_value( $question['reason'] ?? '' ),
                    'target_field_id' => $this->stringify_value( $question['target_field_id'] ?? '' ),
                    'answer_type'     => $this->stringify_value( $question['answer_type'] ?? 'long_text' ),
                    'answer'          => $answer,
                    'required'        => $required,
                    'completed'       => $completed,
                    'has_answer'      => $has_answer,
                    'status'          => $status,
                    'action_label'    => $action_label,
                ];

                $questions[] = $normalized_question;
                $normalized_mapping['questions'][] = $normalized_question;
                $question_index++;
            }

            $normalized_mappings[] = $normalized_mapping;
        }

        $total_questions = count( $questions );
        $open_count      = max( 0, $total_questions - $answered_count );

        $summary = [
            'schema'              => self::SCHEMA,
            'form_id'             => $this->stringify_value( $payload['form_id'] ?? '' ),
            'source'              => $this->stringify_value( $payload['source'] ?? '' ),
            'updated_at'          => $this->stringify_value( $payload['updated_at'] ?? '' ),
            'updated_at_display'  => $this->format_timestamp( $this->stringify_value( $payload['updated_at'] ?? '' ) ),
            'mappings'            => $normalized_mappings,
            'questions'           => $questions,
            'total_questions'     => $total_questions,
            'answered_count'      => $answered_count,
            'completed_count'     => $completed_count,
            'open_count'          => $open_count,
            'required_open_count' => $required_open_count,
            'raw_json'            => $raw_json,
        ];

        $summary['summary_text'] = $this->build_summary_text( $summary );
        $summary['table_text']   = $this->build_table_text( $summary );
        $summary['csv_text']     = $this->build_csv_text( $summary );

        return $summary;
    }

    /**
     * @param array<int,array<string,mixed>> $summaries
     *
     * @return array<string,mixed>
     */
    private function merge_summaries( array $summaries ): array
    {
        $first      = $summaries[0];
        $questions  = [];
        $mappings   = [];
        $raw_values = [];

        foreach ( $summaries as $summary )
        {
            $questions  = array_merge( $questions, $summary['questions'] );
            $mappings   = array_merge( $mappings, $summary['mappings'] );
            $raw_values[] = $summary['raw_json'];
        }

        $first['questions']           = array_values( $questions );
        $first['mappings']            = array_values( $mappings );
        $first['total_questions']     = count( $questions );
        $first['answered_count']      = count( array_filter( $questions, static fn ( array $question ): bool => ! empty( $question['has_answer'] ) ) );
        $first['completed_count']     = count( array_filter( $questions, static fn ( array $question ): bool => ! empty( $question['completed'] ) ) );
        $first['open_count']          = max( 0, $first['total_questions'] - $first['answered_count'] );
        $first['required_open_count'] = count(
            array_filter(
                $questions,
                static fn ( array $question ): bool => ! empty( $question['required'] ) && empty( $question['has_answer'] )
            )
        );
        $first['raw_json']            = '[' . implode( ',', $raw_values ) . ']';
        $first['summary_text']        = $this->build_summary_text( $first );
        $first['table_text']          = $this->build_table_text( $first );
        $first['csv_text']            = $this->build_csv_text( $first );

        return $first;
    }

    private function stringify_value( mixed $value ): string
    {
        if ( is_bool( $value ) )
        {
            return $value ? __( 'Yes', 'sentient-forms' ) : __( 'No', 'sentient-forms' );
        }

        if ( is_scalar( $value ) )
        {
            return trim( wp_strip_all_tags( (string) $value ) );
        }

        if ( is_array( $value ) || is_object( $value ) )
        {
            $json = wp_json_encode( $value );

            return is_string( $json ) ? trim( wp_strip_all_tags( $json ) ) : '';
        }

        return '';
    }

    private function to_bool( mixed $value ): bool
    {
        if ( is_bool( $value ) )
        {
            return $value;
        }

        if ( is_numeric( $value ) )
        {
            return (int) $value === 1;
        }

        if ( is_string( $value ) )
        {
            return in_array( strtolower( $value ), [ '1', 'true', 'yes', 'on' ], true );
        }

        return false;
    }

    private function format_timestamp( string $timestamp ): string
    {
        if ( '' === $timestamp )
        {
            return '';
        }

        $unix = strtotime( $timestamp );
        if ( false === $unix )
        {
            return $timestamp;
        }

        return date_i18n(
            trim( get_option( 'date_format', 'M j, Y' ) . ' ' . get_option( 'time_format', 'g:i a' ) ),
            $unix
        );
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function build_summary_text( array $summary ): string
    {
        $lines = [
            sprintf(
                /* translators: 1: answered count, 2: total count */
                __( 'Realtime Q&A: %1$d of %2$d answered', 'sentient-forms' ),
                (int) $summary['answered_count'],
                (int) $summary['total_questions']
            ),
        ];

        if ( ! empty( $summary['updated_at_display'] ) )
        {
            $lines[] = sprintf(
                /* translators: %s: formatted date */
                __( 'Updated: %s', 'sentient-forms' ),
                $summary['updated_at_display']
            );
        }

        foreach ( $summary['questions'] as $question )
        {
            $lines[] = sprintf(
                '#%d %s',
                (int) $question['index'],
                $question['question']
            );
            $lines[] = sprintf(
                /* translators: %s: answer text */
                __( 'Answer: %s', 'sentient-forms' ),
                '' === $question['answer'] ? __( 'Not answered', 'sentient-forms' ) : $question['answer']
            );
        }

        return implode( "\n", $lines );
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function build_table_text( array $summary ): string
    {
        $rows = [
            implode( "\t", [ 'Question', 'Answer', 'Status', 'Action', 'Target field' ] ),
        ];

        foreach ( $summary['questions'] as $question )
        {
            $rows[] = implode(
                "\t",
                [
                    $this->compact_export_cell( $question['question'] ),
                    $this->compact_export_cell( '' === $question['answer'] ? __( 'Not answered', 'sentient-forms' ) : $question['answer'] ),
                    $this->get_status_label( $question ),
                    $this->compact_export_cell( $question['action_label'] ),
                    $this->compact_export_cell( $question['target_field_id'] ),
                ]
            );
        }

        return implode( "\n", $rows );
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function build_csv_text( array $summary ): string
    {
        $rows = [
            [ 'Question', 'Answer', 'Status', 'Action', 'Target field' ],
        ];

        foreach ( $summary['questions'] as $question )
        {
            $rows[] = [
                $question['question'],
                '' === $question['answer'] ? __( 'Not answered', 'sentient-forms' ) : $question['answer'],
                $this->get_status_label( $question ),
                $question['action_label'],
                $question['target_field_id'],
            ];
        }

        return implode(
            "\n",
            array_map(
                fn ( array $row ): string => implode( ',', array_map( [ $this, 'csv_escape' ], $row ) ),
                $rows
            )
        );
    }

    private function compact_export_cell( mixed $value ): string
    {
        return preg_replace( '/\s+/', ' ', trim( (string) $value ) ) ?? '';
    }

    private function csv_escape( mixed $value ): string
    {
        return '"' . str_replace( '"', '""', (string) $value ) . '"';
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function render_entry_list_cell_summary( array $summary ): string
    {
        return sprintf(
            '<span class="sentient-forms-qna-cell-summary">%s <strong>%s</strong></span>',
            esc_html__( 'Realtime Q&A', 'sentient-forms' ),
            esc_html( $this->get_count_label( $summary ) )
        );
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function render_entry_list_row_insert( array $summary, array $entry, int $form_id ): string
    {
        $entry_id   = $this->get_entry_id( $entry );
        $panel_id   = 'sentient-forms-qna-row-' . $form_id . '-' . $entry_id;
        $detail_url = $entry_id > 0
            ? add_query_arg(
                [
                    'page' => 'gf_entries',
                    'view' => 'entry',
                    'id'   => $form_id,
                    'lid'  => $entry_id,
                ],
                admin_url( 'admin.php' )
            )
            : '';

        $actions = [
            sprintf(
                '<button type="button" class="button-link sentient-forms-qna-row__toggle" data-sf-qna-row-toggle aria-controls="%s" aria-expanded="false">%s</button>',
                esc_attr( $panel_id ),
                esc_html__( 'Preview Q&A', 'sentient-forms' )
            ),
            sprintf(
                '<button type="button" class="button-link sentient-forms-qna-copy" data-sf-qna-copy="summary">%s</button>',
                esc_html__( 'Copy summary', 'sentient-forms' )
            ),
            sprintf(
                '<button type="button" class="button-link sentient-forms-qna-copy" data-sf-qna-copy="table">%s</button>',
                esc_html__( 'Copy table', 'sentient-forms' )
            ),
        ];

        if ( '' !== $detail_url )
        {
            array_unshift(
                $actions,
                sprintf(
                    '<a class="sentient-forms-qna-row__detail" href="%s">%s</a>',
                    esc_url( $detail_url ),
                    esc_html__( 'View Q&A', 'sentient-forms' )
                )
            );
        }

        return sprintf(
            '<div class="sentient-forms-qna-row" data-sf-qna-row>%s<div class="sentient-forms-qna-row__actions">%s</div><div id="%s" class="sentient-forms-qna-row__preview" data-sf-qna-row-preview hidden>%s</div>%s</div>',
            $this->render_summary_header( $summary, 'row' ),
            implode( ' <span class="sentient-forms-qna-row__separator">|</span> ', $actions ),
            esc_attr( $panel_id ),
            $this->render_compact_question_cards( $summary, 3 ),
            $this->render_copy_sources( $summary )
        );
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function render_entry_detail_panel( array $summary ): string
    {
        $high_volume = $this->is_high_volume_summary( $summary );

        return sprintf(
            '<section class="sentient-forms-qna-panel%s" data-sf-qna-panel data-sf-qna-total-questions="%d" data-sf-qna-high-volume="%s">%s%s%s%s%s%s</section>',
            $high_volume ? ' sentient-forms-qna-panel--high-volume' : '',
            (int) $summary['total_questions'],
            $high_volume ? 'true' : 'false',
            $this->render_panel_header( $summary ),
            $this->render_view_tabs(),
            $this->render_cards_view( $summary ),
            $this->render_table_view( $summary ),
            $this->render_json_view( $summary ),
            $this->render_copy_sources( $summary )
        );
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function render_print_panel( array $summary ): string
    {
        return sprintf(
            '<section class="sentient-forms-qna-panel sentient-forms-qna-panel--print">%s%s</section>',
            $this->render_print_summary_header( $summary ),
            $this->render_table( $summary, true )
        );
    }

    /**
     * The Gravity Forms print page does not reliably load plugin stylesheets, so
     * this header must remain readable as plain HTML.
     *
     * @param array<string,mixed> $summary
     */
    private function render_print_summary_header( array $summary ): string
    {
        $parts = [
            sprintf(
                '<strong>%s:</strong> %s',
                esc_html__( 'Realtime Q&A', 'sentient-forms' ),
                esc_html( $this->get_count_label( $summary ) )
            ),
        ];

        if ( ! empty( $summary['updated_at_display'] ) )
        {
            $parts[] = esc_html(
                sprintf(
                    /* translators: %s: formatted date */
                    __( 'Updated %s', 'sentient-forms' ),
                    $summary['updated_at_display']
                )
            );
        }

        return '<p class="sentient-forms-qna-print-summary">' . implode( '<br>', $parts ) . '</p>';
    }

    private function render_malformed_payload_notice( mixed $value ): string
    {
        return sprintf(
            '<div class="sentient-forms-qna-panel sentient-forms-qna-panel--error"><strong>%s</strong><p>%s</p><details><summary>%s</summary><pre>%s</pre></details></div>',
            esc_html__( 'Realtime Q&A could not be displayed.', 'sentient-forms' ),
            esc_html__( 'The stored field is present, but its data is not in the expected format.', 'sentient-forms' ),
            esc_html__( 'Raw data', 'sentient-forms' ),
            esc_html( is_scalar( $value ) ? (string) $value : '' )
        );
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function render_panel_header( array $summary ): string
    {
        return sprintf(
            '<header class="sentient-forms-qna-panel__header">%s<div class="sentient-forms-qna-panel__actions">%s%s%s</div></header>',
            $this->render_summary_header( $summary, 'detail' ),
            $this->render_panel_button( __( 'Summary', 'sentient-forms' ), __( 'Copy Q&A summary', 'sentient-forms' ), 'summary', 'sentient-forms-qna-copy', 'copy' ),
            $this->render_panel_button( __( 'Table', 'sentient-forms' ), __( 'Copy Q&A table', 'sentient-forms' ), 'table', 'sentient-forms-qna-copy', 'copy' ),
            $this->render_panel_button( __( 'CSV', 'sentient-forms' ), __( 'Download Q&A CSV', 'sentient-forms' ), 'csv', 'sentient-forms-qna-export', 'download' )
        );
    }

    private function render_panel_button( string $label, string $accessible_label, string $target, string $class, string $icon ): string
    {
        $attribute = str_contains( $class, 'export' ) ? 'data-sf-qna-export' : 'data-sf-qna-copy';

        return sprintf(
            '<button type="button" class="sentient-forms-qna-action-button %s" %s="%s" data-sf-qna-default-label="%s" aria-label="%s">%s<span class="sentient-forms-qna-action-button__label" data-sf-qna-button-label>%s</span></button>',
            esc_attr( $class ),
            esc_attr( $attribute ),
            esc_attr( $target ),
            esc_attr( $label ),
            esc_attr( $accessible_label ),
            $this->render_panel_button_icon( $icon ),
            esc_html( $label )
        );
    }

    private function render_panel_button_icon( string $icon ): string
    {
        $icons = [
            'copy'     => '<rect x="7" y="5" width="9" height="11" rx="1.5"></rect><path d="M4 12.5V3.75C4 2.78 4.78 2 5.75 2h7.75"></path>',
            'download' => '<path d="M10 3v9"></path><path d="m6.75 8.75 3.25 3.25 3.25-3.25"></path><path d="M4 16.5h12"></path>',
        ];

        if ( ! isset( $icons[ $icon ] ) )
        {
            return '';
        }

        return sprintf(
            '<svg class="sentient-forms-qna-action-button__icon" aria-hidden="true" focusable="false" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">%s</svg>',
            $icons[ $icon ]
        );
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function render_summary_header( array $summary, string $context ): string
    {
        $updated_at = (string) ( $summary['updated_at_display'] ?? '' );
        $meta       = '' !== $updated_at
            ? sprintf(
                '<span class="sentient-forms-qna-summary__updated">%s</span>',
                esc_html(
                    sprintf(
                        /* translators: %s: formatted date */
                        __( 'Updated %s', 'sentient-forms' ),
                        $updated_at
                    )
                )
            )
            : '';

        return sprintf(
            '<div class="sentient-forms-qna-summary sentient-forms-qna-summary--%s"><span class="sentient-forms-qna-pill">%s</span><strong class="sentient-forms-qna-summary__count">%s</strong>%s%s</div>',
            esc_attr( $context ),
            esc_html__( 'Realtime Q&A', 'sentient-forms' ),
            esc_html( $this->get_count_label( $summary ) ),
            $this->render_required_open_badge( $summary ),
            $meta
        );
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function render_required_open_badge( array $summary ): string
    {
        if ( empty( $summary['required_open_count'] ) )
        {
            return '';
        }

        return sprintf(
            '<span class="sentient-forms-qna-pill sentient-forms-qna-pill--attention">%s</span>',
            esc_html(
                sprintf(
                    /* translators: %d: count of required open questions */
                    _n( '%d required open', '%d required open', (int) $summary['required_open_count'], 'sentient-forms' ),
                    (int) $summary['required_open_count']
                )
            )
        );
    }

    private function render_view_tabs(): string
    {
        $tabs = [
            [ 'cards', __( 'Cards', 'sentient-forms' ), 'cards', true ],
            [ 'table', __( 'Table', 'sentient-forms' ), 'table', false ],
            [ 'json', __( 'JSON', 'sentient-forms' ), 'code', false ],
        ];

        $buttons = array_map(
            function ( array $tab ): string {
                [ $view, $label, $icon, $active ] = $tab;

                return sprintf(
                    '<button type="button" class="sentient-forms-qna-tab sentient-forms-qna-icon-button%s" role="tab" aria-selected="%s" data-sf-qna-view-tab="%s" aria-label="%s" title="%s" data-sf-qna-tooltip="%s">%s<span class="screen-reader-text">%s</span></button>',
                    $active ? ' is-active' : '',
                    $active ? 'true' : 'false',
                    esc_attr( $view ),
                    esc_attr( $label ),
                    esc_attr( $label ),
                    esc_attr( $label ),
                    $this->render_qna_icon( $icon ),
                    esc_html( $label )
                );
            },
            $tabs
        );

        return '<div class="sentient-forms-qna-tabs" role="tablist" aria-label="' . esc_attr__( 'Realtime Q&A views', 'sentient-forms' ) . '">' . implode( '', $buttons ) . '</div>';
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function render_cards_view( array $summary ): string
    {
        $review_mode = $this->is_high_volume_summary( $summary );

        return sprintf(
            '<div class="sentient-forms-qna-view" data-sf-qna-view-panel="cards">%s%s</div>',
            $review_mode ? $this->render_review_toolbar( $summary ) : $this->render_detail_toolbar( $summary ),
            $this->render_question_cards( $summary, $review_mode, true )
        );
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function render_table_view( array $summary ): string
    {
        return sprintf(
            '<div class="sentient-forms-qna-view" data-sf-qna-view-panel="table" hidden>%s</div>',
            $this->render_table( $summary, false )
        );
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function render_json_view( array $summary ): string
    {
        $decoded = json_decode( (string) $summary['raw_json'], true );
        $pretty  = is_array( $decoded ) ? wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) : $summary['raw_json'];

        return sprintf(
            '<div class="sentient-forms-qna-view" data-sf-qna-view-panel="json" hidden><pre class="sentient-forms-qna-json">%s</pre></div>',
            esc_html( is_string( $pretty ) ? $pretty : (string) $summary['raw_json'] )
        );
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function render_question_cards( array $summary, bool $review_mode = false, bool $show_detail_controls = false ): string
    {
        if ( empty( $summary['questions'] ) )
        {
            return sprintf(
                '<p class="sentient-forms-qna-empty">%s</p>',
                esc_html__( 'No realtime questions were stored for this entry.', 'sentient-forms' )
            );
        }

        $questions = $review_mode ? $this->get_review_questions( $summary ) : $summary['questions'];
        $cards = array_map(
            fn ( array $question ): string => $this->render_question_card( $question, false, $review_mode, $show_detail_controls ),
            $questions
        );

        return sprintf(
            '<div class="sentient-forms-qna-cards%s" data-sf-qna-cards>%s</div>',
            $review_mode ? ' sentient-forms-qna-cards--review' : '',
            implode( '', $cards )
        );
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function render_compact_question_cards( array $summary, int $limit ): string
    {
        $questions = array_slice( $summary['questions'], 0, $limit );
        $cards     = array_map(
            fn ( array $question ): string => $this->render_question_card( $question, true ),
            $questions
        );
        $remaining = count( $summary['questions'] ) - count( $questions );

        if ( $remaining > 0 )
        {
            $cards[] = sprintf(
                '<p class="sentient-forms-qna-more">%s</p>',
                esc_html(
                    sprintf(
                        /* translators: %d: remaining question count */
                        _n( '%d more question on the entry detail page.', '%d more questions on the entry detail page.', $remaining, 'sentient-forms' ),
                        $remaining
                    )
                )
            );
        }

        return '<div class="sentient-forms-qna-cards sentient-forms-qna-cards--compact">' . implode( '', $cards ) . '</div>';
    }

    /**
     * @param array<string,mixed> $question
     */
    private function render_question_card( array $question, bool $compact = false, bool $review_mode = false, bool $show_detail_toggle = false ): string
    {
        $answer = '' === $question['answer']
            ? esc_html__( 'Not answered', 'sentient-forms' )
            : esc_html( $question['answer'] );
        $reason = ! $compact && '' !== $question['reason']
            ? sprintf( '<p class="sentient-forms-qna-card__reason">%s</p>', esc_html( $question['reason'] ) )
            : '';
        $target = ! $compact && '' !== $question['target_field_id']
            ? sprintf( '<span class="sentient-forms-qna-card__target">%s</span>', esc_html( sprintf( __( 'Field %s', 'sentient-forms' ), $question['target_field_id'] ) ) )
            : '';
        $toggle = $show_detail_toggle
            ? sprintf(
                '<button type="button" class="sentient-forms-qna-card__toggle sentient-forms-qna-icon-button" data-sf-qna-card-toggle aria-expanded="true" aria-label="%s" title="%s" data-sf-qna-tooltip="%s" data-sf-qna-active-label="%s" data-sf-qna-inactive-label="%s" data-sf-qna-active-aria-label="%s" data-sf-qna-inactive-aria-label="%s" data-sf-qna-active-title="%s" data-sf-qna-inactive-title="%s">%s<span class="screen-reader-text" data-sf-qna-button-label>%s</span></button>',
                esc_attr( $this->get_card_toggle_aria_label( $question, true ) ),
                esc_attr__( 'Hide details', 'sentient-forms' ),
                esc_attr__( 'Hide details', 'sentient-forms' ),
                esc_attr__( 'Hide details', 'sentient-forms' ),
                esc_attr__( 'Show details', 'sentient-forms' ),
                esc_attr( $this->get_card_toggle_aria_label( $question, true ) ),
                esc_attr( $this->get_card_toggle_aria_label( $question, false ) ),
                esc_attr__( 'Hide details', 'sentient-forms' ),
                esc_attr__( 'Show details', 'sentient-forms' ),
                $this->render_icon_state_pair( 'chevron-up', 'chevron-down' ),
                esc_html__( 'Hide details', 'sentient-forms' )
            )
            : '';
        $data_attributes = '';
        if ( $show_detail_toggle )
        {
            $data_attributes = sprintf(
                ' data-sf-qna-card data-sf-qna-status="%s" data-sf-qna-original-index="%d"',
                esc_attr( $question['status'] ),
                (int) $question['index']
            );
        }
        if ( $review_mode )
        {
            $data_attributes = sprintf(
                ' data-sf-qna-card data-sf-qna-status="%s" data-sf-qna-required-open="%s" data-sf-qna-priority="%d" data-sf-qna-original-index="%d" data-sf-qna-search="%s"',
                esc_attr( $question['status'] ),
                ! empty( $question['required'] ) && empty( $question['has_answer'] ) ? 'true' : 'false',
                $this->get_question_review_priority( $question ),
                (int) $question['index'],
                esc_attr( $this->build_question_search_text( $question ) )
            );
        }
        $body   = sprintf(
            '<div class="sentient-forms-qna-card__body" data-sf-qna-card-body><p class="sentient-forms-qna-card__answer">%s</p>%s</div>',
            $answer,
            $reason
        );

        return sprintf(
            '<article class="sentient-forms-qna-card sentient-forms-qna-card--%s%s"%s><div class="sentient-forms-qna-card__meta"><span class="sentient-forms-qna-status sentient-forms-qna-status--%s">%s</span><span class="sentient-forms-qna-card__action">%s</span>%s%s</div><p class="sentient-forms-qna-card__question">%s</p>%s</article>',
            esc_attr( $question['status'] ),
            $review_mode ? ' sentient-forms-qna-card--review' : '',
            $data_attributes,
            esc_attr( $question['status'] ),
            esc_html( $this->get_status_label( $question ) ),
            esc_html( $question['action_label'] ),
            $target,
            $toggle,
            esc_html( $question['question'] ),
            $body
        );
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function render_detail_toolbar( array $summary ): string
    {
        if ( (int) $summary['total_questions'] < 2 )
        {
            return '';
        }

        return sprintf(
            '<div class="sentient-forms-qna-detail-toolbar" data-sf-qna-detail-toolbar>%s</div>',
            $this->render_review_toggle_button(
                'sentient-forms-qna-review-button--expand',
                'data-sf-qna-expand-all',
                __( 'Expand all', 'sentient-forms' ),
                __( 'Collapse all', 'sentient-forms' ),
                'expand',
                'collapse',
                true
            )
        );
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function render_review_toolbar( array $summary ): string
    {
        $filter_buttons = [
            $this->render_review_filter_button( 'all', __( 'All', 'sentient-forms' ), 'list', (int) $summary['total_questions'], true ),
            $this->render_review_filter_button( 'open', __( 'Open', 'sentient-forms' ), 'alert', (int) $summary['open_count'], false ),
            $this->render_review_filter_button( 'answered', __( 'Answered', 'sentient-forms' ), 'message-check', max( 0, (int) $summary['answered_count'] - (int) $summary['completed_count'] ), false ),
            $this->render_review_filter_button( 'completed', __( 'Completed', 'sentient-forms' ), 'check-circle', (int) $summary['completed_count'], false ),
        ];

        return sprintf(
            '<div class="sentient-forms-qna-review-toolbar" data-sf-qna-review-toolbar><div class="sentient-forms-qna-review-toolbar__summary"><span class="sentient-forms-qna-review-toolbar__label">%s</span><strong>%s</strong><span>%s</span></div><div class="sentient-forms-qna-review-toolbar__controls"><div class="sentient-forms-qna-review-filter" role="group" aria-label="%s">%s</div><label class="sentient-forms-qna-review-field sentient-forms-qna-review-search">%s<span class="screen-reader-text">%s</span><input type="search" data-sf-qna-search-input placeholder="%s" autocomplete="off"></label><label class="sentient-forms-qna-review-field sentient-forms-qna-review-sort">%s<span class="screen-reader-text">%s</span><select data-sf-qna-sort><option value="priority">%s</option><option value="original">%s</option><option value="status">%s</option></select></label>%s%s</div><p class="sentient-forms-qna-review-empty" data-sf-qna-empty-results hidden>%s</p></div>',
            esc_html__( 'Review mode', 'sentient-forms' ),
            esc_html(
                sprintf(
                    /* translators: %d: total question count */
                    _n( '%d question', '%d questions', (int) $summary['total_questions'], 'sentient-forms' ),
                    (int) $summary['total_questions']
                )
            ),
            esc_html__( 'open items stay first', 'sentient-forms' ),
            esc_attr__( 'Filter realtime Q&A cards', 'sentient-forms' ),
            implode( '', $filter_buttons ),
            $this->render_qna_icon( 'search', 'sentient-forms-qna-review-field__icon' ),
            esc_html__( 'Search realtime Q&A cards', 'sentient-forms' ),
            esc_attr__( 'Search Q&A', 'sentient-forms' ),
            $this->render_qna_icon( 'sort', 'sentient-forms-qna-review-field__icon' ),
            esc_html__( 'Sort realtime Q&A cards', 'sentient-forms' ),
            esc_html__( 'Priority', 'sentient-forms' ),
            esc_html__( 'Original order', 'sentient-forms' ),
            esc_html__( 'Status', 'sentient-forms' ),
            $this->render_review_toggle_button(
                'sentient-forms-qna-review-button--density',
                'data-sf-qna-density',
                __( 'Compact density', 'sentient-forms' ),
                __( 'Comfortable density', 'sentient-forms' ),
                'density',
                'density'
            ),
            $this->render_review_toggle_button(
                'sentient-forms-qna-review-button--expand',
                'data-sf-qna-expand-all',
                __( 'Expand all', 'sentient-forms' ),
                __( 'Collapse all', 'sentient-forms' ),
                'expand',
                'collapse',
                true
            ),
            esc_html__( 'No questions match the current review filters.', 'sentient-forms' )
        );
    }

    private function render_review_filter_button( string $filter, string $label, string $icon, int $count, bool $active ): string
    {
        $accessible_label = sprintf(
            /* translators: 1: filter label, 2: question count */
            __( '%1$s: %2$d', 'sentient-forms' ),
            $label,
            $count
        );

        return sprintf(
            '<button type="button" class="sentient-forms-qna-review-filter__button%s" data-sf-qna-filter="%s" aria-pressed="%s" aria-label="%s" title="%s" data-sf-qna-tooltip="%s">%s<span class="screen-reader-text">%s</span><strong aria-hidden="true">%d</strong></button>',
            $active ? ' is-active' : '',
            esc_attr( $filter ),
            $active ? 'true' : 'false',
            esc_attr( $accessible_label ),
            esc_attr( $label ),
            esc_attr( $label ),
            $this->render_qna_icon( $icon ),
            esc_html( $label ),
            $count
        );
    }

    private function render_review_toggle_button( string $class, string $data_attribute, string $inactive_label, string $active_label, string $inactive_icon, string $active_icon, bool $show_label = false ): string
    {
        $label_class = $show_label
            ? 'sentient-forms-qna-review-button__label'
            : 'screen-reader-text';

        return sprintf(
            '<button type="button" class="sentient-forms-qna-review-button sentient-forms-qna-icon-button %s" %s aria-pressed="false" aria-label="%s" title="%s" data-sf-qna-tooltip="%s" data-sf-qna-inactive-label="%s" data-sf-qna-active-label="%s" data-sf-qna-inactive-aria-label="%s" data-sf-qna-active-aria-label="%s" data-sf-qna-inactive-title="%s" data-sf-qna-active-title="%s">%s<span class="%s" data-sf-qna-button-label>%s</span></button>',
            esc_attr( $class ),
            esc_attr( $data_attribute ),
            esc_attr( $inactive_label ),
            esc_attr( $inactive_label ),
            esc_attr( $inactive_label ),
            esc_attr( $inactive_label ),
            esc_attr( $active_label ),
            esc_attr( $inactive_label ),
            esc_attr( $active_label ),
            esc_attr( $inactive_label ),
            esc_attr( $active_label ),
            $this->render_icon_state_pair( $active_icon, $inactive_icon, false ),
            esc_attr( $label_class ),
            esc_html( $inactive_label )
        );
    }

    private function render_icon_state_pair( string $active_icon, string $inactive_icon, bool $active = true ): string
    {
        return sprintf(
            '<span class="sentient-forms-qna-icon-state" data-sf-qna-icon-active%s>%s</span><span class="sentient-forms-qna-icon-state" data-sf-qna-icon-inactive%s>%s</span>',
            $active ? '' : ' hidden',
            $this->render_qna_icon( $active_icon ),
            $active ? ' hidden' : '',
            $this->render_qna_icon( $inactive_icon )
        );
    }

    private function render_qna_icon( string $icon, string $class = 'sentient-forms-qna-icon' ): string
    {
        $icons = [
            'alert'         => '<circle cx="10" cy="10" r="7"></circle><path d="M10 5.8v5"></path><path d="M10 14.3h.01"></path>',
            'cards'         => '<rect x="4" y="5" width="10" height="8" rx="1.5"></rect><path d="M7 15h7.5A1.5 1.5 0 0 0 16 13.5V8"></path>',
            'check-circle'  => '<circle cx="10" cy="10" r="7"></circle><path d="m6.7 10.2 2.1 2.1 4.5-4.6"></path>',
            'chevron-down'  => '<path d="m5.5 8 4.5 4.5L14.5 8"></path>',
            'chevron-up'    => '<path d="m5.5 12 4.5-4.5 4.5 4.5"></path>',
            'code'          => '<path d="m7.2 6.8-3.2 3.2 3.2 3.2"></path><path d="m12.8 6.8 3.2 3.2-3.2 3.2"></path><path d="m11 5.8-2 8.4"></path>',
            'collapse'      => '<path d="M7.3 4.8v3h-3"></path><path d="m4.4 4.4 3 3"></path><path d="M12.7 15.2v-3h3"></path><path d="m15.6 15.6-3-3"></path>',
            'density'       => '<path d="M4 6h12"></path><path d="M4 10h12"></path><path d="M4 14h12"></path>',
            'expand'        => '<path d="M7.3 7.8h-3v-3"></path><path d="m4.4 7.6 3-3"></path><path d="M12.7 12.2h3v3"></path><path d="m15.6 12.4-3 3"></path>',
            'list'          => '<path d="M7 5.5h9"></path><path d="M7 10h9"></path><path d="M7 14.5h9"></path><path d="M4 5.5h.01"></path><path d="M4 10h.01"></path><path d="M4 14.5h.01"></path>',
            'message-check' => '<path d="M4.5 5.5A2.5 2.5 0 0 1 7 3h6a2.5 2.5 0 0 1 2.5 2.5v4A2.5 2.5 0 0 1 13 12H9l-4.5 3v-9.5Z"></path><path d="m7.4 7.6 1.5 1.5 3.5-3.5"></path>',
            'search'        => '<circle cx="8.8" cy="8.8" r="4.8"></circle><path d="m12.5 12.5 3.5 3.5"></path>',
            'sort'          => '<path d="M6 4v10"></path><path d="m3.8 11.8 2.2 2.2 2.2-2.2"></path><path d="M14 16V6"></path><path d="m11.8 8.2L14 6l2.2 2.2"></path>',
            'table'         => '<rect x="4" y="5" width="12" height="10" rx="1.5"></rect><path d="M4 8.5h12"></path><path d="M8 5v10"></path>',
        ];

        if ( ! isset( $icons[ $icon ] ) )
        {
            return '';
        }

        return sprintf(
            '<svg class="%s" aria-hidden="true" focusable="false" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">%s</svg>',
            esc_attr( $class ),
            $icons[ $icon ]
        );
    }

    /**
     * @param array<string,mixed> $question
     */
    private function get_card_toggle_aria_label( array $question, bool $expanded ): string
    {
        $question_text = '' !== $question['question'] ? $question['question'] : __( 'this question', 'sentient-forms' );

        return sprintf(
            $expanded
                ? /* translators: %s: question text */ __( 'Hide details for %s', 'sentient-forms' )
                : /* translators: %s: question text */ __( 'Show details for %s', 'sentient-forms' ),
            $question_text
        );
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function is_high_volume_summary( array $summary ): bool
    {
        return (int) ( $summary['total_questions'] ?? 0 ) > 6;
    }

    /**
     * @param array<string,mixed> $summary
     *
     * @return array<int,array<string,mixed>>
     */
    private function get_review_questions( array $summary ): array
    {
        $questions = $summary['questions'];
        usort(
            $questions,
            function ( array $left, array $right ): int {
                $priority = $this->get_question_review_priority( $left ) <=> $this->get_question_review_priority( $right );

                return 0 !== $priority ? $priority : ( (int) $left['index'] <=> (int) $right['index'] );
            }
        );

        return $questions;
    }

    /**
     * @param array<string,mixed> $question
     */
    private function get_question_review_priority( array $question ): int
    {
        if ( ! empty( $question['required'] ) && empty( $question['has_answer'] ) )
        {
            return 0;
        }

        if ( 'open' === $question['status'] )
        {
            return 1;
        }

        if ( 'answered' === $question['status'] )
        {
            return 2;
        }

        if ( 'completed' === $question['status'] )
        {
            return 3;
        }

        return 4;
    }

    /**
     * @param array<string,mixed> $question
     */
    private function build_question_search_text( array $question ): string
    {
        $parts = [
            $question['question'] ?? '',
            $question['answer'] ?? '',
            $question['reason'] ?? '',
            $question['action_label'] ?? '',
            $question['target_field_id'] ?? '',
            $question['status'] ?? '',
            $this->get_status_label( $question ),
        ];

        return strtolower( implode( ' ', array_map( 'strval', $parts ) ) );
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function render_table( array $summary, bool $print_mode ): string
    {
        $rows = [];
        foreach ( $summary['questions'] as $question )
        {
            $rows[] = sprintf(
                '<tr><td>%s</td><td>%s</td><td><span class="sentient-forms-qna-status sentient-forms-qna-status--%s">%s</span></td><td>%s</td></tr>',
                esc_html( $question['question'] ),
                esc_html( '' === $question['answer'] ? __( 'Not answered', 'sentient-forms' ) : $question['answer'] ),
                esc_attr( $question['status'] ),
                esc_html( $this->get_status_label( $question ) ),
                esc_html( $question['action_label'] )
            );
        }

        if ( empty( $rows ) )
        {
            $rows[] = sprintf(
                '<tr><td colspan="4">%s</td></tr>',
                esc_html__( 'No realtime questions were stored for this entry.', 'sentient-forms' )
            );
        }

        return sprintf(
            '<table class="sentient-forms-qna-table%s" width="100%%" cellspacing="0" cellpadding="6"><thead><tr><th>%s</th><th>%s</th><th>%s</th><th>%s</th></tr></thead><tbody>%s</tbody></table>',
            $print_mode ? ' sentient-forms-qna-table--print' : '',
            esc_html__( 'Question', 'sentient-forms' ),
            esc_html__( 'Answer', 'sentient-forms' ),
            esc_html__( 'Status', 'sentient-forms' ),
            esc_html__( 'Action', 'sentient-forms' ),
            implode( '', $rows )
        );
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function render_copy_sources( array $summary ): string
    {
        return sprintf(
            '<textarea class="sentient-forms-qna-copy-source" data-sf-qna-copy-source="summary" readonly hidden>%s</textarea><textarea class="sentient-forms-qna-copy-source" data-sf-qna-copy-source="table" readonly hidden>%s</textarea><textarea class="sentient-forms-qna-copy-source" data-sf-qna-copy-source="csv" readonly hidden>%s</textarea><span class="screen-reader-text" data-sf-qna-live-status aria-live="polite" aria-atomic="true"></span>',
            esc_textarea( $summary['summary_text'] ),
            esc_textarea( $summary['table_text'] ),
            esc_textarea( $summary['csv_text'] )
        );
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function get_count_label( array $summary ): string
    {
        return sprintf(
            /* translators: 1: answered count, 2: total question count */
            __( '%1$d of %2$d answered', 'sentient-forms' ),
            (int) $summary['answered_count'],
            (int) $summary['total_questions']
        );
    }

    /**
     * @param array<string,mixed> $question
     */
    private function get_status_label( array $question ): string
    {
        if ( 'completed' === $question['status'] )
        {
            return __( 'Completed', 'sentient-forms' );
        }

        if ( 'answered' === $question['status'] )
        {
            return __( 'Answered', 'sentient-forms' );
        }

        return ! empty( $question['required'] )
            ? __( 'Required', 'sentient-forms' )
            : __( 'Open', 'sentient-forms' );
    }

    private function get_entry_id( array $entry ): int
    {
        return isset( $entry['id'] ) ? absint( $entry['id'] ) : 0;
    }
}
