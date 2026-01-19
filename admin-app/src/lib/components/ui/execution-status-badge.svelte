<script lang="ts">
	import { onMount, onDestroy } from 'svelte';
	import { Badge } from '$lib/components/ui';
	import { wpFetch } from '$lib/wp';

	/**
	 * ExecutionStatusBadge - Displays action execution status with optional auto-refresh
	 * CB-STATUS-001: Execution status per entry with refresh capability
	 */

	type ExecutionStatus = 'unknown' | 'queued' | 'running' | 'succeeded' | 'failed' | 'cancelled';
	type BadgeVariant = 'neutral' | 'success' | 'warning' | 'danger' | 'info';

	interface Props {
		/** CPS mapping ID */
		mappingId: string;
		/** WordPress entry ID */
		entryId: number;
		/** Enable auto-refresh polling (default: true for in-progress statuses) */
		autoRefresh?: boolean;
		/** Refresh interval in ms (default: 5000) */
		refreshInterval?: number;
		/** Callback when status changes */
		onStatusChange?: (status: ExecutionStatus) => void;
	}

	interface StatusResponse {
		id: string;
		mapping_id: string | null;
		entry_id: number;
		status: string;
		result_summary: string | null;
		error_message: string | null;
		credit_cost: number | null;
		started_at: string | null;
		completed_at: string | null;
		created_at: string;
	}

	let {
		mappingId,
		entryId,
		autoRefresh = true,
		refreshInterval = 5000,
		onStatusChange
	}: Props = $props();

	let status = $state<ExecutionStatus>('unknown');
	let loading = $state(false);
	let resultSummary = $state<string | null>(null);
	let errorMessage = $state<string | null>(null);
	let intervalId: ReturnType<typeof setInterval> | null = null;

	// Map status to badge variant
	function getVariant(s: ExecutionStatus): BadgeVariant {
		switch (s) {
			case 'succeeded':
				return 'success';
			case 'failed':
			case 'cancelled':
				return 'danger';
			case 'running':
				return 'info';
			case 'queued':
				return 'warning';
			default:
				return 'neutral';
		}
	}

	// Format status for display
	function formatStatus(s: ExecutionStatus): string {
		switch (s) {
			case 'succeeded':
				return '✓ Succeeded';
			case 'failed':
				return '✗ Failed';
			case 'cancelled':
				return '⊘ Cancelled';
			case 'running':
				return '◐ Running';
			case 'queued':
				return '◯ Queued';
			default:
				return '○ Unknown';
		}
	}

	async function fetchStatus() {
		if (!mappingId || !entryId) return;

		loading = true;
		try {
			const response = await wpFetch<{ success: boolean; data: StatusResponse }>(
				`execution-status/${mappingId}/${entryId}`,
				{ method: 'GET' }
			);

			if (response?.data) {
				const newStatus = response.data.status as ExecutionStatus;
				if (newStatus !== status) {
					status = newStatus;
					onStatusChange?.(newStatus);
				}
				resultSummary = response.data.result_summary;
				errorMessage = response.data.error_message;
			}
		} catch (e) {
			console.error('Failed to fetch execution status', e);
		} finally {
			loading = false;
		}
	}

	// Check if status is "final" (no more updates expected)
	function isFinalStatus(s: ExecutionStatus): boolean {
		return s === 'succeeded' || s === 'failed' || s === 'cancelled';
	}

	// Start/stop auto-refresh based on status
	function updatePolling() {
		// Clear existing interval
		if (intervalId) {
			clearInterval(intervalId);
			intervalId = null;
		}

		// Only poll for non-final statuses
		if (autoRefresh && !isFinalStatus(status)) {
			intervalId = setInterval(fetchStatus, refreshInterval);
		}
	}

	// Watch status changes to update polling
	$effect(() => {
		updatePolling();
	});

	onMount(() => {
		fetchStatus();
	});

	onDestroy(() => {
		if (intervalId) {
			clearInterval(intervalId);
		}
	});
</script>

<span class="sf-execution-status" title={resultSummary ?? errorMessage ?? ''}>
	<Badge variant={getVariant(status)}>
		{#if loading && status === 'unknown'}
			<span class="sf:animate-pulse">Loading...</span>
		{:else}
			{formatStatus(status)}
		{/if}
	</Badge>

	{#if status === 'running'}
		<span class="sf:ml-1 sf:inline-block sf:animate-spin sf:text-xs">⟳</span>
	{/if}
</span>

{#if errorMessage && status === 'failed'}
	<span class="sf:text-xs sf:text-red-600 sf:ml-2" title={errorMessage}>
		{errorMessage.length > 30 ? errorMessage.slice(0, 30) + '…' : errorMessage}
	</span>
{/if}

<style>
	.sf-execution-status {
		display: inline-flex;
		align-items: center;
		gap: 0.25rem;
	}
</style>
