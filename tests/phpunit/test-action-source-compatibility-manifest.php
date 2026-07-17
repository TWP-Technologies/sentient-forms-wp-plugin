<?php

class Tests_Action_Source_Compatibility_Manifest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );
    }

    protected function tearDown(): void
    {
        remove_all_filters( 'sentient_forms_elementor_is_active' );
        remove_all_filters( 'sentient_forms_elementor_pro_forms_api_available' );
        remove_all_filters( 'sentient_forms_wpforms_is_active' );
        remove_all_filters( 'sentient_forms_wpforms_native_entry_storage_available' );

        parent::tearDown();
    }

    public function test_manifest_enumerates_exactly_one_row_for_every_bundled_action_and_versioned_first_party_source(): void
    {
        $registry = new Sentient_Forms_Form_Adapter_Registry( Sentient_Forms_Plugin::instance() );
        $manifest = new Sentient_Forms_Action_Source_Compatibility_Manifest( $registry );
        $rows     = $manifest->all();
        $actions  = Sentient_Forms_Bundled_Action_Templates::codes();
        $sources  = $registry->get_registered_source_ids();

        $this->assertInstanceOf( Sentient_Forms_Action_Source_Compatibility_Manifest_Interface::class, $manifest );
        $this->assertCount( 11, $actions );
        $this->assertSame(
            [ 'gravity_forms', 'contact_form_7', 'wpforms', 'elementor_pro_forms' ],
            $sources
        );
        $this->assertCount( 44, $rows );

        $keys = [];
        foreach ( $rows as $row )
        {
            $key = ( $row['action_code'] ?? '' ) . ':' . ( $row['form_source'] ?? '' );
            $this->assertArrayNotHasKey( $key, $keys );
            $keys[ $key ] = true;
            $this->assertSame(
                $row,
                $manifest->get( (string) $row['action_code'], (string) $row['form_source'] )
            );
        }

        $this->assertCount( count( $actions ) * count( $sources ), $keys );
        $this->assertNull( $manifest->get( 'not_an_action', 'gravity_forms' ) );
        $this->assertNull( $manifest->get( 'entry_summary_v1', 'not_a_source' ) );
    }

    public function test_manifest_ignores_extension_adapters_outside_the_versioned_first_party_contract(): void
    {
        $registry = new Sentient_Forms_Form_Adapter_Registry( Sentient_Forms_Plugin::instance() );
        $registry->register_adapter(
            new class( Sentient_Forms_Plugin::instance() ) extends Sentient_Forms_Contact_Form_7_Adapter
            {
                public function get_id(): string
                {
                    return 'extension_forms';
                }

                public function get_name(): string
                {
                    return 'Extension Forms';
                }
            }
        );

        $this->assertCount( 5, $registry->get_registered_source_ids() );

        $manifest = new Sentient_Forms_Action_Source_Compatibility_Manifest( $registry );
        $this->assertCount( 44, $manifest->all() );
        $this->assertNull( $manifest->get( 'entry_summary_v1', 'extension_forms' ) );
    }

    public function test_manifest_fails_closed_when_a_canonical_adapter_is_unregistered(): void
    {
        $registry = new Sentient_Forms_Form_Adapter_Registry( Sentient_Forms_Plugin::instance() );
        $registry->unregister_adapter( 'wpforms' );

        $this->expectException( LogicException::class );
        $this->expectExceptionMessage(
            'sentient_forms_action_source_manifest_invalid_canonical_adapter:wpforms:missing'
        );

        ( new Sentient_Forms_Action_Source_Compatibility_Manifest( $registry ) )->all();
    }

    public function test_manifest_fails_closed_when_an_extension_replaces_a_canonical_adapter_id(): void
    {
        $registry = new Sentient_Forms_Form_Adapter_Registry( Sentient_Forms_Plugin::instance() );
        $registry->register_adapter(
            new class( Sentient_Forms_Plugin::instance() ) extends Sentient_Forms_Contact_Form_7_Adapter
            {
                public function get_id(): string
                {
                    return 'wpforms';
                }
            }
        );

        $this->expectException( LogicException::class );
        $this->expectExceptionMessage(
            'sentient_forms_action_source_manifest_invalid_canonical_adapter:wpforms:unexpected_class'
        );

        ( new Sentient_Forms_Action_Source_Compatibility_Manifest( $registry ) )->all();
    }

    public function test_manifest_derives_action_policy_facets_and_lifecycle_intersection_from_authorities(): void
    {
        $registry      = new Sentient_Forms_Form_Adapter_Registry( Sentient_Forms_Plugin::instance() );
        $manifest      = new Sentient_Forms_Action_Source_Compatibility_Manifest( $registry );
        $policy_solver = new Sentient_Forms_Action_Policy_Resolver();
        $facet_catalog = new Sentient_Forms_Action_Facet_Catalog();

        foreach ( $manifest->all() as $row )
        {
            $definition = Sentient_Forms_Bundled_Action_Templates::get( (string) $row['action_code'] );
            $this->assertIsArray( $definition );

            $policy = $policy_solver->resolve_action_definition( $definition );
            $this->assertIsArray( $policy );
            $this->assertSame( $policy, $row['action_policy'] ?? null );
            $this->assertSame( $definition['allowed_facets'] ?? [], $row['allowed_facets'] ?? null );
            $this->assertSame( $definition['enabled_facets'] ?? [], $row['enabled_facets'] ?? null );

            foreach ( $row['allowed_facets'] as $facet_code )
            {
                $this->assertTrue( $facet_catalog->has( $facet_code ) );
            }

            $source_lifecycles = array_keys(
                array_filter( $row['source_capabilities']['lifecycles'] ?? [] )
            );
            $this->assertSame(
                array_values( array_intersect( $policy['eligible_lifecycles'], $source_lifecycles ) ),
                $row['supported_lifecycles'] ?? null
            );
            $this->assertContains( (string) $row['form_source'], $registry->get_registered_source_ids() );
        }
    }

    public function test_manifest_generation_fails_closed_for_an_unhandled_unsupported_action(): void
    {
        $registry   = new Sentient_Forms_Form_Adapter_Registry( Sentient_Forms_Plugin::instance() );
        $manifest   = new Sentient_Forms_Action_Source_Compatibility_Manifest( $registry );
        $definition = Sentient_Forms_Bundled_Action_Templates::get( 'clarification_assistant_v1' );
        $descriptor = $registry->get_capability_descriptor( 'contact_form_7' );
        $adapter    = $registry->get_adapter_by_id( 'contact_form_7' );
        $method     = new ReflectionMethod( $manifest, 'build_row' );

        $this->assertIsArray( $definition );
        $this->assertIsArray( $descriptor );
        $this->assertInstanceOf( Sentient_Forms_Adapter_Interface::class, $adapter );

        $this->expectException( LogicException::class );
        $this->expectExceptionMessage(
            'sentient_forms_action_source_manifest_unhandled_action:future_action_v1'
        );

        $method->invoke(
            $manifest,
            'future_action_v1',
            $definition,
            'contact_form_7',
            $descriptor,
            $adapter
        );
    }

    public function test_public_projection_keeps_validation_requirements_and_effects_separate_from_after_submission(): void
    {
        $projection = ( new Sentient_Forms_Action_Source_Compatibility_Manifest() )->public_projection();
        $rows       = $projection['rows'] ?? [];

        $spam = array_values(
            array_filter(
                $rows,
                static fn( array $row ): bool => 'spam_detection_v1' === ( $row['action_code'] ?? '' )
                    && 'gravity_forms' === ( $row['form_source'] ?? '' )
            )
        )[0] ?? null;
        $content = array_values(
            array_filter(
                $rows,
                static fn( array $row ): bool => 'content_validation_v1' === ( $row['action_code'] ?? '' )
                    && 'gravity_forms' === ( $row['form_source'] ?? '' )
            )
        )[0] ?? null;

        $this->assertIsArray( $spam );
        $this->assertSame(
            [ 'native_spam_state' ],
            $spam['lifecycles']['validation']['required_capabilities'] ?? null
        );
        $this->assertNotContains(
            'accepted_submission',
            $spam['lifecycles']['validation']['required_capabilities'] ?? []
        );
        $this->assertSame(
            [ 'accepted_submission' ],
            $spam['lifecycles']['after_submission']['required_capabilities'] ?? null
        );

        $this->assertIsArray( $content );
        $this->assertSame(
            [ 'field_errors', 'form_errors' ],
            $content['lifecycles']['validation']['native_effects'] ?? null
        );
        $this->assertNotContains( 'entry_note', $content['lifecycles']['validation']['native_effects'] ?? [] );
    }

    public function test_checked_public_projection_snapshot_matches_runtime_and_contains_only_public_contract_fields(): void
    {
        $path = dirname( __DIR__, 2 ) . '/contracts/action-source-compatibility.v1.json';
        $this->assertFileExists( $path );

        $snapshot_json = (string) file_get_contents( $path );
        $snapshot      = json_decode( $snapshot_json, true, 512, JSON_THROW_ON_ERROR );
        $runtime       = ( new Sentient_Forms_Action_Source_Compatibility_Manifest() )->public_projection();
        $runtime_json  = wp_json_encode(
            $runtime,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ) . "\n";
        $runtime_semantic = json_decode( $runtime_json, true, 512, JSON_THROW_ON_ERROR );

        $this->assertSame( $runtime_json, $snapshot_json );
        $this->assertSame( $runtime_semantic, $snapshot );
        $this->assertSame(
            [ 'contract_version', 'source_sha256', 'action_codes', 'form_sources', 'rows' ],
            array_keys( $snapshot )
        );
        $this->assertSame( 1, $snapshot['contract_version'] ?? null );
        $this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $snapshot['source_sha256'] ?? '' );
        $this->assertCount( 11, $snapshot['action_codes'] ?? [] );
        $this->assertSame(
            [ 'gravity_forms', 'contact_form_7', 'wpforms', 'elementor_pro_forms' ],
            $snapshot['form_sources'] ?? null
        );
        $this->assertCount( 44, $snapshot['rows'] ?? [] );

        foreach ( $snapshot['rows'] as $row )
        {
            $this->assertSame(
                [ 'action_code', 'form_source', 'support_status', 'lifecycles' ],
                array_keys( $row )
            );
            foreach ( $row['lifecycles'] as $lifecycle => $contract )
            {
                $this->assertContains( $lifecycle, [ 'validation', 'after_submission', 'real_time' ] );
                $this->assertSame(
                    [ 'required_capabilities', 'native_effects', 'requires_submission_ledger' ],
                    array_keys( $contract )
                );
                $this->assertSame(
                    array_values( array_unique( $contract['required_capabilities'] ) ),
                    $contract['required_capabilities']
                );
                $this->assertSame(
                    array_values( array_unique( $contract['native_effects'] ) ),
                    $contract['native_effects']
                );
                $this->assertIsBool( $contract['requires_submission_ledger'] );
            }
        }

        $encoded = wp_json_encode( $snapshot );
        $this->assertStringNotContainsString( 'display_name', $encoded );
        $this->assertStringNotContainsString( 'runtime_availability', $encoded );
        $this->assertStringNotContainsString( 'evidence', $encoded );
    }

    public function test_runtime_and_checked_projection_encode_every_lifecycle_map_as_a_json_object(): void
    {
        $runtime_json  = wp_json_encode(
            ( new Sentient_Forms_Action_Source_Compatibility_Manifest() )->public_projection()
        );
        $snapshot_json = (string) file_get_contents(
            dirname( __DIR__, 2 ) . '/contracts/action-source-compatibility.v1.json'
        );

        foreach ( [ $runtime_json, $snapshot_json ] as $json )
        {
            $projection = json_decode( $json, false, 512, JSON_THROW_ON_ERROR );
            $this->assertIsObject( $projection );
            foreach ( $projection->rows as $row )
            {
                $this->assertIsObject( $row->lifecycles );
            }
        }
    }

    public function test_projection_source_digest_matches_independent_portable_allowlist_hash(): void
    {
        $projection = ( new Sentient_Forms_Action_Source_Compatibility_Manifest() )->public_projection();
        $this->assertArrayHasKey( 'source_sha256', $projection );

        $paths = $this->projection_source_paths();
        $this->assertSame( $paths, Sentient_Forms_Action_Source_Compatibility_Snapshot_Verifier::source_paths() );
        $this->assertContains( 'scripts/check-action-source-compatibility-snapshot.php', $paths );

        $expected = $this->independent_projection_source_sha256( $paths );
        $this->assertSame( $expected, $projection['source_sha256'] );

        $manifest_path = 'includes/actions/class-sentient-forms-action-source-compatibility-manifest.php';
        $manifest      = (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $manifest_path );
        $lf_manifest   = str_replace( [ "\r\n", "\r" ], "\n", $manifest );
        $crlf_manifest = str_replace( "\n", "\r\n", $lf_manifest );
        $lf_digest     = $this->independent_projection_source_sha256(
            $paths,
            [ $manifest_path => $lf_manifest ]
        );
        $crlf_digest   = $this->independent_projection_source_sha256(
            $paths,
            [ $manifest_path => $crlf_manifest ]
        );
        $mutated       = $this->independent_projection_source_sha256(
            $paths,
            [ $manifest_path => $lf_manifest . "\n/* projection mutation */\n" ]
        );

        $this->assertSame( $lf_digest, $crlf_digest );
        $this->assertNotSame( $expected, $mutated );
    }

    /**
     * @return array<int, string>
     */
    private function projection_source_paths(): array
    {
        $paths = [
            'includes/actions/class-sentient-forms-action-source-compatibility-manifest.php',
            'includes/adapters/forms/class-forms-adapter-registry.php',
            'includes/adapters/forms/class-sentient-forms-contact-form-7-adapter.php',
            'includes/adapters/forms/class-sentient-forms-elementor-forms-adapter.php',
            'includes/adapters/forms/class-sentient-forms-gravity-forms-adapter.php',
            'includes/adapters/forms/class-sentient-forms-wpforms-adapter.php',
            'includes/class-sentient-forms-bundled-action-templates.php',
            'includes/interfaces/interface-sentient-forms-accepted-submission-adapter.php',
            'includes/interfaces/interface-sentient-forms-action-source-compatibility-manifest.php',
            'includes/interfaces/interface-sentient-forms-native-effects-capabilities-adapter.php',
            'includes/interfaces/interface-sentient-forms-native-entry-capabilities-adapter.php',
            'includes/interfaces/interface-sentient-forms-native-validation-effects-adapter.php',
            'includes/interfaces/interface-sentient-forms-realtime-adapter.php',
            'includes/interfaces/interface-sentient-forms-validation-adapter.php',
            'includes/rest-api/class-form-sources.php',
            'includes/services/class-sentient-forms-action-facet-catalog.php',
            'includes/services/class-sentient-forms-action-policy-resolver.php',
            'includes/services/class-sentient-forms-form-source-lifecycles.php',
            'scripts/check-action-source-compatibility-snapshot.php',
        ];
        sort( $paths, SORT_STRING );

        return $paths;
    }

    /**
     * @param array<int, string>    $paths
     * @param array<string, string> $overrides
     */
    private function independent_projection_source_sha256( array $paths, array $overrides = [] ): string
    {
        $root = dirname( __DIR__, 2 );
        $hash = hash_init( 'sha256' );
        foreach ( $paths as $path )
        {
            $contents   = $overrides[ $path ] ?? (string) file_get_contents( $root . '/' . $path );
            $normalized = str_replace( [ "\r\n", "\r" ], "\n", $contents );
            hash_update( $hash, strlen( $path ) . ':' . $path );
            hash_update( $hash, strlen( $normalized ) . ':' . $normalized );
        }

        return hash_final( $hash );
    }

    public function test_validation_and_realtime_exceptions_are_explicit_and_capability_truthful(): void
    {
        $manifest = new Sentient_Forms_Action_Source_Compatibility_Manifest();
        $sources  = [ 'gravity_forms', 'contact_form_7', 'wpforms', 'elementor_pro_forms' ];

        foreach ( $sources as $source )
        {
            $content = $manifest->get( 'content_validation_v1', $source );
            $spam    = $manifest->get( 'spam_detection_v1', $source );

            $this->assertIsArray( $content );
            $this->assertTrue( $content['supported'] ?? false );
            $this->assertSame( [ 'validation' ], $content['supported_lifecycles'] ?? null );
            $this->assertTrue( $content['source_capabilities']['validation_effects']['field_errors'] ?? false );
            $this->assertSame(
                'contact_form_7' !== $source,
                $content['source_capabilities']['validation_effects']['form_errors'] ?? null
            );

            $this->assertIsArray( $spam );
            $this->assertTrue( $spam['supported'] ?? false );
            $this->assertContains( 'validation', $spam['supported_lifecycles'] ?? [] );
            $this->assertSame(
                in_array( $source, [ 'gravity_forms', 'contact_form_7' ], true ),
                $spam['source_capabilities']['validation_effects']['submission_spam'] ?? null
            );
            $this->assertSame(
                'gravity_forms' === $source,
                $spam['source_capabilities']['native_enrichment']['spam'] ?? null
            );
        }

        $gravity_realtime = $manifest->get( 'clarification_assistant_v1', 'gravity_forms' );
        $this->assertIsArray( $gravity_realtime );
        $this->assertTrue( $gravity_realtime['supported'] ?? false );
        $this->assertSame( [ 'real_time' ], $gravity_realtime['supported_lifecycles'] ?? null );
        $this->assertSame( [ 'realtime_assistance' ], $gravity_realtime['baseline_surfaces'] ?? null );

        foreach ( [ 'contact_form_7', 'wpforms', 'elementor_pro_forms' ] as $source )
        {
            $row = $manifest->get( 'clarification_assistant_v1', $source );
            $this->assertIsArray( $row );
            $this->assertFalse( $row['supported'] ?? true );
            $this->assertSame( 'intentional_unsupported', $row['support_status'] ?? null );
            $this->assertSame( [], $row['supported_lifecycles'] ?? null );
            $this->assertSame( 'unsupported_lifecycle', $row['unsupported_reason_code'] ?? null );
        }
    }

    public function test_realtime_interface_drives_structural_capability_without_overriding_action_compatibility(): void
    {
        $registry = new Sentient_Forms_Form_Adapter_Registry( Sentient_Forms_Plugin::instance() );
        $gravity = $registry->get_adapter_by_id( 'gravity_forms' );
        $wpforms = $registry->get_adapter_by_id( 'wpforms' );
        $this->assertInstanceOf( Sentient_Forms_Realtime_Adapter_Interface::class, $gravity );
        $this->assertNotInstanceOf( Sentient_Forms_Realtime_Adapter_Interface::class, $wpforms );
        $this->assertSame( 'real_time', $gravity->get_realtime_native_hook() );

        $manifest = new Sentient_Forms_Action_Source_Compatibility_Manifest( $registry );
        $gravity_row = $manifest->get( 'clarification_assistant_v1', 'gravity_forms' );
        $wpforms_row = $manifest->get( 'clarification_assistant_v1', 'wpforms' );

        $this->assertTrue( $gravity_row['source_capabilities']['lifecycles']['real_time'] ?? false );
        $this->assertTrue( $gravity_row['source_capabilities']['requirements']['realtime_qna_storage'] ?? false );
        $this->assertFalse( $wpforms_row['source_capabilities']['lifecycles']['real_time'] ?? true );
        $this->assertFalse( $wpforms_row['supported'] ?? true );
        $this->assertSame( 'intentional_unsupported', $wpforms_row['support_status'] ?? null );
    }

    public function test_unknown_source_without_realtime_is_classified_by_capability_not_slug(): void
    {
        $plugin  = Sentient_Forms_Plugin::instance();
        $adapter = new class( $plugin ) extends Sentient_Forms_Contact_Form_7_Adapter
        {
            public function get_id(): string
            {
                return 'future_forms';
            }

            public function get_name(): string
            {
                return 'Future Forms';
            }
        };
        $manifest   = new Sentient_Forms_Action_Source_Compatibility_Manifest();
        $build_row  = new ReflectionMethod( $manifest, 'build_row' );
        $definition = Sentient_Forms_Bundled_Action_Templates::get( 'clarification_assistant_v1' );

        $this->assertIsArray( $definition );
        $row = $build_row->invoke(
            $manifest,
            'clarification_assistant_v1',
            $definition,
            $adapter->get_id(),
            $adapter->get_capability_descriptor(),
            $adapter
        );

        $this->assertFalse( $row['source_capabilities']['lifecycles']['real_time'] ?? true );
        $this->assertFalse( $row['supported'] ?? true );
        $this->assertSame( 'intentional_unsupported', $row['support_status'] ?? null );
        $this->assertSame( 'unsupported_lifecycle', $row['unsupported_reason_code'] ?? null );
    }

    public function test_after_submission_actions_share_ledger_baseline_without_generalizing_gravity_native_effects(): void
    {
        $manifest = new Sentient_Forms_Action_Source_Compatibility_Manifest();
        $actions  = [
            'entry_summary_v1',
            'sentiment_urgency_v1',
            'missing_information_v1',
            'pain_point_intent_v1',
            'routing_recommendation_v1',
            'toxicity_moderation_v1',
            'lead_grading_v1',
            'suggested_reply_v1',
        ];
        $sources = [ 'gravity_forms', 'contact_form_7', 'wpforms', 'elementor_pro_forms' ];

        foreach ( $actions as $action_code )
        {
            foreach ( $sources as $source )
            {
                $row = $manifest->get( $action_code, $source );
                $this->assertIsArray( $row );
                $this->assertTrue( $row['supported'] ?? false );
                $this->assertSame( [ 'after_submission' ], $row['supported_lifecycles'] ?? null );
                $this->assertSame( [ 'submission_ledger' ], $row['baseline_surfaces'] ?? null );
                $this->assertSame(
                    'gravity_forms' === $source,
                    $row['source_capabilities']['native_enrichment']['notes'] ?? null
                );
            }
        }

        $spam = $manifest->get( 'spam_detection_v1', 'gravity_forms' );
        $this->assertSame( [ 'spam_guidance_rationale_generation' ], $spam['allowed_facets'] ?? null );
        $this->assertSame( [], $spam['enabled_facets'] ?? null );
        $this->assertArrayNotHasKey( 'provider_adapter', $spam );
        $this->assertArrayNotHasKey( 'direct_provider', $spam );
        $this->assertArrayNotHasKey( 'managed_provider', $spam );
    }

    public function test_elementor_structural_compatibility_is_stable_when_runtime_is_unavailable(): void
    {
        remove_all_filters( 'sentient_forms_elementor_is_active' );
        remove_all_filters( 'sentient_forms_elementor_pro_forms_api_available' );
        add_filter( 'sentient_forms_elementor_is_active', '__return_false' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_false' );

        $manifest = new Sentient_Forms_Action_Source_Compatibility_Manifest(
            new Sentient_Forms_Form_Adapter_Registry( Sentient_Forms_Plugin::instance() )
        );
        $actions = [
            'entry_summary_v1',
            'sentiment_urgency_v1',
            'missing_information_v1',
            'pain_point_intent_v1',
            'routing_recommendation_v1',
            'toxicity_moderation_v1',
            'lead_grading_v1',
            'suggested_reply_v1',
            'spam_detection_v1',
        ];

        foreach ( $actions as $action_code )
        {
            $row = $manifest->get( $action_code, 'elementor_pro_forms' );
            $this->assertIsArray( $row );
            $this->assertTrue( $row['supported'] ?? false );
            $this->assertSame( 'supported', $row['support_status'] ?? null );
            $this->assertContains( 'after_submission', $row['supported_lifecycles'] ?? [] );
            $this->assertTrue( $row['source_capabilities']['lifecycles']['after_submission'] ?? false );
            $this->assertFalse( $row['runtime_availability']['is_active'] ?? true );
            $this->assertSame( 'not_installed', $row['runtime_availability']['status'] ?? null );
            $this->assertFalse( $row['runtime_availability']['lifecycles']['after_submission'] ?? true );
            $this->assertTrue( $row['runtime_availability']['requirements']['requires_pro'] ?? false );
        }
    }

    public function test_wpforms_structural_native_entry_capabilities_are_installation_invariant(): void
    {
        remove_all_filters( 'sentient_forms_wpforms_is_active' );
        remove_all_filters( 'sentient_forms_wpforms_native_entry_storage_available' );
        add_filter( 'sentient_forms_wpforms_is_active', '__return_false' );
        add_filter( 'sentient_forms_wpforms_native_entry_storage_available', '__return_false' );

        $inactive = ( new Sentient_Forms_Action_Source_Compatibility_Manifest(
            new Sentient_Forms_Form_Adapter_Registry( Sentient_Forms_Plugin::instance() )
        ) )->get( 'entry_summary_v1', 'wpforms' );

        remove_all_filters( 'sentient_forms_wpforms_is_active' );
        remove_all_filters( 'sentient_forms_wpforms_native_entry_storage_available' );
        add_filter( 'sentient_forms_wpforms_is_active', '__return_true' );
        add_filter( 'sentient_forms_wpforms_native_entry_storage_available', '__return_true' );

        $available = ( new Sentient_Forms_Action_Source_Compatibility_Manifest(
            new Sentient_Forms_Form_Adapter_Registry( Sentient_Forms_Plugin::instance() )
        ) )->get( 'entry_summary_v1', 'wpforms' );

        $expected_structural = [
            'id'    => true,
            'link'  => true,
            'read'  => false,
            'write' => false,
        ];

        $this->assertSame( $expected_structural, $inactive['source_capabilities']['native_entry'] ?? null );
        $this->assertSame( $expected_structural, $available['source_capabilities']['native_entry'] ?? null );
        $this->assertSame(
            [ 'id' => false, 'link' => false, 'read' => false, 'write' => false ],
            $inactive['runtime_availability']['native_entry'] ?? null
        );
        $this->assertSame( $expected_structural, $available['runtime_availability']['native_entry'] ?? null );
    }

    public function test_gravity_webhook_capability_is_structural_while_addon_availability_is_runtime_state(): void
    {
        $registry = new Sentient_Forms_Form_Adapter_Registry( Sentient_Forms_Plugin::instance() );
        $row = ( new Sentient_Forms_Action_Source_Compatibility_Manifest( $registry ) )
            ->get( 'spam_detection_v1', 'gravity_forms' );

        $this->assertTrue( $row['source_capabilities']['native_enrichment']['webhook_controls'] ?? false );
        $this->assertFalse( $row['runtime_availability']['native_enrichment']['webhook_controls'] ?? true );
    }
}
