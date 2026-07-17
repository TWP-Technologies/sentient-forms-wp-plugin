<?php
/**
 * Executable Action-by-Form-Source compatibility manifest.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

final class Sentient_Forms_Action_Source_Compatibility_Manifest implements Sentient_Forms_Action_Source_Compatibility_Manifest_Interface
{
    private const CANONICAL_FORM_SOURCES = [
        Sentient_Forms_Form_Sources::GRAVITY_FORMS,
        Sentient_Forms_Form_Sources::CONTACT_FORM_7,
        Sentient_Forms_Form_Sources::WPFORMS,
        Sentient_Forms_Form_Sources::ELEMENTOR_PRO_FORMS,
    ];

    private const CANONICAL_ADAPTER_CLASSES = [
        Sentient_Forms_Form_Sources::GRAVITY_FORMS => Sentient_Forms_Gravity_Forms_Adapter::class,
        Sentient_Forms_Form_Sources::CONTACT_FORM_7 => Sentient_Forms_Contact_Form_7_Adapter::class,
        Sentient_Forms_Form_Sources::WPFORMS => Sentient_Forms_WPForms_Adapter::class,
        Sentient_Forms_Form_Sources::ELEMENTOR_PRO_FORMS => Sentient_Forms_Elementor_Forms_Adapter::class,
    ];

    private const ORDINARY_AFTER_SUBMISSION_ACTIONS = [
        'entry_summary_v1',
        'sentiment_urgency_v1',
        'missing_information_v1',
        'pain_point_intent_v1',
        'routing_recommendation_v1',
        'toxicity_moderation_v1',
        'lead_grading_v1',
        'suggested_reply_v1',
    ];

    private Sentient_Forms_Form_Adapter_Registry $registry;

    private Sentient_Forms_Action_Policy_Resolver $policy_resolver;

    /** @var array<int, array<string, mixed>>|null */
    private ?array $rows = null;

    /**
     * Build a compatibility manifest from the canonical adapter registry.
     *
     * @param Sentient_Forms_Form_Adapter_Registry|null $registry        Adapter registry to inspect.
     * @param Sentient_Forms_Action_Policy_Resolver|null $policy_resolver Action policy resolver to use.
     */
    public function __construct(
        ?Sentient_Forms_Form_Adapter_Registry $registry = null,
        ?Sentient_Forms_Action_Policy_Resolver $policy_resolver = null
    )
    {
        $this->registry        = $registry ?? Sentient_Forms_Plugin::instance()->get_form_adapter_registry();
        $this->policy_resolver = $policy_resolver ?? new Sentient_Forms_Action_Policy_Resolver();
    }

    /**
     * Enumerate every bundled Action and canonical Form Source pair.
     *
     * @return array<int, array<string, mixed>>
     * @throws LogicException When a canonical Form Source adapter contract is invalid.
     */
    public function all(): array
    {
        $this->assert_canonical_adapters();

        if ( null !== $this->rows )
        {
            return $this->rows;
        }

        $this->rows = [];
        foreach ( Sentient_Forms_Bundled_Action_Templates::definitions() as $action_code => $definition )
        {
            foreach ( self::CANONICAL_FORM_SOURCES as $form_source )
            {
                $descriptor = $this->registry->get_capability_descriptor( $form_source );
                $adapter    = $this->registry->get_adapter_by_id( $form_source );
                if ( ! is_array( $descriptor ) || null === $adapter )
                {
                    throw new LogicException(
                        'sentient_forms_action_source_manifest_invalid_canonical_adapter:' . $form_source . ':descriptor' // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal code from the canonical allowlist.
                    );
                }

                $this->rows[] = $this->build_row(
                    (string) $action_code,
                    $definition,
                    $form_source,
                    $descriptor,
                    $adapter
                );
            }
        }

        return $this->rows;
    }

    private function assert_canonical_adapters(): void
    {
        foreach ( self::CANONICAL_ADAPTER_CLASSES as $form_source => $expected_class )
        {
            $adapter = $this->registry->get_adapter_by_id( $form_source );
            if ( null === $adapter )
            {
                throw new LogicException(
                    'sentient_forms_action_source_manifest_invalid_canonical_adapter:' . $form_source . ':missing' // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal code from the canonical allowlist.
                );
            }
            if ( $expected_class !== get_class( $adapter ) )
            {
                throw new LogicException(
                    'sentient_forms_action_source_manifest_invalid_canonical_adapter:' . $form_source . ':unexpected_class' // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal code from the canonical allowlist.
                );
            }
        }
    }

    /**
     * Query one bundled Action and canonical Form Source pair.
     *
     * @param string $action_code Bundled Action code.
     * @param string $form_source Canonical Form Source identifier.
     *
     * @return array<string, mixed>|null
     * @throws LogicException When a canonical Form Source adapter contract is invalid.
     */
    public function get( string $action_code, string $form_source ): ?array
    {
        $action_code = sanitize_key( $action_code );
        $form_source = sanitize_key( $form_source );

        foreach ( $this->all() as $row )
        {
            if ( $action_code === $row['action_code'] && $form_source === $row['form_source'] )
            {
                return $row;
            }
        }

        return null;
    }

    /**
     * Produce the versioned public-safe compatibility contract.
     *
     * @return array<string, mixed>
     * @throws LogicException When a canonical adapter or semantic source-hash input is invalid.
     */
    public function public_projection(): array
    {
        $rows = array_map(
            static fn( array $row ): array => [
                'action_code'    => $row['action_code'],
                'form_source'    => $row['form_source'],
                'support_status' => $row['support_status'],
                'lifecycles'     => [] === $row['lifecycle_contracts']
                    ? new stdClass()
                    : $row['lifecycle_contracts'],
            ],
            $this->all()
        );

        return [
            'contract_version' => 1,
            'source_sha256'    => $this->projection_source_sha256(),
            'action_codes'     => Sentient_Forms_Bundled_Action_Templates::codes(),
            'form_sources'     => self::CANONICAL_FORM_SOURCES,
            'rows'             => $rows,
        ];
    }

    private function projection_source_sha256(): string
    {
        $plugin_root = dirname( __DIR__, 2 );
        $verifier    = $plugin_root . '/scripts/check-action-source-compatibility-snapshot.php';
        if ( ! is_file( $verifier ) )
        {
            throw new LogicException( 'sentient_forms_action_source_projection_verifier_missing' );
        }

        require_once $verifier;

        return Sentient_Forms_Action_Source_Compatibility_Snapshot_Verifier::source_sha256( $plugin_root );
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $descriptor
     * @return array<string, mixed>
     */
    private function build_row(
        string $action_code,
        array $definition,
        string $form_source,
        array $descriptor,
        Sentient_Forms_Adapter_Interface $adapter
    ): array
    {
        $action_policy = $this->policy_resolver->resolve_action_definition( $definition );
        $base_policy   = is_array( $definition['action_policy'] ?? null )
            ? $this->policy_resolver->resolve( $definition['action_policy'] )
            : new WP_Error( 'sentient_forms_action_policy_missing' );
        $source_capabilities = $this->source_capabilities( $descriptor, $adapter );
        $runtime_availability = $this->runtime_availability( $descriptor );

        if ( is_wp_error( $action_policy ) || is_wp_error( $base_policy ) )
        {
            return $this->invalid_policy_row(
                $action_code,
                $definition,
                $form_source,
                $descriptor,
                $source_capabilities,
                $runtime_availability
            );
        }

        $supported_lifecycles = array_values(
            array_filter(
                $action_policy['eligible_lifecycles'],
                static fn( string $lifecycle ): bool => true === ( $source_capabilities['lifecycles'][ $lifecycle ] ?? false )
            )
        );
        $missing_capabilities = array_values(
            array_filter(
                $action_policy['required_form_source_capabilities'],
                static fn( string $capability ): bool => true !== ( $source_capabilities['requirements'][ $capability ] ?? false )
            )
        );
        $intentional_unsupported = [] === $supported_lifecycles;
        $supported = ! $intentional_unsupported
            && [] !== $supported_lifecycles
            && [] === $missing_capabilities;
        $status    = $supported
            ? 'supported'
            : ( $intentional_unsupported ? 'intentional_unsupported' : 'target_pending' );
        $lifecycle_contracts = $this->lifecycle_contracts(
            $action_code,
            $supported_lifecycles,
            $source_capabilities
        );
        if ( ! $supported )
        {
            $lifecycle_contracts = [];
        }

        return [
            'action_code'              => sanitize_key( $action_code ),
            'action_label'             => sanitize_text_field( (string) ( $definition['display_name'] ?? $action_code ) ),
            'form_source'              => sanitize_key( $form_source ),
            'form_source_label'        => sanitize_text_field( (string) ( $descriptor['label'] ?? $form_source ) ),
            'supported'                => $supported,
            'support_status'           => $status,
            'unsupported_reason_code'  => $supported ? null : ( [] === $supported_lifecycles ? 'unsupported_lifecycle' : 'missing_source_capability' ),
            'action_policy'            => $action_policy,
            'base_action_policy'       => $base_policy,
            'allowed_facets'           => $this->identifier_list( $definition['allowed_facets'] ?? [] ),
            'enabled_facets'           => $this->identifier_list( $definition['enabled_facets'] ?? [] ),
            'supported_lifecycles'     => $supported ? $supported_lifecycles : [],
            'missing_capabilities'     => $missing_capabilities,
            'baseline_surfaces'        => $supported ? $this->baseline_surfaces( $supported_lifecycles ) : [],
            'source_capabilities'      => $source_capabilities,
            'runtime_availability'     => $runtime_availability,
            'lifecycle_contracts'      => $lifecycle_contracts,
        ];
    }

    /**
     * @param array<int, string>        $supported_lifecycles
     * @param array<string, mixed>      $source_capabilities
     * @return array<string, array<string, mixed>>
     */
    private function lifecycle_contracts(
        string $action_code,
        array $supported_lifecycles,
        array $source_capabilities
    ): array
    {
        if ( 'content_validation_v1' === $action_code )
        {
            $effects = [];
            foreach ( [ 'field_errors', 'form_errors' ] as $effect )
            {
                if ( true === ( $source_capabilities['validation_effects'][ $effect ] ?? false ) )
                {
                    $effects[] = $effect;
                }
            }

            return $this->only_supported_lifecycle(
                Sentient_Forms_Form_Source_Lifecycles::VALIDATION,
                $supported_lifecycles,
                $effects,
                $effects,
                false
            );
        }

        if ( 'spam_detection_v1' === $action_code )
        {
            $contracts = [];
            if ( in_array( Sentient_Forms_Form_Source_Lifecycles::VALIDATION, $supported_lifecycles, true ) )
            {
                $validation_effect = true === ( $source_capabilities['validation_effects']['submission_spam'] ?? false )
                    ? 'native_spam_state'
                    : 'form_errors';
                $contracts[ Sentient_Forms_Form_Source_Lifecycles::VALIDATION ] = $this->lifecycle_contract(
                    [ $validation_effect ],
                    [ $validation_effect ],
                    false
                );
            }
            if ( in_array( Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION, $supported_lifecycles, true ) )
            {
                $native_effects = [];
                if ( true === ( $source_capabilities['native_enrichment']['notes'] ?? false ) )
                {
                    $native_effects[] = 'entry_note';
                }
                if ( true === ( $source_capabilities['native_entry']['write'] ?? false ) )
                {
                    $native_effects[] = 'entry_meta';
                }
                foreach ( [ 'spam' => 'native_spam_state', 'notification_controls' => 'notification_controls', 'webhook_controls' => 'webhook_controls' ] as $capability => $effect )
                {
                    if ( true === ( $source_capabilities['native_enrichment'][ $capability ] ?? false ) )
                    {
                        $native_effects[] = $effect;
                    }
                }
                $contracts[ Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION ] = $this->lifecycle_contract(
                    [ 'accepted_submission' ],
                    $native_effects,
                    true === ( $source_capabilities['ledger_required_for_parity'] ?? false )
                );
            }

            return $contracts;
        }

        if ( 'clarification_assistant_v1' === $action_code )
        {
            return $this->only_supported_lifecycle(
                Sentient_Forms_Form_Source_Lifecycles::REAL_TIME,
                $supported_lifecycles,
                [ 'realtime_qna_storage' ],
                [ 'native_realtime_qna_storage' ],
                false
            );
        }

        if ( in_array( $action_code, self::ORDINARY_AFTER_SUBMISSION_ACTIONS, true ) )
        {
            $native_effects = [];
            if ( true === ( $source_capabilities['native_enrichment']['notes'] ?? false ) )
            {
                $native_effects[] = 'entry_note';
            }
            if ( true === ( $source_capabilities['native_entry']['write'] ?? false ) )
            {
                $native_effects[] = 'entry_meta';
            }

            return $this->only_supported_lifecycle(
                Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION,
                $supported_lifecycles,
                [ 'accepted_submission' ],
                $native_effects,
                true === ( $source_capabilities['ledger_required_for_parity'] ?? false )
            );
        }

        throw new LogicException(
            'sentient_forms_action_source_manifest_unhandled_action:' . sanitize_key( $action_code )
        );
    }

    /**
     * @param array<int, string> $supported_lifecycles
     * @param array<int, string> $required_capabilities
     * @param array<int, string> $native_effects
     * @return array<string, array<string, mixed>>
     */
    private function only_supported_lifecycle(
        string $lifecycle,
        array $supported_lifecycles,
        array $required_capabilities,
        array $native_effects,
        bool $requires_submission_ledger
    ): array
    {
        if ( ! in_array( $lifecycle, $supported_lifecycles, true ) )
        {
            return [];
        }

        return [
            $lifecycle => $this->lifecycle_contract(
                $required_capabilities,
                $native_effects,
                $requires_submission_ledger
            ),
        ];
    }

    /**
     * @param array<int, string> $required_capabilities
     * @param array<int, string> $native_effects
     * @return array<string, mixed>
     */
    private function lifecycle_contract(
        array $required_capabilities,
        array $native_effects,
        bool $requires_submission_ledger
    ): array
    {
        return [
            'required_capabilities'      => $this->identifier_list( $required_capabilities ),
            'native_effects'             => $this->identifier_list( $native_effects ),
            'requires_submission_ledger' => $requires_submission_ledger,
        ];
    }

    /**
     * @param array<string, mixed> $descriptor
     * @return array<string, mixed>
     */
    private function source_capabilities(
        array $descriptor,
        Sentient_Forms_Adapter_Interface $adapter
    ): array
    {
        $structural_native_entry = $adapter instanceof Sentient_Forms_Native_Entry_Capabilities_Adapter_Interface
            ? $adapter->get_structural_native_entry_capabilities()
            : [];
        $structural_native_effects = $adapter instanceof Sentient_Forms_Native_Effects_Capabilities_Adapter_Interface
            ? $adapter->get_structural_native_effect_capabilities()
            : [];
        $structural_validation_effects = $adapter instanceof Sentient_Forms_Native_Validation_Effects_Adapter_Interface
            ? $adapter->get_structural_validation_effect_capabilities()
            : [];
        $structural_realtime = $adapter instanceof Sentient_Forms_Realtime_Adapter_Interface
            ? $adapter->get_structural_realtime_capabilities()
            : [];
        $lifecycles = [
            Sentient_Forms_Form_Source_Lifecycles::VALIDATION => $adapter instanceof Sentient_Forms_Validation_Adapter_Interface,
            Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION => $adapter instanceof Sentient_Forms_Accepted_Submission_Adapter_Interface,
            Sentient_Forms_Form_Source_Lifecycles::REAL_TIME => $adapter instanceof Sentient_Forms_Realtime_Adapter_Interface,
        ];

        $native_entry = $this->boolean_capabilities(
            $structural_native_entry,
            [ 'id', 'link', 'read', 'write' ]
        );
        $native_enrichment = $this->boolean_capabilities(
            $structural_native_effects,
            [ 'notes', 'status', 'spam', 'notification_controls', 'webhook_controls' ]
        );
        $validation_effects = $this->boolean_capabilities(
            $structural_validation_effects,
            [ 'field_errors', 'form_errors', 'submission_spam' ]
        );
        $realtime_storage = true === ( $structural_realtime['qna_storage'] ?? false );

        return [
            'lifecycles'           => $lifecycles,
            'requirements'         => [
                'accepted_submission' => true === $lifecycles[ Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION ],
                'field_errors'        => true === ( $validation_effects['field_errors'] ?? false ),
                'realtime_qna_storage' => $realtime_storage,
            ],
            'validation_effects'   => $validation_effects,
            'native_entry'         => $native_entry,
            'native_enrichment'    => $native_enrichment,
            'ledger_required_for_parity' => true === ( $descriptor['ledger']['required_for_parity'] ?? false ),
        ];
    }

    /**
     * @param array<string, mixed> $descriptor
     * @return array<string, mixed>
     */
    private function runtime_availability( array $descriptor ): array
    {
        $lifecycles = [];
        foreach ( Sentient_Forms_Form_Source_Lifecycles::canonical_ids() as $lifecycle )
        {
            $lifecycles[ $lifecycle ] = true === ( $descriptor['lifecycles'][ $lifecycle ]['supported'] ?? false );
        }

        $requirements = is_array( $descriptor['requirements'] ?? null ) ? $descriptor['requirements'] : [];

        return [
            'is_active'            => true === ( $descriptor['is_active'] ?? false ),
            'status'               => sanitize_key( (string) ( $descriptor['availability'] ?? 'unavailable' ) ),
            'message'              => sanitize_text_field( (string) ( $descriptor['availability_message'] ?? '' ) ),
            'lifecycles'           => $lifecycles,
            'native_entry'         => $this->boolean_capabilities(
                is_array( $descriptor['native_entry'] ?? null ) ? $descriptor['native_entry'] : [],
                [ 'id', 'link', 'read', 'write' ]
            ),
            'native_enrichment'    => $this->boolean_capabilities(
                is_array( $descriptor['native_enrichment'] ?? null ) ? $descriptor['native_enrichment'] : [],
                [ 'notes', 'status', 'spam', 'notification_controls', 'webhook_controls' ]
            ),
            'validation_effects'   => $this->boolean_capabilities(
                is_array( $descriptor['validation_effects'] ?? null ) ? $descriptor['validation_effects'] : [],
                [ 'field_errors', 'form_errors', 'submission_spam' ]
            ),
            'requirements'         => [
                'plugin'         => sanitize_text_field( (string) ( $requirements['plugin'] ?? '' ) ),
                'module'         => isset( $requirements['module'] ) ? sanitize_text_field( (string) $requirements['module'] ) : null,
                'requires_pro'   => true === ( $requirements['requires_pro'] ?? false ),
                'requires_addon' => true === ( $requirements['requires_addon'] ?? false ),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $provided
     * @param array<int, string>   $keys
     * @return array<string, bool>
     */
    private function boolean_capabilities( array $provided, array $keys ): array
    {
        $result = [];
        foreach ( $keys as $key )
        {
            $result[ $key ] = true === ( $provided[ $key ] ?? false );
        }

        return $result;
    }

    /**
     * @param array<int, string> $lifecycles
     * @return array<int, string>
     */
    private function baseline_surfaces( array $lifecycles ): array
    {
        $surfaces = [];
        foreach ( $lifecycles as $lifecycle )
        {
            if ( Sentient_Forms_Form_Source_Lifecycles::VALIDATION === $lifecycle )
            {
                $surfaces[] = 'visitor_validation';
            }
            elseif ( Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION === $lifecycle )
            {
                $surfaces[] = 'submission_ledger';
            }
            elseif ( Sentient_Forms_Form_Source_Lifecycles::REAL_TIME === $lifecycle )
            {
                $surfaces[] = 'realtime_assistance';
            }
        }

        return array_values( array_unique( $surfaces ) );
    }

    /**
     * @return array<int, string>
     */
    private function identifier_list( mixed $values ): array
    {
        if ( ! is_array( $values ) )
        {
            return [];
        }

        return array_values(
            array_unique(
                array_filter(
                    array_map(
                        static fn( mixed $value ): string => is_scalar( $value ) ? sanitize_key( (string) $value ) : '',
                        $values
                    )
                )
            )
        );
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $descriptor
     * @param array<string, mixed> $source_capabilities
     * @param array<string, mixed> $runtime_availability
     * @return array<string, mixed>
     */
    private function invalid_policy_row(
        string $action_code,
        array $definition,
        string $form_source,
        array $descriptor,
        array $source_capabilities,
        array $runtime_availability
    ): array
    {
        return [
            'action_code'             => sanitize_key( $action_code ),
            'action_label'            => sanitize_text_field( (string) ( $definition['display_name'] ?? $action_code ) ),
            'form_source'             => sanitize_key( $form_source ),
            'form_source_label'       => sanitize_text_field( (string) ( $descriptor['label'] ?? $form_source ) ),
            'supported'               => false,
            'support_status'          => 'target_pending',
            'unsupported_reason_code' => 'invalid_action_policy',
            'action_policy'           => [],
            'base_action_policy'      => [],
            'allowed_facets'          => $this->identifier_list( $definition['allowed_facets'] ?? [] ),
            'enabled_facets'          => $this->identifier_list( $definition['enabled_facets'] ?? [] ),
            'supported_lifecycles'    => [],
            'missing_capabilities'    => [],
            'baseline_surfaces'       => [],
            'source_capabilities'     => $source_capabilities,
            'runtime_availability'    => $runtime_availability,
            'lifecycle_contracts'     => [],
        ];
    }
}
