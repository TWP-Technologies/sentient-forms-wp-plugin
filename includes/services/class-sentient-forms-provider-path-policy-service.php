<?php
/**
 * Provider-path policy for local-first action creation.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Provider_Path_Policy_Service
{
    public function __construct(
        private ?Sentient_Forms_Provider_Credentials_Repository $credentials = null,
        private ?Sentient_Forms_Local_Action_Model_Selection_Service $model_selection_service = null
    )
    {
        global $wpdb;

        $this->credentials             = $this->credentials ?? new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
        $this->model_selection_service = $this->model_selection_service ?? new Sentient_Forms_Local_Action_Model_Selection_Service(
            null,
            $this->credentials,
            null,
            null
        );
    }

    /**
     * @param array<string, mixed> $definition Bundled action definition.
     * @return array<string, mixed>|WP_Error
     */
    public function build_bundled_action_model_selection( array $definition ): array | WP_Error
    {
        $template_code = $this->template_code_from_definition( $definition );
        $managed      = $this->ready_managed_credential();
        $openrouter = $this->model_selection_service->find_single_ready_credential_for_provider( 'openrouter' );
        if ( is_wp_error( $openrouter ) && ! is_array( $managed ) )
        {
            return $openrouter;
        }

        $openrouter_model = $this->resolve_openrouter_model_for_definition( $definition );
        if ( is_wp_error( $openrouter_model ) )
        {
            if ( is_array( $managed ) )
            {
                $openrouter_model = '';
            }
            else
            {
                return $openrouter_model;
            }
        }

        if ( is_array( $managed ) )
        {
            $selection = $this->managed_selection(
                $template_code,
                absint( $managed['id'] ?? 0 )
            );

            if ( is_array( $openrouter ) && '' !== $openrouter_model )
            {
                $selection['backup_provider']      = 'openrouter';
                $selection['backup_credential_id'] = absint( $openrouter['id'] ?? 0 );
                $selection['backup_model']         = $openrouter_model;
            }

            return $selection;
        }

        if ( is_array( $openrouter ) )
        {
            return $this->openrouter_selection(
                $openrouter_model,
                absint( $openrouter['id'] ?? 0 )
            );
        }

        return $this->unavailable_error(
            'no_ready_provider',
            __( 'Set up Sentient Forms Managed Service or a compatible OpenRouter key before adding this built-in action.', 'sentient-forms' )
        );
    }

    /**
     * @param array<string, mixed> $definitions
     * @return array<string, mixed>
     */
    public function build_bootstrap_policy( array $definitions ): array
    {
        $managed    = $this->ready_managed_credential();
        $openrouter = $this->model_selection_service->find_single_ready_credential_for_provider( 'openrouter' );

        $managed_ready    = is_array( $managed );
        $openrouter_ready = is_array( $openrouter );

        $actions = [];
        foreach ( $definitions as $definition )
        {
            if ( ! is_array( $definition ) )
            {
                continue;
            }

            $code = $this->template_code_from_definition( $definition );
            if ( '' === $code )
            {
                continue;
            }

            $selection = $this->build_bundled_action_model_selection( $definition );
            $actions[ $code ] = [
                'selected_provider'    => is_wp_error( $selection ) ? null : ( $selection['provider'] ?? null ),
                'model_selection'      => is_wp_error( $selection ) ? null : $selection,
                'blocked_reason_code'  => is_wp_error( $selection )
                    ? sanitize_key( (string) ( $selection->get_error_data()['blocked_reason_code'] ?? $selection->get_error_code() ) )
                    : null,
                'requires_structured_output' => $this->definition_requires_structured_output( $definition ),
            ];
        }

        return [
            'default_provider' => $managed_ready ? 'sentient_managed' : ( $openrouter_ready ? 'openrouter' : null ),
            'providers'        => [
                'sentient_managed' => [
                    'ready'         => $managed_ready,
                    'credential_id' => $managed_ready ? absint( $managed['id'] ?? 0 ) : null,
                    'blocked_reason_code' => is_wp_error( $managed )
                        ? sanitize_key( (string) ( $managed->get_error_data()['blocked_reason_code'] ?? $managed->get_error_code() ) )
                        : null,
                ],
                'openrouter' => [
                    'ready'         => $openrouter_ready,
                    'credential_id' => $openrouter_ready ? absint( $openrouter['id'] ?? 0 ) : null,
                    'blocked_reason_code' => is_wp_error( $openrouter )
                        ? sanitize_key( (string) ( $openrouter->get_error_data()['blocked_reason_code'] ?? $openrouter->get_error_code() ) )
                        : null,
                ],
            ],
            'actions'          => $actions,
        ];
    }

    /**
     * @return array<string, mixed>|WP_Error|null
     */
    private function ready_managed_credential(): array | WP_Error | null
    {
        if ( ! $this->model_selection_service->managed_account_is_active() )
        {
            return null;
        }

        return $this->model_selection_service->find_single_ready_credential_for_provider( 'sentient_managed' );
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function resolve_openrouter_model_for_definition( array $definition ): string | WP_Error
    {
        if ( $this->definition_requires_structured_output( $definition ) )
        {
            $model = $this->model_selection_service->resolve_openrouter_preset_model_id( 'sf_structured' );
            if (
                '' === $model
                || ! $this->model_selection_service->model_supports_structured_output( $model, 'openrouter' )
            )
            {
                return $this->unavailable_error(
                    'structured_openrouter_model_unavailable',
                    __( 'The selected OpenRouter route does not have a structured-output capable model for this built-in action.', 'sentient-forms' )
                );
            }

            return $model;
        }

        $model = isset( $definition['default_model'] ) && is_scalar( $definition['default_model'] )
            ? trim( sanitize_text_field( (string) $definition['default_model'] ) )
            : '';

        return '' !== $model ? $model : 'openrouter/auto';
    }

    private function managed_selection( string $template_code, int $credential_id ): array
    {
        $preset = 'clarification_assistant_v1' === $template_code ? 'sf_realtime' : 'sf_default';

        return [
            'provider'      => 'sentient_managed',
            'model'         => $preset,
            'credential_id' => $credential_id,
            'selection'     => [
                'primary'       => $preset,
                'provider'      => 'sentient_managed',
                'is_preset'     => true,
                'credential_id' => $credential_id,
            ],
        ];
    }

    private function openrouter_selection( string $model, int $credential_id ): array
    {
        return [
            'provider'      => 'openrouter',
            'model'         => $model,
            'credential_id' => $credential_id,
            'selection'     => [
                'primary'       => $model,
                'provider'      => 'openrouter',
                'is_preset'     => false,
                'credential_id' => $credential_id,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function definition_requires_structured_output( array $definition ): bool
    {
        return is_array( $definition['structured_output_schema'] ?? null );
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function template_code_from_definition( array $definition ): string
    {
        foreach ( [ 'code', 'template_code', 'action_template_code', 'central_action_id' ] as $key )
        {
            if ( isset( $definition[ $key ] ) && is_scalar( $definition[ $key ] ) )
            {
                $code = sanitize_key( (string) $definition[ $key ] );
                if ( '' !== $code )
                {
                    return $code;
                }
            }
        }

        return '';
    }

    private function unavailable_error( string $reason_code, string $message ): WP_Error
    {
        return new WP_Error(
            'rest_bundled_action_provider_path_unavailable',
            $message,
            [
                'status'              => 409,
                'blocked_reason_code' => sanitize_key( $reason_code ),
            ]
        );
    }
}
