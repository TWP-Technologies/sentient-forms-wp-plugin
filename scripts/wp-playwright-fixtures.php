<?php
/**
 * Seeds deterministic data for Playwright e2e tests.
 *
 * Run via: wp eval-file wp-content/plugins/sentient-forms/scripts/wp-playwright-fixtures.php
 */

if ( ! class_exists( 'GFAPI' ) ) {
    echo "0";
    return;
}

$form_title = 'Playwright QA Form';
$form_id    = null;

$forms = GFAPI::get_forms();
foreach ( $forms as $form ) {
    if ( isset( $form['title'] ) && $form['title'] === $form_title ) {
        $form_id = (int) $form['id'];
        break;
    }
}

if ( null === $form_id ) {
    $form = [
        'title'  => $form_title,
        'fields' => [
            [
                'type'        => 'text',
                'id'          => 1,
                'label'       => 'Name',
                'isRequired'  => true,
            ],
            [
                'type'  => 'email',
                'id'    => 2,
                'label' => 'Email',
            ],
        ],
        'button' => [
            'type' => 'text',
            'text' => 'Submit',
        ],
    ];

    $created = GFAPI::add_form( $form );
    if ( is_wp_error( $created ) ) {
        echo "0";
        return;
    }

    $form_id = (int) $created;
}

$option_key   = sprintf( 'sentient_forms_actions_gravity_forms_%d', $form_id );
$option_value = [
    'enabled' => true,
    'actions' => [
        'playwright_spam' => [
            'local_mapping_id'           => 'map_playwright_spam',
            'central_action_id'          => 'spam_detection_v1',
            'action_name_label'          => 'Playwright Spam Detection',
            'trigger_hooks'              => [ 'gform_validation' ],
            'is_action_enabled_for_form' => true,
            'execution_priority'         => 10,
            'action_type_indicator'      => 'master',
        ],
    ],
];

update_option( $option_key, $option_value, false );

echo (string) $form_id;
