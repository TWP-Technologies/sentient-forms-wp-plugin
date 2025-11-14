<?php
/**
 * Async-capable adapter contract.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface Sentient_Forms_Async_Capable_Adapter_Interface
 *
 * Describes the callbacks an adapter must implement to participate in asynchronous execution.
 */
interface Sentient_Forms_Async_Capable_Adapter_Interface {

	/**
	 * Called after an async job succeeds so the adapter can persist or surface the CPS result.
	 *
	 * @param array<string, mixed> $context Job context (action id, entry id, form id, etc.).
	 * @param array<string, mixed> $result  CPS/API payload that completed successfully.
	 *
	 * @return void
	 */
	public function finalize_async_success( array $context, array $result ): void;

	/**
	 * Called after an async job fails permanently.
	 *
	 * @param array<string, mixed> $context Job context (action id, entry id, form id, etc.).
	 * @param WP_Error             $error   Error describing the failure.
	 *
	 * @return void
	 */
	public function finalize_async_error( array $context, WP_Error $error ): void;

	/**
	 * Called when an evaluation/cleanup job runs separately from the primary execution.
	 *
	 * @param array<string, mixed> $context Job context (action id, entry id, form id, etc.).
	 * @param array<string, mixed> $result  Data prepared for evaluation (e.g., cached CPS response).
	 *
	 * @return void
	 */
	public function finalize_async_evaluation( array $context, array $result ): void;
}
