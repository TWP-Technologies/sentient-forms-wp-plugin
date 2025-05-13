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
// The JavaScript will use the localized `sentientFormsFormsData` or `sentientFormsAdmin.forms`

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
                                            // However, `get_settings_fields()` in `Abstract_Action` adds 'llm', not 'enabled'.
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

    <script type="text/javascript">
        jQuery( document ).ready( function( $ )
        {
            // Ensure sentientFormsAdmin and sentientFormsFormsData are available
            if ( typeof sentientFormsAdmin === 'undefined' )
            {
                console.error( 'Sentient Forms: sentientFormsAdmin object is not defined. Admin scripts may not work correctly.' );
                // Optionally, display a user-facing error or disable functionality
                // return; // Stop further execution if critical data is missing
            }
            if ( typeof sentientFormsFormsData === 'undefined' )
            {
                console.error( 'Sentient Forms: sentientFormsFormsData object is not defined. Form configuration may not work correctly.' );
                // return;
            }

            const modal                   = $( '#sentient-forms-form-modal' );
            // Use sentientFormsFormsData for the initial list of forms for the modal population logic
            const formsForModalPopulation = ( typeof sentientFormsFormsData !== 'undefined' ) ? sentientFormsFormsData : [];

            // Open modal when Configure is clicked
            $( '.sentient-forms-configure-form' ).on( 'click', function( e )
            {
                e.preventDefault();

                const formIdFromButton  = $( this ).data( 'form-id' );
                const adapterFromButton = $( this ).data( 'adapter' );
                let formToConfigure     = null;

                // Find the form data using formsForModalPopulation
                for ( let i = 0; i < formsForModalPopulation.length; i++ )
                {
                    if ( formsForModalPopulation[ i ].id == formIdFromButton && formsForModalPopulation[ i ].adapter == adapterFromButton )
                    {
                        formToConfigure = formsForModalPopulation[ i ];
                        break;
                    }
                }

                if ( !formToConfigure )
                {
                    alert( 'Error: Could not find form data for configuration.' ); // Basic error feedback
                    return;
                }

                // Set form title and IDs
                $( '#sentient-forms-modal-form-title' ).text( formToConfigure.title );
                $( '#sentient-forms-form-id' ).val( formToConfigure.id );
                $( '#sentient-forms-adapter-id' ).val( formToConfigure.adapter );

                // Reset form to defaults before applying saved settings
                $( '#sentient-forms-form-settings-form' )[ 0 ].reset(); // Reset the entire form
                $( '.sentient-forms-actions-tab-content input[type="checkbox"]' ).prop( 'checked', false ); // Explicitly uncheck all action checkboxes

                // Set form settings
                const savedSettings = formToConfigure.settings || {};
                $( '#sentient-forms-form-enabled' ).prop( 'checked', savedSettings.enabled || false );

                // Set action settings
                const savedActions = savedSettings.actions || {};

                // Reset all action settings fields to their PHP-defined defaults or empty
                $( '.sentient-forms-actions-tab-content' ).each( function()
                {
                    const actionTab = $( this );
                    actionTab.find( 'input[type="text"], input[type="number"]' ).each( function()
                    {
                        $( this ).val( $( this ).attr( 'value' ) || '' ); // Reset to default value attribute from PHP or empty
                    } );
                    actionTab.find( 'textarea' ).each( function()
                    {
                        $( this ).val( $( this ).text() || '' ); // Reset to default content from PHP or empty
                    } );
                    actionTab.find( 'select' ).each( function()
                    {
                        const defaultValue = $( this ).find( 'option[selected]' ).val();
                        if ( typeof defaultValue !== 'undefined' )
                        {
                            $( this ).val( defaultValue );
                        } else
                        {
                            $( this ).prop( 'selectedIndex', 0 ); // Reset to the first option if no default selected
                        }
                    } );
                    // Checkboxes are handled below by specific saved settings
                } );

                // Apply saved action settings from formToConfigure.settings.actions
                for ( const actionId in savedActions )
                {
                    if ( !savedActions.hasOwnProperty( actionId ) )
                    {
                        continue;
                    }

                    const actionSettings   = savedActions[ actionId ];
                    const actionTabContent = $( '.sentient-forms-actions-tab-content[data-action-id="' + actionId + '"]' );

                    // Set enabled state for the action itself
                    $( '#sentient-forms-action-' + actionId + '-enabled' ).prop( 'checked', actionSettings.enabled || false );

                    // Set other specific settings for this action
                    for ( const settingKey in actionSettings )
                    {
                        if ( !actionSettings.hasOwnProperty( settingKey ) || settingKey === 'enabled' || settingKey === 'hooks' )
                        {
                            continue;
                        }

                        const fieldInput = actionTabContent.find( '[name="settings[actions][' + actionId + '][' + settingKey + ']"]' );

                        if ( fieldInput.is( 'input[type="checkbox"]' ) )
                        {
                            fieldInput.prop( 'checked', actionSettings[ settingKey ] || false );
                        } else if ( fieldInput.is( 'select' ) )
                        {
                            fieldInput.val( actionSettings[ settingKey ] );
                        } else if ( fieldInput.is( 'textarea' ) )
                        {
                            fieldInput.val( actionSettings[ settingKey ] );
                        } else
                        { // text, number
                            fieldInput.val( actionSettings[ settingKey ] );
                        }
                    }

                    // Set hooks
                    const hooksForAction = actionSettings.hooks || [];
                    actionTabContent.find( 'input[name="settings[actions][' + actionId + '][hooks][]"]' ).each( function()
                    {
                        if ( hooksForAction.includes( $( this ).val() ) )
                        {
                            $( this ).prop( 'checked', true );
                        } else
                        {
                            $( this ).prop( 'checked', false ); // Explicitly uncheck if not in saved hooks
                        }
                    } );
                }

                // Show the first action tab
                $( '.sentient-forms-actions-tab-button:first' ).addClass( 'active' ).siblings().removeClass( 'active' );
                $( '.sentient-forms-actions-tab-content-wrapper .sentient-forms-actions-tab-content:first' )
                    .addClass( 'active' )
                    .siblings()
                    .removeClass( 'active' );

                // Show the modal
                modal.addClass( 'sentient-forms-modal-open' );
            } );

            // Close modal functionality (delegated for dynamically added close buttons if any)
            $( document )
                .on( 'click', '.sentient-forms-modal-close, .sentient-forms-modal-close-button, .sentient-forms-modal-backdrop', function( e )
                {
                    if ( $( this ).hasClass( 'sentient-forms-modal-backdrop' ) && !$( e.target ).is( '.sentient-forms-modal-backdrop' ) )
                    {
                        return; // Click was inside content, not on backdrop itself
                    }
                    modal.removeClass( 'sentient-forms-modal-open' );
                } );

            // Tab functionality
            $( '.sentient-forms-actions-tab-button' ).on( 'click', function()
            {
                const actionId = $( this ).data( 'action-id' );

                $( this ).addClass( 'active' ).siblings().removeClass( 'active' );
                $( '.sentient-forms-actions-tab-content-wrapper .sentient-forms-actions-tab-content[data-action-id="' + actionId + '"]' )
                    .addClass( 'active' ).siblings().removeClass( 'active' );
            } );

            // Save form settings
            $( '#sentient-forms-save-form-settings' ).on( 'click', function()
            {
                const $button = $( this );
                const $result = $( '#sentient-forms-form-settings-result' );

                // Ensure sentientFormsAdmin is defined before trying to access its properties
                if ( typeof sentientFormsAdmin === 'undefined' || !sentientFormsAdmin.i18n || !sentientFormsAdmin.ajaxUrl ||
                     !sentientFormsAdmin.nonce )
                {
                    $result.html( '<span class="error">' + 'Critical JavaScript error: Admin data not loaded.' + '</span>' );
                    console.error( 'sentientFormsAdmin or its properties are not defined for AJAX call.' );
                    return;
                }

                $button.prop( 'disabled', true );
                $result.html( '<span class="spinner is-active"></span> ' + sentientFormsAdmin.i18n.savingSettings );

                $.ajax( {
                    url      : sentientFormsAdmin.ajaxUrl, // CORRECTED: Use sentientFormsAdmin
                    type     : 'POST',
                    data     : {
                        action     : 'sentient_forms_save_form_settings',
                        nonce      : sentientFormsAdmin.nonce, // CORRECTED: Use sentientFormsAdmin
                        form_id    : $( '#sentient-forms-form-id' ).val(),
                        adapter_id : $( '#sentient-forms-adapter-id' ).val(),
                        settings   : getFormSettingsFromModal(), // Renamed function for clarity
                    },
                    success  : function( response )
                    {
                        if ( response.success )
                        {
                            $result.html( '<span class="success">' + response.data.message + '</span>' );
                            // Update the `formsForModalPopulation` array with the new settings
                            const formId    = $( '#sentient-forms-form-id' ).val();
                            const adapterId = $( '#sentient-forms-adapter-id' ).val();
                            const formIndex = formsForModalPopulation.findIndex( f => f.id == formId && f.adapter == adapterId );
                            if ( formIndex > -1 && response.data.settings )
                            {
                                formsForModalPopulation[ formIndex ].settings = response.data.settings;
                            }

                            setTimeout( function()
                            {
                                modal.removeClass( 'sentient-forms-modal-open' );
                                $result.html( '' ); // Clear message
                                // No full page reload, just update the table row visually if needed or rely on next open
                                // For simplicity, we'll just close the modal. A full table refresh is more complex.
                                // Or, you could update the specific row in the main table.
                                // For now, let's assume the user will see changes on next "Configure" click or page refresh.
                                // To visually update the main table immediately, you'd need to find the row and update its "Status" and "Actions" columns.
                                window.location.reload(); // Simplest way to ensure table is up-to-date
                            }, 1500 );
                        } else
                        {
                            $result.html( '<span class="error">' + ( sentientFormsAdmin.i18n.settingsFailed || 'Settings failed: ' ) +
                                          ( response.data.message || 'Unknown error' ) + '</span>' );
                            $button.prop( 'disabled', false );
                        }
                    },
                    error    : function( jqXHR, textStatus, errorThrown )
                    {
                        $result.html( '<span class="error">' + ( sentientFormsAdmin.i18n.settingsError || 'An error occurred.' ) + ' ' + errorThrown +
                                      '</span>' );
                        console.error( 'AJAX Error: ', textStatus, errorThrown, jqXHR.responseText );
                        $button.prop( 'disabled', false );
                    },
                    complete : function()
                    {
                        // Re-enable button if not successful or if not redirecting
                        if ( !$button.prop( 'disabled' ) )
                        { // If it was re-enabled due to error
                            // No action needed here
                        } else if ( $result.find( '.success' ).length === 0 )
                        { // If not success
                            $button.prop( 'disabled', false );
                        }
                    },
                } );
            } );

            // Helper function to get form settings as an object from the modal
            function getFormSettingsFromModal()
            {
                const settings = {
                    enabled : $( '#sentient-forms-form-enabled' ).is( ':checked' ),
                    actions : {},
                };

                $( '.sentient-forms-actions-tab-content' ).each( function()
                {
                    const actionId      = $( this ).data( 'action-id' );
                    // Check if the *action itself* is enabled, not the global form enablement
                    const actionEnabled = $( '#sentient-forms-action-' + actionId + '-enabled' ).is( ':checked' );

                    // Only include settings for actions that are explicitly enabled
                    if ( !actionEnabled )
                    {
                        // We still need to send its 'enabled: false' state if it was previously configured
                        // Or, the backend can handle missing actions as disabled.
                        // For now, let's send it as disabled if it has settings fields,
                        // or if it was part of the original form configuration.
                        // A simpler approach: only send data for actions that are checked as enabled.
                        // The backend will then only update/store these.
                        // If an action is unchecked, its settings might be removed or marked as disabled server-side.
                        // The current PHP `ajax_save_form_settings` seems to rebuild the actions array.
                        // So, if an action is not sent, it might be removed.
                        // To be safe, let's send all actions, but mark them enabled/disabled.

                        // If the action is not enabled, we still want to save its "enabled: false" state.
                        settings.actions[ actionId ] = {
                            enabled : false,
                            hooks   : [],
                        }; // Ensure disabled actions are recorded as such
                        // And collect its other field values as they are, they might be re-enabled later.
                    } else
                    {
                        settings.actions[ actionId ] = {
                            enabled : true,
                            hooks   : [],
                        };
                    }

                    // Get hooks (applies whether action is enabled or not, to preserve choices)
                    $( this ).find( 'input[name="settings[actions][' + actionId + '][hooks][]"]:checked' ).each( function()
                    {
                        if ( !settings.actions[ actionId ].hooks )
                        {
                            settings.actions[ actionId ].hooks = [];
                        } // ensure hooks array exists
                        settings.actions[ actionId ].hooks.push( $( this ).val() );
                    } );
                    if ( !settings.actions[ actionId ].hooks )
                    {
                        settings.actions[ actionId ].hooks = [];
                    }

                    // Get other settings fields
                    $( this ).find( 'input[type="text"], input[type="number"], select, textarea' ).each( function()
                    {
                        const name    = $( this ).attr( 'name' );
                        // Regex to capture action_id and setting_id from name="settings[actions][action_id][setting_id]"
                        const matches = name && name.match( /settings\[actions\]\[([^\]]+)\]\[([^\]]+)\]/ );

                        if ( matches && matches[ 1 ] === actionId )
                        {
                            const settingKey = matches[ 2 ];
                            // Exclude the action's own 'enabled' checkbox here as it's handled above
                            if ( settingKey !== 'enabled' )
                            {
                                settings.actions[ actionId ][ settingKey ] = $( this ).val();
                            }
                        }
                    } );

                    // Get other checkboxes (excluding the main action enable and hooks)
                    $( this ).find( 'input[type="checkbox"]' ).each( function()
                    {
                        const name    = $( this ).attr( 'name' );
                        const matches = name && name.match( /settings\[actions\]\[([^\]]+)\]\[([^\]]+)\]/ );

                        if ( matches && matches[ 1 ] === actionId )
                        {
                            const settingKey = matches[ 2 ];
                            if ( settingKey !== 'enabled' && settingKey !== 'hooks[]' )
                            { // Ensure it's not the main enable or hooks
                                settings.actions[ actionId ][ settingKey ] = $( this ).is( ':checked' );
                            }
                        }
                    } );
                } );
                return settings;
            }
        } );
    </script>
