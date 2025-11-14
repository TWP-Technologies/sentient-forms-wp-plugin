<script lang="ts">
	import { licenseSummary, sessionStore } from '$lib/stores/session';
	import { Button, Card, Section, Badge } from '$lib/components/ui';
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

<Section heading="Dashboard" description="High-level health of Sentient Forms automation.">
	<Button slot="actions" variant="secondary" onclick={() => sessionStore.reset?.()}>
		Refresh
	</Button>

	<div class="sf-grid sf-gap-4 md:sf-grid-cols-3">
		<Card>
			<div class="sf-space-y-1">
				<h3 class="sf-text-sm sf-font-medium sf-text-slate-500">License status</h3>
				<p class="sf-text-lg sf-font-semibold">{$licenseSummary}</p>
				<Badge variant={$licenseSummary === 'License active' ? 'success' : 'warning'}>
					{$licenseSummary}
				</Badge>
			</div>
		</Card>
		<Card>
			<h3 class="sf-text-sm sf-font-medium sf-text-slate-500">Credits remaining</h3>
			<p class="sf-mt-2 sf-text-lg sf-font-semibold">
				{$sessionStore.creditsRemaining ?? '—'}
			</p>
		</Card>
		<Card>
			<h3 class="sf-text-sm sf-font-medium sf-text-slate-500">Last sync</h3>
			<p class="sf-mt-2 sf-text-lg sf-font-semibold">
				{$sessionStore.lastSync ? new Date($sessionStore.lastSync).toLocaleString() : '—'}
			</p>
		</Card>
	</div>
</Section>
