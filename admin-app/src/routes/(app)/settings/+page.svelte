<script lang="ts">
	import { onMount } from 'svelte';
	import { telemetryStore } from '$lib/stores/telemetry.svelte';
	import { asyncSettingsStore } from '$lib/stores/async-settings.svelte';
	import { asyncHealthStore } from '$lib/stores/async-health.svelte';

	const telemetry = telemetryStore;
	const asyncSettings = asyncSettingsStore;
	const asyncHealth = asyncHealthStore;

	let formDirty = false;
	let formState = {
		maxAttempts: 3,
		baseDelaySeconds: 60,
		maxDelaySeconds: 3600
	};

	onMount(() => {
		telemetry.load();
		asyncSettings.load();
		asyncHealth.refresh();
	});

	function toggle(event: Event) {
		const target = event.currentTarget as HTMLInputElement;
		telemetry.setOptIn(target.checked);
	}

	$: if (!$asyncSettings.loading && !$asyncSettings.saving && !formDirty) {
		formState = {
			maxAttempts: $asyncSettings.maxAttempts,
			baseDelaySeconds: $asyncSettings.baseDelaySeconds,
			maxDelaySeconds: $asyncSettings.maxDelaySeconds
		};
	}

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
</script>

<section class="sf-space-y-6 sf-max-w-3xl">
	<header class="sf-space-y-2">
		<h1 class="sf-text-2xl sf-font-semibold sf-text-slate-900">Telemetry &amp; Async Processing</h1>
		<p class="sf-text-slate-600 sf-text-sm">
			Control telemetry consent and tune the async retry queue for Sentient Forms.
		</p>
	</header>

	{#if $asyncHealth.warnings.length}
		<div class="sf-rounded-xl sf-border sf-border-amber-200 sf-bg-amber-50 sf-p-4 sf-space-y-2">
			<div class="sf-flex sf-items-center sf-justify-between">
				<p class="sf-font-semibold sf-text-amber-900">Async warnings</p>
				<button
					type="button"
					class="sf-text-xs sf-text-amber-900 sf-underline"
					onclick={() => asyncHealth.refresh()}
				>
					Refresh
				</button>
			</div>
			<ul class="sf-space-y-1">
				{#each $asyncHealth.warnings as warning}
					<li class="sf-text-sm sf-text-amber-900">
						<strong>{warning.code}</strong>: {warning.message}
					</li>
				{/each}
			</ul>
		</div>
	{/if}

	<div class="sf-rounded-xl sf-border sf-border-slate-200 sf-bg-white sf-p-6 sf-shadow-sm">
		<div class="sf-flex sf-items-center sf-justify-between">
			<div>
				<p class="sf-font-medium sf-text-slate-900">Enable telemetry sharing</p>
				<p class="sf-text-sm sf-text-slate-600">
					Share aggregated action metrics and CPS diagnostics to help Sentient Forms improve reliability.
				</p>
			</div>
			<label class="sf-flex sf-items-center sf-gap-3">
				<span class="sf-text-sm sf-font-semibold">{$telemetry.optIn ? 'On' : 'Off'}</span>
				<input
					type="checkbox"
					class="sf-h-5 sf-w-5"
					checked={$telemetry.optIn}
					disabled={$telemetry.saving}
					onchange={toggle}
				/>
			</label>
		</div>

		<div class="sf-mt-4 sf-text-xs sf-text-slate-500 sf-space-y-1">
			{#if $telemetry.syncedAt}
				<p>Synced {$telemetry.syncedAt}</p>
			{/if}
			{#if $telemetry.remoteUpdatedAt}
				<p>Recorded by CPS {$telemetry.remoteUpdatedAt}</p>
			{/if}
			{#if $telemetry.lastError}
				<p class="sf-text-red-600">Last sync error: {$telemetry.lastError}</p>
			{/if}
		</div>
	</div>

	<div class="sf-rounded-xl sf-border sf-border-slate-200 sf-bg-white sf-p-6 sf-shadow-sm sf-space-y-4">
		<div class="sf-space-y-1">
			<p class="sf-font-medium sf-text-slate-900">Async retry policy</p>
			<p class="sf-text-sm sf-text-slate-600">
				Configure how many times Sentient Forms retries async jobs and how long it waits between attempts.
			</p>
		</div>

		<form class="sf-space-y-4" onsubmit={saveAsyncSettings}>
			<div class="sf-grid sf-grid-cols-1 md:sf-grid-cols-3 sf-gap-4">
				<label class="sf-flex sf-flex-col sf-gap-1">
					<span class="sf-text-sm sf-font-medium sf-text-slate-900">Max attempts</span>
					<input
						name="maxAttempts"
						type="number"
						min="1"
						class="sf-rounded-lg sf-border sf-border-slate-300 sf-px-3 sf-py-2"
						value={formState.maxAttempts}
						oninput={handleInput}
					/>
				</label>

				<label class="sf-flex sf-flex-col sf-gap-1">
					<span class="sf-text-sm sf-font-medium sf-text-slate-900">Base delay (seconds)</span>
					<input
						name="baseDelaySeconds"
						type="number"
						min="5"
						class="sf-rounded-lg sf-border sf-border-slate-300 sf-px-3 sf-py-2"
						value={formState.baseDelaySeconds}
						oninput={handleInput}
					/>
				</label>

				<label class="sf-flex sf-flex-col sf-gap-1">
					<span class="sf-text-sm sf-font-medium sf-text-slate-900">Max delay (seconds)</span>
					<input
						name="maxDelaySeconds"
						type="number"
						min={formState.baseDelaySeconds}
						class="sf-rounded-lg sf-border sf-border-slate-300 sf-px-3 sf-py-2"
						value={formState.maxDelaySeconds}
						oninput={handleInput}
					/>
				</label>
			</div>

			<div class="sf-flex sf-items-center sf-gap-4">
				<button
					type="submit"
					class="sf-rounded-lg sf-bg-slate-900 sf-text-white sf-px-4 sf-py-2 sf-text-sm sf-font-semibold"
					disabled={$asyncSettings.saving}
				>
					{$asyncSettings.saving ? 'Saving…' : 'Save settings'}
				</button>
				{#if formDirty}
					<span class="sf-text-xs sf-text-slate-500">You have unsaved changes.</span>
				{/if}
			</div>
		</form>

		<div class="sf-text-xs sf-text-slate-500 sf-space-y-1">
			{#if $asyncSettings.updatedAt}
				<p>Last updated {$asyncSettings.updatedAt}</p>
			{/if}
			{#if $asyncSettings.updatedBy}
				<p>Updated by {$asyncSettings.updatedBy}</p>
			{/if}
			{#if $asyncSettings.lastError}
				<p class="sf-text-red-600">{$asyncSettings.lastError}</p>
			{/if}
		</div>
	</div>
</section>
