<script lang="ts">
	import { onMount } from 'svelte';
	import { ApiClientError, createClientFromConfig } from '$lib/api/client';
	import type {
		LocalProviderCredential,
		OpenRouterModelsResponse,
		OpenRouterValidateResponse,
		SentientManagedSetupResponse
	} from '$lib/api/types';
	import { Badge, Button, Card, InputField, Section, StateTemplate } from '$lib/components/ui';
	import { navigateToAppPath } from '$lib/navigation';
	import { licenseState } from '$lib/stores/license';
	import { notifications } from '$lib/stores/notifications';
	import { formatTimestamp } from '$lib/utils/date-time';
	import {
		isReadyOpenRouterCredential,
		localOpenRouterSetupUnavailableMessage,
		localOpenRouterSetupUnavailableTitle,
		providerCredentialStatusDetail,
		providerCredentialStatusDetailClass,
		providerStatusLabel,
		providerStatusVariant
	} from '$lib/utils/provider-health';

	const client = createClientFromConfig();
	const DISCLOSURE_VERSION = '2026-04-local-first-openrouter-v1';
	const MANAGED_DISCLOSURE_VERSION = '2026-04-sentient-managed-proxy-v1';

	let loading = $state(true);
	let credentials = $state<LocalProviderCredential[]>([]);
	let validationResult = $state<OpenRouterValidateResponse | null>(null);
	let managedSetupResult = $state<SentientManagedSetupResponse | null>(null);
	let modelCatalog = $state<OpenRouterModelsResponse | null>(null);
	let error = $state<string | null>(null);
	let managedSetupError = $state<string | null>(null);
	let modelCatalogError = $state<string | null>(null);
	let apiKey = $state('');
	let label = $state('OpenRouter key');
	let managedLabel = $state('Sentient managed proxy');
	let saveKey = $state(true);
	let acceptedDisclosure = $state(false);
	let acceptedManagedDisclosure = $state(false);
	let validating = $state(false);
	let deletingCredentialId = $state<number | null>(null);
	let pendingDeleteCredentialId = $state<number | null>(null);
	let managedSetupLoading = $state(false);
	let modelCatalogLoading = $state(true);
	let modelCatalogRefreshing = $state(false);

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
				credential.secret_configured
		)
	);
	let primaryOpenRouterCredential = $derived(openRouterCredentials[0] ?? null);
	let primaryManagedCredential = $derived(readyManagedCredentials[0] ?? managedCredentials[0] ?? null);
	let readyCredentialCount = $derived(
		openRouterCredentials.filter((credential) => credential.status === 'valid').length
	);
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
	let localSetupUnavailableTitle = $derived(
		managedAccountReady
			? 'Connect a provider before building actions'
			: localOpenRouterSetupUnavailableTitle(openRouterCredentials)
	);
	let localSetupUnavailableMessage = $derived(
		managedAccountReady
			? 'Enable the Sentient managed proxy credential here or validate an OpenRouter key for direct local execution.'
			: localOpenRouterSetupUnavailableMessage(openRouterCredentials)
	);
	let freeModelPreview = $derived(
		(modelCatalog?.models ?? []).filter((model) => model.free).slice(0, 6)
	);
	let canRefreshOpenRouterModels = $derived(acceptedDisclosure && !modelCatalogRefreshing);
	let canValidateOpenRouterKey = $derived(
		acceptedDisclosure && apiKey.trim().length > 0 && !validating
	);
	let canEnableManagedProxy = $derived(
		managedAccountReady && acceptedManagedDisclosure && !managedSetupLoading
	);

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
		validationResult = null;

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
		} catch (requestError) {
			error = errorMessage(requestError);
		} finally {
			validating = false;
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

		if (!managedAccountReady) {
			managedSetupError =
				'Activate a Sentient managed account before enabling managed proxy execution.';
			return;
		}

		if (!acceptedManagedDisclosure) {
			managedSetupError =
				'Accept the Sentient managed proxy disclosure before enabling managed execution.';
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

	onMount(() => {
		void loadCredentials();
		void loadModelCatalog();
	});
</script>

<Section
	heading="Providers"
	description="Connect OpenRouter directly from WordPress. Direct BYOK and free-model runs are not billed by Sentient."
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

	<Card class="sf:border-slate-300 sf:bg-slate-50" data-testid="providers-openrouter-summary">
		<div
			class="sf:flex sf:flex-col sf:gap-4 sf:lg:flex-row sf:lg:items-center sf:lg:justify-between"
		>
			<div class="sf:space-y-2">
				<div class="sf:flex sf:flex-wrap sf:gap-2">
					<Badge variant={providerStatusVariant(primaryOpenRouterCredential?.status ?? 'missing')}>
						{providerStatusLabel(primaryOpenRouterCredential?.status ?? 'missing')}
					</Badge>
					<Badge variant="info">Local credential storage</Badge>
				</div>
				<h3 class="sf:text-xl sf:font-semibold sf:text-slate-900">
					{primaryOpenRouterCredential?.label ?? 'OpenRouter direct'}
				</h3>
				<p class="sf:max-w-2xl sf:text-sm sf:text-slate-600">
					OpenRouter receives the prompts and form fields needed for direct model calls. Sentient
					does not receive those direct-call payloads.
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
					<Badge variant="info">Sentient billed</Badge>
				</div>
				<h3 class="sf:text-xl sf:font-semibold sf:text-slate-900">Sentient managed proxy</h3>
				<p class="sf:max-w-2xl sf:text-sm sf:text-slate-600">
					Sentient receives the rendered prompt and required form fields only for managed proxy
					runs. Direct OpenRouter runs stay outside Sentient billing.
				</p>
			</div>
			<div class="sf:flex sf:gap-6">
				<div>
					<p class="sf:text-xs sf:font-medium sf:text-slate-500">Saved proxies</p>
					<p
						class="sf:text-2xl sf:font-semibold sf:text-slate-900"
						data-testid="providers-managed-count"
					>
						{managedCredentials.length}
					</p>
				</div>
				<div>
					<p class="sf:text-xs sf:font-medium sf:text-slate-500">Ready proxies</p>
					<p
						class="sf:text-2xl sf:font-semibold sf:text-slate-900"
						data-testid="providers-managed-ready-count"
					>
						{readyManagedCredentials.length}
					</p>
				</div>
			</div>
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
					message="Accept the OpenRouter disclosure, then refresh the catalog before choosing a specific model."
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

			{#if validationResult}
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

		<Card title="Saved OpenRouter keys" data-testid="providers-openrouter-list-card">
			{#if loading}
				<StateTemplate variant="loading" title="Loading provider keys" dense />
			{:else if openRouterCredentials.length === 0}
				<StateTemplate
					variant="empty"
					title="No OpenRouter key saved"
					message="Validate a key to unlock the direct free path."
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
								{credential.auth_mode} · secret {credential.secret_configured
									? 'configured'
									: 'missing'}
							</p>
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
		<Card title="Enable Sentient managed proxy" data-testid="providers-managed-setup-card">
			{#if !managedAccountReady}
				<StateTemplate
					variant="empty"
					title="Activate managed billing first"
					message="Sentient managed proxy needs an active account and site proxy key. Direct OpenRouter remains available without Sentient billing."
					actionLabel="Open Billing"
					onAction={() => navigateToAppPath('/licensing')}
					dense
					testId="providers-managed-account-required"
				/>
			{:else}
				<form class="sf:space-y-4" onsubmit={setupSentientManagedProxy}>
					<InputField
						id="sentient-managed-label"
						label="Label"
						placeholder="Sentient managed proxy"
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
							I understand Sentient receives the rendered prompt and required form fields for
							managed proxy runs, meters usage, and bills through my Sentient plan.
						</span>
					</label>

					<Button
						type="submit"
						loading={managedSetupLoading}
						disabled={!canEnableManagedProxy}
						data-testid="providers-managed-setup-submit"
					>
						{managedSetupLoading ? 'Enabling...' : 'Enable managed proxy credential'}
					</Button>
				</form>
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
		</Card>

		<Card title="Sentient managed proxy credentials" data-testid="providers-managed-list-card">
			{#if loading}
				<StateTemplate variant="loading" title="Loading managed proxy credentials" dense />
			{:else if managedCredentials.length === 0}
				<StateTemplate
					variant="empty"
					title="No managed proxy credential"
					message="Activate managed billing, accept the disclosure, then enable a local proxy credential."
					dense
				/>
			{:else}
				<div class="sf:space-y-3">
					{#each managedCredentials as credential}
						{@const statusDetail = providerCredentialStatusDetail(credential)}
						<div
							class="sf:border-l sf:border-slate-300 sf:pl-3"
							data-testid="providers-managed-credential"
						>
							<div class="sf:flex sf:flex-wrap sf:items-center sf:justify-between sf:gap-2">
								<p class="sf:font-medium sf:text-slate-900">{credential.label}</p>
								<Badge variant={providerStatusVariant(credential.status)}
									>{providerStatusLabel(credential.status)}</Badge
								>
							</div>
							<p class="sf:mt-1 sf:text-sm sf:text-slate-600">
								{credential.auth_mode} · proxy key {credential.secret_configured
									? 'available'
									: 'missing'}
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
		title="Local action builder"
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
								? 'proxy'
								: 'proxies'}</Badge
						>
						<Badge variant="info">Actions owns setup</Badge>
					</div>
					<p class="sf:max-w-2xl sf:text-sm sf:text-slate-600">
						Choose a form in Actions, then use Direct OpenRouter or Sentient managed proxy to
						create a local action and mapping from the same screen where you manage hooks, run
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
