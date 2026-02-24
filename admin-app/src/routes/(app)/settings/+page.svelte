<script lang="ts">
	import { run } from 'svelte/legacy';

	import { onMount } from 'svelte';
	import { telemetryStore } from '$lib/stores/telemetry.svelte';
	import { asyncSettingsStore } from '$lib/stores/async-settings.svelte';
	import { asyncHealthStore } from '$lib/stores/async-health.svelte';
	import { loggingStore } from '$lib/stores/logging.svelte';
	import { createClientFromConfig } from '$lib/api/client';
	import { notifications } from '$lib/stores/notifications';
	import { Button } from '$lib/components/ui';
	import type { FormSourceSummary } from '$lib/api/types';

	const telemetry = telemetryStore;
	const asyncSettings = asyncSettingsStore;
	const asyncHealth = asyncHealthStore;
	const logging = loggingStore;
	const client = createClientFromConfig();
	const runtime = typeof window === 'undefined' ? undefined : window.sentientFormsConfig;
	const formSources: FormSourceSummary[] = runtime?.formSources ?? [];

	let formDirty = $state(false);
	let formState = $state({
		maxAttempts: 3,
		baseDelaySeconds: 60,
		maxDelaySeconds: 3600
	});
	let executionLoading = $state(false);
	let executionSaving = $state(false);
	let executionGlobalDisabled = $state(false);
	let executionProviderDisabled = $state<Record<string, boolean>>({});

	onMount(() => {
		telemetry.load();
		asyncSettings.load();
		asyncHealth.refresh();
		logging.load();
		loadExecutionSettings();
	});

	function normalizeProviderDisabledMap(
		value: unknown,
		knownProviders: FormSourceSummary[]
	): Record<string, boolean> {
		const normalized: Record<string, boolean> = {};
		for (const source of knownProviders) {
			normalized[source.slug] = false;
		}

		if (!value || typeof value !== 'object' || Array.isArray(value)) {
			return normalized;
		}

		for (const [key, disabled] of Object.entries(value as Record<string, unknown>)) {
			const slug = key?.toString().trim();
			if (!slug) continue;
			normalized[slug] = Boolean(disabled);
		}

		return normalized;
	}

	async function loadExecutionSettings() {
		executionLoading = true;
		try {
			const settings = await client.getSettings({ showNotifications: false });
			executionGlobalDisabled = Boolean(settings.execution_global_disabled);
			executionProviderDisabled = normalizeProviderDisabledMap(
				settings.execution_provider_disabled,
				formSources
			);
		} catch {
			notifications.warning('Failed to load execution control settings');
		} finally {
			executionLoading = false;
		}
	}

	async function toggleExecutionGlobal(nextDisabled: boolean) {
		const previous = executionGlobalDisabled;
		executionGlobalDisabled = nextDisabled;
		executionSaving = true;
		try {
			const settings = await client.updateSettings(
				{
					execution_global_disabled: nextDisabled,
					execution_provider_disabled: executionProviderDisabled
				},
				{ showNotifications: false }
			);
			executionGlobalDisabled = Boolean(settings.execution_global_disabled);
			executionProviderDisabled = normalizeProviderDisabledMap(
				settings.execution_provider_disabled,
				formSources
			);
			notifications.success(
				executionGlobalDisabled ? 'Global execution paused' : 'Global execution resumed'
			);
		} catch {
			executionGlobalDisabled = previous;
			notifications.error('Unable to update global execution control');
		} finally {
			executionSaving = false;
		}
	}

	async function toggleExecutionProvider(providerSlug: string, nextDisabled: boolean) {
		const previousMap = { ...executionProviderDisabled };
		executionProviderDisabled = { ...executionProviderDisabled, [providerSlug]: nextDisabled };
		executionSaving = true;
		try {
			const settings = await client.updateSettings(
				{
					execution_global_disabled: executionGlobalDisabled,
					execution_provider_disabled: executionProviderDisabled
				},
				{ showNotifications: false }
			);
			executionGlobalDisabled = Boolean(settings.execution_global_disabled);
			executionProviderDisabled = normalizeProviderDisabledMap(
				settings.execution_provider_disabled,
				formSources
			);
			notifications.success(
				nextDisabled
					? `Execution paused for ${providerSlug}`
					: `Execution resumed for ${providerSlug}`
			);
		} catch {
			executionProviderDisabled = previousMap;
			notifications.error('Unable to update provider execution control');
		} finally {
			executionSaving = false;
		}
	}

	function toggle(event: Event) {
		const target = event.currentTarget as HTMLInputElement;
		telemetry.setOptIn(target.checked);
	}

	function toggleLogging(event: Event) {
		const target = event.currentTarget as HTMLInputElement;
		logging.setEnabled(target.checked);
	}

	run(() => {
		if (!$asyncSettings.loading && !$asyncSettings.saving && !formDirty) {
			formState = {
				maxAttempts: $asyncSettings.maxAttempts,
				baseDelaySeconds: $asyncSettings.baseDelaySeconds,
				maxDelaySeconds: $asyncSettings.maxDelaySeconds
			};
		}
	});

	function handleInput(event: Event) {
		formDirty = true;
		const target = event.currentTarget as HTMLInputElement;
		const value = Number.parseInt(target.value, 10);
		formState = { ...formState, [target.name]: Number.isNaN(value) ? 0 : value };
	}

	async function saveAsyncSettings(event: SubmitEvent) {
		event.preventDefault();
		const payload = {
			maxAttempts: formState.maxAttempts,
			baseDelaySeconds: formState.baseDelaySeconds,
			maxDelaySeconds: formState.maxDelaySeconds
		};

		const result = await asyncSettings.save(payload);
		if (result) {
			formDirty = false;
		}
	}

	let showClearConfirm = $state(false);

	async function purgeStaleJobs() {
		const result = await asyncHealth.purge({ status: 'queued,failed', olderThan: 10080 });
		if (result) {
			console.log('Purged stale jobs:', result);
		}
	}

	async function clearAllJobs() {
		const result = await asyncHealth.purge({ clearAll: true });
		if (result) {
			console.log('Cleared all jobs:', result);
		}
		showClearConfirm = false;
	}
</script>

<section class="sf:space-y-6 sf:max-w-3xl">
	<header class="sf:space-y-2">
		<h1 class="sf:text-2xl sf:font-semibold sf:text-slate-900">Telemetry &amp; Async Processing</h1>
		<p class="sf:text-slate-600 sf:text-sm">
			Control telemetry consent and tune the async retry queue for Sentient Forms.
		</p>
	</header>

	{#if $asyncHealth.warnings.length}
		<div class="sf:rounded-xl sf:border sf:border-amber-200 sf:bg-amber-50 sf:p-4 sf:space-y-2">
			<div class="sf:flex sf:items-center sf:justify-between">
				<p class="sf:font-semibold sf:text-amber-900">Async warnings</p>
				<Button
					type="button"
					variant="ghost"
					size="sm"
					class="sf:h-auto sf:px-1 sf:py-0 sf:text-amber-900 sf:underline"
					onclick={() => asyncHealth.refresh()}
				>
					Refresh
				</Button>
			</div>
			<ul class="sf:space-y-1">
				{#each $asyncHealth.warnings as warning}
					<li class="sf:text-sm sf:text-amber-900">
						<strong>{warning.code}</strong>: {warning.message}
					</li>
				{/each}
			</ul>
		</div>
	{/if}

	<div class="sf:rounded-xl sf:border sf:border-slate-200 sf:bg-white sf:p-6 sf:shadow-sm">
		<div class="sf:flex sf:items-center sf:justify-between">
			<div>
				<p class="sf:font-medium sf:text-slate-900">Enable telemetry sharing</p>
				<p class="sf:text-sm sf:text-slate-600">
					Share aggregated action metrics and CPS diagnostics to help Sentient Forms improve
					reliability.
				</p>
			</div>
			<label class="sf:flex sf:items-center sf:gap-3">
				<span class="sf:text-sm sf:font-semibold">{$telemetry.optIn ? 'On' : 'Off'}</span>
				<input
					type="checkbox"
					class="sf:h-5 sf:w-5"
					checked={$telemetry.optIn}
					disabled={$telemetry.saving}
					onchange={toggle}
				/>
			</label>
		</div>

		<div class="sf:mt-4 sf:text-xs sf:text-slate-500 sf:space-y-1">
			{#if $telemetry.syncedAt}
				<p>Synced {$telemetry.syncedAt}</p>
			{/if}
			{#if $telemetry.remoteUpdatedAt}
				<p>Recorded by CPS {$telemetry.remoteUpdatedAt}</p>
			{/if}
			{#if $telemetry.lastError}
				<p class="sf:text-red-600">Last sync error: {$telemetry.lastError}</p>
			{/if}
		</div>
	</div>

	<div
		class="sf:rounded-xl sf:border sf:border-slate-200 sf:bg-white sf:p-6 sf:shadow-sm sf:space-y-3"
	>
		<div class="sf:flex sf:items-center sf:justify-between">
			<div>
				<p class="sf:font-medium sf:text-slate-900">Enable on-site logging</p>
				<p class="sf:text-sm sf:text-slate-600">
					Write masked diagnostic logs to <code>wp-content/uploads/sentient-forms/logs</code> for support.
				</p>
			</div>
			<label class="sf:flex sf:items-center sf:gap-3">
				<span class="sf:text-sm sf:font-semibold">{$logging.enabled ? 'On' : 'Off'}</span>
				<input
					type="checkbox"
					class="sf:h-5 sf:w-5"
					checked={$logging.enabled}
					disabled={$logging.saving}
					onchange={toggleLogging}
				/>
			</label>
		</div>
		{#if $logging.lastError}
			<p class="sf:text-xs sf:text-red-600">{$logging.lastError}</p>
		{/if}
	</div>

	<div class="sf:rounded-xl sf:border sf:border-slate-200 sf:bg-white sf:p-6 sf:shadow-sm sf:space-y-4">
		<div class="sf:space-y-1">
			<p class="sf:font-medium sf:text-slate-900">Execution controls</p>
			<p class="sf:text-sm sf:text-slate-600">
				Pause Sentient Forms execution globally or by form provider while keeping mappings editable.
			</p>
		</div>

		<div class="sf:flex sf:items-center sf:justify-between sf:p-3 sf:bg-slate-50 sf:rounded-lg">
			<div>
				<p class="sf:text-sm sf:font-medium sf:text-slate-700">Global execution</p>
				<p class="sf:text-xs sf:text-slate-500">Stops all providers when enabled.</p>
			</div>
			<label class="sf:flex sf:items-center sf:gap-3">
				<span class="sf:text-sm sf:font-semibold">{executionGlobalDisabled ? 'Paused' : 'Active'}</span>
				<input
					type="checkbox"
					class="sf:h-5 sf:w-5"
					checked={!executionGlobalDisabled}
					disabled={executionSaving || executionLoading}
					onchange={(event) =>
						toggleExecutionGlobal(!(event.currentTarget as HTMLInputElement).checked)}
				/>
			</label>
		</div>

		{#if formSources.length > 0}
			<div class="sf:space-y-2">
				{#each formSources as source}
					<div class="sf:flex sf:items-center sf:justify-between sf:p-3 sf:border sf:border-slate-200 sf:rounded-lg">
						<div class="sf:flex sf:items-center sf:gap-2">
							<p class="sf:text-sm sf:text-slate-800">{source.label}</p>
							<span class="sf:text-xs sf:text-slate-500">
								{source.isActive ? 'Plugin active' : 'Plugin inactive'}
							</span>
						</div>
						<label class="sf:flex sf:items-center sf:gap-3">
							<span class="sf:text-sm sf:font-semibold">
								{executionProviderDisabled[source.slug] ? 'Paused' : 'Active'}
							</span>
							<input
								type="checkbox"
								class="sf:h-5 sf:w-5"
								checked={!executionProviderDisabled[source.slug]}
								disabled={executionSaving || executionLoading || !source.isActive}
								onchange={(event) =>
									toggleExecutionProvider(
										source.slug,
										!(event.currentTarget as HTMLInputElement).checked
									)}
							/>
						</label>
					</div>
				{/each}
			</div>
		{/if}
	</div>

	<div
		class="sf:rounded-xl sf:border sf:border-slate-200 sf:bg-white sf:p-6 sf:shadow-sm sf:space-y-4"
	>
		<div class="sf:space-y-1">
			<p class="sf:font-medium sf:text-slate-900">Async retry policy</p>
			<p class="sf:text-sm sf:text-slate-600">
				Configure how many times Sentient Forms retries async jobs and how long it waits between
				attempts.
			</p>
		</div>

		<form class="sf:space-y-4" onsubmit={saveAsyncSettings}>
			<div class="sf:grid sf:grid-cols-1 sf:md:grid-cols-3 sf:gap-4">
				<label class="sf:flex sf:flex-col sf:gap-1">
					<span class="sf:text-sm sf:font-medium sf:text-slate-900">Max attempts</span>
					<input
						name="maxAttempts"
						type="number"
						min="1"
						class="sf:rounded-lg sf:border sf:border-slate-300 sf:px-3 sf:py-2"
						value={formState.maxAttempts}
						oninput={handleInput}
					/>
				</label>

				<label class="sf:flex sf:flex-col sf:gap-1">
					<span class="sf:text-sm sf:font-medium sf:text-slate-900">Base delay (seconds)</span>
					<input
						name="baseDelaySeconds"
						type="number"
						min="5"
						class="sf:rounded-lg sf:border sf:border-slate-300 sf:px-3 sf:py-2"
						value={formState.baseDelaySeconds}
						oninput={handleInput}
					/>
				</label>

				<label class="sf:flex sf:flex-col sf:gap-1">
					<span class="sf:text-sm sf:font-medium sf:text-slate-900">Max delay (seconds)</span>
					<input
						name="maxDelaySeconds"
						type="number"
						min={formState.baseDelaySeconds}
						class="sf:rounded-lg sf:border sf:border-slate-300 sf:px-3 sf:py-2"
						value={formState.maxDelaySeconds}
						oninput={handleInput}
					/>
				</label>
			</div>

			<div class="sf:flex sf:items-center sf:gap-4">
				<Button type="submit" disabled={$asyncSettings.saving}>
					{$asyncSettings.saving ? 'Saving…' : 'Save settings'}
				</Button>
				{#if formDirty}
					<span class="sf:text-xs sf:text-slate-500">You have unsaved changes.</span>
				{/if}
			</div>
		</form>

		<div class="sf:text-xs sf:text-slate-500 sf:space-y-1">
			{#if $asyncSettings.updatedAt}
				<p>Last updated {$asyncSettings.updatedAt}</p>
			{/if}
			{#if $asyncSettings.updatedBy}
				<p>Updated by {$asyncSettings.updatedBy}</p>
			{/if}
			{#if $asyncSettings.lastError}
				<p class="sf:text-red-600">{$asyncSettings.lastError}</p>
			{/if}
		</div>
	</div>

	<div
		class="sf:rounded-xl sf:border sf:border-slate-200 sf:bg-white sf:p-6 sf:shadow-sm sf:space-y-4"
	>
		<div class="sf:space-y-1">
			<p class="sf:font-medium sf:text-slate-900">Queue maintenance</p>
			<p class="sf:text-sm sf:text-slate-600">
				Clear stale or stuck jobs from the async processing queue.
			</p>
		</div>

		<div class="sf:flex sf:flex-col sf:gap-3">
			<div class="sf:flex sf:items-center sf:justify-between sf:p-3 sf:bg-slate-50 sf:rounded-lg">
				<div>
					<p class="sf:text-sm sf:font-medium sf:text-slate-700">Queue depth</p>
					<p class="sf:text-xl sf:font-semibold sf:text-slate-900">{$asyncHealth.queue_depth}</p>
				</div>
				{#if $asyncHealth.oldest_run_at}
					<div class="sf:text-right">
						<p class="sf:text-sm sf:font-medium sf:text-slate-700">Oldest job</p>
						<p class="sf:text-sm sf:text-slate-600">
							{new Date($asyncHealth.oldest_run_at * 1000).toLocaleDateString()}
						</p>
					</div>
				{/if}
			</div>

			{#if $asyncHealth.lastPurgeResult}
				<div class="sf:text-sm sf:text-green-700 sf:bg-green-50 sf:p-2 sf:rounded-lg">
					✓ {$asyncHealth.lastPurgeResult.message}
				</div>
			{/if}

			<div class="sf:flex sf:gap-3">
				<Button
					type="button"
					variant="secondary"
					class="sf:border-amber-500 sf:bg-amber-50 sf:text-amber-900 hover:sf:bg-amber-100"
					disabled={$asyncHealth.purging || $asyncHealth.queue_depth === 0}
					onclick={purgeStaleJobs}
				>
					{$asyncHealth.purging ? 'Purging…' : 'Purge stale jobs'}
				</Button>
				<Button
					type="button"
					variant="danger"
					disabled={$asyncHealth.purging || $asyncHealth.queue_depth === 0}
					onclick={() => (showClearConfirm = true)}
				>
					Clear all
				</Button>
			</div>

			<p class="sf:text-xs sf:text-slate-500">
				"Purge stale jobs" removes queued/failed jobs older than 1 week. "Clear all" removes all job
				metadata.
			</p>
		</div>
	</div>

	{#if showClearConfirm}
		<div
			class="sf:fixed sf:inset-0 sf:bg-black/50 sf:flex sf:items-center sf:justify-center sf:z-50"
		>
			<div class="sf:bg-white sf:rounded-xl sf:p-6 sf:max-w-sm sf:space-y-4 sf:shadow-xl">
				<p class="sf:font-semibold sf:text-slate-900">Clear all job metadata?</p>
				<p class="sf:text-sm sf:text-slate-600">
					This will remove all tracked async jobs, including successful ones. This action cannot be
					undone.
				</p>
				<div class="sf:flex sf:gap-3 sf:justify-end">
					<Button type="button" variant="secondary" onclick={() => (showClearConfirm = false)}>
						Cancel
					</Button>
					<Button
						type="button"
						variant="danger"
						disabled={$asyncHealth.purging}
						onclick={clearAllJobs}
					>
						{$asyncHealth.purging ? 'Clearing…' : 'Clear all'}
					</Button>
				</div>
			</div>
		</div>
	{/if}
</section>
