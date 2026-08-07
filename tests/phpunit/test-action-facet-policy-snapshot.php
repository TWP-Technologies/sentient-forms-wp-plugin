<?php

require_once dirname( __DIR__, 2 ) . '/scripts/check-action-facet-policy-snapshot.php';

final class Tests_Action_Facet_Policy_Snapshot extends WP_UnitTestCase
{
    public function test_committed_snapshot_matches_the_plugin_owned_facet_catalog(): void
    {
        $plugin_root = dirname( __DIR__, 2 );

        Sentient_Forms_Action_Facet_Policy_Snapshot_Verifier::verify_snapshot( $plugin_root );

        $snapshot = json_decode(
            (string) file_get_contents( $plugin_root . '/contracts/action-facet-policy-catalog.v1.json' ),
            true,
            64,
            JSON_THROW_ON_ERROR
        );
        $this->assertSame(
            [ 'snapshot_version', 'source_sha256', 'facets' ],
            array_keys( $snapshot )
        );
        $this->assertSame(
            [ 'spam_guidance_rationale_generation' ],
            array_keys( $snapshot['facets'] )
        );
    }

    public function test_verifier_rejects_drifted_or_incomplete_snapshot_data(): void
    {
        $plugin_root = dirname( __DIR__, 2 );
        $snapshot = Sentient_Forms_Action_Facet_Policy_Snapshot_Verifier::build_snapshot( $plugin_root );

        $drifted = $snapshot;
        $drifted['facets']['spam_guidance_rationale_generation']['feature_access'] = 'unrestricted';
        $this->assertFalse(
            Sentient_Forms_Action_Facet_Policy_Snapshot_Verifier::snapshot_matches( $plugin_root, $drifted )
        );

        $incomplete = $snapshot;
        unset( $incomplete['facets']['spam_guidance_rationale_generation']['metering_class'] );
        $this->assertFalse(
            Sentient_Forms_Action_Facet_Policy_Snapshot_Verifier::snapshot_matches( $plugin_root, $incomplete )
        );

        $unknown = $snapshot;
        $unknown['facets']['unknown_facet'] = $snapshot['facets']['spam_guidance_rationale_generation'];
        $unknown['facets']['unknown_facet']['code'] = 'unknown_facet';
        $this->assertFalse(
            Sentient_Forms_Action_Facet_Policy_Snapshot_Verifier::snapshot_matches( $plugin_root, $unknown )
        );
    }
}
