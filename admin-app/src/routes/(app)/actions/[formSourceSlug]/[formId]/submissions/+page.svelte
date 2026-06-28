<script lang="ts">
	import { onDestroy, onMount } from 'svelte';
	import {
		Badge,
		Button,
		ButtonLink,
		Card,
		InputField,
		SearchInput,
		Section,
		SelectField,
		StateTemplate
	} from '$lib/components/ui';
	import { createClientFromConfig } from '$lib/api/client';
	import type {
		FormSourceDescriptor,
		SubmissionLedgerRecord,
		SubmissionLedgerSettingsResponse
	} from '$lib/api/types';
	import { appHref } from '$lib/navigation';
	import { formatTimestamp } from '$lib/utils/date-time';
	import {
		formatSubmissionLedgerFieldPreview,
		safeSubmissionNativeEntryUrl
	} from '$lib/utils/submission-ledger';

	type Props = { data: { formSourceSlug: string; formId: string } };

	let { data }: Props = $props();

	const FILTER_DEBOUNCE_MS = 300;
	const pageSizeOptions = [
		{ value: '10', label: '10 per page' },
		{ value: '25', label: '25 per page' },
		{ value: '50', label: '50 per page' },
		{ value: '100', label: '100 per page' }
	];
	const hasFilesOptions = [
		{ value: 'all', label: 'All submissions' },
		{ value: 'yes', label: 'With files' },
		{ value: 'no', label: 'Without files' }
	];
	const sortOptions = [
		{ value: 'captured_desc', label: 'Newest captured' },
		{ value: 'captured_asc', label: 'Oldest captured' },
		{ value: 'submitted_desc', label: 'Newest submitted' },
		{ value: 'submitted_asc', label: 'Oldest submitted' },
		{ value: 'native_entry_desc', label: 'Native entry desc' },
		{ value: 'native_entry_asc', label: 'Native entry asc' }
	];

	const client = createClientFromConfig();
	let loading = $state(true);
	let error = $state<string | null>(null);
	let settings = $state<SubmissionLedgerSettingsResponse | null>(null);
	let formSourceDescriptor = $state<FormSourceDescriptor | null>(null);
	let records = $state<SubmissionLedgerRecord[]>([]);
	let total = $state(0);
	let query = $state('');
	let nativeEntry = $state('');
	let capturedFrom = $state('');
	let capturedTo = $state('');
	let hasFiles = $state('all');
	let sort = $state('captured_desc');
	let perPage = $state('10');
	let offset = $state(0);
	let filterTimer: ReturnType<typeof setTimeout> | null = null;
	let ledgerRequestSequence = 0;

	const routeFormSourceSlug = $derived(encodeURIComponent(data.formSourceSlug));
	const routeFormId = $derived(encodeURIComponent(data.formId));
	const formDetailHref = $derived(appHref(`/actions/${routeFormSourceSlug}/${routeFormId}`));
	const formLabel = $derived(`${data.formSourceSlug.replaceAll('_', ' ')} #${data.formId}`);
	const nativeSubmissionLimitation = $derived(
		descriptorRequirementString(formSourceDescriptor?.requirements, 'native_submission_parity_reason')
	);
	const pageSize = $derived(Math.max(1, Number(perPage) || 10));
	const resultStart = $derived(total > 0 && records.length > 0 ? offset + 1 : 0);
	const resultEnd = $derived(total > 0 ? Math.min(offset + records.length, total) : 0);
	const resultSummary = $derived.by(() => {
		if (total <= 0 || records.length === 0) return 'Showing 0 of 0';
		if (resultStart === resultEnd) return `Showing ${resultStart} of ${total.toLocaleString()}`;
		return `Showing ${resultStart.toLocaleString()}-${resultEnd.toLocaleString()} of ${total.toLocaleString()}`;
	});
	const hasPreviousPage = $derived(offset > 0);
	const hasNextPage = $derived(offset + records.length < total);
	const hasActiveFilters = $derived(
		query.trim() !== '' ||
			nativeEntry.trim() !== '' ||
			capturedFrom.trim() !== '' ||
			capturedTo.trim() !== '' ||
			hasFiles !== 'all' ||
			sort !== 'captured_desc'
	);

	function descriptorRequirementString(
		requirements: FormSourceDescriptor['requirements'] | undefined,
		key: string
	): string {
		const value = requirements?.[key];
		return typeof value === 'string' ? value.trim() : '';
	}

	async function loadLedgerSubmissions() {
		const requestSequence = ++ledgerRequestSequence;
		loading = true;
		error = null;

		try {
			const [bootstrapResult, settingsResult] = await Promise.allSettled([
				client.getFormActionsBootstrap(data.formSourceSlug, data.formId, {
					showNotifications: false
				}),
				client.getSubmissionLedgerSettings(data.formSourceSlug, data.formId, {
					showNotifications: false
				})
			]);

			formSourceDescriptor =
				bootstrapResult.status === 'fulfilled'
					? (bootstrapResult.value.form_source_descriptor ?? null)
					: null;

			if (settingsResult.status === 'rejected') {
				throw settingsResult.reason;
			}

			const nextSettings = settingsResult.value;
			if (requestSequence !== ledgerRequestSequence) return;

			settings = nextSettings;

			if (!nextSettings.enabled) {
				records = [];
				total = 0;
				return;
			}

			const nextRecords = await client.getSubmissionLedgerRecords(data.formSourceSlug, data.formId, {
				perPage: pageSize,
				offset,
				q: query,
				nativeEntry,
				capturedFrom,
				capturedTo,
				hasFiles: hasFiles === 'all' ? null : hasFiles === 'yes',
				sort,
				showNotifications: false
			});
			if (requestSequence !== ledgerRequestSequence) return;

			records = Array.isArray(nextRecords.records) ? nextRecords.records : [];
			total = Number.isFinite(nextRecords.total) ? nextRecords.total : records.length;
		} catch (caught) {
			if (requestSequence !== ledgerRequestSequence) return;

			error = caught instanceof Error ? caught.message : 'Unable to load submission ledger.';
			records = [];
			total = 0;
		} finally {
			if (requestSequence === ledgerRequestSequence) {
				loading = false;
			}
		}
	}

	function clearFilterTimer() {
		if (!filterTimer) return;
		clearTimeout(filterTimer);
		filterTimer = null;
	}

	function queueFilterReload() {
		clearFilterTimer();
		filterTimer = setTimeout(() => {
			offset = 0;
			void loadLedgerSubmissions();
		}, FILTER_DEBOUNCE_MS);
	}

	function applyFilterReload() {
		clearFilterTimer();
		offset = 0;
		void loadLedgerSubmissions();
	}

	function clearFilters() {
		query = '';
		nativeEntry = '';
		capturedFrom = '';
		capturedTo = '';
		hasFiles = 'all';
		sort = 'captured_desc';
		offset = 0;
		clearFilterTimer();
		void loadLedgerSubmissions();
	}

	function goToPreviousPage() {
		offset = Math.max(0, offset - pageSize);
		void loadLedgerSubmissions();
	}

	function goToNextPage() {
		offset += pageSize;
		void loadLedgerSubmissions();
	}

	onMount(() => {
		void loadLedgerSubmissions();
	});

	function actionRunsFor(record: SubmissionLedgerRecord) {
		return Array.isArray(record.action_runs) ? record.action_runs : [];
	}

	function actionRunCountLabel(record: SubmissionLedgerRecord) {
		const count = actionRunsFor(record).length;
		return `${count.toLocaleString()} action ${count === 1 ? 'run' : 'runs'}`;
	}

	function latestActionRunSummary(record: SubmissionLedgerRecord) {
		const latest = actionRunsFor(record)[0];
		if (!latest) {
			return 'No action output recorded yet.';
		}

		const result = latest.last_result;
		if (result && typeof result === 'object' && !Array.isArray(result)) {
			const structured = result.structured;
			if (structured && typeof structured === 'object' && !Array.isArray(structured)) {
				const summary = (structured as Record<string, unknown>).summary;
				if (typeof summary === 'string' && summary.trim()) {
					return summary;
				}
			}

			const summary = result.summary;
			if (typeof summary === 'string' && summary.trim()) {
				return summary;
			}
		}

		if (latest.last_error_message) {
			return latest.last_error_message;
		}

		return latest.status;
	}

	onDestroy(() => {
		clearFilterTimer();
		ledgerRequestSequence += 1;
	});
</script>

{#snippet actions()}
	<ButtonLink variant="secondary" href={formDetailHref}>Back to form actions</ButtonLink>
	<Button variant="secondary" onclick={loadLedgerSubmissions}>Refresh</Button>
{/snippet}

<Section
	heading="Submission Ledger"
	description={`Stored Sentient Forms submission snapshots for ${formLabel}.`}
	{actions}
>
	<Card data-testid="submission-ledger-status-card">
		<div
			class="sf:flex sf:flex-col sf:gap-3 sf:sm:flex-row sf:sm:items-center sf:sm:justify-between"
		>
			<div>
				<p class="sf:text-sm sf:font-medium sf:text-slate-800">Ledger storage</p>
				<p class="sf:mt-1 sf:text-xs sf:text-slate-600">
					{settings?.enabled
						? 'Logical field snapshots are enabled for this form.'
						: 'Logical field snapshots are not stored while this is off.'}
				</p>
				{#if nativeSubmissionLimitation}
					<p class="sf:mt-2 sf:text-xs sf:text-amber-700" data-testid="submission-ledger-native-limit">
						{nativeSubmissionLimitation}
					</p>
				{/if}
			</div>
			<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
				<Badge variant={settings?.enabled ? 'success' : 'neutral'}>
					{settings?.enabled ? 'On' : 'Off'}
				</Badge>
				<Badge variant="neutral">{total.toLocaleString()} records</Badge>
			</div>
		</div>
	</Card>

	<Card data-testid="submission-ledger-records-card">
		<div class="sf:mb-5 sf:space-y-4" data-testid="submission-ledger-controls">
			<div class="sf:grid sf:gap-3 sf:lg:grid-cols-[minmax(14rem,1.2fr)_minmax(10rem,0.8fr)_auto] sf:lg:items-end">
				<div>
					<label
						for="submission-ledger-search"
						class="sf:mb-1 sf:block sf:text-sm sf:font-medium sf:text-slate-700"
					>
						Search submissions
					</label>
					<SearchInput
						id="submission-ledger-search"
						bind:value={query}
						placeholder="Search fields, UUID, metadata, or native entry"
						aria-label="Search Submission Ledger"
						data-testid="submission-ledger-search"
						oninput={queueFilterReload}
					/>
				</div>
				<InputField
					id="submission-ledger-native-entry"
					label="Native Entry"
					placeholder="Entry ID"
					bind:value={nativeEntry}
					data-testid="submission-ledger-native-entry"
					oninput={queueFilterReload}
				/>
				<Button variant="secondary" onclick={clearFilters} disabled={!hasActiveFilters}>
					Clear filters
				</Button>
			</div>

			<div class="sf:grid sf:gap-3 sf:md:grid-cols-2 sf:xl:grid-cols-5">
				<InputField
					id="submission-ledger-captured-from"
					label="Captured From"
					type="datetime-local"
					placeholder={undefined}
					bind:value={capturedFrom}
					onchange={applyFilterReload}
				/>
				<InputField
					id="submission-ledger-captured-to"
					label="Captured To"
					type="datetime-local"
					placeholder={undefined}
					bind:value={capturedTo}
					onchange={applyFilterReload}
				/>
				<SelectField
					id="submission-ledger-has-files"
					label="Files"
					options={hasFilesOptions}
					bind:value={hasFiles}
					onchange={applyFilterReload}
				/>
				<SelectField
					id="submission-ledger-sort"
					label="Sort"
					options={sortOptions}
					bind:value={sort}
					onchange={applyFilterReload}
				/>
				<SelectField
					id="submission-ledger-page-size"
					label="Page Size"
					options={pageSizeOptions}
					bind:value={perPage}
					onchange={applyFilterReload}
				/>
			</div>
		</div>

		{#if loading}
			<StateTemplate
				variant="loading"
				title="Loading submissions"
				message="Fetching stored submission snapshots."
				inline
				testId="submission-ledger-loading"
			/>
		{:else if error}
			<StateTemplate
				variant="error"
				title="Unable to load submissions"
				message={error}
				actionLabel="Retry"
				onAction={loadLedgerSubmissions}
				inline
				testId="submission-ledger-error"
			/>
		{:else if records.length === 0}
			<StateTemplate
				variant="empty"
				title={hasActiveFilters ? 'No matching submissions' : 'No stored submissions'}
				message={hasActiveFilters
					? 'Clear filters or broaden the search to review more stored submissions.'
					: 'New submitted forms will appear here after ledger storage is enabled and a form is submitted.'}
				inline
				testId="submission-ledger-empty"
			/>
		{:else}
			<div
				class="sf:mb-3 sf:flex sf:flex-col sf:gap-2 sf:sm:flex-row sf:sm:items-center sf:sm:justify-between"
			>
				<p class="sf:text-sm sf:font-medium sf:text-slate-700" data-testid="submission-ledger-results-summary">
					{resultSummary}
				</p>
				<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
					<Button variant="secondary" size="sm" onclick={goToPreviousPage} disabled={!hasPreviousPage}>
						Previous page
					</Button>
					<Button variant="secondary" size="sm" onclick={goToNextPage} disabled={!hasNextPage}>
						Next page
					</Button>
				</div>
			</div>
			<div class="sf:overflow-x-auto" data-testid="submission-ledger-table-scroll">
				<table class="sf:min-w-full sf:divide-y sf:divide-slate-200" data-testid="submission-ledger-table">
					<thead class="sf:bg-slate-50">
						<tr
							class="sf:text-left sf:text-xs sf:font-semibold sf:uppercase sf:tracking-wide sf:text-slate-600"
						>
							<th class="sf:px-4 sf:py-3">Submission</th>
							<th class="sf:px-4 sf:py-3">Captured</th>
							<th class="sf:px-4 sf:py-3">Fields</th>
							<th class="sf:px-4 sf:py-3">Action runs</th>
							<th class="sf:px-4 sf:py-3 sf:text-right">Native entry</th>
						</tr>
					</thead>
					<tbody class="sf:divide-y sf:divide-slate-200">
						{#each records as record (record.submission_uuid)}
							{@const nativeEntryUrl = safeSubmissionNativeEntryUrl(record.native_entry_url)}
							<tr class="sf:text-sm sf:text-slate-700" data-testid="submission-ledger-row">
								<td class="sf:px-4 sf:py-3">
									<p class="sf:font-medium sf:text-slate-900">{record.submission_uuid}</p>
									{#if record.native_entry_id}
										<p class="sf:mt-1 sf:text-xs sf:text-slate-500">
											Native entry {record.native_entry_id}
										</p>
									{/if}
								</td>
								<td class="sf:px-4 sf:py-3">
									{formatTimestamp(record.captured_at)}
									{#if record.expires_at}
										<p class="sf:mt-1 sf:text-xs sf:text-slate-500">
											Expires {formatTimestamp(record.expires_at)}
										</p>
									{/if}
								</td>
								<td class="sf:max-w-md sf:px-4 sf:py-3">
									<p class="sf:line-clamp-2 sf:text-slate-700">
										{formatSubmissionLedgerFieldPreview(record)}
									</p>
								</td>
								<td class="sf:max-w-sm sf:px-4 sf:py-3" data-testid="submission-ledger-action-runs">
									<p class="sf:text-sm sf:font-medium sf:text-slate-800">
										{actionRunCountLabel(record)}
									</p>
									<p class="sf:mt-1 sf:line-clamp-2 sf:text-xs sf:text-slate-600">
										{latestActionRunSummary(record)}
									</p>
								</td>
								<td class="sf:px-4 sf:py-3 sf:text-right">
									{#if nativeEntryUrl}
										<a
											href={nativeEntryUrl}
											class="sf:text-sm sf:font-medium sf:text-primary-700 hover:sf:text-primary-800"
											data-sveltekit-reload
											rel="external"
										>
											Open
										</a>
									{:else}
										<span class="sf:text-xs sf:text-slate-500">Unavailable</span>
									{/if}
								</td>
							</tr>
						{/each}
					</tbody>
				</table>
			</div>
		{/if}
	</Card>
</Section>
