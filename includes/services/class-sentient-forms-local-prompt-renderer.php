<?php
/**
 * Local prompt rendering for local-first action execution.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Local_Prompt_Renderer
{
    /**
     * Build template variables from a form entry and mapping input bindings.
     *
     * @param array<string, mixed> $bindings Mapping input bindings keyed by prompt variable.
     * @param array<string, mixed> $form     Form metadata.
     * @param array<string, mixed> $entry    Form entry values.
     * @param array<string, mixed> $context  Runtime context.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function build_variables( array $bindings, array $form, array $entry, array $context = [] ): array | WP_Error
    {
        $variables = [
            'entry'   => $entry,
            'form'    => $form,
            'context' => $context,
        ];

        foreach ( $bindings as $name => $binding )
        {
            $variable_name = (string) $name;
            if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9_-]*$/', $variable_name ) )
            {
                return new WP_Error(
                    'sentient_forms_invalid_prompt_variable',
                    __( 'Prompt variable names must be non-empty strings.', 'sentient-forms' )
                );
            }

            $resolved = $this->resolve_binding( $binding, $form, $entry, $context );
            if ( is_wp_error( $resolved ) )
            {
                return $resolved;
            }

            $variables[ $variable_name ] = $resolved;
        }

        return $variables;
    }

    /**
     * Render a prompt template using double-brace placeholders.
     *
     * @param string               $template  Prompt template.
     * @param array<string, mixed> $variables Variables available to the template.
     *
     * @return string|WP_Error
     */
    public function render_template( string $template, array $variables ): string | WP_Error
    {
        $errors = [];

        $rendered = preg_replace_callback(
            '/{{\s*([^{}]+?)\s*}}/',
            function ( array $matches ) use ( $variables, &$errors ): string {
                $placeholder = trim( (string) ( $matches[1] ?? '' ) );
                $value       = str_starts_with( strtolower( $placeholder ), 'field:' )
                    ? $this->resolve_field_placeholder_value( substr( $placeholder, strlen( 'field:' ) ), $variables )
                    : $this->resolve_path( $variables, $placeholder, false );

                if ( null === $value )
                {
                    $errors[] = $placeholder;
                    return '';
                }

                return $this->stringify_value( $value );
            },
            $template
        );

        if ( null === $rendered )
        {
            return new WP_Error(
                'sentient_forms_prompt_render_failed',
                __( 'Prompt template could not be rendered.', 'sentient-forms' )
            );
        }

        if ( [] !== $errors )
        {
            return new WP_Error(
                'sentient_forms_unresolved_prompt_placeholder',
                sprintf(
                    /* translators: %s: comma-separated prompt placeholders. */
                    __( 'Prompt template contains unresolved placeholders: %s', 'sentient-forms' ),
                    implode( ', ', array_unique( $errors ) )
                ),
                [
                    'placeholders' => array_values( array_unique( $errors ) ),
                ]
            );
        }

        return $rendered;
    }

    /**
     * Render OpenRouter-compatible chat messages.
     *
     * @param array<int, array<string, mixed>> $messages  Messages with role/content fields.
     * @param array<string, mixed>             $variables Template variables.
     *
     * @return array<int, array<string, string>>|WP_Error
     */
    public function render_messages( array $messages, array $variables ): array | WP_Error
    {
        $rendered = [];

        foreach ( $messages as $message )
        {
            if ( ! is_array( $message ) )
            {
                return new WP_Error(
                    'sentient_forms_invalid_prompt_message',
                    __( 'Prompt messages must be objects.', 'sentient-forms' )
                );
            }

            $role = sanitize_key( (string) ( $message['role'] ?? 'user' ) );
            if ( ! in_array( $role, [ 'system', 'user', 'assistant', 'tool' ], true ) )
            {
                return new WP_Error(
                    'sentient_forms_invalid_prompt_message_role',
                    __( 'Prompt message role is not supported.', 'sentient-forms' )
                );
            }

            $content = $message['content'] ?? '';
            if ( ! is_scalar( $content ) )
            {
                return new WP_Error(
                    'sentient_forms_invalid_prompt_message_content',
                    __( 'Prompt message content must be a string.', 'sentient-forms' )
                );
            }

            $rendered_content = $this->render_template( (string) $content, $variables );
            if ( is_wp_error( $rendered_content ) )
            {
                return $rendered_content;
            }

            $rendered[] = [
                'role'    => $role,
                'content' => $rendered_content,
            ];
        }

        if ( [] === $rendered )
        {
            return new WP_Error(
                'sentient_forms_missing_prompt_messages',
                __( 'At least one prompt message is required.', 'sentient-forms' )
            );
        }

        return $rendered;
    }

    private function resolve_binding( mixed $binding, array $form, array $entry, array $context ): mixed
    {
        if ( is_array( $binding ) )
        {
            $source = sanitize_key( (string) ( $binding['source'] ?? 'entry' ) );

            if ( 'literal' === $source )
            {
                return $binding['value'] ?? null;
            }

            $path = (string) ( $binding['path'] ?? $binding['field'] ?? '' );
            return $this->resolve_from_source( $source, $path, $form, $entry, $context );
        }

        if ( ! is_scalar( $binding ) )
        {
            return new WP_Error(
                'sentient_forms_invalid_input_binding',
                __( 'Input bindings must be strings or objects.', 'sentient-forms' )
            );
        }

        $path = (string) $binding;

        foreach ( [ 'entry', 'form', 'context' ] as $source )
        {
            $prefix = $source . '.';
            if ( str_starts_with( $path, $prefix ) )
            {
                return $this->resolve_from_source( $source, substr( $path, strlen( $prefix ) ), $form, $entry, $context );
            }
        }

        return $this->resolve_gravity_entry_value( $path, $entry, $form );
    }

    private function resolve_from_source( string $source, string $path, array $form, array $entry, array $context ): mixed
    {
        $root = match ( $source )
        {
            'form'    => $form,
            'context' => $context,
            default   => $entry,
        };

        return $this->resolve_path( $root, $path );
    }

    private function resolve_gravity_entry_value( string $path, array $entry, array $form ): mixed
    {
        if ( array_key_exists( $path, $entry ) )
        {
            return $entry[ $path ];
        }

        $field_key = 'field_' . $path;
        if ( array_key_exists( $field_key, $entry ) )
        {
            return $entry[ $field_key ];
        }

        $field = $this->find_form_field_by_id( $path, $form );
        if ( null !== $field )
        {
            return $this->field_value_from_entry( $this->field_id( $field ), $entry, $this->field_type( $field ), '' );
        }

        return $this->resolve_path( $entry, $path );
    }

    /**
     * Resolve field merge tags that target either a concrete field id or a human-friendly selector.
     *
     * @param array<string, mixed> $variables Prompt variables containing form and entry roots.
     */
    private function resolve_field_placeholder_value( string $selector, array $variables ): string
    {
        $selector = trim( strtolower( $selector ) );
        if ( '' === $selector )
        {
            return '';
        }

        $form  = is_array( $variables['form'] ?? null ) ? $variables['form'] : [];
        $entry = is_array( $variables['entry'] ?? null ) ? $variables['entry'] : [];

        if ( str_starts_with( $selector, 'type:' ) )
        {
            return $this->resolve_field_selector_by_type( substr( $selector, strlen( 'type:' ) ), $entry, $form );
        }

        if ( str_starts_with( $selector, 'label_contains:' ) )
        {
            return $this->resolve_field_selector_by_label(
                substr( $selector, strlen( 'label_contains:' ) ),
                $entry,
                $form
            );
        }

        return $this->stringify_value( $entry[ $selector ] ?? '' );
    }

    /**
     * @param array<string, mixed> $entry Form entry values.
     * @param array<string, mixed> $form  Form metadata.
     */
    private function resolve_field_selector_by_type( string $selector, array $entry, array $form ): string
    {
        $parts = explode( '.', strtolower( trim( $selector ) ), 2 );
        $type  = sanitize_key( $parts[0] ?? '' );
        $part  = sanitize_key( $parts[1] ?? '' );
        if ( '' === $type )
        {
            return '';
        }

        foreach ( $this->form_fields( $form ) as $field )
        {
            if ( $this->field_type( $field ) !== $type )
            {
                continue;
            }

            return $this->field_value_from_entry( $this->field_id( $field ), $entry, $type, $part );
        }

        return '';
    }

    /**
     * @param array<string, mixed> $entry Form entry values.
     * @param array<string, mixed> $form  Form metadata.
     */
    private function resolve_field_selector_by_label( string $label_fragment, array $entry, array $form ): string
    {
        $needle = strtolower( trim( $label_fragment ) );
        if ( '' === $needle )
        {
            return '';
        }

        foreach ( $this->form_fields( $form ) as $field )
        {
            $label = strtolower( $this->field_label( $field ) );
            if ( '' === $label || ! str_contains( $label, $needle ) )
            {
                continue;
            }

            return $this->field_value_from_entry(
                $this->field_id( $field ),
                $entry,
                $this->field_type( $field ),
                ''
            );
        }

        return '';
    }

    /**
     * @param array<string, mixed> $form Form metadata.
     * @return array<int, mixed>
     */
    private function form_fields( array $form ): array
    {
        return isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : [];
    }

    /**
     * @param array<string, mixed> $form Form metadata.
     */
    private function find_form_field_by_id( string $field_id, array $form ): mixed
    {
        foreach ( $this->form_fields( $form ) as $field )
        {
            if ( $this->field_id( $field ) === $field_id )
            {
                return $field;
            }
        }

        return null;
    }

    private function field_id( mixed $field ): string
    {
        if ( is_array( $field ) )
        {
            return isset( $field['id'] ) ? (string) $field['id'] : '';
        }

        return is_object( $field ) && isset( $field->id ) ? (string) $field->id : '';
    }

    private function field_type( mixed $field ): string
    {
        if ( is_array( $field ) )
        {
            return isset( $field['type'] ) ? sanitize_key( (string) $field['type'] ) : '';
        }

        return is_object( $field ) && isset( $field->type ) ? sanitize_key( (string) $field->type ) : '';
    }

    private function field_label( mixed $field ): string
    {
        if ( is_array( $field ) )
        {
            return isset( $field['label'] ) ? (string) $field['label'] : '';
        }

        return is_object( $field ) && isset( $field->label ) ? (string) $field->label : '';
    }

    /**
     * @param array<string, mixed> $entry Form entry values.
     */
    private function field_value_from_entry( string $field_id, array $entry, string $field_type, string $part ): string
    {
        if ( '' === $field_id )
        {
            return '';
        }

        if ( 'name' === $field_type )
        {
            $first = $this->stringify_value( $entry[ $field_id . '.3' ] ?? $entry[ $field_id . '.1' ] ?? '' );
            $last  = $this->stringify_value( $entry[ $field_id . '.6' ] ?? $entry[ $field_id . '.2' ] ?? '' );
            if ( 'first' === $part )
            {
                return $first;
            }
            if ( 'last' === $part )
            {
                return $last;
            }
            $full = trim( $this->stringify_value( $entry[ $field_id ] ?? '' ) );
            return '' !== $full ? $full : trim( $first . ' ' . $last );
        }

        return $this->stringify_value( $entry[ $field_id ] ?? '' );
    }

    private function resolve_path( array $root, string $path, bool $allow_empty_path = true ): mixed
    {
        $path = trim( $path );
        if ( '' === $path )
        {
            return $allow_empty_path ? $root : null;
        }

        $value = $root;
        foreach ( explode( '.', $path ) as $segment )
        {
            if ( is_array( $value ) && array_key_exists( $segment, $value ) )
            {
                $value = $value[ $segment ];
                continue;
            }

            return null;
        }

        return $value;
    }

    private function stringify_value( mixed $value ): string
    {
        if ( null === $value )
        {
            return '';
        }

        if ( is_bool( $value ) )
        {
            return $value ? 'true' : 'false';
        }

        if ( is_scalar( $value ) )
        {
            return (string) $value;
        }

        $encoded = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
        return is_string( $encoded ) ? $encoded : '';
    }
}
