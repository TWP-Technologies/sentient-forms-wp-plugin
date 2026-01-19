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
	import { wpFetch } from '$lib/wp';
	import type { CreditBalanceResponse } from '$lib/api/types';

	let licenseKey = $state('');
	let issues: ValidationIssue[] = $state([]);
	let credits = $state<CreditBalanceResponse | null>(null);
	let creditsLoading = $state(false);

	onMount(() => {
		licenseStore.load();
		fetchCredits();
	});

	async function fetchCredits() {
		creditsLoading = true;
		try {
			credits = await wpFetch<CreditBalanceResponse>('credits/balance');
		} catch (e) {
			console.error('Failed to fetch credits', e);
		} finally {
			creditsLoading = false;
		}
	}

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
	heading={$licenseStore.status === 'active' ? 'License management' : 'License activation'}
	description={$licenseStore.status === 'active'
		? 'Your Sentient Forms license is active. Manage your subscription below.'
		: 'Provide your Sentient Forms license key to enable CPS-backed automations.'}
>
	<ValidationSummary {issues} />

	{#if $licenseStore.status !== 'active'}
		<Card>
			<form class="sf:space-y-4" onsubmit={handleActivate}>
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
	{/if}

	<!-- Credits Card (CB-LIC-002) -->
	{#if $licenseStore.status === 'active'}
		<Card title="Credits">
			<div class="sf:space-y-3">
				{#if creditsLoading}
					<p class="sf:text-slate-400">Loading credits...</p>
				{:else if credits}
					{@const quota = credits.tier?.monthly_credit_quota ?? 100}
					{@const balance = credits.current_balance}
					{@const percentage = Math.min(100, Math.round((balance / quota) * 100))}

					<div class="sf:flex sf:items-center sf:justify-between sf:text-sm">
						<span class="sf:font-medium sf:text-slate-600">Credits remaining</span>
						<span class="sf:font-semibold sf:text-slate-900">
							{balance} / {quota}
						</span>
					</div>

					<!-- Progress bar -->
					<div class="sf:w-full sf:bg-slate-200 sf:rounded-full sf:h-2.5">
						<div
							class="sf:h-2.5 sf:rounded-full {percentage > 20
								? 'sf:bg-emerald-500'
								: 'sf:bg-amber-500'}"
							style="width: {percentage}%"
						></div>
					</div>

					<p class="sf:text-xs sf:text-slate-500">
						Credits reset on the 1st of each month.
						{credits.tier?.display_name ? ` Tier: ${credits.tier.display_name}` : ''}
					</p>

					{#if percentage <= 10 && percentage > 0}
						<Alert variant="warning">
							Low credits remaining. Consider upgrading your plan to avoid interruptions.
						</Alert>
					{:else if percentage === 0}
						<Alert variant="danger">
							No credits remaining. Actions will not execute until credits reset or you upgrade.
						</Alert>
					{/if}
				{:else}
					<p class="sf:text-slate-500">Unable to load credit balance.</p>
				{/if}
			</div>
		</Card>
	{/if}

	<Card title="Status">
		<div class="sf:text-sm sf:space-y-2">
			<div class="sf:flex sf:items-center sf:justify-between">
				<span class="sf:font-medium sf:text-slate-600">License</span>
				<Badge
					variant={$licenseStore.status === 'active'
						? 'success'
						: $licenseStore.status === 'error'
							? 'danger'
							: 'warning'}
				>
					{$licenseStore.status}
				</Badge>
			</div>
			<div class="sf:flex sf:items-center sf:justify-between">
				<span class="sf:font-medium sf:text-slate-600">Proxy key stored</span>
				<span class="sf:text-slate-900 sf:font-semibold">
					{$licenseStore.proxyKeyPresent ? 'Yes' : 'No'}
				</span>
			</div>
			<div class="sf:flex sf:items-center sf:justify-between">
				<span class="sf:font-medium sf:text-slate-600">Tier</span>
				<span class="sf:text-slate-900">{$licenseStore.tier ?? '—'}</span>
			</div>
			<div class="sf:flex sf:items-center sf:justify-between">
				<span class="sf:font-medium sf:text-slate-600">Expires</span>
				<span class="sf:text-slate-900">{$licenseStore.expiresAt ?? '—'}</span>
			</div>
			<div class="sf:flex sf:items-center sf:justify-between">
				<span class="sf:font-medium sf:text-slate-600">Last synced</span>
				<span class="sf:text-slate-900">{$licenseStore.lastSynced ?? '—'}</span>
			</div>
		</div>

		{#if $licenseStore.loading}
			<Alert variant="info" class="sf:mt-4">Activating license… this may take a few seconds.</Alert>
		{/if}

		{#if $licenseStore.status === 'active'}
			<Button
				variant="secondary"
				class="sf:mt-4"
				disabled={$licenseStore.loading}
				onclick={() => licenseStore.deactivate()}
			>
				{$licenseStore.loading ? 'Processing…' : 'Deactivate license'}
			</Button>
		{/if}
	</Card>
</Section>
