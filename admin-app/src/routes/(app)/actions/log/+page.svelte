<script lang="ts">
	import { onMount } from 'svelte';
	import { Section, Card, Button, Badge, Input, StateTemplate } from '$lib/components/ui';
	import { formatTimestamp } from '$lib/utils/date-time';
	import {
		buildActionLogRowPresentation,
		buildActiveFilterChips,
		buildPaginationPresentation,
		normalizeActionLogFilters,
		type ActiveFilterChip,
		type ActionLogFilters,
		type ActionLogStatus,
		type ActionLogResultVariant
	} from '$lib/utils/action-log-presentation';
	import { wpFetch } from '$lib/wp';

	interface ActionLogEntry {
		id: string;
		form_source: string;
		form_id: number;
		entry_id: number | null;
		action_code: string;
		action_label: string;
		status: ActionLogStatus;
		result_summary: string | null;
		classification: string | null;
		credits_used: number;
		error_code: string | null;
		error_message: string | null;
		structured_output_valid: boolean;
		execution_request_id?: string | null;
		mapping_id?: string | null;
		resolved_model_id?: string | null;
		pricing?: {
			pricing_policy_version?: string | null;
			estimate_source?: string | null;
			base_floor_credits?: number | null;
			normalized_actual_credits?: number | null;
			debited_credits?: number | null;
		} | null;
		details?: Record<string, unknown> | null;
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

	const EMPTY_FILTERS: ActionLogFilters = {
		formId: '',
		status: '',
		actionCode: ''
	};

	let entries = $state<ActionLogEntry[]>([]);
	let total = $state(0);
	let page = $state(1);
	let perPage = $state(20);
	let totalPages = $state(1);
	let loading = $state(true);
	let error = $state<string | null>(null);

	let draftFilters = $state<ActionLogFilters>({ ...EMPTY_FILTERS });
	let appliedFilters = $state<ActionLogFilters>({ ...EMPTY_FILTERS });

	let activeFilterChips = $derived(buildActiveFilterChips(appliedFilters));
	let hasActiveFilters = $derived(activeFilterChips.length > 0);
	let hasPendingFilterEdits = $derived(
		draftFilters.formId !== appliedFilters.formId ||
			draftFilters.status !== appliedFilters.status ||
			draftFilters.actionCode !== appliedFilters.actionCode
	);
	let pagination = $derived(
		buildPaginationPresentation({
			page,
			perPage,
			total,
			totalPages
		})
	);

	async function fetchLogs(): Promise<void> {
		loading = true;
		error = null;
		try {
			const params = new URLSearchParams({
				page: String(page),
				per_page: String(perPage)
			});
			if (appliedFilters.formId) {
				params.set('form_id', appliedFilters.formId);
			}
			if (appliedFilters.status) {
				params.set('status', appliedFilters.status);
			}
			if (appliedFilters.actionCode) {
				params.set('action_code', appliedFilters.actionCode);
			}

			const response = await wpFetch<LogResponse>(`actions/log?${params}`);
			entries = response.entries;
			total = response.total;
			perPage = response.per_page;
			totalPages = Math.max(1, response.total_pages);
		} catch (requestError) {
			error =
				requestError instanceof Error ? requestError.message : 'Failed to fetch action logs';
		} finally {
			loading = false;
		}
	}

	function applyFilters(): void {
		const normalizedFilters = normalizeActionLogFilters(draftFilters);
		draftFilters = { ...normalizedFilters };
		appliedFilters = { ...normalizedFilters };
		page = 1;
		void fetchLogs();
	}

	function clearFilters(): void {
		draftFilters = { ...EMPTY_FILTERS };
		appliedFilters = { ...EMPTY_FILTERS };
		page = 1;
		void fetchLogs();
	}

	function clearFilterChip(key: ActiveFilterChip['key']): void {
		switch (key) {
			case 'form_id':
				draftFilters.formId = '';
				appliedFilters.formId = '';
				break;
			case 'status':
				draftFilters.status = '';
				appliedFilters.status = '';
				break;
			case 'action_code':
				draftFilters.actionCode = '';
				appliedFilters.actionCode = '';
				break;
		}

		page = 1;
		void fetchLogs();
	}

	function nextPage(): void {
		if (page < totalPages) {
			page += 1;
			void fetchLogs();
		}
	}

	function prevPage(): void {
		if (page > 1) {
			page -= 1;
			void fetchLogs();
		}
	}

	function resultTextClass(variant: ActionLogResultVariant): string {
		switch (variant) {
			case 'danger':
				return 'sf:text-danger-700 sf:font-medium';
			case 'success':
				return 'sf:text-success-700 sf:font-medium';
			default:
				return 'sf:text-slate-700';
		}
	}

	function isRecord(value: unknown): value is Record<string, unknown> {
		return Boolean(value) && typeof value === 'object' && !Array.isArray(value);
	}

	function readStringPath(source: unknown, path: string[]): string | null {
		let current: unknown = source;
		for (const segment of path) {
			if (!isRecord(current) || !(segment in current)) {
				return null;
			}
			current = current[segment];
		}

		return typeof current === 'string' && current.trim().length > 0 ? current.trim() : null;
	}

	function readNumberPath(source: unknown, path: string[]): number | null {
		let current: unknown = source;
		for (const segment of path) {
			if (!isRecord(current) || !(segment in current)) {
				return null;
			}
			current = current[segment];
		}

		return typeof current === 'number' && Number.isFinite(current) ? current : null;
	}

	function readArrayPath(source: unknown, path: string[]): unknown[] {
		let current: unknown = source;
		for (const segment of path) {
			if (!isRecord(current) || !(segment in current)) {
				return [];
			}
			current = current[segment];
		}

		return Array.isArray(current) ? current : [];
	}

	function hasOperationalDetails(entry: ActionLogEntry): boolean {
		return Boolean(
			entry.execution_request_id ||
				entry.mapping_id ||
				entry.resolved_model_id ||
				entry.pricing?.pricing_policy_version ||
				readStringPath(entry.details, ['meta', 'request_id']) ||
				readStringPath(entry.details, ['evaluation_payload', 'result_data', 'justification']) ||
				readStringPath(entry.details, ['evaluation_payload', 'result_data', 'reasoning']) ||
				readNumberPath(entry.details, ['evaluation_payload', 'result_data', 'confidence']) !== null ||
				readArrayPath(entry.details, ['evaluation_payload', 'result_data', 'indicators']).length > 0
		);
	}

	function indicatorLabel(indicator: unknown): string {
		if (typeof indicator === 'string') {
			return indicator;
		}

		if (isRecord(indicator)) {
			const label = readStringPath(indicator, ['label']) ?? readStringPath(indicator, ['name']);
			if (label) {
				return label;
			}

			const reason = readStringPath(indicator, ['reason']);
			if (reason) {
				return reason;
			}
		}

		return JSON.stringify(indicator);
	}

	onMount(() => {
		void fetchLogs();
	});
</script>

<Section heading="Action Log" description="View history of AI action executions.">
	{#snippet actions()}
		<Button variant="secondary" onclick={fetchLogs} disabled={loading}>
			{loading ? 'Refreshing...' : 'Refresh'}
		</Button>
	{/snippet}

	<Card data-testid="action-log-filter-card">
		<div class="sf:flex sf:flex-wrap sf:gap-4 sf:items-end">
			<div class="sf:flex-1 sf:min-w-[120px]">
				<label for="filter-form-id" class="sf:text-sm sf:font-medium sf:text-slate-600">Form ID</label>
				<Input
					id="filter-form-id"
					type="number"
					bind:value={draftFilters.formId}
					placeholder="All forms"
				/>
			</div>
			<div class="sf:flex-1 sf:min-w-[120px]">
				<label for="filter-status" class="sf:text-sm sf:font-medium sf:text-slate-600">Status</label>
				<select
					id="filter-status"
					bind:value={draftFilters.status}
					class="sf:w-full sf:px-3 sf:py-2 sf:border sf:border-slate-300 sf:bg-white sf:rounded-md sf:focus-visible:border-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
				>
					<option value="">All</option>
					<option value="success">Success</option>
					<option value="pending">Pending</option>
					<option value="blocked">Blocked</option>
					<option value="error">Error</option>
				</select>
			</div>
			<div class="sf:flex-1 sf:min-w-[120px]">
				<label for="filter-action" class="sf:text-sm sf:font-medium sf:text-slate-600">Action</label>
				<Input
					id="filter-action"
					type="text"
					bind:value={draftFilters.actionCode}
					placeholder="e.g. spam_detection_v1"
				/>
			</div>
			<div class="sf:flex sf:flex-wrap sf:gap-2">
				<Button variant="primary" onclick={applyFilters} data-testid="action-log-apply-filters">
					Apply
				</Button>
				<Button
					variant="secondary"
					onclick={clearFilters}
					data-testid="action-log-clear-filters"
					disabled={!hasActiveFilters && !hasPendingFilterEdits}
				>
					Clear
				</Button>
			</div>
		</div>
		{#if hasPendingFilterEdits}
			<p class="sf:mt-3 sf:text-xs sf:text-slate-500" data-testid="action-log-filter-pending">
				Filter changes are pending. Click Apply to refresh results.
			</p>
		{/if}
	</Card>

	{#if hasActiveFilters}
		<Card data-testid="action-log-active-filters">
			<div class="sf:flex sf:flex-wrap sf:items-center sf:justify-between sf:gap-3">
				<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
					<p class="sf:text-sm sf:font-medium sf:text-slate-700">Active filters</p>
					<div class="sf:flex sf:flex-wrap sf:gap-2">
						{#each activeFilterChips as chip}
							<Button
								variant="secondary"
								size="sm"
								onclick={() => clearFilterChip(chip.key)}
								data-testid={`action-log-filter-chip-${chip.key}`}
								aria-label={`Remove filter ${chip.label}: ${chip.value}`}
							>
								<span class="sf:font-medium">{chip.label}:</span>
								<span>{chip.value}</span>
								<span aria-hidden="true">x</span>
							</Button>
						{/each}
					</div>
				</div>
				<Button
					variant="ghost"
					size="sm"
					onclick={clearFilters}
					data-testid="action-log-clear-active-filters"
				>
					Clear all
				</Button>
			</div>
		</Card>
	{/if}

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
		<Card>
			<div class="sf:overflow-x-auto" data-testid="action-log-table-scroll">
				<table class="sf:w-full sf:text-sm" data-testid="action-log-table">
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
						{#each entries as entry (entry.id)}
							{@const rowPresentation = buildActionLogRowPresentation({
								status: entry.status,
								structuredOutputValid: entry.structured_output_valid,
								classification: entry.classification,
								resultSummary: entry.result_summary,
								errorCode: entry.error_code,
								errorMessage: entry.error_message
							})}
							<tr class="sf:border-b sf:last:border-0 sf:hover:bg-slate-50" data-testid={`action-log-row-${entry.id}`}>
								<td class="sf:py-3 sf:pr-4 sf:font-medium sf:text-slate-700">{entry.form_id}</td>
								<td class="sf:py-3 sf:pr-4 sf:text-slate-700">{entry.entry_id ?? '—'}</td>
								<td class="sf:py-3 sf:pr-4">
									<span class="sf:font-medium sf:text-slate-900">{entry.action_label}</span>
									<br />
									<span class="sf:text-xs sf:font-mono sf:text-slate-500">{entry.action_code}</span>
								</td>
								<td class="sf:py-3 sf:pr-4">
									<Badge variant={rowPresentation.statusVariant}>
										{rowPresentation.statusLabel}
									</Badge>
								</td>
								<td class="sf:py-3 sf:pr-4">
									<Badge variant={rowPresentation.outputVariant}>
										{rowPresentation.outputLabel}
									</Badge>
								</td>
								<td class="sf:py-3 sf:pr-4 sf:max-w-[220px] sf:align-top">
									{#if rowPresentation.resultKind === 'badge'}
										<Badge variant={rowPresentation.resultVariant}>
											{rowPresentation.resultLabel}
										</Badge>
									{:else}
										<span
											class={resultTextClass(rowPresentation.resultVariant)}
											title={rowPresentation.resultTitle ?? rowPresentation.resultLabel}
										>
											{rowPresentation.resultLabel}
										</span>
									{/if}
								</td>
								<td class="sf:py-3 sf:pr-4 sf:text-slate-700">{entry.credits_used}</td>
								<td class="sf:py-3 sf:text-slate-500 sf:text-xs">
									{formatTimestamp(entry.created_at)}
								</td>
							</tr>
							{#if hasOperationalDetails(entry)}
								<tr
									class="sf:border-b sf:last:border-0 sf:bg-slate-50/60"
									data-testid={`action-log-details-row-${entry.id}`}
								>
									<td colspan="8" class="sf:px-4 sf:pb-4 sf:pt-1">
										<details data-testid={`action-log-details-${entry.id}`}>
											<summary class="sf:cursor-pointer sf:text-sm sf:font-medium sf:text-slate-700">
												Execution details
											</summary>
											<div class="sf:mt-3 sf:grid sf:gap-3 sf:text-xs sf:text-slate-600 md:sf:grid-cols-2">
												{#if entry.execution_request_id}
													<div>
														<p class="sf:font-semibold sf:text-slate-700">Execution Request</p>
														<p class="sf:font-mono sf:text-[11px] sf:break-all">{entry.execution_request_id}</p>
													</div>
												{/if}
												{#if readStringPath(entry.details, ['meta', 'request_id'])}
													<div>
														<p class="sf:font-semibold sf:text-slate-700">CPS Request</p>
														<p class="sf:font-mono sf:text-[11px] sf:break-all">{readStringPath(entry.details, ['meta', 'request_id'])}</p>
													</div>
												{/if}
												{#if entry.mapping_id}
													<div>
														<p class="sf:font-semibold sf:text-slate-700">Mapping</p>
														<p class="sf:font-mono sf:text-[11px] sf:break-all">{entry.mapping_id}</p>
													</div>
												{/if}
												{#if entry.resolved_model_id}
													<div>
														<p class="sf:font-semibold sf:text-slate-700">Resolved Model</p>
														<p class="sf:font-mono sf:text-[11px] sf:break-all">{entry.resolved_model_id}</p>
													</div>
												{/if}
												{#if entry.pricing?.pricing_policy_version}
													<div>
														<p class="sf:font-semibold sf:text-slate-700">Pricing Policy</p>
														<p class="sf:font-mono sf:text-[11px] sf:break-all">{entry.pricing.pricing_policy_version}</p>
														<p class="sf:mt-1">
															Debited {entry.pricing.debited_credits ?? entry.credits_used} credits
															{#if entry.pricing.base_floor_credits !== null && entry.pricing.base_floor_credits !== undefined}
																, base floor {entry.pricing.base_floor_credits}
															{/if}
															{#if entry.pricing.normalized_actual_credits !== null && entry.pricing.normalized_actual_credits !== undefined}
																, normalized actual {entry.pricing.normalized_actual_credits}
															{/if}
														</p>
													</div>
												{/if}
												{#if readStringPath(entry.details, ['evaluation_payload', 'result_data', 'justification']) || readStringPath(entry.details, ['evaluation_payload', 'result_data', 'reasoning'])}
													<div class="md:sf:col-span-2">
														<p class="sf:font-semibold sf:text-slate-700">Justification</p>
														<p>
															{readStringPath(entry.details, ['evaluation_payload', 'result_data', 'justification']) ??
																readStringPath(entry.details, ['evaluation_payload', 'result_data', 'reasoning'])}
														</p>
													</div>
												{/if}
												{#if readNumberPath(entry.details, ['evaluation_payload', 'result_data', 'confidence']) !== null}
													<div>
														<p class="sf:font-semibold sf:text-slate-700">Confidence</p>
														<p>{readNumberPath(entry.details, ['evaluation_payload', 'result_data', 'confidence'])}</p>
													</div>
												{/if}
												{#if readArrayPath(entry.details, ['evaluation_payload', 'result_data', 'indicators']).length > 0}
													<div class="md:sf:col-span-2">
														<p class="sf:font-semibold sf:text-slate-700">Indicators</p>
														<div class="sf:flex sf:flex-wrap sf:gap-2 sf:mt-1">
															{#each readArrayPath(entry.details, ['evaluation_payload', 'result_data', 'indicators']) as indicator}
																<Badge variant="warning">{indicatorLabel(indicator)}</Badge>
															{/each}
														</div>
													</div>
												{/if}
											</div>
										</details>
									</td>
								</tr>
							{/if}
						{/each}
					</tbody>
				</table>
			</div>

			<div class="sf:flex sf:flex-wrap sf:justify-between sf:items-center sf:gap-3 sf:mt-4 sf:pt-4 sf:border-t" data-testid="action-log-pagination">
				<div class="sf:flex sf:flex-col sf:gap-1 sf:text-sm sf:text-slate-500">
					<span data-testid="action-log-pagination-range">{pagination.rangeLabel}</span>
					<span data-testid="action-log-pagination-page">{pagination.pageLabel}</span>
				</div>
				<div class="sf:flex sf:gap-2">
					<Button variant="secondary" disabled={page <= 1} onclick={prevPage}>Previous</Button>
					<Button variant="secondary" disabled={page >= totalPages} onclick={nextPage}>Next</Button>
				</div>
			</div>
		</Card>
	{/if}
</Section>
