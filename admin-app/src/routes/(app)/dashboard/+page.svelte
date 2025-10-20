<script lang="ts">
	import { licenseSummary, sessionStore } from '$lib/stores/session';
	import { onMount } from 'svelte';

	onMount(() => {
		// Mock data for skeleton UI; will be replaced by real API during integration.
		sessionStore.hydrate({
			siteUrl: 'https://gravityforms.dev',
			licenseStatus: 'active',
			proxyKeyPresent: true,
			creditsRemaining: 912,
			lastSync: new Date().toISOString()
		});
	});
</script>

<section class="sf-space-y-6">
	<header class="sf-flex sf-items-center sf-justify-between">
		<div>
			<h2 class="sf-text-2xl sf-font-semibold">Dashboard</h2>
			<p class="sf-text-sm sf-text-slate-500">High-level health of Sentient Forms automation.</p>
		</div>
		<button
			class="sf-inline-flex sf-items-center sf-rounded sf-bg-slate-900 sf-px-3 sf-py-2 sf-text-sm sf-font-medium sf-text-white hover:sf-bg-slate-800"
			type="button"
		>
			Refresh
		</button>
	</header>

	<div class="sf-grid sf-gap-4 md:sf-grid-cols-3">
		<div class="sf-rounded-lg sf-border sf-border-slate-200 sf-bg-white sf-p-4">
			<h3 class="sf-text-sm sf-font-medium sf-text-slate-500">License status</h3>
			<p class="sf-mt-2 sf-text-lg sf-font-semibold">{$licenseSummary}</p>
		</div>
		<div class="sf-rounded-lg sf-border sf-border-slate-200 sf-bg-white sf-p-4">
			<h3 class="sf-text-sm sf-font-medium sf-text-slate-500">Credits remaining</h3>
			<p class="sf-mt-2 sf-text-lg sf-font-semibold">
				{$sessionStore.creditsRemaining ?? '—'}
			</p>
		</div>
		<div class="sf-rounded-lg sf-border sf-border-slate-200 sf-bg-white sf-p-4">
			<h3 class="sf-text-sm sf-font-medium sf-text-slate-500">Last sync</h3>
			<p class="sf-mt-2 sf-text-lg sf-font-semibold">
				{$sessionStore.lastSync ? new Date($sessionStore.lastSync).toLocaleString() : '—'}
			</p>
		</div>
	</div>
</section>
