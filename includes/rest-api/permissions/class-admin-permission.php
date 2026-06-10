<?php
/**
 * Admin Permission Class for the Sentient Forms plugin.
 * Contains specific permission checking logic for actions typically restricted
 * to plugin administrators or users with specific administrative capabilities.
 * These methods are designed to be used as 'permission_callback' in `register_rest_route`.
 *
 * @package    SentientForms
 * @subpackage REST_API\Permissions
 * @since      0.1.0
 */

// Ensure this file is loaded within WordPress.
if ( !defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Class Sentient_Forms_Admin_Permission
 * Handles permission checks for administrative tasks via the REST API.
 */
class Sentient_Forms_Admin_Permission
{

    use Sentient_Forms_Permission_Utils_Trait;

    /**
     * Capability required to manage general plugin settings and core functionalities.
     *
     * @since 0.1.0
     */
    const MANAGE_PLUGIN_CAP = 'manage_options';

    /**
     * Capability required to view plugin data or dashboards (less privileged than managing).
     *
     * @since 0.1.0
     */
    const VIEW_PLUGIN_DATA_CAP = 'edit_posts';

    /**
     * Checks if the current user can manage the plugin's settings or perform similar admin-level actions.
     *
     * @param WP_REST_Request $request The current REST API request object.
     *
     * @return true|WP_Error True if the user has permission, WP_Error otherwise.
     * @since 0.1.0
     */
    public function can_manage_settings( WP_REST_Request $request ): true | WP_Error
    {
        return $this->check_current_user_capability(
            self::MANAGE_PLUGIN_CAP,
            __( 'Sorry, you are not allowed to manage Sentient Forms settings.', 'sentient-forms' ),
        );
    }

    /**
     * Checks if the current user can view plugin data (e.g., dashboards, read-only info).
     *
     * @param WP_REST_Request $request The current REST API request object.
     *
     * @return true|WP_Error True if the user has permission, WP_Error otherwise.
     * @since 0.1.0
     */
    public function can_view_plugin_data( WP_REST_Request $request ): true | WP_Error
    {
        return $this->check_current_user_capability(
            self::VIEW_PLUGIN_DATA_CAP,
            __( 'Sorry, you are not allowed to view this Sentient Forms data.', 'sentient-forms' ),
        );
    }

    /**
     * Checks if the current user can perform a specific, highly privileged critical action.
     *
     * @param WP_REST_Request $request The current REST API request object.
     *
     * @return true|WP_Error True if the user has permission, WP_Error otherwise.
     * @since 0.1.0
     */
    public function can_perform_critical_action( WP_REST_Request $request ): true | WP_Error
    {
        $capability_check = $this->check_current_user_capability(
            self::MANAGE_PLUGIN_CAP,
            __( 'Sorry, you are not allowed to perform this critical Sentient Forms action.', 'sentient-forms' ),
        );
        if ( is_wp_error( $capability_check ) )
        {
            return $capability_check;
        }
        return true;
    }
}
