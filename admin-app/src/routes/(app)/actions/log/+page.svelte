<script lang="ts">
	import { onMount } from 'svelte';
	import { Section, Card, Button, Badge, Input, StateTemplate } from '$lib/components/ui';
	import { wpFetch } from '$lib/wp';

	interface ActionLogEntry {
		id: string;
		form_source: string;
		form_id: number;
		entry_id: number | null;
		action_code: string;
		action_label: string;
		status: 'pending' | 'success' | 'error';
		result_summary: string | null;
		classification: string | null;
		credits_used: number;
		error_code: string | null;
		error_message: string | null;
		structured_output_valid: boolean;
		created_at: string;
		completed_at: string | null;
	}

	interface LogResponse {
		entries: ActionLogEntry[];
		total: number;
		total_pages: number;
		page: number;
		per_page: number;
	}

	let entries = $state<ActionLogEntry[]>([]);
	let total = $state(0);
	let page = $state(1);
	let perPage = $state(20);
	let totalPages = $state(1);
	let loading = $state(true);
	let error = $state<string | null>(null);

	// Filters
	let filterFormId = $state('');
	let filterStatus = $state('');
	let filterActionCode = $state('');
	let hasActiveFilters = $derived(
		Boolean(filterFormId) || Boolean(filterStatus) || Boolean(filterActionCode)
	);

	async function fetchLogs() {
		loading = true;
		error = null;
		try {
			const params = new URLSearchParams({
				page: String(page),
				per_page: String(perPage)
			});
			if (filterFormId) params.set('form_id', filterFormId);
			if (filterStatus) params.set('status', filterStatus);
			if (filterActionCode) params.set('action_code', filterActionCode);

			const response = await wpFetch<LogResponse>(`actions/log?${params}`);
			entries = response.entries;
			total = response.total;
			totalPages = response.total_pages;
		} catch (e) {
			error = e instanceof Error ? e.message : 'Failed to fetch action logs';
		} finally {
			loading = false;
		}
	}

	function applyFilters() {
		page = 1;
		fetchLogs();
	}

	function clearFilters() {
		filterFormId = '';
		filterStatus = '';
		filterActionCode = '';
		page = 1;
		fetchLogs();
	}

	function nextPage() {
		if (page < totalPages) {
			page++;
			fetchLogs();
		}
	}

	function prevPage() {
		if (page > 1) {
			page--;
			fetchLogs();
		}
	}

	function getStatusVariant(status: string): 'success' | 'warning' | 'danger' {
		switch (status) {
			case 'success':
				return 'success';
			case 'pending':
				return 'warning';
			case 'error':
				return 'danger';
			default:
				return 'warning';
		}
	}

	function formatDate(isoString: string | null): string {
		if (!isoString) return '—';
		return new Date(isoString).toLocaleString();
	}

	onMount(() => {
		fetchLogs();
	});
</script>

<Section heading="Action Log" description="View history of AI action executions.">
	{#snippet actions()}
		<Button variant="secondary" onclick={fetchLogs}>Refresh</Button>
	{/snippet}

	<!-- Filters -->
	<Card>
		<div class="sf:flex sf:flex-wrap sf:gap-4 sf:items-end">
			<div class="sf:flex-1 sf:min-w-[120px]">
				<label for="filter-form-id" class="sf:text-sm sf:font-medium sf:text-slate-600"
					>Form ID</label
				>
				<Input
					id="filter-form-id"
					type="number"
					bind:value={filterFormId}
					placeholder="All forms"
				/>
			</div>
			<div class="sf:flex-1 sf:min-w-[120px]">
				<label for="filter-status" class="sf:text-sm sf:font-medium sf:text-slate-600">Status</label
				>
				<select
					id="filter-status"
					bind:value={filterStatus}
					class="sf:w-full sf:px-3 sf:py-2 sf:border sf:rounded-md"
				>
					<option value="">All</option>
					<option value="success">Success</option>
					<option value="pending">Pending</option>
					<option value="error">Error</option>
				</select>
			</div>
			<div class="sf:flex-1 sf:min-w-[120px]">
				<label for="filter-action" class="sf:text-sm sf:font-medium sf:text-slate-600">Action</label
				>
				<Input
					id="filter-action"
					type="text"
					bind:value={filterActionCode}
					placeholder="e.g. spam_detection_v1"
				/>
			</div>
			<div class="sf:flex sf:gap-2">
				<Button variant="primary" onclick={applyFilters}>Apply</Button>
				<Button variant="ghost" onclick={clearFilters}>Clear</Button>
			</div>
		</div>
	</Card>

	<!-- Loading/Error States -->
	{#if loading}
		<StateTemplate
			variant="loading"
			title="Loading action logs"
			message="Fetching the latest Sentient Forms execution history."
			testId="action-log-loading-state"
		/>
	{:else if error}
		<StateTemplate
			variant="error"
			title="Unable to load action logs"
			message={error}
			actionLabel="Retry"
			onAction={fetchLogs}
			testId="action-log-error-state"
		/>
	{:else if entries.length === 0}
		<StateTemplate
			variant="empty"
			title={hasActiveFilters ? 'No action logs match the current filters' : 'No action logs yet'}
			message={hasActiveFilters
				? 'Try different filters or clear them to see more entries.'
				: 'Actions will appear here as they execute.'}
			actionLabel={hasActiveFilters ? 'Clear filters' : null}
			onAction={hasActiveFilters ? clearFilters : null}
			testId="action-log-empty-state"
		/>
	{:else}
		<!-- Log Table -->
		<Card>
			<div class="sf:overflow-x-auto">
				<table class="sf:w-full sf:text-sm">
					<thead>
						<tr class="sf:border-b sf:text-left sf:text-slate-500">
							<th class="sf:pb-2 sf:pr-4">Form</th>
							<th class="sf:pb-2 sf:pr-4">Entry</th>
							<th class="sf:pb-2 sf:pr-4">Action</th>
							<th class="sf:pb-2 sf:pr-4">Status</th>
							<th class="sf:pb-2 sf:pr-4">Output</th>
							<th class="sf:pb-2 sf:pr-4">Result</th>
							<th class="sf:pb-2 sf:pr-4">Credits</th>
							<th class="sf:pb-2">Time</th>
						</tr>
					</thead>
					<tbody>
						{#each entries as entry}
							<tr class="sf:border-b sf:last:border-0 sf:hover:bg-slate-50">
								<td class="sf:py-3 sf:pr-4">{entry.form_id}</td>
								<td class="sf:py-3 sf:pr-4">{entry.entry_id ?? '—'}</td>
								<td class="sf:py-3 sf:pr-4">
									<span class="sf:font-medium">{entry.action_label}</span>
									<br />
									<span class="sf:text-xs sf:text-slate-400">{entry.action_code}</span>
								</td>
								<td class="sf:py-3 sf:pr-4">
									<Badge variant={getStatusVariant(entry.status)}>
										{entry.status}
									</Badge>
								</td>
								<td class="sf:py-3 sf:pr-4">
									{#if entry.status === 'success'}
										<Badge variant={entry.structured_output_valid ? 'success' : 'warning'}>
											{entry.structured_output_valid ? '✓ Structured' : 'Raw'}
										</Badge>
									{:else}
										—
									{/if}
								</td>
								<td class="sf:py-3 sf:pr-4 sf:max-w-[200px] sf:truncate">
									{#if entry.classification}
										<Badge variant={entry.classification === 'spam' ? 'danger' : 'success'}>
											{entry.classification}
										</Badge>
									{:else if entry.result_summary}
										<span title={entry.result_summary}>{entry.result_summary}</span>
									{:else if entry.error_message}
										<span class="sf:text-red-600" title={entry.error_message}>
											{entry.error_code ?? 'Error'}: {entry.error_message.length > 30
												? entry.error_message.slice(0, 30) + '...'
												: entry.error_message}
										</span>
									{:else}
										—
									{/if}
								</td>
								<td class="sf:py-3 sf:pr-4">{entry.credits_used}</td>
								<td class="sf:py-3 sf:text-slate-500 sf:text-xs">
									{formatDate(entry.created_at)}
								</td>
							</tr>
						{/each}
					</tbody>
				</table>
			</div>

			<!-- Pagination -->
			<div class="sf:flex sf:justify-between sf:items-center sf:mt-4 sf:pt-4 sf:border-t">
				<span class="sf:text-sm sf:text-slate-500">
					Showing {(page - 1) * perPage + 1}–{Math.min(page * perPage, total)} of {total}
				</span>
				<div class="sf:flex sf:gap-2">
					<Button variant="ghost" disabled={page <= 1} onclick={prevPage}>Previous</Button>
					<Button variant="ghost" disabled={page >= totalPages} onclick={nextPage}>Next</Button>
				</div>
			</div>
		</Card>
	{/if}
</Section>
