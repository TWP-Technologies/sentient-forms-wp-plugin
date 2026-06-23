<script lang="ts">
	import { onMount } from 'svelte';
	import { ApiClientError, createClientFromConfig } from '$lib/api/client';
	import type {
		LocalProviderCredential,
		OpenRouterModelsResponse,
		OpenRouterValidateResponse,
		PluginSettingsResponse,
		SentientManagedRevokeResponse,
		SentientManagedSetupResponse
	} from '$lib/api/types';
	import {
		Alert,
		Badge,
		Button,
		Card,
		InputField,
		Section,
		StateTemplate
	} from '$lib/components/ui';
	import { navigateToAppPath } from '$lib/navigation';
	import { licenseState } from '$lib/stores/license';
	import { notifications } from '$lib/stores/notifications';
	import {
		OPENROUTER_DISCLOSURE_VERSION,
		SENTIENT_MANAGED_DISCLOSURE_VERSION
	} from '$lib/constants/external-services';
	import { formatTimestamp } from '$lib/utils/date-time';
	import {
		isReadyOpenRouterCredential,
		localOpenRouterSetupUnavailableMessage,
		localOpenRouterSetupUnavailableTitle,
		providerCredentialAuthModeLabel,
		providerCredentialSecretSummary,
		providerCredentialStatusDetail,
		providerCredentialStatusDetailClass,
		providerStatusLabel,
		providerStatusVariant
	} from '$lib/utils/provider-health';

	const client = createClientFromConfig();
	const DISCLOSURE_VERSION = OPENROUTER_DISCLOSURE_VERSION;
	const MANAGED_DISCLOSURE_VERSION = SENTIENT_MANAGED_DISCLOSURE_VERSION;

	let loading = $state(true);
	let credentials = $state<LocalProviderCredential[]>([]);
	let validationResult = $state<OpenRouterValidateResponse | null>(null);
	let validationResultSource = $state<'manual_key' | 'constant' | null>(null);
	let managedSetupResult = $state<SentientManagedSetupResponse | null>(null);
	let managedRevokeResult = $state<SentientManagedRevokeResponse | null>(null);
	let modelCatalog = $state<OpenRouterModelsResponse | null>(null);
	let error = $state<string | null>(null);
	let constantError = $state<string | null>(null);
	let managedSetupError = $state<string | null>(null);
	let managedRevokeError = $state<string | null>(null);
	let modelCatalogError = $state<string | null>(null);
	let settingsError = $state<string | null>(null);
	let apiKey = $state('');
	let label = $state('OpenRouter key');
	let constantLabel = $state('OpenRouter server secret');
	let constantName = $state('SENTIENT_FORMS_OPENROUTER_KEY');
	let managedLabel = $state('Sentient Forms Managed Service');
	let saveKey = $state(true);
	let acceptedDisclosure = $state(false);
	let acceptedManagedDisclosure = $state(false);
	let validating = $state(false);
	let validatingConstant = $state(false);
	let deletingCredentialId = $state<number | null>(null);
	let pendingDeleteCredentialId = $state<number | null>(null);
	let managedSetupLoading = $state(false);
	let managedRevokeLoading = $state(false);
	let modelCatalogLoading = $state(true);
	let modelCatalogRefreshing = $state(false);
	let managedZdrRequired = $state(false);
	let managedZdrSaving = $state(false);
	let settingsLoaded = $state(false);
	let settingsRequestToken = 0;

	let openRouterCredentials = $derived(
		credentials.filter((credential) => credential.provider === 'openrouter')
	);
	let readyOpenRouterCredentials = $derived(
		openRouterCredentials.filter((credential) => isReadyOpenRouterCredential(credential))
	);
	let managedCredentials = $derived(
		credentials.filter((credential) => credential.provider === 'sentient_managed')
	);
	let readyManagedCredentials = $derived(
		managedCredentials.filter(
			(credential) =>
				credential.auth_mode === 'sentient_proxy' &&
				credential.status === 'valid' &&
				credential.secret_configured &&
				readManagedConsent(credential).state === 'accepted'
		)
	);
	let primaryOpenRouterCredential = $derived(openRouterCredentials[0] ?? null);
	let primaryManagedCredential = $derived(
		readyManagedCredentials[0] ?? managedCredentials[0] ?? null
	);
	let managedConsent = $derived(readManagedConsent(primaryManagedCredential));
	let readyCredentialCount = $derived(readyOpenRouterCredentials.length);
	let readyProviderCredentials = $derived([
		...readyOpenRouterCredentials,
		...readyManagedCredentials
	]);
	let managedAccountReady = $derived(
		['active', 'trial', 'valid'].includes(licenseState.status) &&
			licenseState.proxyKeyPresent &&
			Boolean(licenseState.licenseId) &&
			Boolean(licenseState.siteId)
	);
	let managedZdrControlDisabled = $derived(
		managedZdrSaving || !settingsLoaded || !managedAccountReady
	);
	let localSetupUnavailableTitle = $derived(
		managedAccountReady
			? 'Connect a provider before building actions'
			: localOpenRouterSetupUnavailableTitle(openRouterCredentials)
	);
	let localSetupUnavailableMessage = $derived(
		managedAccountReady
			? 'Enable the Sentient Forms Managed Service here or validate an OpenRouter key or server secret for direct local execution.'
			: localOpenRouterSetupUnavailableMessage(openRouterCredentials)
	);
	let freeModelPreview = $derived(
		(modelCatalog?.models ?? []).filter((model) => model.free).slice(0, 6)
	);
	let canRefreshOpenRouterModels = $derived(acceptedDisclosure && !modelCatalogRefreshing);
	let canValidateOpenRouterKey = $derived(
		acceptedDisclosure && apiKey.trim().length > 0 && !validating
	);
	let canValidateOpenRouterConstant = $derived(
		acceptedDisclosure && constantName.trim().length > 0 && !validatingConstant
	);
	let canEnableManagedProxy = $derived(
		managedAccountReady &&
			acceptedManagedDisclosure &&
			!managedSetupLoading &&
			managedConsent.state !== 'accepted'
	);

	$effect(() => {
		if (managedConsent.state === 'accepted') {
			acceptedManagedDisclosure = true;
		} else if (managedConsent.state === 'revoked') {
			acceptedManagedDisclosure = false;
		}
	});

	type ManagedConsentSummary = {
		state: 'accepted' | 'revoked' | 'missing';
		consentId: number | null;
		disclosureVersion: string | null;
		acceptedAt: string | null;
		revokedAt: string | null;
	};

	function isRecord(value: unknown): value is Record<string, unknown> {
		return Boolean(value) && typeof value === 'object' && !Array.isArray(value);
	}

	function readManagedConsent(credential: LocalProviderCredential | null): ManagedConsentSummary {
		const statusJson = isRecord(credential?.status_json) ? credential.status_json : null;
		const managedConsent = isRecord(statusJson?.managed_consent)
			? statusJson.managed_consent
			: null;
		const rawState = typeof managedConsent?.state === 'string' ? managedConsent.state : 'missing';
		const state =
			rawState === 'accepted' || rawState === 'revoked' || rawState === 'missing'
				? rawState
				: 'missing';
		const consentId =
			typeof managedConsent?.consent_id === 'number' ? managedConsent.consent_id : null;

		return {
			state,
			consentId,
			disclosureVersion:
				typeof managedConsent?.disclosure_version === 'string'
					? managedConsent.disclosure_version
					: null,
			acceptedAt:
				typeof managedConsent?.accepted_at === 'string' ? managedConsent.accepted_at : null,
			revokedAt: typeof managedConsent?.revoked_at === 'string' ? managedConsent.revoked_at : null
		};
	}

	function managedConsentBadgeVariant(state: ManagedConsentSummary['state']) {
		if (state === 'accepted') return 'success';
		if (state === 'revoked') return 'warning';
		return 'neutral';
	}

	function managedConsentLabel(state: ManagedConsentSummary['state']): string {
		if (state === 'accepted') return 'Consent accepted';
		if (state === 'revoked') return 'Consent revoked';
		return 'Consent missing';
	}

	function managedConsentTimestamp(consent: ManagedConsentSummary): string | null {
		if (consent.state === 'accepted') return consent.acceptedAt;
		if (consent.state === 'revoked') return consent.revokedAt;
		return null;
	}

	function errorMessage(requestError: unknown): string {
		if (requestError instanceof ApiClientError) {
			const payload = requestError.payload;
			if (payload && typeof payload === 'object' && 'message' in payload) {
				const message = (payload as { message?: unknown }).message;
				if (typeof message === 'string' && message.trim().length > 0) {
					return message;
				}
			}
		}

		return requestError instanceof Error ? requestError.message : 'Provider request failed.';
	}

	async function loadCredentials(): Promise<void> {
		loading = true;
		error = null;

		try {
			credentials = await client.getLocalProviderCredentials({ showNotifications: false });
		} catch (requestError) {
			error = errorMessage(requestError);
		} finally {
			loading = false;
		}
	}

	async function loadModelCatalog(): Promise<void> {
		modelCatalogLoading = true;
		modelCatalogError = null;

		try {
			modelCatalog = await client.getOpenRouterModels({ limit: 100 }, { showNotifications: false });
		} catch (requestError) {
			modelCatalogError = errorMessage(requestError);
		} finally {
			modelCatalogLoading = false;
		}
	}

	function syncManagedZdrSettings(settings: PluginSettingsResponse): void {
		managedZdrRequired = Boolean(settings.managed_zdr_required);
	}

	async function loadSettings(): Promise<void> {
		const requestToken = ++settingsRequestToken;
		settingsError = null;

		try {
			const settings = await client.getSettings({ showNotifications: false });
			if (requestToken !== settingsRequestToken) return;
			syncManagedZdrSettings(settings);
			settingsLoaded = true;
		} catch (requestError) {
			if (requestToken !== settingsRequestToken) return;
			settingsError = errorMessage(requestError);
		}
	}

	async function toggleManagedZdrRequired(nextRequired: boolean): Promise<void> {
		if (!managedAccountReady) {
			managedZdrRequired = false;
			notifications.warning('Active Sentient Forms Managed Service is required to enforce ZDR.');
			return;
		}

		settingsRequestToken += 1;
		const previous = managedZdrRequired;
		managedZdrRequired = nextRequired;
		managedZdrSaving = true;
		settingsError = null;

		try {
			const settings = await client.updateSettings(
				{ managed_zdr_required: nextRequired },
				{ showNotifications: false }
			);
			syncManagedZdrSettings(settings);
			settingsLoaded = true;
			notifications.success(
				nextRequired ? 'Managed ZDR enforcement enabled' : 'Managed ZDR enforcement disabled'
			);
		} catch (requestError) {
			managedZdrRequired = previous;
			settingsError = errorMessage(requestError);
			notifications.error('Unable to update managed ZDR enforcement');
		} finally {
			managedZdrSaving = false;
		}
	}

	async function refreshOpenRouterModels(): Promise<void> {
		modelCatalogError = null;

		if (!acceptedDisclosure) {
			modelCatalogError =
				'Accept the OpenRouter external-service disclosure before refreshing model metadata.';
			return;
		}

		modelCatalogRefreshing = true;

		try {
			modelCatalog = await client.refreshOpenRouterModels(
				{
					disclosure_version: DISCLOSURE_VERSION,
					accepted_external_service_terms: acceptedDisclosure,
					output_modalities: 'text'
				},
				{ showNotifications: false }
			);
		} catch (requestError) {
			modelCatalogError = errorMessage(requestError);
		} finally {
			modelCatalogRefreshing = false;
		}
	}

	async function validateOpenRouterKey(event: SubmitEvent): Promise<void> {
		event.preventDefault();

		error = null;
		constantError = null;
		validationResult = null;
		validationResultSource = null;

		if (apiKey.trim().length === 0) {
			error = 'Enter an OpenRouter API key before validating.';
			return;
		}

		if (!acceptedDisclosure) {
			error = 'Accept the OpenRouter external-service disclosure before validating this key.';
			return;
		}

		validating = true;

		try {
			validationResult = await client.validateOpenRouterKey(
				{
					api_key: apiKey.trim(),
					label: label.trim() || undefined,
					save: saveKey,
					disclosure_version: DISCLOSURE_VERSION,
					accepted_external_service_terms: acceptedDisclosure
				},
				{ showNotifications: false }
			);

			if (saveKey) {
				apiKey = '';
				await loadCredentials();
			}
			validationResultSource = 'manual_key';
		} catch (requestError) {
			error = errorMessage(requestError);
		} finally {
			validating = false;
		}
	}

	async function saveOpenRouterConstant(event: SubmitEvent): Promise<void> {
		event.preventDefault();

		error = null;
		constantError = null;
		validationResult = null;
		validationResultSource = null;

		if (constantName.trim().length === 0) {
			constantError = 'Enter the constant or environment variable name before validating.';
			return;
		}

		if (!acceptedDisclosure) {
			constantError =
				'Accept the OpenRouter external-service disclosure before validating this server secret.';
			return;
		}

		validatingConstant = true;

		try {
			validationResult = await client.saveOpenRouterConstant(
				{
					constant_name: constantName.trim(),
					label: constantLabel.trim() || undefined,
					disclosure_version: DISCLOSURE_VERSION,
					accepted_external_service_terms: acceptedDisclosure
				},
				{ showNotifications: false }
			);

			await loadCredentials();
			validationResultSource = 'constant';
		} catch (requestError) {
			constantError = errorMessage(requestError);
		} finally {
			validatingConstant = false;
		}
	}

	function confirmCredentialDelete(credential: LocalProviderCredential): void {
		pendingDeleteCredentialId = credential.id;
	}

	function cancelCredentialDelete(): void {
		pendingDeleteCredentialId = null;
	}

	async function deleteProviderCredential(credential: LocalProviderCredential): Promise<void> {
		error = null;
		deletingCredentialId = credential.id;

		try {
			await client.deleteLocalProviderCredential(credential.id, { showNotifications: false });
			credentials = credentials.filter((candidate) => candidate.id !== credential.id);
			pendingDeleteCredentialId = null;
			notifications.success(`${credential.label} deleted`);
		} catch (requestError) {
			const message = errorMessage(requestError);
			error = message;
			notifications.error(message);
		} finally {
			deletingCredentialId = null;
		}
	}

	async function setupSentientManagedProxy(event: SubmitEvent): Promise<void> {
		event.preventDefault();

		managedSetupError = null;
		managedSetupResult = null;
		managedRevokeError = null;
		managedRevokeResult = null;

		if (!managedAccountReady) {
			managedSetupError =
				'Activate a Sentient Forms Managed Service account before enabling managed service.';
			return;
		}

		if (!acceptedManagedDisclosure) {
			managedSetupError =
				'Accept the Sentient Forms Managed Service disclosure before enabling managed service.';
			return;
		}

		managedSetupLoading = true;

		try {
			managedSetupResult = await client.setupSentientManagedProvider(
				{
					label: managedLabel.trim() || undefined,
					disclosure_version: MANAGED_DISCLOSURE_VERSION,
					accepted_external_service_terms: acceptedManagedDisclosure
				},
				{ showNotifications: false }
			);

			await loadCredentials();
		} catch (requestError) {
			managedSetupError = errorMessage(requestError);
		} finally {
			managedSetupLoading = false;
		}
	}

	async function revokeSentientManagedProxy(): Promise<void> {
		managedSetupError = null;
		managedSetupResult = null;
		managedRevokeError = null;
		managedRevokeResult = null;
		managedRevokeLoading = true;

		try {
			managedRevokeResult = await client.revokeSentientManagedProvider(
				{
					disclosure_version: MANAGED_DISCLOSURE_VERSION,
					confirm_managed_service_revocation: true
				},
				{ showNotifications: false }
			);
			acceptedManagedDisclosure = false;
			await loadCredentials();
			notifications.success('Managed-service consent revoked. Stripe subscription unchanged.');
		} catch (requestError) {
			managedRevokeError = errorMessage(requestError);
		} finally {
			managedRevokeLoading = false;
		}
	}

	onMount(() => {
		void loadCredentials();
		void loadModelCatalog();
		void loadSettings();
	});
</script>

<Section
	heading="Providers"
	description="Choose how Sentient Forms actions reach models. Managed service is the recommended setup for most WebMasters; free OpenRouter routes and BYOK remain available for testing and self-managed provider billing."
>
	{#snippet actions()}
		<Button variant="secondary" onclick={loadCredentials} disabled={loading}>
			{loading ? 'Refreshing...' : 'Refresh'}
		</Button>
	{/snippet}

	{#if error}
		<StateTemplate
			variant="error"
			title="Provider setup needs attention"
			message={error}
			actionLabel="Retry"
			onAction={loadCredentials}
			testId="providers-error-state"
		/>
	{/if}

	<Card title="Pick the operating model" data-testid="providers-operating-model-card">
		<div class="sf:grid sf:gap-3 sf:lg:grid-cols-3">
			<div class="sf:rounded sf:border sf:border-primary-200 sf:bg-primary-50 sf:p-4">
				<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
					<Badge variant={managedAccountReady ? 'success' : 'warning'}>
						{managedAccountReady ? 'Ready to enable' : 'License needed'}
					</Badge>
					<Badge variant="info">Recommended</Badge>
				</div>
				<h3 class="sf:mt-3 sf:text-base sf:font-semibold sf:text-slate-900">
					Sentient Forms Managed Service
				</h3>
				<p class="sf:mt-2 sf:text-sm sf:text-slate-600">
					Use a Sentient Forms subscription when you want model access, spend controls, metering,
					and service setup handled for this WordPress site.
				</p>
				<p class="sf:mt-3 sf:text-xs sf:font-medium sf:text-slate-700">
					Managed runs are pass-through: Sentient Forms does not store LLM prompts, form fields, or
					outputs beyond the billing/support metadata described in the disclosure.
				</p>
			</div>
			<div class="sf:rounded sf:border sf:border-slate-200 sf:bg-white sf:p-4">
				<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
					<Badge variant="success">Free path</Badge>
					<Badge variant="neutral">OpenRouter</Badge>
				</div>
				<h3 class="sf:mt-3 sf:text-base sf:font-semibold sf:text-slate-900">
					Try actions with free models
				</h3>
				<p class="sf:mt-2 sf:text-sm sf:text-slate-600">
					Use OpenRouter routes marked free. This is the lowest-friction way to confirm a workflow,
					but model availability, privacy posture, and retention policy are controlled by OpenRouter
					and the upstream model provider.
				</p>
			</div>
			<div class="sf:rounded sf:border sf:border-slate-200 sf:bg-white sf:p-4">
				<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
					<Badge variant="info">BYOK</Badge>
					<Badge variant="neutral">Direct billing</Badge>
				</div>
				<h3 class="sf:mt-3 sf:text-base sf:font-semibold sf:text-slate-900">
					Bring your own OpenRouter key
				</h3>
				<p class="sf:mt-2 sf:text-sm sf:text-slate-600">
					Keep Sentient Forms out of the request path for direct model calls. You manage the
					OpenRouter account, key limits, provider terms, and paid model charges.
				</p>
			</div>
		</div>
	</Card>

	<Card class="sf:border-slate-300 sf:bg-slate-50" data-testid="providers-managed-summary">
		<div
			class="sf:flex sf:flex-col sf:gap-4 sf:lg:flex-row sf:lg:items-center sf:lg:justify-between"
		>
			<div class="sf:space-y-2">
				<div class="sf:flex sf:flex-wrap sf:gap-2">
					<Badge variant={providerStatusVariant(primaryManagedCredential?.status ?? 'missing')}>
						{providerStatusLabel(primaryManagedCredential?.status ?? 'missing')}
					</Badge>
					<Badge variant={managedAccountReady ? 'success' : 'warning'}>
						{managedAccountReady ? 'Managed account ready' : 'Managed account inactive'}
					</Badge>
					<Badge variant={managedConsentBadgeVariant(managedConsent.state)}>
						{managedConsentLabel(managedConsent.state)}
					</Badge>
					<Badge variant="info">Sentient Forms billed</Badge>
				</div>
				<h3 class="sf:text-xl sf:font-semibold sf:text-slate-900">
					Sentient Forms Managed Service
				</h3>
				<p class="sf:max-w-2xl sf:text-sm sf:text-slate-600">
					Sentient Forms receives rendered prompts and required form fields only for managed service
					runs. Direct OpenRouter runs stay outside Sentient Forms billing.
				</p>
			</div>
			<div class="sf:flex sf:gap-6">
				<div>
					<p class="sf:text-xs sf:font-medium sf:text-slate-500">Saved service credentials</p>
					<p
						class="sf:text-2xl sf:font-semibold sf:text-slate-900"
						data-testid="providers-managed-count"
					>
						{managedCredentials.length}
					</p>
				</div>
				<div>
					<p class="sf:text-xs sf:font-medium sf:text-slate-500">Ready service credentials</p>
					<p
						class="sf:text-2xl sf:font-semibold sf:text-slate-900"
						data-testid="providers-managed-ready-count"
					>
						{readyManagedCredentials.length}
					</p>
				</div>
				<div>
					<p class="sf:text-xs sf:font-medium sf:text-slate-500">Consent state</p>
					<p class="sf:text-sm sf:font-semibold sf:text-slate-900">
						{managedConsentLabel(managedConsent.state)}
					</p>
				</div>
			</div>
		</div>
	</Card>

	<Card class="sf:border-slate-300 sf:bg-white" data-testid="providers-managed-zdr">
		<div
			class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:sm:flex-row sf:sm:items-start"
		>
			<div class="sf:min-w-0 sf:space-y-1">
				<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
					<Badge variant={managedAccountReady ? 'success' : 'warning'}>
						{managedAccountReady ? 'Managed service active' : 'Managed service required'}
					</Badge>
					{#if managedZdrRequired}
						<Badge variant="success">ZDR enforced</Badge>
					{/if}
				</div>
				<p class="sf:font-medium sf:text-slate-900">Enforce ZDR for managed service</p>
				<p class="sf:max-w-2xl sf:text-sm sf:leading-6 sf:text-slate-600">
					Requires Sentient Forms Managed Service to use routes that OpenRouter marks for Zero Data
					Retention and to deny provider data collection. If the selected model is no longer
					eligible, Sentient Forms uses a comparable ZDR-safe model when available or fails safely.
				</p>
				{#if settingsError}
					<p class="sf:text-sm sf:text-danger-700" role="alert">{settingsError}</p>
				{/if}
			</div>
			<label class="sf:flex sf:items-center sf:gap-3">
				<span class="sf:text-sm sf:font-semibold">{managedZdrRequired ? 'On' : 'Off'}</span>
				<input
					type="checkbox"
					class="sf:h-5 sf:w-5 sf:rounded sf:text-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
					checked={managedZdrRequired}
					disabled={managedZdrControlDisabled}
					onchange={(event) =>
						toggleManagedZdrRequired((event.currentTarget as HTMLInputElement).checked)}
					aria-label="Enforce ZDR for managed service"
				/>
			</label>
		</div>
	</Card>

	<Card class="sf:border-slate-300 sf:bg-slate-50" data-testid="providers-openrouter-summary">
		<div class="sf:space-y-4">
			<div
				class="sf:flex sf:flex-col sf:gap-4 sf:lg:flex-row sf:lg:items-center sf:lg:justify-between"
			>
				<div class="sf:space-y-2">
					<div class="sf:flex sf:flex-wrap sf:gap-2">
						<Badge
							variant={providerStatusVariant(primaryOpenRouterCredential?.status ?? 'missing')}
						>
							{providerStatusLabel(primaryOpenRouterCredential?.status ?? 'missing')}
						</Badge>
						<Badge variant="info">Local credential storage</Badge>
						<Badge variant="neutral">Server secret supported</Badge>
					</div>
					<h3 class="sf:text-xl sf:font-semibold sf:text-slate-900">
						{primaryOpenRouterCredential?.label ?? 'OpenRouter direct'}
					</h3>
					<p class="sf:max-w-2xl sf:text-sm sf:text-slate-600">
						OpenRouter receives the prompts and form fields needed for direct model calls. Sentient
						Forms does not receive those direct-call payloads. Use the local vault for the fastest
						setup, or use a server constant or environment variable when you want the key to stay
						out of the plugin database.
					</p>
				</div>
				<div class="sf:flex sf:gap-6">
					<div>
						<p class="sf:text-xs sf:font-medium sf:text-slate-500">Saved keys</p>
						<p
							class="sf:text-2xl sf:font-semibold sf:text-slate-900"
							data-testid="providers-openrouter-count"
						>
							{openRouterCredentials.length}
						</p>
					</div>
					<div>
						<p class="sf:text-xs sf:font-medium sf:text-slate-500">Ready keys</p>
						<p
							class="sf:text-2xl sf:font-semibold sf:text-slate-900"
							data-testid="providers-openrouter-ready-count"
						>
							{readyCredentialCount}
						</p>
					</div>
				</div>
			</div>

			<Alert variant="warning">
				<p class="sf:font-semibold">Privacy depends on the route you choose</p>
				<p class="sf:mt-1">
					OpenRouter can mark models as available on Zero Data Retention (ZDR) routes, and
					Sentient Forms shows those tags to help you choose. ZDR
					<span class="sf:font-semibold sf:italic sf:underline">enforcement</span>
					for direct OpenRouter users can only be configured in OpenRouter.
				</p>
			</Alert>
		</div>
	</Card>

	<Card title="OpenRouter model catalog" data-testid="providers-openrouter-model-catalog">
		<div
			class="sf:flex sf:flex-col sf:gap-4 sf:lg:flex-row sf:lg:items-start sf:lg:justify-between"
		>
			<div class="sf:space-y-2">
				<div class="sf:flex sf:flex-wrap sf:gap-2">
					<Badge variant={modelCatalog && modelCatalog.total_cached > 0 ? 'success' : 'neutral'}>
						{modelCatalog && modelCatalog.total_cached > 0 ? 'Cached locally' : 'No local cache'}
					</Badge>
					{#if modelCatalog && modelCatalog.stale_count > 0}
						<Badge variant="warning">{modelCatalog.stale_count} stale</Badge>
					{/if}
				</div>
				<p class="sf:max-w-2xl sf:text-sm sf:text-slate-600">
					Refresh pulls model names, pricing, capabilities, and free-model flags from OpenRouter. No
					form data or prompts are sent.
				</p>
				{#if modelCatalogError}
					<p
						class="sf:text-sm sf:text-danger-700"
						role="alert"
						data-testid="providers-model-catalog-error"
					>
						{modelCatalogError}
					</p>
				{/if}
				<Alert
					variant={acceptedDisclosure ? 'success' : 'info'}
					data-testid="providers-model-catalog-disclosure"
				>
					<label class="sf:flex sf:items-start sf:gap-3 sf:text-sm sf:text-slate-700">
						<input
							class="sf:mt-1 sf:h-4 sf:w-4 sf:rounded sf:border-slate-300"
							type="checkbox"
							bind:checked={acceptedDisclosure}
						/>
						<span>
							I understand refreshing the catalog contacts OpenRouter for model metadata, pricing,
							and capability flags. No form data or prompts are sent. I accept the
							<a
								class="sf:font-medium sf:text-slate-900 sf:underline"
								href="https://openrouter.ai/terms"
								target="_blank"
								rel="noreferrer noopener">OpenRouter terms</a
							>
							and
							<a
								class="sf:font-medium sf:text-slate-900 sf:underline"
								href="https://openrouter.ai/privacy"
								target="_blank"
								rel="noreferrer noopener">privacy policy</a
							>.
						</span>
					</label>
				</Alert>
			</div>

			<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-6">
				<div>
					<p class="sf:text-xs sf:font-medium sf:text-slate-500">Cached models</p>
					<p
						class="sf:text-2xl sf:font-semibold sf:text-slate-900"
						data-testid="providers-openrouter-model-count"
					>
						{modelCatalogLoading ? '...' : (modelCatalog?.total_cached ?? 0)}
					</p>
				</div>
				<div>
					<p class="sf:text-xs sf:font-medium sf:text-slate-500">Free models</p>
					<p
						class="sf:text-2xl sf:font-semibold sf:text-slate-900"
						data-testid="providers-openrouter-free-model-count"
					>
						{modelCatalogLoading ? '...' : (modelCatalog?.free_count ?? 0)}
					</p>
				</div>
				<Button
					variant="secondary"
					loading={modelCatalogRefreshing}
					disabled={!canRefreshOpenRouterModels}
					onclick={refreshOpenRouterModels}
					data-testid="providers-refresh-model-catalog"
				>
					{modelCatalogRefreshing ? 'Refreshing...' : 'Refresh catalog'}
				</Button>
			</div>
		</div>

		{#if modelCatalogLoading}
			<div class="sf:mt-4">
				<StateTemplate variant="loading" title="Loading model cache" dense />
			</div>
		{:else if !modelCatalog || modelCatalog.total_cached === 0}
			<div class="sf:mt-4">
				<StateTemplate
					variant="empty"
					title="No model cache yet"
					message="Accept the OpenRouter disclosure in this catalog panel, then refresh before choosing a specific cached model."
					actionLabel={acceptedDisclosure ? 'Refresh catalog' : null}
					onAction={acceptedDisclosure ? refreshOpenRouterModels : null}
					dense
				/>
			</div>
		{:else if freeModelPreview.length > 0}
			<div class="sf:mt-4 sf:grid sf:gap-3 sf:md:grid-cols-2 sf:xl:grid-cols-3">
				{#each freeModelPreview as model}
					<div
						class="sf:rounded sf:border sf:border-slate-200 sf:bg-white sf:p-3"
						data-testid="providers-openrouter-free-model"
					>
						<div class="sf:flex sf:items-start sf:justify-between sf:gap-2">
							<p class="sf:text-sm sf:font-medium sf:text-slate-900">{model.name}</p>
							<Badge variant={model.stale ? 'warning' : 'success'}
								>{model.stale ? 'Stale' : 'Free'}</Badge
							>
						</div>
						<p class="sf:mt-1 sf:break-all sf:text-xs sf:text-slate-500">{model.id}</p>
						<p class="sf:mt-2 sf:text-xs sf:text-slate-600">
							Context {model.context_length ?? 'unknown'} tokens · refreshed {formatTimestamp(
								model.fetched_at,
								'never'
							)}
						</p>
					</div>
				{/each}
			</div>
		{/if}
	</Card>

	<div class="sf:grid sf:gap-4 sf:xl:grid-cols-[1fr_0.9fr]">
		<div class="sf:space-y-4">
			<Card title="Validate OpenRouter key" data-testid="providers-openrouter-form-card">
				<form class="sf:space-y-4" onsubmit={validateOpenRouterKey}>
					<InputField
						id="openrouter-label"
						label="Label"
						placeholder="OpenRouter key"
						bind:value={label}
						disabled={validating}
					/>
					<InputField
						id="openrouter-api-key"
						label="API key"
						type="password"
						placeholder="sk-or-..."
						autocomplete="off"
						bind:value={apiKey}
						disabled={validating}
						required
					/>

					<label class="sf:flex sf:items-start sf:gap-3 sf:text-sm sf:text-slate-700">
						<input
							class="sf:mt-1 sf:h-4 sf:w-4 sf:rounded sf:border-slate-300"
							type="checkbox"
							bind:checked={saveKey}
							disabled={validating}
						/>
						<span>Save the validated key in the local provider vault.</span>
					</label>

					<label class="sf:flex sf:items-start sf:gap-3 sf:text-sm sf:text-slate-700">
						<input
							class="sf:mt-1 sf:h-4 sf:w-4 sf:rounded sf:border-slate-300"
							type="checkbox"
							bind:checked={acceptedDisclosure}
							disabled={validating}
							required
						/>
						<span>
							I understand OpenRouter receives request data for direct model calls and I accept the
							<a
								class="sf:font-medium sf:text-slate-900 sf:underline"
								href="https://openrouter.ai/terms"
								target="_blank"
								rel="noreferrer noopener">OpenRouter terms</a
							>
							and
							<a
								class="sf:font-medium sf:text-slate-900 sf:underline"
								href="https://openrouter.ai/privacy"
								target="_blank"
								rel="noreferrer noopener">privacy policy</a
							>.
						</span>
					</label>

					<Button type="submit" loading={validating} disabled={!canValidateOpenRouterKey}>
						{validating ? 'Validating...' : 'Validate key'}
					</Button>
				</form>

				{#if validationResult && validationResultSource === 'manual_key'}
					<div
						class="sf:mt-4 sf:rounded sf:border sf:border-success-200 sf:bg-success-50 sf:p-4"
						data-testid="providers-openrouter-validation-result"
					>
						<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
							<Badge variant={providerStatusVariant(validationResult.status)}>
								{providerStatusLabel(validationResult.status)}
							</Badge>
							<span class="sf:text-sm sf:text-success-800">
								Consent #{validationResult.consent_id} recorded.
							</span>
						</div>
						<p class="sf:mt-2 sf:text-sm sf:text-success-800">
							{validationResult.credential_id
								? `Saved credential #${validationResult.credential_id}.`
								: 'Validated without saving the key.'}
						</p>
					</div>
				{/if}
			</Card>

			<Card
				title="Use server constant or environment variable"
				subtitle="Recommended for high-sensitivity sites."
				data-testid="providers-openrouter-constant-card"
			>
				<div class="sf:space-y-4">
					<Alert variant="info">
						<p class="sf:font-semibold">No OpenRouter key is stored in the plugin database</p>
						<p class="sf:mt-1">
							Define a constant or environment variable on this WordPress host first, then validate
							it here. Sentient Forms only stores the reference name and status metadata.
						</p>
					</Alert>

					<form class="sf:space-y-4" onsubmit={saveOpenRouterConstant}>
						<InputField
							id="openrouter-constant-label"
							label="Label"
							placeholder="OpenRouter server secret"
							bind:value={constantLabel}
							disabled={validatingConstant}
						/>
						<InputField
							id="openrouter-constant-name"
							label="Constant or environment variable"
							placeholder="SENTIENT_FORMS_OPENROUTER_KEY"
							bind:value={constantName}
							disabled={validatingConstant}
							required
						/>

						<p class="sf:text-xs sf:text-slate-500">
							Example:
							<code
								class="sf:rounded sf:bg-slate-200 sf:px-1.5 sf:py-0.5 sf:font-mono sf:text-[13px] sf:text-slate-800"
								>SENTIENT_FORMS_OPENROUTER_KEY</code
							>. Names must start with
							<code
								class="sf:rounded sf:bg-slate-200 sf:px-1.5 sf:py-0.5 sf:font-mono sf:text-[13px] sf:text-slate-800"
								>SENTIENT_FORMS_OPENROUTER_</code
							>. Do not use WordPress auth keys, salts, database credentials, or other unrelated
							server secrets.
						</p>

						<label class="sf:flex sf:items-start sf:gap-3 sf:text-sm sf:text-slate-700">
							<input
								class="sf:mt-1 sf:h-4 sf:w-4 sf:rounded sf:border-slate-300"
								type="checkbox"
								bind:checked={acceptedDisclosure}
								disabled={validatingConstant}
								required
							/>
							<span>
								I understand OpenRouter receives request data for direct model calls and I accept
								the
								<a
									class="sf:font-medium sf:text-slate-900 sf:underline"
									href="https://openrouter.ai/terms"
									target="_blank"
									rel="noreferrer noopener">OpenRouter terms</a
								>
								and
								<a
									class="sf:font-medium sf:text-slate-900 sf:underline"
									href="https://openrouter.ai/privacy"
									target="_blank"
									rel="noreferrer noopener">privacy policy</a
								>.
							</span>
						</label>

						<Button
							type="submit"
							variant="secondary"
							loading={validatingConstant}
							disabled={!canValidateOpenRouterConstant}
							data-testid="providers-openrouter-constant-submit"
						>
							{validatingConstant ? 'Validating...' : 'Validate server secret'}
						</Button>
					</form>

					{#if constantError}
						<p
							class="sf:text-sm sf:text-danger-700"
							role="alert"
							data-testid="providers-openrouter-constant-error"
						>
							{constantError}
						</p>
					{/if}

					{#if validationResult && validationResultSource === 'constant'}
						<div
							class="sf:rounded sf:border sf:border-success-200 sf:bg-success-50 sf:p-4"
							data-testid="providers-openrouter-constant-result"
						>
							<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
								<Badge variant={providerStatusVariant(validationResult.status)}>
									{providerStatusLabel(validationResult.status)}
								</Badge>
								<span class="sf:text-sm sf:text-success-800">
									Consent #{validationResult.consent_id} recorded.
								</span>
							</div>
							<p class="sf:mt-2 sf:text-sm sf:text-success-800">
								Server secret
								<code
									class="sf:rounded sf:bg-slate-200 sf:px-1.5 sf:py-0.5 sf:font-mono sf:text-[13px] sf:text-slate-800"
									>{validationResult.constant_name ?? constantName}</code
								>
								is now ready as credential #{validationResult.credential_id}.
							</p>
						</div>
					{/if}
				</div>
			</Card>
		</div>

		<Card title="Saved OpenRouter keys" data-testid="providers-openrouter-list-card">
			{#if loading}
				<StateTemplate variant="loading" title="Loading provider keys" dense />
			{:else if openRouterCredentials.length === 0}
				<StateTemplate
					variant="empty"
					title="No OpenRouter key saved"
					message="Validate a key or server secret to unlock the direct free path."
					dense
				/>
			{:else}
				<div class="sf:space-y-3">
					{#each openRouterCredentials as credential}
						{@const statusDetail = providerCredentialStatusDetail(credential)}
						<div
							class="sf:border-l sf:border-slate-300 sf:pl-3"
							data-testid="providers-openrouter-credential"
						>
							<div class="sf:flex sf:flex-wrap sf:items-center sf:justify-between sf:gap-2">
								<p class="sf:font-medium sf:text-slate-900">{credential.label}</p>
								<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
									<Badge variant={providerStatusVariant(credential.status)}
										>{providerStatusLabel(credential.status)}</Badge
									>
									{#if credential.auth_mode === 'constant'}
										<Badge variant="neutral">Server secret</Badge>
									{/if}
									<Button
										size="sm"
										variant="danger"
										disabled={deletingCredentialId === credential.id}
										onclick={() => confirmCredentialDelete(credential)}
										data-testid={`providers-openrouter-delete-${credential.id}`}
									>
										Delete
									</Button>
								</div>
							</div>
							<p class="sf:mt-1 sf:text-sm sf:text-slate-600">
								{providerCredentialAuthModeLabel(credential)} · {providerCredentialSecretSummary(
									credential
								)}
							</p>
							{#if credential.auth_mode === 'constant' && credential.constant_name}
								<p class="sf:mt-1 sf:text-xs sf:text-slate-500">
									Reference
									<code
										class="sf:rounded sf:bg-slate-200 sf:px-1.5 sf:py-0.5 sf:font-mono sf:text-[13px] sf:text-slate-800"
										>{credential.constant_name}</code
									>
								</p>
							{/if}
							<p class="sf:mt-1 sf:text-xs sf:text-slate-500">
								Last validated {formatTimestamp(credential.last_validated_at, 'never')}
							</p>
							{#if statusDetail}
								<p
									class={`sf:mt-2 sf:text-xs ${providerCredentialStatusDetailClass(credential)}`}
									data-testid="providers-openrouter-credential-status-detail"
								>
									{statusDetail}
								</p>
							{/if}
							{#if pendingDeleteCredentialId === credential.id}
								<div
									class="sf:mt-3 sf:rounded sf:border sf:border-danger-500 sf:bg-danger-50 sf:p-3"
									data-testid="providers-openrouter-delete-confirmation"
								>
									<p class="sf:text-sm sf:font-medium sf:text-danger-600">
										Delete this saved OpenRouter key?
									</p>
									<p class="sf:mt-1 sf:text-xs sf:text-slate-700">
										Actions using this key need another provider key before they can run.
									</p>
									<div class="sf:mt-3 sf:flex sf:flex-wrap sf:gap-2">
										<Button
											size="sm"
											variant="danger"
											loading={deletingCredentialId === credential.id}
											onclick={() => deleteProviderCredential(credential)}
											data-testid={`providers-openrouter-delete-confirm-${credential.id}`}
										>
											{deletingCredentialId === credential.id ? 'Deleting...' : 'Delete key'}
										</Button>
										<Button
											size="sm"
											variant="secondary"
											disabled={deletingCredentialId === credential.id}
											onclick={cancelCredentialDelete}
											data-testid={`providers-openrouter-delete-cancel-${credential.id}`}
										>
											Cancel
										</Button>
									</div>
								</div>
							{/if}
						</div>
					{/each}
				</div>
			{/if}
		</Card>
	</div>

	<div class="sf:grid sf:gap-4 sf:xl:grid-cols-[1fr_0.9fr]">
		<Card
			title={managedConsent.state === 'accepted'
				? 'Manage Sentient Forms Managed Service'
				: 'Enable Sentient Forms Managed Service'}
			data-testid="providers-managed-setup-card"
		>
			{#if !managedAccountReady}
				<StateTemplate
					variant="empty"
					title="Activate managed service first"
					message="Sentient Forms Managed Service needs an active subscription for this WordPress site. Direct OpenRouter remains available without Sentient Forms billing."
					actionLabel="Open Managed Service"
					onAction={() => navigateToAppPath('/licensing')}
					dense
					testId="providers-managed-account-required"
				/>
			{:else}
				<div class="sf:space-y-4">
					<div
						class={`sf:rounded sf:border sf:p-4 ${
							managedConsent.state === 'accepted'
								? 'sf:border-success-200 sf:bg-success-50'
								: managedConsent.state === 'revoked'
									? 'sf:border-amber-200 sf:bg-amber-50'
									: 'sf:border-slate-200 sf:bg-slate-50'
						}`}
						data-testid="providers-managed-consent-state"
					>
						<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
							<Badge variant={managedConsentBadgeVariant(managedConsent.state)}>
								{managedConsentLabel(managedConsent.state)}
							</Badge>
							{#if managedConsent.consentId}
								<Badge variant="neutral">Consent #{managedConsent.consentId}</Badge>
							{/if}
						</div>
						<h3 class="sf:mt-3 sf:text-base sf:font-semibold sf:text-slate-900">
							{#if managedConsent.state === 'accepted'}
								Sentient Forms Managed Service is allowed for this site
							{:else if managedConsent.state === 'revoked'}
								Sentient Forms Managed Service is disabled locally
							{:else}
								Consent is required before Sentient Forms Managed Service can run
							{/if}
						</h3>
						<p class="sf:mt-2 sf:text-sm sf:text-slate-700">
							{#if managedConsent.state === 'accepted'}
								This WordPress site may send rendered prompts and required form fields through the
								Sentient Forms Managed Service. You can revoke this local consent without canceling
								or changing the Stripe subscription.
							{:else if managedConsent.state === 'revoked'}
								New managed-service requests are blocked from this plugin until consent is enabled
								again. Revocation does not cancel or change the Stripe subscription.
							{:else}
								Enable managed service only when this site should use Sentient Forms billing and
								the central proxy for model runs.
							{/if}
						</p>
						{#if managedConsentTimestamp(managedConsent)}
							<p class="sf:mt-2 sf:text-xs sf:text-slate-600">
								Last changed {formatTimestamp(managedConsentTimestamp(managedConsent), 'unknown')}.
							</p>
						{/if}

						{#if managedConsent.state === 'accepted'}
							<div class="sf:mt-4">
								<Button
									variant="danger"
									loading={managedRevokeLoading}
									disabled={managedRevokeLoading}
									onclick={revokeSentientManagedProxy}
									data-testid="providers-managed-revoke-submit"
								>
									{managedRevokeLoading ? 'Revoking...' : 'Revoke managed-service consent'}
								</Button>
							</div>
						{/if}
					</div>

					{#if managedConsent.state !== 'accepted'}
						<form class="sf:space-y-4" onsubmit={setupSentientManagedProxy}>
							<InputField
								id="sentient-managed-label"
								label="Label"
								placeholder="Sentient Forms Managed Service"
								bind:value={managedLabel}
								disabled={managedSetupLoading}
							/>

							<label class="sf:flex sf:items-start sf:gap-3 sf:text-sm sf:text-slate-700">
								<input
									class="sf:mt-1 sf:h-4 sf:w-4 sf:rounded sf:border-slate-300"
									type="checkbox"
									bind:checked={acceptedManagedDisclosure}
									disabled={managedSetupLoading}
									required
								/>
								<span>
									I understand Sentient Forms receives the rendered prompt and required form fields
									for managed service runs, meters usage, and bills through my Sentient Forms plan.
								</span>
							</label>

							<Button
								type="submit"
								loading={managedSetupLoading}
								disabled={!canEnableManagedProxy}
								data-testid="providers-managed-setup-submit"
							>
								{#if managedSetupLoading}
									Enabling...
								{:else if managedConsent.state === 'revoked'}
									Re-enable managed service
								{:else}
									Enable managed service
								{/if}
							</Button>
						</form>
					{/if}
				</div>
			{/if}

			{#if managedSetupError}
				<p
					class="sf:mt-4 sf:text-sm sf:text-danger-700"
					role="alert"
					data-testid="providers-managed-setup-error"
				>
					{managedSetupError}
				</p>
			{/if}

			{#if managedRevokeError}
				<p
					class="sf:mt-4 sf:text-sm sf:text-danger-700"
					role="alert"
					data-testid="providers-managed-revoke-error"
				>
					{managedRevokeError}
				</p>
			{/if}

			{#if managedSetupResult}
				<div
					class="sf:mt-4 sf:rounded sf:border sf:border-success-200 sf:bg-success-50 sf:p-4"
					data-testid="providers-managed-setup-result"
				>
					<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
						<Badge variant={providerStatusVariant(managedSetupResult.status)}>
							{providerStatusLabel(managedSetupResult.status)}
						</Badge>
						<span class="sf:text-sm sf:text-success-800">
							Consent #{managedSetupResult.consent_id} recorded.
						</span>
					</div>
					<p class="sf:mt-2 sf:text-sm sf:text-success-800">
						Managed credential #{managedSetupResult.credential_id} is ready.
					</p>
				</div>
			{/if}

			{#if managedRevokeResult}
				<div
					class="sf:mt-4 sf:rounded sf:border sf:border-amber-200 sf:bg-amber-50 sf:p-4"
					data-testid="providers-managed-revoke-result"
				>
					<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
						<Badge variant="warning">Consent revoked</Badge>
						<span class="sf:text-sm sf:text-amber-800">
							Consent #{managedRevokeResult.consent_id} recorded.
						</span>
					</div>
					<p class="sf:mt-2 sf:text-sm sf:text-amber-800">
						The local managed-service credential is disabled. Stripe billing was not changed.
					</p>
				</div>
			{/if}
		</Card>

		<Card
			title="Sentient Forms Managed Service credentials"
			data-testid="providers-managed-list-card"
		>
			{#if loading}
				<StateTemplate variant="loading" title="Loading managed service credentials" dense />
			{:else if managedCredentials.length === 0}
				<StateTemplate
					variant="empty"
					title="No managed service credential"
					message="Activate managed service, accept the disclosure, then enable the site credential."
					dense
				/>
			{:else}
				<div class="sf:space-y-3">
					{#each managedCredentials as credential}
						{@const statusDetail = providerCredentialStatusDetail(credential)}
						{@const credentialConsent = readManagedConsent(credential)}
						<div
							class="sf:border-l sf:border-slate-300 sf:pl-3"
							data-testid="providers-managed-credential"
						>
							<div class="sf:flex sf:flex-wrap sf:items-center sf:justify-between sf:gap-2">
								<p class="sf:font-medium sf:text-slate-900">{credential.label}</p>
								<div class="sf:flex sf:flex-wrap sf:gap-2">
									<Badge variant={providerStatusVariant(credential.status)}
										>{providerStatusLabel(credential.status)}</Badge
									>
									<Badge variant={managedConsentBadgeVariant(credentialConsent.state)}>
										{managedConsentLabel(credentialConsent.state)}
									</Badge>
								</div>
							</div>
							<p class="sf:mt-1 sf:text-sm sf:text-slate-600">
								{providerCredentialAuthModeLabel(credential)} · {providerCredentialSecretSummary(
									credential
								)}
							</p>
							<p class="sf:mt-1 sf:text-xs sf:text-slate-500">
								Last checked {formatTimestamp(credential.last_validated_at, 'never')}
							</p>
							{#if statusDetail}
								<p
									class={`sf:mt-2 sf:text-xs ${providerCredentialStatusDetailClass(credential)}`}
									data-testid="providers-managed-credential-status-detail"
								>
									{statusDetail}
								</p>
							{/if}
						</div>
					{/each}
				</div>
			{/if}
		</Card>
	</div>

	<Card
		title="Action builder"
		subtitle="Create form-specific provider actions from the Actions screen."
		data-testid="providers-actions-builder-redirect-card"
	>
		{#if readyProviderCredentials.length === 0}
			<StateTemplate
				variant="empty"
				title={localSetupUnavailableTitle}
				message={localSetupUnavailableMessage}
				dense
				testId="providers-actions-builder-no-credential"
			/>
		{:else}
			<div
				class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-4 sf:lg:flex-row sf:lg:items-center"
			>
				<div class="sf:space-y-2">
					<div class="sf:flex sf:flex-wrap sf:gap-2">
						<Badge variant="success"
							>{readyOpenRouterCredentials.length} ready key{readyOpenRouterCredentials.length === 1
								? ''
								: 's'}</Badge
						>
						<Badge variant={readyManagedCredentials.length > 0 ? 'success' : 'neutral'}
							>{readyManagedCredentials.length} managed {readyManagedCredentials.length === 1
								? 'service credential'
								: 'service credentials'}</Badge
						>
						<Badge variant="info">Actions owns setup</Badge>
					</div>
					<p class="sf:max-w-2xl sf:text-sm sf:text-slate-600">
						Choose a form in Actions, then use Direct OpenRouter or the Sentient Forms managed
						service to create an action and mapping from the same screen where you manage hooks, run
						mode, and mapping health.
					</p>
				</div>
				<Button onclick={() => navigateToAppPath('/actions')} data-testid="providers-open-actions">
					Open Actions
				</Button>
			</div>
		{/if}
	</Card>
</Section>
