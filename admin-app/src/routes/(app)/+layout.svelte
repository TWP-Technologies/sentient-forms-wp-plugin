<script lang="ts">
	import { page } from '$app/state';
	import { onMount } from 'svelte';
	import PrivacySetupAssistant from '$lib/components/privacy-setup-assistant.svelte';
	import { createClientFromConfig } from '$lib/api/client';
	import type { PluginSettingsResponse } from '$lib/api/types';
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
	import { notifications } from '$lib/stores/notifications';

	interface Props {
		children?: import('svelte').Snippet;
	}

	let { children }: Props = $props();
	const client = createClientFromConfig();
	const runtime = typeof window === 'undefined' ? undefined : window.sentientFormsConfig;

	const links: Array<{ path: NavigationLinkPath; label: string }> = [
		{ path: '/dashboard', label: 'Dashboard' },
		{ path: '/providers', label: 'Providers' },
		{ path: '/actions', label: 'Actions' },
		{ path: '/actions/log', label: 'Action Log' },
		{ path: '/actions/custom', label: 'Custom Actions' },
		{ path: '/licensing', label: 'Managed Billing' },
		{ path: '/settings', label: 'Settings' },
		{ path: '/settings/migration', label: 'Cutover' }
	];

	let renderedPath = $derived(deriveActivePath(page.url));
	let activePath = $derived(resolveActiveNavPath(renderedPath));
	let mismatchKey = $state<string | null>(null);
	let softRepairAttempted = $state(false);
	let hardRepairAttempted = $state(false);
	let privacySettings = $state<PluginSettingsResponse | null>(null);
	let privacyAssistantOpen = $state(false);
	let privacyAssistantSaving = $state(false);

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

	async function loadPrivacySettings(options: { openAssistantWhenIncomplete?: boolean } = {}) {
		const openAssistantWhenIncomplete = options.openAssistantWhenIncomplete ?? true;
		if (runtime?.currentUser && !runtime.currentUser.canManage) return;

		try {
			const settings = await client.getSettings({ showNotifications: false });
			privacySettings = settings;
			if (openAssistantWhenIncomplete && !settings.privacy_setup_completed_at) {
				privacyAssistantOpen = true;
			}
		} catch (error) {
			console.error('Failed to load Sentient Forms privacy settings', error);
		}
	}

	async function applyPrivacyPreset(
		profile: NonNullable<PluginSettingsResponse['privacy_setup_profile']>
	) {
		privacyAssistantSaving = true;
		try {
			const settings = await client.updateSettings(
				{ privacy_setup_profile: profile },
				{ showNotifications: false }
			);
			privacySettings = settings;
			privacyAssistantOpen = false;
			window.dispatchEvent(
				new CustomEvent('sentient-forms:settings-updated', {
					detail: settings
				})
			);
			const profileLabel =
				settings.privacy_setup_profile === 'privacy_focused'
					? 'Privacy focused'
					: settings.privacy_setup_profile === 'maximum_privacy'
						? 'Maximum privacy'
						: settings.privacy_setup_profile === 'maximum_visibility'
							? 'Maximum visibility'
							: 'Balanced';
			notifications.success(`${profileLabel} defaults saved`);
		} catch (error) {
			console.error('Failed to save privacy setup preset', error);
			notifications.error('Unable to save privacy setup');
		} finally {
			privacyAssistantSaving = false;
		}
	}

	onMount(() => {
		if (routerType === 'hash' && typeof window !== 'undefined' && window.location.hash === '') {
			void navigateToAppPath('/dashboard', { replaceState: true, noScroll: true, keepFocus: true });
		}

		void loadPrivacySettings();

		const openAssistant = () => {
			privacyAssistantOpen = true;
			if (!privacySettings) {
				void loadPrivacySettings({ openAssistantWhenIncomplete: false });
			}
		};

		window.addEventListener('sentient-forms:open-privacy-setup', openAssistant);

		return () => {
			window.removeEventListener('sentient-forms:open-privacy-setup', openAssistant);
		};
	});
</script>

<div
	data-sentient-admin-shell
	class="sf:min-w-0 sf:bg-slate-100 sf:text-slate-900 sf:font-sans"
>
	<div data-sentient-admin-frame class="sf:flex sf:min-w-0 sf:flex-col sf:md:flex-row">
		<aside class="sf:w-full sf:shrink-0 sf:bg-white sf:md:w-64 sf:shadow-sm">
			<div class="sf:p-4 sf:sm:p-6 sf:border-b sf:border-slate-200">
				<h1 class="sf:text-lg sf:font-semibold">Sentient Forms</h1>
				<p class="sf:text-sm sf:text-slate-600">Local-first AI form automation</p>
			</div>
			<nav class="sf:p-4 sf:flex sf:flex-col sf:gap-2">
				{#each links as link}
					<a
						class="sf:rounded sf:px-3 sf:py-2 sf:text-sm sf:font-medium sf:transition-all sf:hover:bg-slate-100 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-slate-600 sf:focus-visible:ring-offset-2 sf:focus-visible:ring-offset-white"
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
		<main class="sf:flex-1 sf:min-w-0 sf:bg-slate-50 sf:p-4 sf:sm:p-6">
			<div
				data-sentient-admin-content
				data-testid="app-content-frame"
				class="sf:mx-auto sf:flex sf:w-full sf:min-w-0 sf:max-w-[112rem] sf:flex-col"
			>
				<svelte:boundary>
					{@render children?.()}

					{#snippet failed(error, reset)}
						<section
							class="sf:rounded sf:border sf:border-rose-300 sf:bg-rose-50 sf:p-4 sf:space-y-2 sf:break-words"
							data-testid="route-boundary-error"
						>
							<h2 class="sf:text-base sf:font-semibold sf:text-rose-900">
								This view hit an error
							</h2>
							<p class="sf:text-sm sf:text-rose-800">{String(error)}</p>
							<Button type="button" variant="danger" size="sm" onclick={reset}
								>Retry view</Button
							>
						</section>
					{/snippet}
				</svelte:boundary>
			</div>
		</main>
	</div>
</div>

<PrivacySetupAssistant
	open={privacyAssistantOpen}
	settings={privacySettings}
	saving={privacyAssistantSaving}
	dismissible={Boolean(privacySettings?.privacy_setup_completed_at)}
	onapply={applyPrivacyPreset}
	onclose={() => {
		if (privacySettings?.privacy_setup_completed_at) {
			privacyAssistantOpen = false;
		}
	}}
/>
