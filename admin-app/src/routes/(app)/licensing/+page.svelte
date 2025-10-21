<script lang="ts">
	import { sessionStore } from '$lib/stores/session';
	import type { SessionState } from '$lib/stores/session';
	import { onMount } from 'svelte';
	import { Section, Card, Button, Alert, Badge } from '$lib/components/ui';

	let form: SessionState = {
		siteUrl: '',
		licenseStatus: 'inactive',
		proxyKeyPresent: false,
		creditsRemaining: null,
		lastSync: null
	};

	onMount(() => {
		const unsubscribe = sessionStore.subscribe((state) => {
			form = { ...state };
		});

		return () => unsubscribe();
	});

	function simulateActivate() {
		sessionStore.hydrate({
			licenseStatus: 'activating'
		});

		setTimeout(() => {
			sessionStore.hydrate({
				licenseStatus: 'active',
				proxyKeyPresent: true,
				lastSync: new Date().toISOString()
			});
		}, 500);
	}
</script>


<Section
	heading="License activation"
	description="Provide your Sentient Forms license key to enable CPS-backed automations."
>
	<Card>
		<form class="sf-space-y-4">
		<div class="sf-space-y-1">
			<label class="sf-text-sm sf-font-medium sf-text-slate-700" for="license-key">
				License key
			</label>
			<input
				class="sf-w-full sf-rounded sf-border sf-border-slate-300 sf-bg-white sf-px-3 sf-py-2 focus:sf-border-slate-500 focus:sf-outline-none"
				id="license-key"
				name="license-key"
				placeholder="LIC-XXXX-XXXX-XXXX"
				type="text"
			/>
		</div>
		<div class="sf-space-y-1">
			<label class="sf-text-sm sf-font-medium sf-text-slate-700" for="site-url">
				Site URL
			</label>
			<input
				bind:value={form.siteUrl}
				class="sf-w-full sf-rounded sf-border sf-border-slate-300 sf-bg-white sf-px-3 sf-py-2 focus:sf-border-slate-500 focus:sf-outline-none"
				id="site-url"
				name="site-url"
				placeholder="https://example.com"
				type="url"
			/>
		</div>
		<Button type="button" on:click={simulateActivate}>Activate</Button>
	</form>
	</Card>

	<Card title="Status">
		<div class="sf-text-sm sf-space-y-2">
			<div class="sf-flex sf-items-center sf-justify-between">
				<span class="sf-font-medium sf-text-slate-600">License</span>
				<Badge variant={$sessionStore.licenseStatus === 'active' ? 'success' : 'warning'}>
					{$sessionStore.licenseStatus}
				</Badge>
			</div>
			<div class="sf-flex sf-items-center sf-justify-between">
				<span class="sf-font-medium sf-text-slate-600">Proxy key stored</span>
				<span class="sf-text-slate-900 sf-font-semibold">
					{$sessionStore.proxyKeyPresent ? 'Yes' : 'No'}
				</span>
			</div>
		</div>

		{#if $sessionStore.licenseStatus === 'activating'}
			<Alert variant="info" class="sf-mt-4">
				Activating license… this may take a few seconds.
			</Alert>
		{/if}
	</Card>
</Section>
