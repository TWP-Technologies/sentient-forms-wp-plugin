<script lang="ts">
	import { onMount } from 'svelte';
	import {
		Section,
		Card,
		Button,
		Alert,
		Badge,
		InputField,
		ValidationSummary
	} from '$lib/components/ui';
	import type { ValidationIssue } from '$lib/components/ui/types';
	import { licenseStore } from '$lib/stores/license';

	let licenseKey = '';
	let issues: ValidationIssue[] = [];

	onMount(() => {
		licenseStore.load();
	});

async function handleActivate(event: SubmitEvent) {
	event.preventDefault();
	issues = [];

	if (!licenseKey.trim()) {
		issues = [{ id: 'license-key', message: 'Enter your license key' }];
			return;
		}

		await licenseStore.activate(licenseKey.trim());
		licenseKey = '';
	}
</script>


<Section
	heading="License activation"
	description="Provide your Sentient Forms license key to enable CPS-backed automations."
>
	<ValidationSummary {issues} />
	<Card>
		<form class="sf-space-y-4" onsubmit={handleActivate}>
			<InputField
				id="license-key"
				bind:value={licenseKey}
				label="License key"
				placeholder="LIC-XXXX-XXXX-XXXX"
				required
				error={issues.find((issue) => issue.id === 'license-key')?.message ?? null}
			/>
			<InputField
				id="site-url"
				value={$licenseStore.siteUrl}
				label="Site URL"
				type="url"
				placeholder={$licenseStore.siteUrl}
				disabled
			/>
			<Button type="submit" disabled={$licenseStore.loading}>
				{$licenseStore.loading ? 'Processing…' : 'Activate'}
			</Button>
		</form>
	</Card>

	<Card title="Status">
		<div class="sf-text-sm sf-space-y-2">
			<div class="sf-flex sf-items-center sf-justify-between">
				<span class="sf-font-medium sf-text-slate-600">License</span>
				<Badge variant={$licenseStore.status === 'active' ? 'success' : $licenseStore.status === 'error' ? 'danger' : 'warning'}>
					{$licenseStore.status}
				</Badge>
			</div>
			<div class="sf-flex sf-items-center sf-justify-between">
				<span class="sf-font-medium sf-text-slate-600">Proxy key stored</span>
				<span class="sf-text-slate-900 sf-font-semibold">
					{$licenseStore.proxyKeyPresent ? 'Yes' : 'No'}
				</span>
			</div>
			<div class="sf-flex sf-items-center sf-justify-between">
				<span class="sf-font-medium sf-text-slate-600">Tier</span>
				<span class="sf-text-slate-900">{$licenseStore.tier ?? '—'}</span>
			</div>
			<div class="sf-flex sf-items-center sf-justify-between">
				<span class="sf-font-medium sf-text-slate-600">Expires</span>
				<span class="sf-text-slate-900">{$licenseStore.expiresAt ?? '—'}</span>
			</div>
			<div class="sf-flex sf-items-center sf-justify-between">
				<span class="sf-font-medium sf-text-slate-600">Last synced</span>
				<span class="sf-text-slate-900">{$licenseStore.lastSynced ?? '—'}</span>
			</div>
		</div>

		{#if $licenseStore.loading}
			<Alert variant="info" class="sf-mt-4">
				Activating license… this may take a few seconds.
			</Alert>
		{/if}

		{#if $licenseStore.status === 'active'}
			<Button
				variant="secondary"
				class="sf-mt-4"
				disabled={$licenseStore.loading}
				onclick={() => licenseStore.deactivate()}
			>
				{$licenseStore.loading ? 'Processing…' : 'Deactivate license'}
			</Button>
		{/if}
	</Card>
</Section>
