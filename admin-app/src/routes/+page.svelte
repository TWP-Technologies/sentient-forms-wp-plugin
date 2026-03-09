<script lang="ts">
	import { onMount } from 'svelte';
	import { navigateToAppPath, readHashPathFromLocation, routerType } from '$lib/navigation';

	const fallbackPath = '/dashboard';

	onMount(() => {
		const targetPath =
			routerType === 'hash'
				? (() => {
					const hashPath = readHashPathFromLocation();
					return hashPath === '/' ? fallbackPath : hashPath;
				})()
				: fallbackPath;

		void navigateToAppPath(targetPath, {
			replaceState: true,
			noScroll: true,
			keepFocus: true
		});
	});
</script>

<section class="sf:flex sf:min-h-screen sf:items-center sf:justify-center sf:bg-slate-100 sf:px-4">
	<p class="sf:text-sm sf:text-slate-600">Loading Sentient Forms…</p>
</section>
