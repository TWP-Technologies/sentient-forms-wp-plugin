<script lang="ts">
	import { page } from '$app/stores';
	import { derived } from 'svelte/store';
	import { onMount } from 'svelte';
	import { appHref, deriveActivePath, routerType } from '$lib/navigation';
	interface Props {
		children?: import('svelte').Snippet;
	}

	let { children }: Props = $props();

	const activePath = derived(page, ($page) => deriveActivePath($page.url));

	const links = [
		{ path: '/dashboard', label: 'Dashboard' },
		{ path: '/licensing', label: 'Licensing' },
		{ path: '/actions', label: 'Actions' },
		{ path: '/actions/log', label: 'Action Log' },
		{ path: '/actions/custom', label: 'Custom Actions' },
		{ path: '/settings', label: 'Settings' }
	];

	onMount(() => {
		if (routerType === 'hash' && typeof window !== 'undefined' && window.location.hash === '') {
			window.location.replace(appHref('/dashboard'));
		}
	});
</script>

<div class="sf:min-h-screen sf:bg-slate-100 sf:text-slate-900 sf:font-sans">
	<div class="sf:flex sf:min-h-screen sf:flex-col sf:md:flex-row">
		<aside class="sf:w-full sf:bg-white sf:md:w-64 sf:shadow-sm">
			<div class="sf:p-6 sf:border-b sf:border-slate-200">
				<h1 class="sf:text-lg sf:font-semibold">Sentient Forms</h1>
				<p class="sf:text-sm sf:text-slate-500">LLM-powered form automation</p>
			</div>
			<nav class="sf:p-4 sf:flex sf:flex-col sf:gap-2">
				{#each links as link}
					<a
						class="sf:rounded sf:px-3 sf:py-2 sf:text-sm sf:font-medium sf:transition-all sf:hover:bg-slate-100"
						class:sf-bg-slate-200={$activePath === link.path}
						class:sf-text-slate-900={$activePath === link.path}
						href={appHref(link.path)}
					>
						{link.label}
					</a>
				{/each}
			</nav>
		</aside>
		<main class="sf:flex-1 sf:p-6 sf:bg-white sf:shadow-inner">
			{@render children?.()}
		</main>
	</div>
</div>
