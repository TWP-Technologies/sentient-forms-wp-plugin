<?php
/**
 * Tests the source-neutral native effect outcome contract.
 */

class NativeEffectOutcomesTest extends WP_UnitTestCase
{
    public function test_merge_exposes_all_four_terminal_native_effect_states(): void
    {
        $outcomes = Sentient_Forms_Native_Effect_Outcomes::merge(
            [
                [
                    'effect' => 'entry_note',
                    'status' => 'unsupported',
                    'reason' => 'native_notes_unavailable',
                ],
            ],
            Sentient_Forms_Native_Effect_Outcomes::from_execution_effects(
                [
                    'applied' => [ 'store_result' ],
                    'skipped' => [
                        [
                            'effect' => 'mark_as_spam',
                            'reason' => 'classification_not_found',
                        ],
                    ],
                    'failed'  => [
                        [
                            'effect' => 'post_execution:webhook',
                            'reason' => 'transport_failed',
                        ],
                    ],
                ]
            )
        );

        $by_effect = [];
        foreach ( $outcomes as $outcome )
        {
            $by_effect[ $outcome['effect'] ] = $outcome;
        }

        $this->assertSame( 'unsupported', $by_effect['entry_note']['status'] ?? null );
        $this->assertSame( 'applied', $by_effect['store_result']['status'] ?? null );
        $this->assertSame( 'skipped', $by_effect['mark_as_spam']['status'] ?? null );
        $this->assertSame( 'failed', $by_effect['post_execution:webhook']['status'] ?? null );
        $this->assertSame( '', $by_effect['store_result']['reason'] ?? null );
    }

    public function test_merge_keeps_stricter_preflight_outcome_for_the_same_effect(): void
    {
        $outcomes = Sentient_Forms_Native_Effect_Outcomes::merge(
            Sentient_Forms_Native_Effect_Outcomes::from_execution_effects(
                [ 'applied' => [ 'entry_note' ] ]
            ),
            [
                [
                    'effect' => 'entry_note',
                    'status' => 'unsupported',
                    'reason' => 'native_notes_unavailable',
                ],
            ]
        );

        $this->assertSame(
            [
                [
                    'effect' => 'entry_note',
                    'status' => 'unsupported',
                    'reason' => 'native_notes_unavailable',
                ],
            ],
            $outcomes
        );
    }
}
