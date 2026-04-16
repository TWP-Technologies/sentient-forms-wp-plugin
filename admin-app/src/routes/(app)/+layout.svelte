	<script lang="ts">
		import type { CreditBalanceResponse } from '$lib/api/types';
		import { page } from '$app/state';
		import { onMount, untrack } from 'svelte';
		import {
			appHref,
		deriveActivePath,
		navigateToAppPath,
		readHashPathFromLocation,
			resolveActiveNavPath,
			routerType,
			type NavigationLinkPath
		} from '$lib/navigation';
		import { Button, QuotaCtaCallout } from '$lib/components/ui';
		import { loadLicenseInfoSnapshot } from '$lib/stores/license';
		import { sessionStore, type LicenseStatus } from '$lib/stores/session';
		import { getNextCreditReset } from '$lib/utils/credits';
		import {
			buildCreditPresentation,
			type CreditSeverity,
			isConnectedLicenseStatus,
			type QuotaCtaAction
		} from '$lib/utils/license-health-presentation';
		import { wpFetch } from '$lib/wp';
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
		let shellCredits = $state<CreditBalanceResponse | null>(null);
		let shellCreditRefreshPending = $state(false);
		let lastShellCreditRefreshPath: string | null = null;

		let resetInfo = $derived(getNextCreditReset());
		let shellCreditPresentation = $derived(
			buildCreditPresentation(shellCredits, resetInfo.summary, 'global')
		);
		let showGlobalCreditBanner = $derived(
			activePath !== '/dashboard' &&
				activePath !== '/licensing' &&
				shellCreditPresentation.quotaCta !== null &&
				isConnectedLicenseStatus($sessionStore.licenseStatus) &&
				$sessionStore.proxyKeyPresent
		);

		function handleNavClick(event: MouseEvent, path: NavigationLinkPath): void {
			if (event.defaultPrevented || event.button !== 0) return;
			if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
			event.preventDefault();
			void navigateToAppPath(path);
		}

		function mapQuotaCalloutSeverity(
			severity: CreditSeverity
		): Exclude<CreditSeverity, 'normal'> {
			return severity === 'normal' ? 'unknown' : severity;
		}

		function handleGlobalQuotaAction(action: QuotaCtaAction): void {
			if (action === 'focus_licensing_billing') {
				void navigateToAppPath('/licensing?focus=billing');
				return;
			}

			if (action === 'navigate_licensing') {
				void navigateToAppPath('/licensing');
			}
		}

		function hasConnectedShellLicense(): boolean {
			const runtimeLicense =
				typeof window === 'undefined' ? undefined : window.sentientFormsConfig?.license;
			const runtimeStatus = (runtimeLicense?.status as LicenseStatus | undefined) ?? 'inactive';

			return (
				(Boolean(runtimeLicense?.proxyKeyPresent) && isConnectedLicenseStatus(runtimeStatus)) ||
				($sessionStore.proxyKeyPresent && isConnectedLicenseStatus($sessionStore.licenseStatus))
			);
		}

		async function refreshShellCreditHealth(): Promise<void> {
			if (shellCreditRefreshPending) {
				return;
			}

			shellCreditRefreshPending = true;

			try {
				const license = await loadLicenseInfoSnapshot();
				const hydratedLicenseStatus = (license.status as LicenseStatus) ?? 'inactive';

				sessionStore.hydrate({
					siteUrl: license.site_url ?? window.location.origin,
					licenseStatus: hydratedLicenseStatus,
					proxyKeyPresent: license.proxy_key_present ?? false,
					lastSync: license.last_synced ?? null
				});

				if (isConnectedLicenseStatus(hydratedLicenseStatus) && license.proxy_key_present) {
					const credits = await wpFetch<CreditBalanceResponse>('credits/balance?force_refresh=1');
					shellCredits = credits;
					sessionStore.hydrate({
						creditsRemaining: credits.current_balance
					});
				} else {
					shellCredits = null;
					sessionStore.hydrate({ creditsRemaining: null });
				}
			} catch (error) {
				console.error('Failed to refresh app credit health', error);
				shellCredits = null;
			} finally {
				shellCreditRefreshPending = false;
			}
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

		$effect(() => {
			const currentPath = activePath;
			if (typeof window === 'undefined') return;
			if (currentPath === '/dashboard' || currentPath === '/licensing') {
				lastShellCreditRefreshPath = null;
				return;
			}

			const isConnected = untrack(() => hasConnectedShellLicense());
			if (!isConnected) {
				lastShellCreditRefreshPath = null;
				shellCredits = null;
				return;
			}

			if (lastShellCreditRefreshPath === currentPath) {
				return;
			}

			lastShellCreditRefreshPath = currentPath;
			untrack(() => {
				void refreshShellCreditHealth();
			});
		});

		onMount(() => {
			if (routerType === 'hash' && typeof window !== 'undefined' && window.location.hash === '') {
				void navigateToAppPath('/dashboard', { replaceState: true, noScroll: true, keepFocus: true });
			}
	});
</script>

<div class="sf:min-h-screen sf:min-w-0 sf:bg-slate-100 sf:text-slate-900 sf:font-sans">
	<div class="sf:flex sf:min-h-screen sf:min-w-0 sf:flex-col sf:md:flex-row">
		<aside class="sf:w-full sf:shrink-0 sf:bg-white sf:md:w-64 sf:shadow-sm">
			<div class="sf:p-4 sf:sm:p-6 sf:border-b sf:border-slate-200">
				<h1 class="sf:text-lg sf:font-semibold">Sentient Forms</h1>
				<p class="sf:text-sm sf:text-slate-500">LLM-powered form automation</p>
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
				<main class="sf:flex-1 sf:min-w-0 sf:p-4 sf:sm:p-6 sf:bg-white sf:shadow-inner">
					{#if showGlobalCreditBanner}
						<QuotaCtaCallout
							class="sf:mb-6"
							severity={mapQuotaCalloutSeverity(shellCreditPresentation.severity)}
							title={shellCreditPresentation.calloutTitle}
							message={shellCreditPresentation.detail}
							cta={shellCreditPresentation.quotaCta}
							onAction={handleGlobalQuotaAction}
							testId="app-credit-banner"
							ctaTestId="app-credit-banner-button"
							reasonTestId="app-credit-banner-reason"
						/>
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
			</main>
		</div>
	</div>
