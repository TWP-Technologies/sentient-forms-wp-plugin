<script lang="ts">
	import { onMount } from 'svelte';
	import { telemetryStore } from '$lib/stores/telemetry.svelte';

	const telemetry = telemetryStore;

	onMount(() => {
		telemetry.load();
	});

	function toggle(event: Event) {
		const target = event.currentTarget as HTMLInputElement;
		telemetry.setOptIn(target.checked);
	}
</script>

<section class="sf-space-y-6 sf-max-w-3xl">
	<header class="sf-space-y-2">
		<h1 class="sf-text-2xl sf-font-semibold sf-text-slate-900">Telemetry &amp; Privacy</h1>
		<p class="sf-text-slate-600 sf-text-sm">
			Control whether Sentient Forms sends anonymized usage telemetry (action counts, health signals) to Total Web Partners.
			Telemetry never includes form entries or visitor identifiers and remains off by default.
		</p>
	</header>

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
</section>
