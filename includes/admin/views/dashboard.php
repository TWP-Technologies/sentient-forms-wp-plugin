<?php
/**
 * Admin dashboard view
 *
 * @package Sentient_Forms
 */

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) )
{
    exit;
}

// Set default values for variables
$license_status = $license_status ?? '';
$license_data   = $license_data ?? [];
$form_count     = $form_count ?? 0;

$plugin = Sentient_Forms_Plugin::instance();
?>
<div class="wrap sentient-forms-admin">
    <h1><?php _e( 'Sentient Forms Dashboard', 'sentient-forms' ); ?></h1>

    <div class="sentient-forms-dashboard">
        <div class="sentient-forms-dashboard-header">
            <div class="sentient-forms-dashboard-welcome">
                <h2><?php _e( 'Welcome to Sentient Forms', 'sentient-forms' ); ?></h2>
                <p><?php _e(
                        'Sentient Forms bridges the gap between WordPress form builders and Large Language Models (LLMs), allowing you to automate intelligent actions based on form submission data.',
                        'sentient-forms',
                    ); ?></p>
            </div>
        </div>

        <div class="sentient-forms-dashboard-widgets">
            <div class="sentient-forms-dashboard-widget">
                <h3><?php _e( 'Billing Mode', 'sentient-forms' ); ?></h3>
                <div class="sentient-forms-dashboard-widget-content">
                    <p><?php _e( 'Direct OpenRouter actions are billed by OpenRouter. Sentient-managed actions use your managed plan allowance in the Licensing screen.', 'sentient-forms' ); ?></p>
                    <?php if ( $license_status === 'valid' && isset( $license_data[ 'plan' ] ) ): ?>
                        <p class="sentient-forms-plan-info">
                            <?php printf( __( 'Current Plan: %s', 'sentient-forms' ), esc_html( $license_data[ 'plan' ] ) ); ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="sentient-forms-dashboard-widget">
                <h3><?php _e( 'License Status', 'sentient-forms' ); ?></h3>
                <div class="sentient-forms-dashboard-widget-content">
                    <?php if ( $license_status === 'valid' ): ?>
                        <div class="sentient-forms-license-status sentient-forms-license-valid">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <?php _e( 'License Active', 'sentient-forms' ); ?>
                        </div>
                        <?php if ( isset( $license_data[ 'expires' ] ) && $license_data[ 'expires' ] ): ?>
                            <p>
                                <?php printf(
                                    __( 'Expires: %s', 'sentient-forms' ),
                                    date_i18n( get_option( 'date_format' ), strtotime( $license_data[ 'expires' ] ) ),
                                ); ?>
                            </p>
                        <?php endif; ?><?php else: ?>
                        <div class="sentient-forms-license-status sentient-forms-license-invalid">
                            <span class="dashicons dashicons-warning"></span>
                            <?php _e( 'License Inactive', 'sentient-forms' ); ?>
                        </div><p><?php _e( 'Please activate your license to enable all features.', 'sentient-forms' ); ?></p>
                        <a href="<?php echo admin_url( 'admin.php?page=sentient_forms_license' ); ?>" class="button button-primary">
                            <?php _e( 'Activate License', 'sentient-forms' ); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="sentient-forms-dashboard-widget">
                <h3><?php _e( 'Quick Stats', 'sentient-forms' ); ?></h3>
                <div class="sentient-forms-dashboard-widget-content">
                    <div class="sentient-forms-stats">
                        <div class="sentient-forms-stat">
                            <span class="sentient-forms-stat-value"><?php esc_html_e( (int)$form_count ); ?></span>
                            <span class="sentient-forms-stat-label"><?php _e( 'Forms', 'sentient-forms' ); ?></span>
                        </div>
                        <div class="sentient-forms-stat">
                            <span class="sentient-forms-stat-value">
                                <?php echo esc_html( (string) count( Sentient_Forms_Bundled_Action_Templates::codes() ) ); ?>
                            </span><span class="sentient-forms-stat-label"><?php _e( 'Actions', 'sentient-forms' ); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="sentient-forms-dashboard-sections">
            <div class="sentient-forms-dashboard-section">
                <h3><?php _e( 'Getting Started', 'sentient-forms' ); ?></h3>
                <div class="sentient-forms-dashboard-section-content">
                    <ol class="sentient-forms-getting-started">
                        <li>
                            <h4><?php _e( 'Activate Your License', 'sentient-forms' ); ?></h4>
                            <p><?php _e( 'Enter your license key to unlock all features and receive updates.', 'sentient-forms' ); ?></p>
                            <a href="<?php echo admin_url( 'admin.php?page=sentient_forms_license' ); ?>" class="button button-secondary">
                                <?php _e( 'License Settings', 'sentient-forms' ); ?>
                            </a>
                        </li>
                        <li>
                            <h4><?php _e( 'Configure Global Settings', 'sentient-forms' ); ?></h4>
                            <p><?php _e( 'Set up your API key and global preferences for all forms.', 'sentient-forms' ); ?></p>
                            <a href="<?php echo admin_url( 'admin.php?page=sentient_forms_settings' ); ?>" class="button button-secondary">
                                <?php _e( 'Global Settings', 'sentient-forms' ); ?>
                            </a>
                        </li>
                        <li>
                            <h4><?php _e( 'Configure Form-Specific Settings', 'sentient-forms' ); ?></h4>
                            <p><?php _e( 'Enable and configure AI actions for specific forms.', 'sentient-forms' ); ?></p>
                            <a href="<?php echo admin_url( 'admin.php?page=sentient_forms_forms' ); ?>" class="button button-secondary">
                                <?php _e( 'Form Settings', 'sentient-forms' ); ?>
                            </a>
                        </li>
                    </ol>
                </div>
            </div>

            <div class="sentient-forms-dashboard-section">
                <h3><?php _e( 'Available Actions', 'sentient-forms' ); ?></h3>
                <div class="sentient-forms-dashboard-section-content">
                    <div class="sentient-forms-actions-list">
                        <?php foreach ( Sentient_Forms_Bundled_Action_Templates::definitions() as $action ): ?>
                            <div class="sentient-forms-action-item">
                                <div class="sentient-forms-action-icon">
                                    <span class="dashicons dashicons-admin-generic"></span>
                                </div>
                                <div class="sentient-forms-action-details">
                                    <h4><?php echo esc_html( (string) ( $action['display_name'] ?? $action['code'] ?? '' ) ); ?></h4>
                                    <p><?php echo esc_html( (string) ( $action['description'] ?? '' ) ); ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <a href="<?php echo admin_url( 'admin.php?page=sentient_forms_actions' ); ?>" class="button button-secondary">
                        <?php _e( 'View All Actions', 'sentient-forms' ); ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
