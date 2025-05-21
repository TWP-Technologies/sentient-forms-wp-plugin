<?php
/**
 * Admin View: Settings.
 *
 * @package SentientForms
 * @since   0.1.0
 */

if ( !defined( 'ABSPATH' ) )
{
    exit; // Exit if accessed directly.
}

$llm_registry = Sentient_Forms_Plugin::instance()->get_llm_model_registry();
$all_models   = [];

if ( $llm_registry instanceof Sentient_Forms_Llm_Model_Registry )
{
    // Fetch models that are Active or in Preview.
    $all_models = $llm_registry->get_models(
        [
            Sentient_Forms_Llm_Status::ACTIVE,
            Sentient_Forms_Llm_Status::PREVIEW,
        ],
    );
}
else
{
    // Handle error: registry not available.
    // You might want to display an admin notice or log this.
    echo '<div class="notice notice-error"><p>' .
         esc_html__( 'Error: LLM Model Registry is not available. LLM selection will not work correctly.', 'sentient-forms' ) .
         '</p></div>';
}

$options        = get_option( 'sentient_forms_settings', [] );
$api_key        = $options[ 'api_key' ] ?? '';
$default_llm_id = $options[ 'default_llm' ] ?? '';

// If no default_llm_id is set, try to get a system default (e.g., the one marked as default for free tier).
if ( empty( $default_llm_id ) && $llm_registry instanceof Sentient_Forms_Llm_Model_Registry )
{
    $default_free_model = $llm_registry->get_model_by_id( SENTIENT_FORMS_DEFAULT_FREE_LLM_ID );
    if ( $default_free_model )
    {
        $default_llm_id = $default_free_model->get_id();
    }
    elseif ( !empty( $all_models ) )
    {
        // Fallback to the first available model if the constant-defined default isn't found.
        $first_model    = reset( $all_models );
        $default_llm_id = $first_model->get_id();
    }
}

$license_key    = $options[ 'license_key' ] ?? '';
$license_status = get_option( 'sentient_forms_license_status', 'inactive' );

?>
<div class="wrap sentient-forms-admin-wrap">
    <h1><?php esc_html_e( 'Sentient Forms Settings', 'sentient-forms' ); ?></h1>

    <form method="post" action="options.php">
        <?php settings_fields( 'sentient_forms_settings_group' ); ?>
        <?php do_settings_sections( 'sentient_forms_settings_group' ); ?>

        <table class="form-table">
            <tr valign="top">
                <th scope="row"><?php esc_html_e( 'Sentient Forms API Key', 'sentient-forms' ); ?></th>
                <td>
                    <input type="text" name="sentient_forms_settings[api_key]" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text"/>
                    <p class="description">
                        <?php
                        echo wp_kses_post(
                            sprintf(
                            // translators: %s: Link to Sentient Forms website.
                                __(
                                    'Enter your API key to connect to the Sentient Forms service. Get your API key from <a href="%s" target="_blank">your account</a>.',
                                    'sentient-forms',
                                ),
                                'https://sentientforms.com/account/', // gx todo - replace with actual link.
                            ),
                        );
                        ?>
                    </p>
                </td>
            </tr>

            <tr valign="top">
                <th scope="row"><?php esc_html_e( 'Default LLM Model', 'sentient-forms' ); ?></th>
                <td>
                    <select name="sentient_forms_settings[default_llm]" id="sentient_forms_default_llm">
                        <?php if ( empty( $all_models ) && $llm_registry ) : ?>
                            <option value=""><?php esc_html_e( 'No models available or registry error.', 'sentient-forms' ); ?></option>
                        <?php elseif ( !$llm_registry ) : ?>
                            <option value=""><?php esc_html_e( 'LLM Registry not loaded.', 'sentient-forms' ); ?></option>
                        <?php else : ?><?php
                            /** @var Sentient_Forms_Llm_Model_Interface $model */
                            foreach ( $all_models as $model ) :
                                $label = sprintf(
                                    '%s (%s) - %s',
                                    esc_html( $model->get_name() ),
                                    esc_html( $model->get_cost_tier()->get_name() ),
                                    esc_html( $model->get_description() ),
                                );

                                // Shorten description if too long for a select option.
                                if ( strlen( $label ) > 100 && strlen( $model->get_description() ) > 50 )
                                {
                                    $short_description = substr( $model->get_description(), 0, 47 ) . '...';
                                    $label             = sprintf(
                                        '%s (%s) - %s',
                                        esc_html( $model->get_name() ),
                                        esc_html( $model->get_cost_tier()->get_name() ),
                                        esc_html( $short_description ),
                                    );
                                }
                                ?>
                                <option value="<?php echo esc_attr( $model->get_id() ); ?>" <?php selected( $default_llm_id, $model->get_id() ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                </option>
                            <?php endforeach; ?><?php endif; ?>
                    </select>
                    <p class="description">
                        <?php esc_html_e(
                            'Select the default Large Language Model to be used for actions if not specified otherwise.',
                            'sentient-forms',
                        ); ?>
                    </p>
                </td>
            </tr>

            <?php
            if ( class_exists( 'Sentient_Forms_Pro_Updater' ) ) :
                ?>
                <tr valign="top">
                    <th scope="row"><?php esc_html_e( 'License Key', 'sentient-forms' ); ?></th>
                    <td>
                        <input type="text"
                               name="sentient_forms_settings[license_key]"
                               value="<?php echo esc_attr( $license_key ); ?>"
                               class="regular-text"/>
                        <p class="description">
                            <?php
                            echo wp_kses_post(
                                sprintf(
                                // translators: %s: Link to Sentient Forms website.
                                    __(
                                        'Enter your license key to receive updates for the Pro version. Get your license key from <a href="%s" target="_blank">your account</a>.',
                                        'sentient-forms',
                                    ),
                                    'https://sentientforms.com/account/', // gx todo - replace with actual link.
                                ),
                            );
                            ?>
                            <br>
                            <?php if ( $license_status === 'valid' ) : ?>
                                <span style="color: green;"><?php esc_html_e( 'License active.', 'sentient-forms' ); ?></span>
                            <?php elseif ( $license_status === 'invalid' ) : ?>
                                <span style="color: red;"><?php esc_html_e( 'License invalid.', 'sentient-forms' ); ?></span>
                            <?php else : ?>
                                <span style="color: orange;"><?php esc_html_e( 'License inactive.', 'sentient-forms' ); ?></span>
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
            <?php endif; ?>

        </table>

        <?php submit_button(); ?>
    </form>
</div>
