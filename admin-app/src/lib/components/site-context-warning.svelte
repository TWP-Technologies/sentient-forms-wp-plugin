<script lang="ts">
	import { Alert } from '$lib/components/ui';
	import { parseSiteContextStatusResponse } from '$lib/schemas/site-context';
	import { wpRequestEndpoint } from '$lib/wp';
	import { normalizeSiteContextResponse, siteContextWarningMessage } from '$lib/utils/site-context';
	import type { SiteContextStatusResponse } from '$lib/api/types';
	import { onMount } from 'svelte';

	interface Props {
		includeMode?: string | null;
		class?: string;
	}

	let { includeMode = 'global', class: className = '' }: Props = $props();

	let status = $state<SiteContextStatusResponse | null>(null);
	let loading = $state(false);
	let loadFailed = $state(false);

	const message = $derived(status ? siteContextWarningMessage(status, includeMode) : null);

	async function loadStatus(): Promise<void> {
		loading = true;
		loadFailed = false;
		try {
			status = parseSiteContextStatusResponse(
				normalizeSiteContextResponse(await wpRequestEndpoint('siteContext.read'))
			);
		} catch (error) {
			console.error('Failed to load Site Context status', error);
			loadFailed = true;
		} finally {
			loading = false;
		}
	}

	onMount(() => {
		void loadStatus();
	});
</script>

{#if message}
	<Alert
		variant={status?.settings.consent_status === 'declined' ? 'info' : 'warning'}
		class={`sf:py-3 ${className}`}
		data-testid="site-context-action-warning"
	>
		<div
			class="sf:flex sf:flex-col sf:gap-1 sf:sm:flex-row sf:sm:items-start sf:sm:justify-between"
		>
			<p>{message}</p>
			<a
				href="#/settings/context"
				class="sf:shrink-0 sf:font-semibold sf:underline sf:underline-offset-2"
			>
				Review Site Context
			</a>
		</div>
	</Alert>
{:else if loadFailed}
	<p class={`sf:text-xs sf:text-slate-500 ${className}`} data-testid="site-context-warning-error">
		Site Context status could not be checked.
	</p>
{:else if loading}
	<p class={`sf:text-xs sf:text-slate-500 ${className}`} data-testid="site-context-warning-loading">
		Checking Site Context status…
	</p>
{/if}
