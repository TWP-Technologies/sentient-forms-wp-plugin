<script lang="ts">
	import { page } from '$app/state';
	import { onMount } from 'svelte';
	import {
		appHref,
		deriveActivePath,
		navigateToAppPath,
		readHashPathFromLocation,
		resolveActiveNavPath,
		routerType,
		type NavigationLinkPath
	} from '$lib/navigation';
	import { Button } from '$lib/components/ui';
	interface Props {
		children?: import('svelte').Snippet;
	}

	let { children }: Props = $props();

	const links: Array<{ path: NavigationLinkPath; label: string }> = [
		{ path: '/dashboard', label: 'Dashboard' },
		{ path: '/licensing', label: 'Licensing' },
		{ path: '/actions', label: 'Actions' },
		{ path: '/actions/log', label: 'Action Log' },
		{ path: '/actions/custom', label: 'Custom Actions' },
		{ path: '/settings', label: 'Settings' }
	];

	let renderedPath = $derived(deriveActivePath(page.url));
	let activePath = $derived(resolveActiveNavPath(renderedPath));
	let mismatchKey = $state<string | null>(null);
	let softRepairAttempted = $state(false);
	let hardRepairAttempted = $state(false);

	function handleNavClick(event: MouseEvent, path: NavigationLinkPath): void {
		if (event.defaultPrevented || event.button !== 0) return;
		if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
		event.preventDefault();
		void navigateToAppPath(path);
	}

	$effect(() => {
		if (routerType !== 'hash' || typeof window === 'undefined') return;

		const hashPath = readHashPathFromLocation();
		if (hashPath === renderedPath) {
			mismatchKey = null;
			softRepairAttempted = false;
			hardRepairAttempted = false;
			return;
		}

		const nextKey = `${hashPath}=>${renderedPath}`;
		if (mismatchKey !== nextKey) {
			mismatchKey = nextKey;
			softRepairAttempted = false;
			hardRepairAttempted = false;
		}

		if (!softRepairAttempted) {
			softRepairAttempted = true;
			void navigateToAppPath(hashPath, { replaceState: true, noScroll: true, keepFocus: true });
			return;
		}

		if (!hardRepairAttempted) {
			hardRepairAttempted = true;
			window.location.replace(appHref(hashPath));
		}
	});

	onMount(() => {
		if (routerType === 'hash' && typeof window !== 'undefined' && window.location.hash === '') {
			void navigateToAppPath('/dashboard', { replaceState: true, noScroll: true, keepFocus: true });
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
							class:sf-bg-slate-200={activePath === link.path}
							class:sf-text-slate-900={activePath === link.path}
							href={appHref(link.path)}
							data-nav-path={link.path}
							onclick={(event) => handleNavClick(event, link.path)}
						>
							{link.label}
						</a>
					{/each}
			</nav>
		</aside>
			<main class="sf:flex-1 sf:p-6 sf:bg-white sf:shadow-inner">
				<svelte:boundary>
					{@render children?.()}

					{#snippet failed(error, reset)}
						<section
							class="sf:rounded sf:border sf:border-rose-300 sf:bg-rose-50 sf:p-4 sf:space-y-2"
							data-testid="route-boundary-error"
						>
							<h2 class="sf:text-base sf:font-semibold sf:text-rose-900">This view hit an error</h2>
							<p class="sf:text-sm sf:text-rose-800">{String(error)}</p>
							<Button type="button" variant="danger" size="sm" onclick={reset}>Retry view</Button>
						</section>
					{/snippet}
				</svelte:boundary>
			</main>
		</div>
	</div>
