<?php
/**
 * REST API Credit Controller class for the Sentient Forms plugin.
 * Handles routes related to retrieving credit information from the proxy.
 *
 * @package    SentientForms
 * @subpackage REST_API\Controllers
 * @since      0.1.0
 */

if ( !defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Class Sentient_Forms_Credit_Controller
 * Manages REST API endpoints for credit balance.
 */
class Sentient_Forms_Credit_Controller extends Abstract_Sentient_Forms_Base_Controller
{
    use Trait_Sentient_Forms_Permission_Utils;
    /**
     * The base of this controller's routes.
     *
     * @var string
     * @since 0.1.0
     */
    protected string $rest_base = 'credits';

    /**
     * Transient key used to cache the credit balance.
     */
    private const BALANCE_TRANSIENT_KEY = 'sf_credit_balance_cache';

    /**
     * Option key used for storing the last known credit balance.
     */
    private const BALANCE_OPTION_KEY = 'sentient_forms_credit_balance';

    /**
     * How long to cache the credit balance in seconds.
     */
    private const BALANCE_CACHE_TTL = 60 * 10; // 10 minutes

    /**
     * Registers the routes for the credit controller.
     *
     * @since 0.1.0
     */
    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/balance',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_credit_balance' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => [
                        'force_refresh' => [
                            'description'       => __( 'Force a refresh of the cached balance.', 'sentient-forms' ),
                            'type'              => 'boolean',
                            'default'           => false,
                            'sanitize_callback' => 'rest_sanitize_boolean',
                        ],
                    ],
                ],
                'schema' => [ $this, 'get_item_schema' ],
            ],
        );
    }

    /**
     * Fetches the current credit balance from the proxy API.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function get_credit_balance( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $plugin        = Sentient_Forms_Plugin::instance();
        $api_key       = $plugin->get_proxy_api_key();
        $force_refresh = $request->get_param( 'force_refresh' );

        if ( empty( $api_key ) )
        {
            // In development mode, return mock credits data instead of error
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG )
            {
                return $this->prepare_item_for_response( [
                    'current_balance' => 1000, // Mock development credits
                    'ledger_delta'    => 0,
                    'tier'            => [
                        'code'                 => 'dev',
                        'display_name'         => 'Development',
                        'monthly_credit_quota' => 5000,
                    ],
                    'stale'           => false,
                    'dev_mode'        => true,
                ] );
            }

            return $this->prepare_error_response(
                'missing_api_key',
                __( 'Proxy API key is not configured.', 'sentient-forms' ),
                400,
            );
        }

		// If not forcing a refresh, try to get the cached balance.
		if ( !$force_refresh )
		{
			$cached_balance = $this->format_cached_balance( get_transient( self::BALANCE_TRANSIENT_KEY ) );
			if ( null !== $cached_balance )
			{
				return $this->prepare_item_for_response( $cached_balance );
			}
		}

		$balance = $this->fetch_and_cache_balance( $api_key );

		if ( is_wp_error( $balance ) )
		{
			// If fetching failed, try to return the last known good balance (stale data).
			$stale_balance = $this->format_cached_balance( get_option( self::BALANCE_OPTION_KEY, null ) );
			if ( null !== $stale_balance )
			{
				$stale_balance['stale'] = true;
				return $this->prepare_item_for_response( $stale_balance );
			}

			// If no stale balance is available, return mock data in dev mode or error in production.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG )
			{
				return $this->prepare_item_for_response( [
					'current_balance' => 1000, // Mock development credits
					'ledger_delta'    => 0,
					'tier'            => [
						'code'                 => 'dev',
						'display_name'         => 'Development',
						'monthly_credit_quota' => 5000,
					],
					'stale'           => false,
					'dev_mode'        => true,
				] );
			}

			return $this->prepare_error_response(
				'credit_balance_error',
				$balance->get_error_message(),
				500,
			);
		}

		return $this->prepare_item_for_response( $balance );
	}

    /**
     * Fetch the credit balance from the proxy and update caches.
     *
     * @param string $api_key The API key to use for the client.
     *
     * @return WP_Error|int The balance as an integer on success, or WP_Error on failure.
     */
	private function fetch_and_cache_balance( string $api_key ): WP_Error | array
	{
		if ( !class_exists( 'Sentient_Forms_Llm_Api_Client' ) )
		{
			return new WP_Error( 'api_client_missing', __( 'LLM API Client class not found.', 'sentient-forms' ), [ 'status' => 500 ] );
		}
		$client   = new Sentient_Forms_Llm_Api_Client( $api_key );
		$response = $client->get_credit_balance();

		if ( is_wp_error( $response ) )
		{
			return $response;
		}

		if ( !is_array( $response ) )
		{
			return new WP_Error(
				'invalid_response_format', __( 'Unexpected response format from API. Expected an array.', 'sentient-forms' ), [ 'status' => 500 ]
			);
		}

		$normalized = $this->normalize_credit_payload( $response );
		if ( is_wp_error( $normalized ) )
		{
			return $normalized;
		}

		set_transient( self::BALANCE_TRANSIENT_KEY, $normalized, self::BALANCE_CACHE_TTL );
		update_option( self::BALANCE_OPTION_KEY, $normalized, false );

		return $normalized;
	}

	private function normalize_credit_payload( array $response ): WP_Error | array
	{
		$data = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : $response;
		$current_balance = null;

		if ( isset( $data['current_balance'] ) )
		{
			$current_balance = (int) $data['current_balance'];
		}
		elseif ( isset( $data['balance'] ) )
		{
			$current_balance = (int) $data['balance'];
		}

		if ( null === $current_balance )
		{
			return new WP_Error(
				'invalid_response_content',
				__( 'Invalid credit balance response.', 'sentient-forms' ),
				[ 'status' => 500 ]
			);
		}

		return [
			'current_balance' => $current_balance,
			'ledger_delta'    => isset( $data['ledger_delta'] ) ? (int) $data['ledger_delta'] : 0,
			'tier'            => isset( $data['tier'] ) && is_array( $data['tier'] ) ? $data['tier'] : null,
			'stale'           => false,
		];
	}

	private function format_cached_balance( $value ): ?array
	{
		if ( is_array( $value ) && isset( $value['current_balance'] ) )
		{
			return [
				'current_balance' => (int) $value['current_balance'],
				'ledger_delta'    => isset( $value['ledger_delta'] ) ? (int) $value['ledger_delta'] : 0,
				'tier'            => isset( $value['tier'] ) && is_array( $value['tier'] ) ? $value['tier'] : null,
				'stale'           => isset( $value['stale'] ) ? rest_sanitize_boolean( $value['stale'] ) : false,
			];
		}

		if ( is_numeric( $value ) )
		{
			return [
				'current_balance' => (int) $value,
				'ledger_delta'    => 0,
				'tier'            => null,
				'stale'           => false,
			];
		}

		return null;
	}

    /**
     * Retrieves the schema for the credit balance response.
     *
     * @return array|null Item schema data.
     */
    public function get_item_schema(): ?array
    {
        if ( $this->schema )
        {
            return $this->schema;
        }

        $this->schema = [
            '$schema'    => 'https://json-schema.org/draft/2020-12/schema',
            'title'      => $this->rest_base . '/balance',
            'type'       => 'object',
            'properties' => [
                'balance' => [
                    'description' => __( 'Remaining credit balance.', 'sentient-forms' ),
                    'type'        => 'integer',
                    'context'     => [ 'view' ],
                    'readonly'    => true,
                ],
                'stale'   => [
                    'description' => __( 'Indicates if the balance may be stale due to an error fetching the latest data.', 'sentient-forms' ),
                    'type'        => 'boolean',
                    'context'     => [ 'view' ],
                    'readonly'    => true,
                ],
            ],
            'required'   => [ 'balance' ],
        ];

        return $this->schema;
    }
}
