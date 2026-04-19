<script lang="ts">
	import { onMount } from 'svelte';
	import { createClientFromConfig } from '$lib/api/client';
	import { notifications } from '$lib/stores/notifications';
	import { Button, StateTemplate } from '$lib/components/ui';
	import type {
		LocalMigrationApprovedResetResponse,
		LocalMigrationDryRunResponse,
		LocalMigrationReadinessReport
	} from '$lib/api/types';

	const client = createClientFromConfig();

	let report = $state<LocalMigrationReadinessReport | null>(null);
	let dryRunResult = $state<LocalMigrationDryRunResponse | null>(null);
	let resetResult = $state<LocalMigrationApprovedResetResponse | null>(null);
	let loading = $state(true);
	let dryRunLoading = $state(false);
	let resetLoading = $state(false);
	let loadError = $state<string | null>(null);
	let confirmationPhrase = $state('');

	const resettableTableLabels: Record<string, string> = {
		sentient_action_templates: 'Action templates',
		sentient_custom_actions: 'Custom actions',
		sentient_form_mappings: 'Form mappings',
		sentient_execution_events: 'Execution events',
		sentient_async_requests: 'Queue records'
	};

	const preservedTableLabels: Record<string, string> = {
		sentient_provider_credentials: 'Provider credentials',
		sentient_external_service_consents: 'External-service consent',
		sentient_migration_runs: 'Migration audit rows',
		sentient_model_cache: 'Model cache'
	};

	let resettableCounts = $derived(report ? buildCounts(report, resettableTableLabels) : []);
	let preservedCounts = $derived(report ? buildCounts(report, preservedTableLabels) : []);
	let resettableTotal = $derived(sumCounts(resettableCounts));
	let legacyOptionTotal = $derived(report ? countLegacyOptions(report) : 0);
	let canReset = $derived(
		Boolean(report) &&
			confirmationPhrase.trim() === report?.confirmation_phrase &&
			!resetLoading &&
			!loading
	);

	onMount(() => {
		void loadReport();
	});

	async function loadReport(): Promise<void> {
		loading = true;
		loadError = null;
		try {
			report = await client.getLocalMigrationReadiness({ showNotifications: false });
		} catch (error) {
			loadError = error instanceof Error ? error.message : 'Cutover readiness could not be loaded.';
		} finally {
			loading = false;
		}
	}

	async function recordDryRun(): Promise<void> {
		dryRunLoading = true;
		try {
			dryRunResult = await client.createLocalMigrationDryRun({ showNotifications: false });
			report = dryRunResult.report;
			notifications.success(`Dry run recorded as migration run #${dryRunResult.run_id}`);
		} catch {
			notifications.error('Unable to record migration dry run');
		} finally {
			dryRunLoading = false;
		}
	}

	async function runApprovedReset(): Promise<void> {
		if (!report || !canReset) return;

		resetLoading = true;
		try {
			resetResult = await client.runLocalMigrationApprovedReset(
				{ confirmation_phrase: confirmationPhrase.trim() },
				{ showNotifications: false }
			);
			report = resetResult.after;
			confirmationPhrase = '';
			notifications.success(`Cutover reset completed as migration run #${resetResult.run_id}`);
		} catch {
			notifications.error('Unable to complete cutover reset');
		} finally {
			resetLoading = false;
		}
	}

	function buildCounts(
		currentReport: LocalMigrationReadinessReport,
		labels: Record<string, string>
	): Array<{ key: string; label: string; count: number | null }> {
		return Object.entries(labels).map(([key, label]) => ({
			key,
			label,
			count: currentReport.local_tables[key] ?? currentReport.runtime_tables[key] ?? null
		}));
	}

	function sumCounts(items: Array<{ count: number | null }>): number {
		return items.reduce((total, item) => total + (item.count ?? 0), 0);
	}

	function countLegacyOptions(currentReport: LocalMigrationReadinessReport): number {
		const exactCount = Object.values(currentReport.legacy_options.exact_options).filter(
			(option) => option.exists && option.will_delete
		).length;
		const prefixCount = Object.values(currentReport.legacy_options.option_prefixes).reduce(
			(total, option) => total + (option.will_delete ? (option.count ?? 0) : 0),
			0
		);
		return exactCount + prefixCount;
	}
</script>

<section class="sf:min-w-0 sf:space-y-6 sf:max-w-5xl">
	<header class="sf:space-y-2">
		<h1 class="sf:text-2xl sf:font-semibold sf:text-slate-900">Local-First Cutover</h1>
		<p class="sf:text-sm sf:text-slate-600">
			Reset legacy action wiring after the move to on-site configuration and direct provider
			execution.
		</p>
	</header>

	{#if loading}
		<StateTemplate
			variant="loading"
			title="Loading cutover readiness"
			message="Reading local tables, legacy options, and preserved settings."
			testId="migration-readiness-loading-state"
		/>
	{:else if loadError}
		<StateTemplate
			variant="error"
			title="Cutover readiness unavailable"
			message={loadError}
			actionLabel="Retry"
			onAction={() => {
				void loadReport();
			}}
			testId="migration-readiness-error-state"
		/>
	{:else if report}
		<div class="sf:grid sf:grid-cols-1 sf:gap-3 sf:md:grid-cols-3">
			<div class="sf:rounded-lg sf:border sf:border-slate-200 sf:bg-white sf:p-4 sf:shadow-sm">
				<p class="sf:text-sm sf:text-slate-500">Rows reset</p>
				<p class="sf:mt-1 sf:text-2xl sf:font-semibold sf:text-slate-900">{resettableTotal}</p>
			</div>
			<div class="sf:rounded-lg sf:border sf:border-slate-200 sf:bg-white sf:p-4 sf:shadow-sm">
				<p class="sf:text-sm sf:text-slate-500">Legacy options reset</p>
				<p class="sf:mt-1 sf:text-2xl sf:font-semibold sf:text-slate-900">{legacyOptionTotal}</p>
			</div>
			<div class="sf:rounded-lg sf:border sf:border-slate-200 sf:bg-white sf:p-4 sf:shadow-sm">
				<p class="sf:text-sm sf:text-slate-500">Local execution</p>
				<p class="sf:mt-1 sf:text-lg sf:font-semibold sf:text-slate-900">
					{report.ready_for_local_execution ? 'Provider ready' : 'Provider setup needed'}
				</p>
			</div>
		</div>

		{#if report.warnings.length > 0}
			<div class="sf:rounded-lg sf:border sf:border-amber-200 sf:bg-amber-50 sf:p-4 sf:space-y-2">
				<p class="sf:font-semibold sf:text-amber-950">Review before reset</p>
				<ul class="sf:space-y-1">
					{#each report.warnings as warning}
						<li class="sf:text-sm sf:text-amber-950">
							<span class="sf:font-medium">{warning.code}</span>: {warning.message}
						</li>
					{/each}
				</ul>
			</div>
		{/if}

		<div class="sf:grid sf:grid-cols-1 sf:gap-4 sf:lg:grid-cols-2">
			<section
				class="sf:rounded-lg sf:border sf:border-rose-200 sf:bg-white sf:p-5 sf:shadow-sm sf:space-y-4"
			>
				<div class="sf:space-y-1">
					<h2 class="sf:text-lg sf:font-semibold sf:text-slate-900">Reset scope</h2>
					<p class="sf:text-sm sf:text-slate-600">
						Action builder state, legacy mappings, local execution history, and queued runtime
						metadata.
					</p>
				</div>
				<div class="sf:divide-y sf:divide-slate-100">
					{#each resettableCounts as item}
						<div class="sf:flex sf:items-center sf:justify-between sf:gap-3 sf:py-2">
							<span class="sf:text-sm sf:text-slate-700">{item.label}</span>
							<span class="sf:text-sm sf:font-semibold sf:text-slate-900">
								{item.count === null ? 'Missing' : item.count}
							</span>
						</div>
					{/each}
				</div>
			</section>

			<section
				class="sf:rounded-lg sf:border sf:border-emerald-200 sf:bg-white sf:p-5 sf:shadow-sm sf:space-y-4"
			>
				<div class="sf:space-y-1">
					<h2 class="sf:text-lg sf:font-semibold sf:text-slate-900">Preserved scope</h2>
					<p class="sf:text-sm sf:text-slate-600">
						Provider setup, consent records, model cache, migration history, and license settings.
					</p>
				</div>
				<div class="sf:divide-y sf:divide-slate-100">
					{#each preservedCounts as item}
						<div class="sf:flex sf:items-center sf:justify-between sf:gap-3 sf:py-2">
							<span class="sf:text-sm sf:text-slate-700">{item.label}</span>
							<span class="sf:text-sm sf:font-semibold sf:text-slate-900">
								{item.count === null ? 'Missing' : item.count}
							</span>
						</div>
					{/each}
				</div>
			</section>
		</div>

		<section
			class="sf:rounded-lg sf:border sf:border-slate-200 sf:bg-white sf:p-5 sf:shadow-sm sf:space-y-4"
		>
			<div class="sf:space-y-1">
				<h2 class="sf:text-lg sf:font-semibold sf:text-slate-900">Legacy options</h2>
				<p class="sf:text-sm sf:text-slate-600">
					Option-backed Gravity Forms mappings, action defaults, action logs, and stale CPS
					transients.
				</p>
			</div>
			<div class="sf:grid sf:grid-cols-1 sf:gap-3 sf:md:grid-cols-2">
				{#each Object.entries(report.legacy_options.exact_options) as [name, option]}
					<div class="sf:rounded-lg sf:border sf:border-slate-200 sf:p-3">
						<p class="sf:break-all sf:text-sm sf:font-medium sf:text-slate-900">{name}</p>
						<p class="sf:text-xs sf:text-slate-500">
							{option.exists ? 'Present' : 'Absent'}{option.will_delete
								? ' · reset'
								: ' · preserved'}
						</p>
					</div>
				{/each}
				{#each Object.entries(report.legacy_options.option_prefixes) as [prefix, option]}
					<div class="sf:rounded-lg sf:border sf:border-slate-200 sf:p-3">
						<p class="sf:break-all sf:text-sm sf:font-medium sf:text-slate-900">{prefix}*</p>
						<p class="sf:text-xs sf:text-slate-500">
							{option.count ?? 0} found{option.will_delete ? ' · reset' : ' · preserved'}
						</p>
					</div>
				{/each}
			</div>
		</section>

		<section
			class="sf:rounded-lg sf:border sf:border-slate-200 sf:bg-white sf:p-5 sf:shadow-sm sf:space-y-4"
		>
			<div
				class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:md:flex-row sf:md:items-center"
			>
				<div class="sf:space-y-1">
					<h2 class="sf:text-lg sf:font-semibold sf:text-slate-900">Audit run</h2>
					<p class="sf:text-sm sf:text-slate-600">
						Record the current report without changing tables or options.
					</p>
				</div>
				<Button type="button" variant="secondary" disabled={dryRunLoading} onclick={recordDryRun}>
					{dryRunLoading ? 'Recording...' : 'Record dry run'}
				</Button>
			</div>
			{#if dryRunResult}
				<p class="sf:text-sm sf:text-green-700">
					Dry run #{dryRunResult.run_id} recorded at {dryRunResult.report.generated_at}.
				</p>
			{/if}
		</section>

		<section
			class="sf:rounded-lg sf:border sf:border-rose-300 sf:bg-rose-50 sf:p-5 sf:shadow-sm sf:space-y-4"
		>
			<div class="sf:space-y-1">
				<h2 class="sf:text-lg sf:font-semibold sf:text-rose-950">Approved reset</h2>
				<p class="sf:text-sm sf:text-rose-900">
					Type <code>{report.confirmation_phrase}</code> to clear the reset scope and preserve the local
					provider setup.
				</p>
			</div>
			<label class="sf:block sf:space-y-2">
				<span class="sf:text-sm sf:font-medium sf:text-rose-950">Confirmation phrase</span>
				<input
					type="text"
					class="sf:w-full sf:rounded-lg sf:border sf:border-rose-300 sf:bg-white sf:px-3 sf:py-2 sf:text-sm sf:text-slate-900 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-rose-700 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-rose-50"
					bind:value={confirmationPhrase}
					autocomplete="off"
					spellcheck="false"
				/>
			</label>
			<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-3">
				<Button type="button" variant="danger" disabled={!canReset} onclick={runApprovedReset}>
					{resetLoading ? 'Resetting...' : 'Run approved reset'}
				</Button>
				<Button
					type="button"
					variant="ghost"
					disabled={loading || resetLoading}
					onclick={() => {
						void loadReport();
					}}
				>
					Refresh report
				</Button>
			</div>
			{#if resetResult}
				<p class="sf:text-sm sf:text-rose-950">
					Reset #{resetResult.run_id} completed. {sumCounts(
						Object.values(resetResult.deleted_tables).map((count) => ({ count }))
					)} rows removed.
				</p>
			{/if}
		</section>
	{/if}
</section>
