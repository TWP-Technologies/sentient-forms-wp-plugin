<script lang="ts">
	import { onMount } from 'svelte';
	import ExternalLinkIcon from '@lucide/svelte/icons/external-link';
	import EyeIcon from '@lucide/svelte/icons/eye';
	import FileTextIcon from '@lucide/svelte/icons/file-text';
	import PanelsTopLeftIcon from '@lucide/svelte/icons/panels-top-left';
	import Rows3Icon from '@lucide/svelte/icons/rows-3';
	import {
		Section,
		Card,
		Button,
		ButtonLink,
		Badge,
		Input,
		StateTemplate
	} from '$lib/components/ui';
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
		form_id: string | number;
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
			provider_cost?: Record<string, unknown> | null;
		} | null;
		usage_cost?: {
			route?: string | null;
			label?: string | null;
			kind?: string | null;
			known?: boolean | null;
			credits?: number | null;
			amount_usd?: number | null;
		} | null;
		details?: Record<string, unknown> | null;
		form_context?: ActionLogFormContext | null;
		created_at: string;
		completed_at: string | null;
	}

	interface ActionLogFormContext {
		provider_slug: string;
		provider_label: string;
		form_id: string | number;
		form_name: string;
		entry_id: number | null;
		links: {
			provider_admin_url?: string | null;
			form_admin_url?: string | null;
			entries_admin_url?: string | null;
			entry_admin_url?: string | null;
		};
		entry_preview_available: boolean;
		form_missing?: boolean;
	}

	interface ActionLogEntryPreviewField {
		field_id: string;
		label: string;
		value: string;
	}

	interface ActionLogEntryPreview {
		log_id: string;
		provider_label: string;
		form_id: number;
		form_name: string;
		entry_id: number;
		date_created: string | null;
		status: string | null;
		fields: ActionLogEntryPreviewField[];
		links: ActionLogFormContext['links'];
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
	let previewEntry = $state<ActionLogEntry | null>(null);
	let previewCache = $state<Record<string, ActionLogEntryPreview>>({});
	let previewLoadingId = $state<string | null>(null);
	let previewError = $state<string | null>(null);

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
	let activePreview = $derived(previewEntry ? (previewCache[previewEntry.id] ?? null) : null);

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
			error = requestError instanceof Error ? requestError.message : 'Failed to fetch action logs';
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

	function formContext(entry: ActionLogEntry): ActionLogFormContext {
		return (
			entry.form_context ?? {
				provider_slug: entry.form_source,
				provider_label: providerLabel(entry.form_source),
				form_id: entry.form_id,
				form_name: formIdLabel(entry.form_id),
				entry_id: entry.entry_id,
				links: {},
				entry_preview_available: Boolean(entry.entry_id)
			}
		);
	}

	function formIdLabel(formId: string | number | null | undefined): string {
		if (typeof formId === 'number') return `Form #${formId}`;

		const value = String(formId ?? '').trim();
		return value ? `Form ${value}` : 'Form -';
	}

	function providerLabel(formSource: string): string {
		if (formSource === 'gravity_forms' || formSource === 'gravity-forms') return 'Gravity Forms';
		if (formSource === 'elementor_forms') return 'Elementor Forms';
		if (!formSource) return 'Unknown provider';
		return formSource.replace(/[_-]+/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
	}

	function entryContextLabel(entry: ActionLogEntry): string {
		const context = formContext(entry);
		const parts = [context.provider_label, formIdLabel(context.form_id || entry.form_id)];
		parts.push(context.entry_id ? `Entry #${context.entry_id}` : 'Entry pending');
		return parts.join(' · ');
	}

	async function openPreview(entry: ActionLogEntry): Promise<void> {
		previewEntry = entry;
		previewError = null;

		if (previewCache[entry.id]) {
			return;
		}

		previewLoadingId = entry.id;
		try {
			const preview = await wpFetch<ActionLogEntryPreview>(
				`actions/log/${encodeURIComponent(entry.id)}/entry-preview`
			);
			previewCache = {
				...previewCache,
				[entry.id]: preview
			};
		} catch (requestError) {
			previewError =
				requestError instanceof Error ? requestError.message : 'Failed to load entry preview';
		} finally {
			previewLoadingId = null;
		}
	}

	function closePreview(): void {
		previewEntry = null;
		previewError = null;
		previewLoadingId = null;
	}

	function closePreviewFromBackdrop(event: MouseEvent): void {
		if (event.target === event.currentTarget) {
			closePreview();
		}
	}

	function handlePreviewKeydown(event: KeyboardEvent): void {
		if (event.key === 'Escape' && previewEntry) {
			closePreview();
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

	function readUnknownPath(source: unknown, path: string[]): unknown {
		let current: unknown = source;
		for (const segment of path) {
			if (!isRecord(current) || !(segment in current)) {
				return null;
			}
			current = current[segment];
		}

		return current;
	}

	function isManagedEntry(entry: ActionLogEntry): boolean {
		return (
			entry.usage_cost?.route === 'sentient_forms_managed' ||
			readStringPath(entry.details, ['provider']) === 'sentient_managed'
		);
	}

	function sanitizeManagedDisplayValue(value: unknown): unknown {
		if (Array.isArray(value)) {
			return value.map(sanitizeManagedDisplayValue);
		}
		if (!isRecord(value)) {
			return value;
		}

		const sanitized: Record<string, unknown> = {};
		for (const [key, child] of Object.entries(value)) {
			if (
				key === 'amount_usd' ||
				key === 'billed_amount_microusd' ||
				key === 'total_billed_micro_usd' ||
				key === 'currency' ||
				key === 'provider_cost'
			) {
				continue;
			}
			const sanitizedChild = sanitizeManagedDisplayValue(child);
			if (
				(key === 'billing' || key === 'cost' || key === 'details') &&
				isRecord(sanitizedChild) &&
				Object.keys(sanitizedChild).length === 0
			) {
				continue;
			}
			sanitized[key] = sanitizedChild;
		}
		return sanitized;
	}

	function usageCostLabel(entry: ActionLogEntry): string {
		const label = entry.usage_cost?.label;
		if (typeof label === 'string' && label.trim().length > 0) {
			return label.trim();
		}

		const debitedCredits = entry.pricing?.debited_credits ?? entry.credits_used;
		if (typeof debitedCredits === 'number' && debitedCredits > 0) {
			return `SF ${debitedCredits} ${debitedCredits === 1 ? 'credit' : 'credits'}`;
		}

		return 'Unknown';
	}

	function usageCostVariant(
		entry: ActionLogEntry
	): 'neutral' | 'success' | 'warning' | 'danger' | 'info' {
		const kind = entry.usage_cost?.kind ?? '';
		if (kind === 'openrouter_free') return 'success';
		if (kind === 'openrouter_currency') return 'info';
		if (kind === 'sentient_credits') return 'warning';
		return 'neutral';
	}

	function usageCostTitle(entry: ActionLogEntry): string {
		const route = entry.usage_cost?.route;
		const known = entry.usage_cost?.known;
		if (route === 'openrouter_direct' && known === false) {
			return 'OpenRouter direct run. Provider-billed estimate was not returned with this execution.';
		}
		if (route === 'openrouter_direct') {
			return 'OpenRouter direct run. Provider charges belong to the site owner OpenRouter account.';
		}
		if (route === 'sentient_forms_managed') {
			return 'Sentient Forms managed-service run. Usage is metered by Sentient Forms.';
		}
		return 'Usage route could not be determined from stored execution metadata.';
	}

	function usagePolicyLabel(entry: ActionLogEntry): string {
		return entry.usage_cost?.route === 'sentient_forms_managed'
			? 'Managed action credits'
			: 'Provider estimate';
	}

	function storedResult(entry: ActionLogEntry): unknown {
		const result =
			readUnknownPath(entry.details, ['stored_result']) ??
			readUnknownPath(entry.details, ['evaluation_payload', 'result_data']) ??
			null;

		return isManagedEntry(entry) ? sanitizeManagedDisplayValue(result) : result;
	}

	function formatJson(value: unknown): string {
		if (value === null || value === undefined) {
			return '';
		}

		return JSON.stringify(value, null, 2);
	}

	function hasOperationalDetails(entry: ActionLogEntry): boolean {
		return Boolean(
			entry.execution_request_id ||
			entry.mapping_id ||
			entry.resolved_model_id ||
			entry.pricing?.pricing_policy_version ||
			storedResult(entry) !== null ||
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

<svelte:window onkeydown={handlePreviewKeydown} />

<Section heading="Action Log" description="View local and managed AI action execution history.">
	{#snippet actions()}
		<Button variant="secondary" onclick={fetchLogs} disabled={loading}>
			{loading ? 'Refreshing...' : 'Refresh'}
		</Button>
	{/snippet}

	<Card data-testid="action-log-filter-card">
		<div class="sf:flex sf:flex-wrap sf:gap-4 sf:items-end">
			<div class="sf:flex-1 sf:min-w-[120px]">
				<label for="filter-form-id" class="sf:text-sm sf:font-medium sf:text-slate-600"
					>Form ID</label
				>
				<Input
					id="filter-form-id"
					type="number"
					bind:value={draftFilters.formId}
					placeholder="All forms"
				/>
			</div>
			<div class="sf:flex-1 sf:min-w-[120px]">
				<label for="filter-status" class="sf:text-sm sf:font-medium sf:text-slate-600">Status</label
				>
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
				<label for="filter-action" class="sf:text-sm sf:font-medium sf:text-slate-600">Action</label
				>
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
			message="Fetching local execution history."
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
							<th class="sf:pb-2 sf:pr-4">Source</th>
							<th class="sf:pb-2 sf:pr-4">Action</th>
							<th class="sf:pb-2 sf:pr-4">Outcome</th>
							<th class="sf:pb-2">Run</th>
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
							{@const context = formContext(entry)}
							{@const justification =
								readStringPath(entry.details, [
									'evaluation_payload',
									'result_data',
									'justification'
								]) ??
								readStringPath(entry.details, ['evaluation_payload', 'result_data', 'reasoning'])}
							{@const confidence = readNumberPath(entry.details, [
								'evaluation_payload',
								'result_data',
								'confidence'
							])}
							{@const indicators = readArrayPath(entry.details, [
								'evaluation_payload',
								'result_data',
								'indicators'
							])}
							<tr
								class="sf:border-b sf:last:border-0 sf:hover:bg-slate-50"
								data-testid={`action-log-row-${entry.id}`}
							>
								<td class="sf:py-3 sf:pr-4 sf:min-w-[240px] sf:align-top">
									<div class="sf:flex sf:flex-col sf:gap-2">
										<span class="sf:font-medium sf:text-slate-900">{context.form_name}</span>
										<span class="sf:text-xs sf:text-slate-500">{entryContextLabel(entry)}</span>
										{#if context.form_missing}
											<Badge variant="warning">Form missing</Badge>
										{/if}
										<div
											class="sf:flex sf:flex-wrap sf:gap-2"
											data-testid={`action-log-actions-${entry.id}`}
										>
											{#if context.links.provider_admin_url}
												<ButtonLink
													size="xs"
													variant="ghost"
													href={context.links.provider_admin_url}
													data-sveltekit-reload
													rel="external"
													data-testid={`action-log-provider-link-${entry.id}`}
												>
													<PanelsTopLeftIcon class="sf:h-3 sf:w-3" aria-hidden="true" />
													Provider
												</ButtonLink>
											{/if}
											{#if context.links.form_admin_url}
												<ButtonLink
													size="xs"
													variant="ghost"
													href={context.links.form_admin_url}
													data-sveltekit-reload
													rel="external"
													data-testid={`action-log-form-link-${entry.id}`}
												>
													<ExternalLinkIcon class="sf:h-3 sf:w-3" aria-hidden="true" />
													Form
												</ButtonLink>
											{/if}
											{#if context.links.entries_admin_url}
												<ButtonLink
													size="xs"
													variant="ghost"
													href={context.links.entries_admin_url}
													data-sveltekit-reload
													rel="external"
													data-testid={`action-log-entries-link-${entry.id}`}
												>
													<Rows3Icon class="sf:h-3 sf:w-3" aria-hidden="true" />
													Entries
												</ButtonLink>
											{/if}
											{#if context.links.entry_admin_url}
												<ButtonLink
													size="xs"
													variant="secondary"
													href={context.links.entry_admin_url}
													data-sveltekit-reload
													rel="external"
													data-testid={`action-log-entry-link-${entry.id}`}
												>
													<FileTextIcon class="sf:h-3 sf:w-3" aria-hidden="true" />
													Entry
												</ButtonLink>
											{/if}
											<Button
												size="xs"
												variant={context.entry_preview_available ? 'secondary' : 'ghost'}
												disabled={!context.entry_preview_available}
												onclick={() => void openPreview(entry)}
												title={context.entry_preview_available
													? 'Preview this entry'
													: 'Preview is available after an entry is saved'}
												data-testid={`action-log-preview-button-${entry.id}`}
											>
												<EyeIcon class="sf:h-3 sf:w-3" aria-hidden="true" />
												Preview
											</Button>
										</div>
									</div>
								</td>
								<td class="sf:py-3 sf:pr-4 sf:min-w-[120px] sf:align-top">
									<span class="sf:font-medium sf:text-slate-900">{entry.action_label}</span>
									<br />
									<span class="sf:text-xs sf:font-mono sf:text-slate-500">{entry.action_code}</span>
								</td>
								<td class="sf:py-3 sf:pr-4 sf:min-w-[150px] sf:align-top">
									<div class="sf:flex sf:flex-col sf:gap-2">
										<div class="sf:flex sf:flex-wrap sf:gap-2">
											<Badge variant={rowPresentation.statusVariant}>
												{rowPresentation.statusLabel}
											</Badge>
											<Badge variant={rowPresentation.outputVariant}>
												{rowPresentation.outputLabel}
											</Badge>
										</div>
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
									</div>
								</td>
								<td class="sf:py-3 sf:align-top">
									<div class="sf:flex sf:min-w-[115px] sf:flex-col sf:gap-2">
										<span title={usageCostTitle(entry)}>
											<Badge variant={usageCostVariant(entry)}>
												{usageCostLabel(entry)}
											</Badge>
										</span>
										<span class="sf:text-xs sf:text-slate-500">
											{formatTimestamp(entry.created_at)}
										</span>
									</div>
								</td>
							</tr>
							{#if hasOperationalDetails(entry)}
								<tr
									class="sf:border-b sf:last:border-0 sf:bg-slate-50/60"
									data-testid={`action-log-details-row-${entry.id}`}
								>
									<td colspan="4" class="sf:px-4 sf:pb-4 sf:pt-1">
										<details data-testid={`action-log-details-${entry.id}`}>
											<summary
												class="sf:cursor-pointer sf:text-sm sf:font-medium sf:text-slate-700"
											>
												Execution details
											</summary>
											<div
												class="sf:mt-3 sf:grid sf:gap-3 sf:text-xs sf:text-slate-600 md:sf:grid-cols-3 xl:sf:grid-cols-5"
											>
												{#if entry.execution_request_id}
													<div class="sf:min-w-0">
														<p class="sf:font-semibold sf:text-slate-700">Execution Request</p>
														<p class="sf:font-mono sf:text-[11px] sf:break-all">
															{entry.execution_request_id}
														</p>
													</div>
												{/if}
												{#if readStringPath(entry.details, ['meta', 'request_id'])}
													<div class="sf:min-w-0">
														<p class="sf:font-semibold sf:text-slate-700">Managed request</p>
														<p class="sf:font-mono sf:text-[11px] sf:break-all">
															{readStringPath(entry.details, ['meta', 'request_id'])}
														</p>
													</div>
												{/if}
												{#if entry.mapping_id}
													<div class="sf:min-w-0">
														<p class="sf:font-semibold sf:text-slate-700">Mapping</p>
														<p class="sf:font-mono sf:text-[11px] sf:break-all">
															{entry.mapping_id}
														</p>
													</div>
												{/if}
												{#if entry.resolved_model_id}
													<div class="sf:min-w-0">
														<p class="sf:font-semibold sf:text-slate-700">Resolved Model</p>
														<p class="sf:font-mono sf:text-[11px] sf:break-all">
															{entry.resolved_model_id}
														</p>
													</div>
												{/if}
												{#if entry.pricing?.pricing_policy_version}
													<div class="sf:min-w-0">
														<p class="sf:font-semibold sf:text-slate-700">Usage policy</p>
														<p class="sf:font-mono sf:text-[11px] sf:break-all">
															{entry.pricing.pricing_policy_version}
														</p>
														<p class="sf:mt-1">
															{usagePolicyLabel(entry)} {usageCostLabel(entry)}
															{#if entry.pricing.base_floor_credits !== null && entry.pricing.base_floor_credits !== undefined}
																, base floor {entry.pricing.base_floor_credits}
															{/if}
															{#if entry.pricing.normalized_actual_credits !== null && entry.pricing.normalized_actual_credits !== undefined}
																, normalized actual {entry.pricing.normalized_actual_credits}
															{/if}
														</p>
													</div>
												{/if}
												{#if justification}
													<div class="md:sf:col-span-3 xl:sf:col-span-5">
														<p class="sf:font-semibold sf:text-slate-700">Justification</p>
														<p>{justification}</p>
													</div>
												{/if}
												{#if confidence !== null}
													<div>
														<p class="sf:font-semibold sf:text-slate-700">Confidence</p>
														<p>{confidence}</p>
													</div>
												{/if}
												{#if indicators.length > 0}
													<div class="md:sf:col-span-3 xl:sf:col-span-5">
														<p class="sf:font-semibold sf:text-slate-700">Indicators</p>
														<div class="sf:flex sf:flex-wrap sf:gap-2 sf:mt-1">
															{#each indicators as indicator}
																<Badge variant="warning">{indicatorLabel(indicator)}</Badge>
															{/each}
														</div>
													</div>
												{/if}
												{#if storedResult(entry) !== null}
													<div class="md:sf:col-span-3 xl:sf:col-span-5">
														<p class="sf:font-semibold sf:text-slate-700">Stored result</p>
														<pre
															class="sf:mt-1 sf:max-h-80 sf:overflow-auto sf:rounded sf:border sf:border-slate-200 sf:bg-white sf:p-3 sf:text-[11px] sf:leading-relaxed sf:text-slate-800">{formatJson(
																storedResult(entry)
															)}</pre>
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

			<div
				class="sf:flex sf:flex-wrap sf:justify-between sf:items-center sf:gap-3 sf:mt-4 sf:pt-4 sf:border-t"
				data-testid="action-log-pagination"
			>
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

{#if previewEntry}
	<div
		class="sf:fixed sf:inset-0 sf:z-[999999] sf:flex sf:justify-end sf:bg-slate-950/35 sf:backdrop-blur-[1px]"
		role="presentation"
		onclick={closePreviewFromBackdrop}
		data-testid="action-log-preview-backdrop"
	>
		<div
			class="sf:h-full sf:w-full sf:max-w-2xl sf:overflow-y-auto sf:bg-slate-50 sf:p-5 sf:shadow-2xl sf:sm:p-6"
			role="dialog"
			aria-modal="true"
			aria-labelledby="action-log-entry-preview-title"
			data-action-log-preview-sheet
			data-testid="action-log-preview-sheet"
		>
			<div
				class="sf:flex sf:items-start sf:justify-between sf:gap-4 sf:border-b sf:border-slate-200 sf:pb-4"
			>
				<div class="sf:min-w-0">
					<p class="sf:text-xs sf:font-bold sf:uppercase sf:tracking-[0.12em] sf:text-slate-400">
						Entry preview
					</p>
					<h2
						id="action-log-entry-preview-title"
						class="sf:mt-1 sf:text-2xl sf:font-semibold sf:text-slate-950"
					>
						{formContext(previewEntry).form_name}
					</h2>
					<p class="sf:mt-1 sf:text-sm sf:text-slate-500">
						{entryContextLabel(previewEntry)}
					</p>
				</div>
				<Button variant="secondary" onclick={closePreview}>Close</Button>
			</div>

			{#if previewLoadingId === previewEntry.id}
				<div
					class="sf:mt-6 sf:rounded sf:border sf:border-slate-200 sf:bg-white sf:p-5"
					data-testid="action-log-preview-loading"
				>
					<p class="sf:text-sm sf:font-medium sf:text-slate-700">Loading entry preview...</p>
					<p class="sf:mt-1 sf:text-sm sf:text-slate-500">
						Pulling the entry from Gravity Forms only for this row.
					</p>
				</div>
			{:else if previewError}
				<div
					class="sf:mt-6 sf:rounded sf:border sf:border-danger-200 sf:bg-danger-50 sf:p-5"
					data-testid="action-log-preview-error"
				>
					<p class="sf:text-sm sf:font-medium sf:text-danger-800">Preview unavailable</p>
					<p class="sf:mt-1 sf:text-sm sf:text-danger-700">{previewError}</p>
				</div>
			{:else if activePreview}
				<div class="sf:mt-5 sf:flex sf:flex-wrap sf:items-center sf:gap-2">
					<Badge variant="info">{activePreview.provider_label}</Badge>
					<Badge variant="neutral">Form #{activePreview.form_id}</Badge>
					<Badge variant="neutral">Entry #{activePreview.entry_id}</Badge>
					{#if activePreview.status}
						<Badge variant="neutral">{activePreview.status}</Badge>
					{/if}
				</div>

				<div class="sf:mt-5 sf:flex sf:flex-wrap sf:gap-2">
					{#if activePreview.links.form_admin_url}
						<ButtonLink
							size="sm"
							variant="secondary"
							href={activePreview.links.form_admin_url}
							data-sveltekit-reload
							rel="external"
						>
							<ExternalLinkIcon class="sf:h-4 sf:w-4" aria-hidden="true" />
							Open form
						</ButtonLink>
					{/if}
					{#if activePreview.links.entry_admin_url}
						<ButtonLink
							size="sm"
							variant="secondary"
							href={activePreview.links.entry_admin_url}
							data-sveltekit-reload
							rel="external"
						>
							<FileTextIcon class="sf:h-4 sf:w-4" aria-hidden="true" />
							Open entry
						</ButtonLink>
					{/if}
				</div>

				<section class="sf:mt-6">
					<h3 class="sf:text-base sf:font-semibold sf:text-slate-950">Visible fields</h3>
					<div class="sf:mt-3 sf:grid sf:gap-3">
						{#each activePreview.fields as field (`${field.field_id}-${field.label}`)}
							<div
								class="sf:rounded sf:border sf:border-slate-200 sf:bg-white sf:p-4"
								data-testid="action-log-preview-field"
							>
								<p
									class="sf:text-xs sf:font-semibold sf:uppercase sf:tracking-[0.08em] sf:text-slate-400"
								>
									{field.label}
								</p>
								<p class="sf:mt-2 sf:whitespace-pre-wrap sf:text-sm sf:leading-6 sf:text-slate-800">
									{field.value}
								</p>
							</div>
						{:else}
							<p
								class="sf:rounded sf:border sf:border-slate-200 sf:bg-white sf:p-4 sf:text-sm sf:text-slate-500"
							>
								No visible field values were available for this entry.
							</p>
						{/each}
					</div>
				</section>
			{/if}
		</div>
	</div>
{/if}

<style>
	:global([data-action-log-preview-sheet]) {
		animation: action-log-preview-sheet-enter 160ms ease-out;
	}

	@keyframes action-log-preview-sheet-enter {
		from {
			opacity: 0;
			transform: translateX(32px);
		}
		to {
			opacity: 1;
			transform: translateX(0);
		}
	}

	@media (prefers-reduced-motion: reduce) {
		:global([data-action-log-preview-sheet]) {
			animation: none;
		}
	}
</style>
