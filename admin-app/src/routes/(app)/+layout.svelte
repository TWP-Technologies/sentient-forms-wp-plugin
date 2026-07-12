<script module lang="ts">
	import type { Notification } from '$lib/stores/notifications';

	type NotificationToastOptions = {
		id: string;
		duration: number;
	};

	type NotificationToastSink = Record<
		Notification['type'],
		(message: string, options: NotificationToastOptions) => unknown
	>;

	export function createNotificationForwarder(sink: NotificationToastSink) {
		const renderedNotificationIds = new Set<number>();

		return (items: Notification[]): void => {
			for (const item of items) {
				if (renderedNotificationIds.has(item.id)) continue;
				renderedNotificationIds.add(item.id);
				const timeout = item.timeout ?? 4000;
				const options = {
					id: `sentient-notification-${item.id}`,
					duration: timeout <= 0 ? Number.POSITIVE_INFINITY : timeout
				};
				sink[item.type](item.message, options);
			}
		};
	}
</script>

<script lang="ts">
	import { page } from '$app/state';
	import { onMount, type ComponentType, type SvelteComponent } from 'svelte';
	import { SESSION_EXPIRED_EVENT } from '$lib/api/session-expiry';
	import {
		SECURITY_ROADBLOCK_EVENT,
		type SecurityRoadblockDetail
	} from '$lib/api/security-roadblock';
	import { licenseState } from '$lib/stores/license';
	import sentientFormsLogo from '$lib/assets/sentient-forms-logo-horizontal.svg';
	import PrivacySetupAssistant from '$lib/components/privacy-setup-assistant.svelte';
	import WpAdminNoticeTray from '$lib/components/wp-admin-notice-tray.svelte';
	import { createClientFromConfig } from '$lib/api/client';
	import type { PluginSettingsResponse } from '$lib/api/types';
	import RefreshCwIcon from '@lucide/svelte/icons/refresh-cw';
	import RotateCwIcon from '@lucide/svelte/icons/rotate-cw';
	import ShieldAlertIcon from '@lucide/svelte/icons/shield-alert';
	import {
		appHref,
		deriveActivePath,
		navigateToAppPath,
		readHashPathFromLocation,
		resolveActiveNavPath,
		routerType,
		type NavigationLinkPath
	} from '$lib/navigation';
	import { Alert, Badge, Button } from '$lib/components/ui';
	import { notifications } from '$lib/stores/notifications';
	import { runAdminCssHealthCheck } from '$lib/utils/admin-css-health';
	import { Toaster, toast } from 'sonner-svelte';
	import { readRuntimeConfigSafely } from '$lib/schemas/runtime-config';

	interface Props {
		children?: import('svelte').Snippet;
	}

	let { children }: Props = $props();
	const client = createClientFromConfig();
	const runtime = readRuntimeConfigSafely();

	const links: Array<{ path: NavigationLinkPath; label: string }> = [
		{ path: '/dashboard', label: 'Dashboard' },
		{ path: '/providers', label: 'Providers' },
		{ path: '/actions', label: 'Actions' },
		{ path: '/lead-scoring', label: 'Lead Scoring' },
		{ path: '/actions/log', label: 'Action Log' },
		{ path: '/actions/custom', label: 'Custom Actions' },
		{ path: '/licensing', label: 'Managed Service' },
		{ path: '/settings', label: 'Settings' }
	];

	let renderedPath = $derived(deriveActivePath(page.url));
	let activePath = $derived(resolveActiveNavPath(renderedPath));
	let mismatchKey = $state<string | null>(null);
	let softRepairAttempted = $state(false);
	let hardRepairAttempted = $state(false);
	let privacySettings = $state<PluginSettingsResponse | null>(null);
	let privacySettingsLoading = $state(false);
	let privacySettingsLoadError = $state<string | null>(null);
	let privacyAssistantOpen = $state(false);
	let privacyAssistantSaving = $state(false);
	let privacyApplyError = $state<string | null>(null);
	let sessionExpired = $state(false);
	let sessionExpiredMessage = $state(
		'WordPress session expired. Reload this admin page before retrying.'
	);
	let securityRoadblock = $state<SecurityRoadblockDetail | null>(null);
	let securityRoadblockDetailsOpen = $state(false);
	type SonnerToasterProps = {
		position?: 'top-center';
		richColors?: boolean;
	};

	// sonner-svelte ships Svelte 4 style declarations that mark defaulted props as required
	// under Svelte 5. Keep the compatibility cast local to the root toaster mount.
	const SonnerToaster = Toaster as unknown as ComponentType<SvelteComponent<SonnerToasterProps>>;
	const forwardNotifications = createNotificationForwarder(toast);
	const hasSecurityRoadblockDetails = $derived(
		Boolean(securityRoadblock?.rayId || securityRoadblock?.providerDetails?.length)
	);
	const canRetrySecurityRoadblock = $derived(Boolean(securityRoadblock?.retryEventName));
	let managedAccountReady = $derived(
		['active', 'trial', 'valid'].includes(licenseState.status) &&
			licenseState.proxyKeyPresent &&
			Boolean(licenseState.licenseId) &&
			Boolean(licenseState.siteId)
	);

	function handleNavClick(event: MouseEvent, path: NavigationLinkPath): void {
		if (event.defaultPrevented || event.button !== 0) return;
		if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
		event.preventDefault();
		void navigateToAppPath(path);
	}

	function reloadAdminPage(): void {
		if (typeof window === 'undefined') return;
		window.location.reload();
	}

	function retrySecurityRoadblock(): void {
		const retryEventName = securityRoadblock?.retryEventName;
		if (typeof window === 'undefined' || !retryEventName) return;
		const detail = securityRoadblock;
		securityRoadblock = null;
		securityRoadblockDetailsOpen = false;
		window.dispatchEvent(new CustomEvent(retryEventName, { detail }));
	}

	function updateWpAdminOffset(): void {
		if (typeof window === 'undefined') return;

		const adminBar = document.getElementById('wpadminbar');
		let offset = 0;
		if (adminBar instanceof HTMLElement) {
			const rect = adminBar.getBoundingClientRect();
			const position = window.getComputedStyle(adminBar).position;
			if (position === 'fixed' && rect.height > 0) {
				offset = Math.max(0, Math.ceil(rect.bottom));
			}
		}

		if (offset === 0 && document.body.classList.contains('wp-admin')) {
			offset = window.innerWidth <= 782 ? 46 : 32;
		}

		const offsetValue = `${offset}px`;
		document.documentElement.style.setProperty('--sentient-forms-wp-admin-offset', offsetValue);
		document
			.getElementById('sentient-forms-admin-app')
			?.style.setProperty('--sentient-forms-wp-admin-offset', offsetValue);
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

		privacySettingsLoading = true;
		privacySettingsLoadError = null;
		try {
			const settings = await client.getSettings({ showNotifications: false });
			privacySettings = settings;
			privacyApplyError = null;
			if (openAssistantWhenIncomplete && !settings.privacy_setup_completed_at) {
				privacyAssistantOpen = true;
			}
		} catch (error) {
			console.error('Failed to load Sentient Forms privacy settings', error);
			privacySettingsLoadError = readableSettingsError(
				error,
				'Privacy settings could not be loaded. Retry before applying a preset.'
			);
		} finally {
			privacySettingsLoading = false;
		}
	}

	async function applyPrivacyPreset(
		profile: NonNullable<PluginSettingsResponse['privacy_setup_profile']>,
		options: { managedZdrRequired?: boolean } = {}
	) {
		privacyAssistantSaving = true;
		privacyApplyError = null;
		try {
			const settings = await client.updateSettings(
				{
					privacy_setup_profile: profile,
					...(managedAccountReady && typeof options.managedZdrRequired === 'boolean'
						? { managed_zdr_required: options.managedZdrRequired }
						: {})
				},
				{ showNotifications: false }
			);
			if (!settings.privacy_setup_completed_at) {
				privacyApplyError =
					'Privacy setup did not return a completion timestamp. Reload this admin page and try again.';
				notifications.error('Unable to confirm privacy setup was saved');
				return;
			}
			privacySettings = settings;
			privacyAssistantOpen = false;
			privacyApplyError = null;
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
			privacyApplyError = readableSettingsError(
				error,
				'Unable to save privacy setup. Reload this admin page and try again.'
			);
			notifications.error(privacyApplyError);
		} finally {
			privacyAssistantSaving = false;
		}
	}

	function readableSettingsError(error: unknown, fallback: string): string {
		const payload =
			error && typeof error === 'object' && 'payload' in error
				? (error as { payload?: unknown }).payload
				: null;

		const message = payloadMessage(payload);
		if (message) return message;

		const status =
			error && typeof error === 'object' && 'status' in error
				? (error as { status?: unknown }).status
				: null;
		if (status === 401 || status === 403) {
			return 'WordPress rejected the settings save. Reload this admin page and try again.';
		}

		if (error instanceof Error && error.message !== 'Request failed') return error.message;
		return fallback;
	}

	function payloadMessage(payload: unknown): string | null {
		if (typeof payload === 'string') {
			const trimmed = payload.trim();
			return trimmed.length > 0 ? trimmed : null;
		}
		if (!payload || typeof payload !== 'object') return null;

		const direct = (payload as { message?: unknown }).message;
		if (typeof direct === 'string' && direct.trim().length > 0) return direct.trim();

		const nested = (payload as { error?: { message?: unknown } }).error?.message;
		if (typeof nested === 'string' && nested.trim().length > 0) return nested.trim();

		return null;
	}

	onMount(() => {
		if (routerType === 'hash' && typeof window !== 'undefined' && window.location.hash === '') {
			void navigateToAppPath('/dashboard', { replaceState: true, noScroll: true, keepFocus: true });
		}

		const unsubscribeNotifications = notifications.subscribe(forwardNotifications);

		void loadPrivacySettings();
		updateWpAdminOffset();
		runAdminCssHealthCheck();

		const openAssistant = () => {
			privacyApplyError = null;
			privacyAssistantOpen = true;
			if (!privacySettings) {
				void loadPrivacySettings({ openAssistantWhenIncomplete: false });
			}
		};

		window.addEventListener('sentient-forms:open-privacy-setup', openAssistant);

		const handleSessionExpired = (event: Event) => {
			const detail = (event as CustomEvent<{ message?: string }>).detail;
			sessionExpiredMessage =
				typeof detail?.message === 'string' && detail.message.trim().length > 0
					? detail.message
					: 'WordPress session expired. Reload this admin page before retrying.';
			sessionExpired = true;
		};

		const handleSecurityRoadblock = (event: Event) => {
			const detail = (event as CustomEvent<SecurityRoadblockDetail>).detail;
			if (!detail) return;
			securityRoadblock = detail;
			securityRoadblockDetailsOpen = false;
		};

		window.addEventListener(SESSION_EXPIRED_EVENT, handleSessionExpired);
		window.addEventListener(SECURITY_ROADBLOCK_EVENT, handleSecurityRoadblock);
		window.addEventListener('resize', updateWpAdminOffset);
		const adminOffsetObserver = new MutationObserver(updateWpAdminOffset);
		adminOffsetObserver.observe(document.body, {
			attributes: true,
			childList: true,
			subtree: true
		});

		return () => {
			unsubscribeNotifications();
			window.removeEventListener('sentient-forms:open-privacy-setup', openAssistant);
			window.removeEventListener(SESSION_EXPIRED_EVENT, handleSessionExpired);
			window.removeEventListener(SECURITY_ROADBLOCK_EVENT, handleSecurityRoadblock);
			window.removeEventListener('resize', updateWpAdminOffset);
			adminOffsetObserver.disconnect();
		};
	});
</script>

<div data-sentient-admin-shell class="sf:min-w-0 sf:bg-slate-100 sf:text-slate-900 sf:font-sans">
	<WpAdminNoticeTray />
	<div data-sentient-admin-frame class="sf:flex sf:min-w-0 sf:flex-col sf:md:flex-row">
		<aside class="sf:w-full sf:shrink-0 sf:bg-white sf:md:w-64 sf:shadow-sm">
			<div class="sf:p-4 sf:sm:p-6 sf:border-b sf:border-slate-200">
				<h1 class="sf:sr-only">Sentient Forms</h1>
				<img
					src={sentientFormsLogo}
					alt="Sentient Forms"
					class="sf:h-10 sf:w-auto sf:max-w-full"
					width="2161"
					height="361"
				/>
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
				{#if sessionExpired}
					<Alert variant="warning" class="sf:mb-4" data-testid="session-expired-banner">
						<div
							class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:sm:flex-row sf:sm:items-center"
						>
							<div>
								<p class="sf:font-semibold">WordPress session expired</p>
								<p class="sf:mt-1 sf:text-sm">{sessionExpiredMessage}</p>
							</div>
							<Button size="sm" onclick={reloadAdminPage} data-testid="session-expired-reload">
								Reload admin
							</Button>
						</div>
					</Alert>
				{/if}
				{#if securityRoadblock}
					<Alert
						variant="warning"
						class="sf:mb-4 sf:border-warning-200 sf:bg-white sf:text-slate-900"
						data-testid="security-roadblock-banner"
					>
						<div
							class="sf:flex sf:flex-col sf:gap-3 sf:sm:flex-row sf:sm:items-start sf:sm:justify-between"
						>
							<div class="sf:flex sf:min-w-0 sf:gap-3">
								<span
									class="sf:mt-0.5 sf:flex sf:h-8 sf:w-8 sf:flex-none sf:items-center sf:justify-center sf:rounded sf:border sf:border-warning-200 sf:bg-warning-50 sf:text-warning-700"
									aria-hidden="true"
								>
									<ShieldAlertIcon class="sf:h-4 sf:w-4" />
								</span>
								<div class="sf:min-w-0">
									<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
										<Badge variant="warning">{securityRoadblock.label}</Badge>
										<p class="sf:text-sm sf:font-semibold sf:text-slate-950">Request interrupted</p>
									</div>
									<p class="sf:mt-1 sf:text-sm sf:text-slate-700">
										{securityRoadblock.message}
									</p>
									{#if hasSecurityRoadblockDetails}
										<details
											class="sf:mt-2 sf:text-xs sf:text-slate-600"
											bind:open={securityRoadblockDetailsOpen}
										>
											<summary class="sf:cursor-pointer sf:font-medium sf:text-slate-700">
												Technical details
											</summary>
											<dl class="sf:mt-2 sf:grid sf:gap-1 sf:sm:grid-cols-[8rem_minmax(0,1fr)]">
												{#if securityRoadblock.rayId}
													<dt class="sf:font-medium">Cloudflare Ray ID</dt>
													<dd class="sf:min-w-0 sf:break-all sf:font-mono">
														{securityRoadblock.rayId}
													</dd>
												{/if}
												{#each securityRoadblock.providerDetails ?? [] as detail}
													<dt class="sf:font-medium">Signal</dt>
													<dd class="sf:min-w-0 sf:break-all sf:font-mono">{detail}</dd>
												{/each}
											</dl>
										</details>
									{/if}
								</div>
							</div>
							<div class="sf:flex sf:flex-none sf:gap-2 sf:self-start">
								{#if canRetrySecurityRoadblock}
									<Button
										size="sm"
										variant="secondary"
										iconOnly
										title="Retry request"
										aria-label="Retry request"
										onclick={retrySecurityRoadblock}
										data-testid="security-roadblock-retry"
									>
										<RefreshCwIcon class="sf:h-4 sf:w-4" aria-hidden="true" />
									</Button>
								{/if}
								<Button
									size="sm"
									variant="secondary"
									iconOnly
									title="Reload admin"
									aria-label="Reload admin"
									onclick={reloadAdminPage}
									data-testid="security-roadblock-reload"
								>
									<RotateCwIcon class="sf:h-4 sf:w-4" aria-hidden="true" />
								</Button>
							</div>
						</div>
					</Alert>
				{/if}
				<svelte:boundary>
					{@render children?.()}

					{#snippet failed(error, reset)}
						<section
							class="sf:rounded sf:border sf:border-rose-300 sf:bg-rose-50 sf:p-4 sf:space-y-2 sf:break-words"
							data-testid="route-boundary-error"
						>
							<h2 class="sf:text-base sf:font-semibold sf:text-rose-900">This view hit an error</h2>
							<p class="sf:text-sm sf:text-rose-800">{String(error)}</p>
							<Button type="button" variant="danger" size="sm" onclick={reset}>Retry view</Button>
						</section>
					{/snippet}
				</svelte:boundary>
			</div>
		</main>
	</div>
</div>

<SonnerToaster position="top-center" richColors />

<PrivacySetupAssistant
	open={privacyAssistantOpen}
	settings={privacySettings}
	loading={privacySettingsLoading}
	loadError={privacySettingsLoadError}
	saving={privacyAssistantSaving}
	applyError={privacyApplyError}
	{managedAccountReady}
	dismissible={Boolean(privacySettings?.privacy_setup_completed_at)}
	onapply={applyPrivacyPreset}
	onretry={() => {
		void loadPrivacySettings({ openAssistantWhenIncomplete: false });
	}}
	onclose={() => {
		if (privacySettings?.privacy_setup_completed_at) {
			privacyAssistantOpen = false;
			privacyApplyError = null;
		}
	}}
/>
