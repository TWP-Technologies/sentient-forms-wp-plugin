<?php
/**
 * Service wrapper for CPS custom-action CRUD endpoints.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Custom_Actions_Service
{
    private Sentient_Forms_Plugin $plugin;

    private Sentient_Forms_Api_Client $client;

    public function __construct( Sentient_Forms_Plugin $plugin, ?Sentient_Forms_Api_Client $client = null )
    {
        $this->plugin = $plugin;
        $this->client = $client ?? $plugin->get_cps_api_client();
    }

    /**
     * @param array<string, mixed> $query
     */
    public function list( array $query = [] ): WP_Error | Sentient_Forms_Custom_Action_List_Response
    {
        $options = $this->build_bearer_options();
        if ( is_wp_error( $options ) )
        {
            return $options;
        }

        $path = '/custom-actions';
        if ( ! empty( $query ) )
        {
            $path .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
        }

        $response = $this->client->get( $path, $options );
        if ( is_wp_error( $response ) )
        {
            return $response;
        }

        return Sentient_Forms_Custom_Action_List_Response::from_api_payload( $response );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create( array $payload, string $actor_hint ): WP_Error | Sentient_Forms_Custom_Action_Mutation_Response
    {
        return $this->send_mutation( '/custom-actions', 'post', $payload, $actor_hint );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update( string $id, array $payload, string $actor_hint ): WP_Error | Sentient_Forms_Custom_Action_Mutation_Response
    {
        $path = sprintf( '/custom-actions/%s', rawurlencode( $id ) );
        return $this->send_mutation( $path, 'put', $payload, $actor_hint );
    }

    public function archive( string $id, string $actor_hint ): WP_Error | Sentient_Forms_Custom_Action_Mutation_Response
    {
        $options = $this->build_bearer_options();
        if ( is_wp_error( $options ) )
        {
            return $options;
        }

        $path     = sprintf( '/custom-actions/%s', rawurlencode( $id ) );
        $response = $this->client->delete(
            $path,
            [ 'actor_hint' => $actor_hint ],
            $options
        );

        if ( is_wp_error( $response ) )
        {
            return $response;
        }

        return Sentient_Forms_Custom_Action_Mutation_Response::from_api_payload( $response );
    }

    public function reactivate( string $id, string $actor_hint ): WP_Error | Sentient_Forms_Custom_Action_Mutation_Response
    {
        $options = $this->build_bearer_options();
        if ( is_wp_error( $options ) )
        {
            return $options;
        }

        $path     = sprintf( '/custom-actions/%s/reactivate', rawurlencode( $id ) );
        $response = $this->client->post(
            $path,
            [ 'actor_hint' => $actor_hint ],
            $options
        );

        if ( is_wp_error( $response ) )
        {
            return $response;
        }

        return Sentient_Forms_Custom_Action_Mutation_Response::from_api_payload( $response );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function send_mutation(
        string $path,
        string $method,
        array $payload,
        string $actor_hint
    ): WP_Error | Sentient_Forms_Custom_Action_Mutation_Response {
        $options = $this->build_bearer_options();
        if ( is_wp_error( $options ) )
        {
            return $options;
        }

        $body = array_merge( $payload, [ 'actor_hint' => $actor_hint ] );

        $response = match ( strtolower( $method ) ) {
            'post' => $this->client->post( $path, $body, $options ),
            'put'  => $this->client->put( $path, $body, $options ),
            default => new WP_Error( 'cps_invalid_method', __( 'Unsupported CPS mutation method.', 'sentient-forms' ) ),
        };

        if ( is_wp_error( $response ) )
        {
            return $response;
        }

        return Sentient_Forms_Custom_Action_Mutation_Response::from_api_payload( $response );
    }

    /**
     * @return array{bearer_token: string}|WP_Error
     */
    private function build_bearer_options(): array | WP_Error
    {
        $proxy_key = $this->plugin->get_proxy_api_key();
        if ( empty( $proxy_key ) )
        {
            return new WP_Error(
                'cps_missing_proxy_key',
                __( 'Sentient Forms proxy key is missing; activate your license before managing custom actions.', 'sentient-forms' )
            );
        }

        return [
            'bearer_token' => $proxy_key,
        ];
    }
}
