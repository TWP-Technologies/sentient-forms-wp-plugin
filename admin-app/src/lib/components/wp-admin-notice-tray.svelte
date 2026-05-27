<script lang="ts">
	import { onMount } from 'svelte';
	import {
		countCollectedWpAdminNotices,
		relocateWpAdminNotices
	} from '$lib/utils/wp-admin-notices';
	import Button from '$lib/components/ui/button.svelte';

	let trayContent: HTMLDivElement;
	let noticeCount = $state(0);
	let expanded = $state(false);

	function resolveAppRoot(): HTMLElement | null {
		const wordpressRoot = document.getElementById('sentient-forms-admin-app');
		if (wordpressRoot instanceof HTMLElement) return wordpressRoot;
		const shell = document.querySelector('[data-sentient-admin-shell]');
		return shell instanceof HTMLElement ? shell : null;
	}

	function updateNoticeCount(): void {
		if (!trayContent) return;
		noticeCount = countCollectedWpAdminNotices(trayContent);
		if (noticeCount === 0) {
			expanded = false;
		}
	}

	function syncNotices(): void {
		if (!trayContent || typeof document === 'undefined') return;
		const appRoot = resolveAppRoot();
		if (!appRoot) return;
		noticeCount = relocateWpAdminNotices(appRoot, trayContent);
		if (noticeCount === 0) {
			expanded = false;
		}
	}

	onMount(() => {
		const appRoot = resolveAppRoot();
		if (!appRoot) return;

		let scheduled = false;
		const scheduleSync = () => {
			if (scheduled) return;
			scheduled = true;
			queueMicrotask(() => {
				scheduled = false;
				syncNotices();
			});
		};

		const observer = new MutationObserver(scheduleSync);
		observer.observe(appRoot, { childList: true, subtree: true });
		if (appRoot.parentElement) {
			observer.observe(appRoot.parentElement, { childList: true, subtree: true });
		}

		const handleClick = () => {
			window.setTimeout(updateNoticeCount, 0);
		};
		trayContent.addEventListener('click', handleClick);

		syncNotices();

		return () => {
			observer.disconnect();
			trayContent.removeEventListener('click', handleClick);
		};
	});
</script>

<section
	data-sentient-wp-notice-tray
	data-testid="wp-notice-tray"
	class:sf:hidden={noticeCount === 0}
	class="sf:border-b sf:border-slate-200 sf:bg-white"
	aria-label="WordPress admin notices"
>
	<div class="sf:mx-auto sf:flex sf:w-full sf:max-w-[112rem] sf:flex-col sf:gap-3 sf:px-4 sf:py-3 sf:sm:px-6">
		<Button
			data-testid="wp-notice-tray-toggle"
			variant="ghost"
			size="sm"
			class="sf:w-fit"
			aria-expanded={expanded}
			onclick={() => {
				expanded = !expanded;
			}}
		>
			<span>{expanded ? 'Hide' : 'Show'} WordPress notices</span>
			<span class="sf:rounded sf:bg-slate-200 sf:px-2 sf:py-0.5 sf:text-xs sf:text-slate-700">
				{noticeCount}
			</span>
		</Button>
		<div
			bind:this={trayContent}
			data-testid="wp-notice-tray-content"
			class="sf:space-y-2"
			hidden={!expanded}
		></div>
	</div>
</section>
