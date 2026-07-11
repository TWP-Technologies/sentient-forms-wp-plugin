<?php
/**
 * Admin forms view
 *
 * @package Sentient_Forms
 */

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) )
{
    exit;
}

// Data for the view is passed via extract() in Sentient_Forms_Admin::render_forms_tab()
// Expected variables: $plugin, $active_form_adapters, $all_actions

// Prepare $forms data (this was originally here and is fine for the PHP part of the view)
$_plugin_instance = $plugin ?? Sentient_Forms_Plugin::instance(); // Ensure plugin instance is available
$_form_adapter_registry = $_plugin_instance->get_form_adapter_registry();
$_forms_list = [];

if ( $_form_adapter_registry )
{
    $_form_providers = $_form_adapter_registry->get_adapters( true ); // Get only active adapters
    if ( !empty( $_form_providers ) )
    {
        $_all_forms_from_providers = array_map(
            static fn( Sentient_Forms_Adapter_Interface $provider ) => $provider->get_forms(),
            $_form_providers,
        );
        $_all_forms_from_providers = array_filter( $_all_forms_from_providers ); // Filter out empty results

        if ( !empty( $_all_forms_from_providers ) )
        {
            $_forms_list = array_reduce(
                $_all_forms_from_providers,
                static fn( $carry, $item ) => array_merge( $carry, is_array( $item ) ? $item : [] ),
                [],
            );
        }
    }
}
// The $forms variable is now $_forms_list for clarity in this scope

?>
<div class="wrap sentient-forms-admin">
    <h1><?php _e( 'Sentient Forms - Form Settings', 'sentient-forms' ); ?></h1>

    <?php if ( empty( $_forms_list ) ): ?>
    <div class="notice notice-warning">
        <p>
            <?php
            if ( empty( $active_form_adapters ) )
            {
                _e(
                    'No active form builder integrations found. Please ensure a supported form plugin (e.g., Gravity Forms) is active.',
                    'sentient-forms',
                );
            }
            else
            {
                _e( 'No forms found for the active form builders. Please create a form in your form builder first.', 'sentient-forms' );
            }
            ?>
        </p>
    </div>
    <?php else: ?>
    <div class="sentient-forms-forms-list">
        <div class="sentient-forms-forms-header">
            <p><?php _e(
                'Configure AI actions for your forms. Enable actions for each form and customize their settings.',
                'sentient-forms',
            ); ?></p>
        </div>

        <div class="sentient-forms-forms-table-wrapper">
            <table class="wp-list-table widefat fixed striped sentient-forms-forms-table">
                <thead>
                    <tr>
                        <th class="sentient-forms-form-title-column"><?php _e( 'Form', 'sentient-forms' ); ?></th>
                        <th class="sentient-forms-form-builder-column"><?php _e( 'Form Builder', 'sentient-forms' ); ?></th>
                        <th class="sentient-forms-form-status-column"><?php _e( 'Status', 'sentient-forms' ); ?></th>
                        <th class="sentient-forms-form-actions-column"><?php _e( 'Actions', 'sentient-forms' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $_forms_list as $form ): ?>
                    <tr>
                        <td class="sentient-forms-form-title-column">
                            <strong><?= esc_html( $form[ 'title' ] ); ?></strong>
                            <div class="row-actions">
                                        <span class="edit">
                                            <a href="#"
                                               class="sentient-forms-configure-form"
                                               data-form-id="<?= esc_attr( $form[ 'id' ] ); ?>"
                                               data-adapter="<?= esc_attr( $form[ 'adapter' ] ); ?>">
                                                <?php _e( 'Configure', 'sentient-forms' ); ?>
                                            </a>
                                        </span>
                            </div>
                        </td>
                        <td class="sentient-forms-form-builder-column">
                            <?php echo esc_html( $form[ 'adapter_name' ] ); // Ensure 'adapter_name' is part of $form data ?>
                        </td>
                        <td class="sentient-forms-form-status-column">
                            <?php if ( isset( $form[ 'settings' ][ 'enabled' ] ) && $form[ 'settings' ][ 'enabled' ] ): ?>
                            <span class="sentient-forms-status sentient-forms-status-enabled">
                                            <span class="dashicons dashicons-yes-alt"></span>
                                            <?php _e( 'Enabled', 'sentient-forms' ); ?>
                                        </span> <?php else: ?> <span class="sentient-forms-status sentient-forms-status-disabled">
                                            <span class="dashicons dashicons-no-alt"></span>
                                            <?php _e( 'Disabled', 'sentient-forms' ); ?>
                                        </span> <?php endif; ?>
                        </td>
                        <td class="sentient-forms-form-actions-column">
                            <?php
                            $enabled_actions = 0;
                            if ( isset( $form[ 'settings' ][ 'actions' ] ) && is_array( $form[ 'settings' ][ 'actions' ] ) )
                            {
                                foreach ( $form[ 'settings' ][ 'actions' ] as $action_id => $action_settings )
                                {
                                    if ( isset( $action_settings[ 'enabled' ] ) && $action_settings[ 'enabled' ] )
                                    {
                                        $enabled_actions++;
                                    }
                                }
                            }
                            ?> <span class="sentient-forms-action-count">
                                        <?php printf(
                                            esc_html( _n( '%s action enabled', '%s actions enabled', $enabled_actions, 'sentient-forms' ) ),
                                            esc_html( $enabled_actions ),
                                        ); ?>
                                    </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="sentient-forms-form-modal" class="sentient-forms-modal">
        <div class="sentient-forms-modal-backdrop"></div>
        <div class="sentient-forms-modal-dialog">
            <div class="sentient-forms-modal-content">
                <div class="sentient-forms-modal-header">
                    <h2><?php _e( 'Configure Form', 'sentient-forms' ); ?>: <span id="sentient-forms-modal-form-title"></span></h2>
                    <button type="button" class="sentient-forms-modal-close" aria-label="<?php esc_attr_e( 'Close', 'sentient-forms' ); ?>">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="sentient-forms-modal-body">
                    <form id="sentient-forms-form-settings-form"> <?php // Changed ID to avoid conflict if any, and to be more specific ?>
                        <input type="hidden" id="sentient-forms-form-id" name="form_id" value="">
                        <input type="hidden" id="sentient-forms-adapter-id" name="adapter_id" value="">

                        <div class="sentient-forms-form-settings-section">
                            <h3><?php _e( 'General Settings', 'sentient-forms' ); ?></h3>
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="sentient-forms-form-enabled"><?php _e( 'Enable Sentient Forms', 'sentient-forms' ); ?></label>
                                    </th>
                                    <td>
                                        <label>
                                            <input type="checkbox" id="sentient-forms-form-enabled" name="settings[enabled]" value="1">
                                            <?php _e( 'Enable AI actions for this form', 'sentient-forms' ); ?>
                                        </label>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <div class="sentient-forms-form-settings-section">
                            <h3><?php _e( 'Actions', 'sentient-forms' ); ?></h3>
                            <?php if ( empty( $all_actions ) ): ?>
                            <p><?php _e(
                                'No actions are currently available. This might be a configuration issue with the plugin.',
                                'sentient-forms',
                            ); ?></p>
                            <?php else: ?>
                            <div class="sentient-forms-actions-tabs">
                                <div class="sentient-forms-actions-tabs-nav">
                                    <?php foreach ( $all_actions as $action ): ?>
                                    <button type="button"
                                            class="sentient-forms-actions-tab-button"
                                            data-action-id="<?php echo esc_attr( $action->get_id() ); ?>">
                                        <span class="dashicons <?php echo esc_attr( $action->get_icon() ); ?>"></span> <?php echo esc_html(
                                        $action->get_name(),
                                    ); ?>
                                    </button>
                                    <?php endforeach; ?>
                                </div>

                                <div class="sentient-forms-actions-tabs-content-wrapper"> <?php // Added a wrapper for content sections ?> <?php foreach ( $all_actions as $action ): ?>
                                    <div class="sentient-forms-actions-tab-content" data-action-id="<?php echo esc_attr( $action->get_id() ); ?>">
                                        <div class="sentient-forms-action-header">
                                            <h4><?php echo esc_html( $action->get_name() ); ?></h4>
                                            <p><?php echo esc_html( $action->get_description() ); ?></p>
                                        </div>

                                        <table class="form-table">
                                            <tr>
                                                <th scope="row">
                                                    <label for="sentient-forms-action-<?php echo esc_attr( $action->get_id() ); ?>-enabled">
                                                        <?php _e( 'Enable Action', 'sentient-forms' ); ?>
                                                    </label>
                                                </th>
                                                <td>
                                                    <label>
                                                        <input type="checkbox"
                                                               id="sentient-forms-action-<?php echo esc_attr( $action->get_id() ); ?>-enabled"
                                                               name="settings[actions][<?php echo esc_attr( $action->get_id() ); ?>][enabled]"
                                                               value="1">
                                                        <?php printf(
                                                            esc_html__( 'Enable %s for this form', 'sentient-forms' ),
                                                            esc_html( $action->get_name() ),
                                                        ); ?>
                                                    </label>
                                                </td>
                                            </tr>

                                            <?php
                                            // Get action settings fields
                                            $settings_fields = $action->get_settings_fields();

                                            // Remove 'enabled' field if it's managed globally, or ensure it has a unique context if needed
                                            // The current structure has 'enabled' per action, which is fine.

                                            // Display remaining settings fields
                                            foreach ( $settings_fields as $field_key => $field_args ):
                                            // Skip the main 'enabled' field for the action if it's already handled above
                                            // or if it's a common field that shouldn't be duplicated here.
                                                // The legacy settings array may not contain an explicit enabled flag.
                                            // So, this check might not be necessary unless 'enabled' is also part of $settings_fields.

                                            $field_input_id = "sentient-forms-action-" . esc_attr( $action->get_id() ) . "-" . esc_attr( $field_key );
                                            $field_input_name = "settings[actions][" .
                                                                esc_attr( $action->get_id() ) .
                                                                "][" .
                                                                esc_attr( $field_key ) .
                                                                "]";
                                            $default_value = $field_args[ 'default' ] ?? '';
                                            ?>
                                            <tr>
                                                <th scope="row">
                                                    <label for="<?php echo esc_attr( $field_input_id ); ?>">
                                                        <?php echo esc_html( $field_args[ 'label' ] ); ?>
                                                    </label>
                                                </th>
                                                <td>
                                                    <?php if ( $field_args[ 'type' ] === 'text' || $field_args[ 'type' ] === 'number' ): ?>
                                                    <input type="<?php echo esc_attr( $field_args[ 'type' ] ); ?>"
                                                           id="<?php echo esc_attr( $field_input_id ); ?>"
                                                           name="<?php echo esc_attr( $field_input_name ); ?>"
                                                           value="<?php echo esc_attr( $default_value ); ?>"
                                                           class="<?php echo ( $field_args[ 'type' ] === 'number' ) ? 'small-text'
                                                               : 'regular-text'; ?>"
                                                           <?php if ( isset( $field_args[ 'min' ] ) ): ?>min="<?= esc_attr(
                                                               $field_args[ 'min' ],
                                                           ); ?>"<?php endif; ?>
                                                           <?php if ( isset( $field_args[ 'max' ] ) ): ?>max="<?= esc_attr(
                                                               $field_args[ 'max' ],
                                                           ); ?>"<?php endif; ?>
                                                           <?php if ( isset( $field_args[ 'step' ] ) ): ?>step="<?= esc_attr(
                                                               $field_args[ 'step' ],
                                                           ); ?>"<?php endif; ?>>
                                                    <?php elseif ( $field_args[ 'type' ] === 'checkbox' ): ?>
                                                    <label>
                                                        <input type="checkbox"
                                                               id="<?php echo esc_attr( $field_input_id ); ?>"
                                                               name="<?php echo esc_attr( $field_input_name ); ?>"
                                                               value="1"<?php checked(
                                                            rest_sanitize_boolean( $default_value ),
                                                        ); // Default for checkbox should be boolean ?>>
                                                        <?php echo isset( $field_args[ 'description_inline' ] ) ? ' ' . esc_html(
                                                                $field_args[ 'description_inline' ],
                                                            ) : ''; ?>
                                                    </label>
                                                    <?php elseif ( $field_args[ 'type' ] === 'select' ||
                                                                   $field_args[ 'type' ] === 'llm_model_select' ): ?>
                                                    <select id="<?= esc_attr( $field_input_id ); ?>" name="<?= esc_attr( $field_input_name ); ?>">
                                                        <?php
                                                        $options_to_display = $field_args[ 'options' ] ?? [];
                                                        if ( $field_args[ 'type' ] === 'llm_model_select' )
                                                        {
                                                            echo '<option value="">' .
                                                                 esc_html__( 'Use Global Default LLM', 'sentient-forms' ) .
                                                                 '</option>';
                                                            $_llm_registry   = $_plugin_instance->get_llm_model_registry();
                                                            $_available_llms = [];
                                                            if ( $_llm_registry )
                                                            {
                                                                $_required_capabilities = method_exists(
                                                                    $action,
                                                                    'get_required_llm_capabilities',
                                                                ) ? $action->get_required_llm_capabilities() : [];
                                                                $_available_llms        = $_llm_registry->get_models(
                                                                    statuses    : [
                                                                                      Sentient_Forms_Llm_Status::ACTIVE,
                                                                                      Sentient_Forms_Llm_Status::PREVIEW,
                                                                                  ],
                                                                    capabilities: $_required_capabilities,
                                                                );
                                                            }
                                                            if ( !empty( $_available_llms ) )
                                                            {
                                                                foreach ( $_available_llms as $_llm_model )
                                                                {
                                                                    echo sprintf(
                                                                        '<option value="%s" %s>%s</option>',
                                                                        esc_attr( $_llm_model->get_id() ),
                                                                        selected( $default_value, $_llm_model->get_id(), false ),
                                                                        esc_html(
                                                                            $_llm_model->get_name() .
                                                                            ' (' .
                                                                            $_llm_model->get_cost_tier()->get_name() .
                                                                            ')',
                                                                        ),
                                                                    );
                                                                }
                                                            }
                                                            else
                                                            {
                                                                echo '<option value="" disabled>' .
                                                                     esc_html__( 'No compatible LLMs found.', 'sentient-forms' ) .
                                                                     '</option>';
                                                            }
                                                        }
                                                        else
                                                        { // Regular select
                                                        foreach ( $options_to_display as $option_value => $option_label ): ?>
                                                        <option value="<?= esc_attr( $option_value ); ?>"<?php selected(
                                                            $default_value,
                                                            $option_value,
                                                        ); ?>>
                                                            <?= esc_html( $option_label ); ?>
                                                        </option>
                                                        <?php endforeach;
                                                        } ?>
                                                    </select>
                                                    <?php elseif ( $field_args[ 'type' ] === 'textarea' ): ?> <textarea id="<?= esc_attr(
                                                    $field_input_id,
                                                ); ?>" name="<?= esc_attr( $field_input_name ); ?>" class="large-text" rows="10"><?= esc_textarea(
                                                    $default_value,
                                                ); ?>
                                                                </textarea> <?php endif; ?>

                                                    <?php if ( isset( $field_args[ 'description' ] ) && $field_args[ 'type' ] !== 'checkbox' ): ?>
                                                    <p class="description"><?= wp_kses_post( $field_args[ 'description' ] ); ?></p>
                                                    <?php elseif ( isset( $field_args[ 'description' ] ) &&
                                                                   $field_args[ 'type' ] === 'checkbox' &&
                                                                   empty( $field_args[ 'description_inline' ] ) ): ?>
                                                    <p class="description"><?= wp_kses_post( $field_args[ 'description' ] ); ?></p>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>

                                            <?php if ( method_exists( $action, 'get_hooks' ) && !empty( $action->get_hooks() ) ): ?>
                                            <tr>
                                                <th scope="row">
                                                    <label><?php _e(
                                                        'When to Run',
                                                        'sentient-forms',
                                                    ); // No 'for' needed if multiple checkboxes ?></label>
                                                </th>
                                                <td>
                                                    <?php
                                                    $hooks = $action->get_hooks();
                                                    foreach ( $hooks as $hook_id => $hook_label ):
                                                    $hook_input_id = "sentient-forms-action-" .
                                                                     esc_attr( $action->get_id() ) .
                                                                     "-hook-" .
                                                                     esc_attr( $hook_id );
                                                    ?>
                                                    <label for="<?php echo esc_attr( $hook_input_id ); ?>">
                                                        <input type="checkbox"
                                                               id="<?php echo esc_attr( $hook_input_id ); ?>"
                                                               name="settings[actions][<?php echo esc_attr(
                                                                   $action->get_id(),
                                                               ); ?>][hooks][]"
                                                               value="<?php echo esc_attr( $hook_id ); ?>">
                                                        <?php echo esc_html( $hook_label ); ?>
                                                    </label>
                                                    <br> <?php endforeach; ?>
                                                    <p class="description"><?= esc_html__(
                                                        'Select the events that should trigger this action for the form.',
                                                        'sentient-forms',
                                                    ); ?></p>
                                                </td>
                                            </tr>
                                            <?php endif; ?>
                                        </table>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; // End check for empty $all_actions ?>
                        </div>

                        <div class="sentient-forms-form-settings-actions">
                            <button type="button" class="button button-primary" id="sentient-forms-save-form-settings">
                                <?php _e( 'Save Settings', 'sentient-forms' ); ?>
                            </button>
                            <button type="button"
                                    class="button button-secondary sentient-forms-modal-close-button"> <?php // Changed class to avoid conflict ?><?php _e(
                                'Cancel',
                                'sentient-forms',
                            ); ?>
                            </button>
                            <span id="sentient-forms-form-settings-result"
                                  class="sentient-forms-ajax-result"></span> <?php // Added class for styling ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
