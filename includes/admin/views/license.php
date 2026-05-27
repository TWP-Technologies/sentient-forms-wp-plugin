<?php
/**
 * Admin license view
 *
 * @package Sentient_Forms
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Set default values for variables
$license_key = $license_key ?? '';
$license_status = $license_status ?? '';
$license_data = $license_data ?? [];
?>
<div class="wrap sentient-forms-admin">
    <h1><?php _e('Sentient Forms License', 'sentient-forms'); ?></h1>
    
    <div class="sentient-forms-license-section">
        <h2><?php _e('License Management', 'sentient-forms'); ?></h2>
        <p><?php _e('Manage your Sentient Forms license to unlock all features and receive updates.', 'sentient-forms'); ?></p>
        
        <form method="post" action="options.php" id="sentient-forms-license-form">
            <?php settings_fields('sentient_forms_settings'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="sentient_forms_license_key"><?php _e('License Key', 'sentient-forms'); ?></label>
                    </th>
                    <td>
                        <input type="text" 
                               id="sentient_forms_license_key" 
                               name="sentient_forms_options[license_key]" 
                               value="<?php echo esc_attr($license_key); ?>" 
                               class="regular-text"
                               placeholder="<?php _e('Enter your license key', 'sentient-forms'); ?>"
                               <?php echo ($license_status === 'valid') ? 'readonly' : ''; ?>
                        />
                        <p class="description">
                            <?php _e('Enter your Sentient Forms license key to activate the plugin.', 'sentient-forms'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <?php _e('License Status', 'sentient-forms'); ?>
                    </th>
                    <td>
                        <?php if ($license_status === 'valid'): ?>
                            <div class="sentient-forms-license-status sentient-forms-license-valid">
                                <span class="dashicons dashicons-yes-alt"></span>
                                <?php _e('Active', 'sentient-forms'); ?>
                            </div>
                        <?php elseif ($license_status === 'expired'): ?>
                            <div class="sentient-forms-license-status sentient-forms-license-expired">
                                <span class="dashicons dashicons-warning"></span>
                                <?php _e('Expired', 'sentient-forms'); ?>
                            </div>
                        <?php elseif ($license_status === 'disabled' || $license_status === 'revoked'): ?>
                            <div class="sentient-forms-license-status sentient-forms-license-disabled">
                                <span class="dashicons dashicons-dismiss"></span>
                                <?php _e('Disabled/Revoked', 'sentient-forms'); ?>
                            </div>
                        <?php elseif ($license_status === 'invalid'): ?>
                            <div class="sentient-forms-license-status sentient-forms-license-invalid">
                                <span class="dashicons dashicons-warning"></span>
                                <?php _e('Invalid', 'sentient-forms'); ?>
                            </div>
                        <?php else: ?>
                            <div class="sentient-forms-license-status sentient-forms-license-inactive">
                                <span class="dashicons dashicons-marker"></span>
                                <?php _e('Inactive', 'sentient-forms'); ?>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($license_status === 'valid'): ?>
                    <tr>
                        <th scope="row">
                            <?php _e('License Details', 'sentient-forms'); ?>
                        </th>
                        <td>
                            <table class="sentient-forms-license-details">
                                <?php if (isset($license_data['plan'])): ?>
                                    <tr>
                                        <th><?php _e('Plan', 'sentient-forms'); ?></th>
                                        <td><?php echo esc_html($license_data['plan']); ?></td>
                                    </tr>
                                <?php endif; ?>
                                <?php if (isset($license_data['expires']) && $license_data['expires']): ?>
                                    <tr>
                                        <th><?php _e('Expires', 'sentient-forms'); ?></th>
                                        <td><?php echo date_i18n(get_option('date_format'), strtotime($license_data['expires'])); ?></td>
                                    </tr>
                                <?php endif; ?>
                                <?php if (isset($license_data['activations_left'])): ?>
                                    <tr>
                                        <th><?php _e('Activations Left', 'sentient-forms'); ?></th>
                                        <td><?php echo esc_html($license_data['activations_left']); ?></td>
                                    </tr>
                                <?php endif; ?>
                                <?php if (isset($license_data['site_count']) && isset($license_data['license_limit'])): ?>
                                    <tr>
                                        <th><?php _e('Sites', 'sentient-forms'); ?></th>
                                        <td><?php printf(__('%1$s / %2$s', 'sentient-forms'), $license_data['site_count'], $license_data['license_limit']); ?></td>
                                    </tr>
                                <?php endif; ?>
                            </table>
                        </td>
                    </tr>
                <?php endif; ?>
            </table>
            
            <p class="submit">
                <?php if ($license_status !== 'valid'): ?>
                    <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php _e('Activate License', 'sentient-forms'); ?>">
                <?php else: ?>
                    <button type="button" class="button button-secondary" id="sentient-forms-deactivate-license">
                        <?php _e('Deactivate Site License', 'sentient-forms'); ?>
                    </button>
                    <span class="description" style="display:block;margin-top:6px;">
                        <?php _e('Deactivation disconnects this WordPress site. It does not cancel Stripe billing.', 'sentient-forms'); ?>
                    </span>
                <?php endif; ?>
                <button type="button" class="button button-secondary" id="sentient-forms-check-license">
                    <?php _e('Check License Status', 'sentient-forms'); ?>
                </button>
            </p>
        </form>
    </div>
    
    <div class="sentient-forms-license-section">
        <h2><?php _e('Need Managed Service?', 'sentient-forms'); ?></h2>
        <p><?php _e('Choose monthly managed form intelligence for this WordPress site.', 'sentient-forms'); ?></p>
        
        <div class="sentient-forms-license-plans">
            <div class="sentient-forms-license-plan">
                <h3><?php _e('Starter', 'sentient-forms'); ?></h3>
                <div class="sentient-forms-license-plan-price">
                    <span class="sentient-forms-license-plan-amount">$15</span>
                    <span class="sentient-forms-license-plan-period">/month</span>
                </div>
                <ul class="sentient-forms-license-plan-features">
                    <li><?php _e('1 site', 'sentient-forms'); ?></li>
                    <li><?php _e('1,000 managed action credits/month', 'sentient-forms'); ?></li>
                    <li><?php _e('Managed model access and spend controls', 'sentient-forms'); ?></li>
                </ul>
                <a href="https://sentientforms.com/pricing/?utm_source=plugin&utm_medium=license_page&utm_campaign=starter" class="button button-primary" target="_blank">
                    <?php _e('Choose Starter', 'sentient-forms'); ?>
                </a>
            </div>
            
            <div class="sentient-forms-license-plan sentient-forms-license-plan-featured">
                <div class="sentient-forms-license-plan-badge"><?php _e('Popular', 'sentient-forms'); ?></div>
                <h3><?php _e('Pro', 'sentient-forms'); ?></h3>
                <div class="sentient-forms-license-plan-price">
                    <span class="sentient-forms-license-plan-amount">$39</span>
                    <span class="sentient-forms-license-plan-period">/month</span>
                </div>
                <ul class="sentient-forms-license-plan-features">
                    <li><?php _e('1 site', 'sentient-forms'); ?></li>
                    <li><?php _e('3,000 managed action credits/month', 'sentient-forms'); ?></li>
                    <li><?php _e('Managed model access and spend controls', 'sentient-forms'); ?></li>
                </ul>
                <a href="https://sentientforms.com/pricing/?utm_source=plugin&utm_medium=license_page&utm_campaign=pro" class="button button-primary" target="_blank">
                    <?php _e('Choose Pro', 'sentient-forms'); ?>
                </a>
            </div>
            
            <div class="sentient-forms-license-plan">
                <h3><?php _e('Business', 'sentient-forms'); ?></h3>
                <div class="sentient-forms-license-plan-price">
                    <span class="sentient-forms-license-plan-amount">$99</span>
                    <span class="sentient-forms-license-plan-period">/month</span>
                </div>
                <ul class="sentient-forms-license-plan-features">
                    <li><?php _e('1 site', 'sentient-forms'); ?></li>
                    <li><?php _e('10,000 managed action credits/month', 'sentient-forms'); ?></li>
                    <li><?php _e('Business capacity packs available', 'sentient-forms'); ?></li>
                </ul>
                <a href="https://sentientforms.com/pricing/?utm_source=plugin&utm_medium=license_page&utm_campaign=business" class="button button-primary" target="_blank">
                    <?php _e('Choose Business', 'sentient-forms'); ?>
                </a>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
    jQuery(document).ready(function($) {
        // Deactivate license
        $('#sentient-forms-deactivate-license').on('click', function() {
            if (confirm('<?php _e('Deactivate this site license? This disconnects this WordPress site and does not cancel Stripe billing.', 'sentient-forms'); ?>')) {
                // Clear the license key field and submit the form
                $('#sentient_forms_license_key').val('');
                $('#sentient-forms-license-form').submit();
            }
        });
        
        // Check license status
        $('#sentient-forms-check-license').on('click', function() {
            var $button = $(this);
            var originalText = $button.text();
            
            $button.text('<?php _e('Checking...', 'sentient-forms'); ?>');
            $button.prop('disabled', true);
            
            // Reload the page to check the license status
            window.location.reload();
        });
    });
</script>
