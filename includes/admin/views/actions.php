<?php
/**
 * Admin actions view
 *
 * @package Sentient_Forms
 */

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) )
{
    exit;
}

// Set default values for variables
$actions = $actions ?? [];
?>
<div class="wrap sentient-forms-admin">
    <h1><?php _e( 'Sentient Forms - Available Actions', 'sentient-forms' ); ?></h1>

    <div class="sentient-forms-actions-header">
        <p><?php _e(
                'These are the AI-powered actions available in Sentient Forms. You can enable and configure these actions for each form in the Forms section.',
                'sentient-forms',
            ); ?></p>
    </div>

    <?php if ( empty( $actions ) ): ?>
        <div class="notice notice-warning">
            <p><?php _e( 'No actions found. This could be due to a plugin configuration issue.', 'sentient-forms' ); ?></p>
        </div>
    <?php else: ?>
        <div class="sentient-forms-actions-list">
            <?php foreach ( $actions as $action ): ?>
                <div class="sentient-forms-action-card">
                    <div class="sentient-forms-action-card-header">
                        <div class="sentient-forms-action-icon">
                            <span class="dashicons <?php echo esc_attr( $action->get_icon() ); ?>"></span>
                        </div>
                        <div class="sentient-forms-action-title">
                            <h2><?php echo esc_html( $action->get_name() ); ?></h2>
                        </div>
                    </div>

                    <div class="sentient-forms-action-card-body">
                        <div class="sentient-forms-action-description">
                            <p><?php echo esc_html( $action->get_description() ); ?></p>
                        </div>

                        <div class="sentient-forms-action-details">
                            <h3><?php _e( 'When It Runs', 'sentient-forms' ); ?></h3>
                            <ul class="sentient-forms-action-hooks">
                                <?php foreach ( $action->get_hooks() as $hook_id => $hook_label ): ?>
                                    <li>
                                        <strong><?php echo esc_html( $hook_label ); ?></strong>
                                        <span class="sentient-forms-hook-id">(<?php echo esc_html( $hook_id ); ?>)</span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>

                            <h3><?php _e( 'Compatible With', 'sentient-forms' ); ?></h3>
                            <ul class="sentient-forms-action-compatibility">
                                <?php foreach ( $action->get_compatibility() as $adapter_id => $compatible ): ?><?php if ( $compatible ): ?>
                                    <li>
                                        <?php
                                        echo match ( $adapter_id )
                                        {
                                            'gravity_forms' => '<span class="dashicons dashicons-yes"></span> Gravity Forms',
                                            default         => '<span class="dashicons dashicons-yes"></span> ' .
                                                               esc_html( ucfirst( str_replace( '_', ' ', $adapter_id ) ) ),
                                        };
                                        ?>
                                    </li>
                                <?php endif; ?><?php endforeach; ?>
                            </ul>

                            <h3><?php _e( 'Settings', 'sentient-forms' ); ?></h3>
                            <div class="sentient-forms-action-settings">
                                <table class="widefat">
                                    <thead>
                                        <tr>
                                            <th><?php _e( 'Setting', 'sentient-forms' ); ?></th>
                                            <th><?php _e( 'Description', 'sentient-forms' ); ?></th>
                                            <th><?php _e( 'Default', 'sentient-forms' ); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $settings_fields = $action->get_settings_fields();
                                        foreach ( $settings_fields as $field_id => $field ):
                                            ?>
                                            <tr>
                                                <td><strong><?php echo esc_html( $field[ 'label' ] ); ?></strong></td>
                                                <td><?php echo isset( $field[ 'description' ] ) ? esc_html( $field[ 'description' ] ) : ''; ?></td>
                                                <td>
                                                    <?php
                                                    if ( isset( $field[ 'default' ] ) )
                                                    {
                                                        if ( $field[ 'type' ] === 'checkbox' )
                                                        {
                                                            echo $field[ 'default' ]
                                                                ? __( 'Enabled', 'sentient-forms' )
                                                                : __(
                                                                    'Disabled',
                                                                    'sentient-forms',
                                                                );
                                                        }
                                                        elseif ( $field[ 'type' ] === 'select' &&
                                                                 isset( $field[ 'options' ][ $field[ 'default' ] ] ) )
                                                        {
                                                            echo esc_html( $field[ 'options' ][ $field[ 'default' ] ] );
                                                        }
                                                        elseif ( $field[ 'type' ] === 'textarea' )
                                                        {
                                                            echo '<em>' . __( 'Custom template', 'sentient-forms' ) . '</em>';
                                                        }
                                                        else
                                                        {
                                                            echo esc_html( $field[ 'default' ] );
                                                        }
                                                    }
                                                    else
                                                    {
                                                        echo '<em>' . __( 'None', 'sentient-forms' ) . '</em>';
                                                    }
                                                    ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="sentient-forms-action-card-footer">
                        <a href="<?php echo admin_url( 'admin.php?page=sentient_forms_forms' ); ?>" class="button button-primary">
                            <?php _e( 'Configure for Forms', 'sentient-forms' ); ?>
                        </a>

                        <?php if ( $action->get_id() === 'spam_analysis' ): ?>
                            <a href="https://sentientforms.com/docs/actions/spam-analysis/?utm_source=plugin&utm_medium=actions_page"
                               class="button button-secondary"
                               target="_blank">
                                <?php _e( 'Learn More', 'sentient-forms' ); ?>
                            </a>
                        <?php elseif ( $action->get_id() === 'entry_evaluation' ): ?>
                            <a href="https://sentientforms.com/docs/actions/entry-evaluation/?utm_source=plugin&utm_medium=actions_page"
                               class="button button-secondary"
                               target="_blank">
                                <?php _e( 'Learn More', 'sentient-forms' ); ?>
                            </a>
                        <?php else: ?>
                            <a href="https://sentientforms.com/docs/actions/?utm_source=plugin&utm_medium=actions_page"
                               class="button button-secondary"
                               target="_blank">
                                <?php _e( 'Learn More', 'sentient-forms' ); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="sentient-forms-actions-footer">
            <div class="sentient-forms-actions-cta">
                <h3><?php _e( 'Need More Actions?', 'sentient-forms' ); ?></h3>
                <p><?php _e(
                        'We\'re constantly developing new AI-powered actions for Sentient Forms. Have an idea for a new action? Let us know!',
                        'sentient-forms',
                    ); ?></p>
                <a href="https://sentientforms.com/contact/?utm_source=plugin&utm_medium=actions_page" class="button button-primary" target="_blank">
                    <?php _e( 'Request an Action', 'sentient-forms' ); ?>
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>