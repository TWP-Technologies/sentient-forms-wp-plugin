<script lang="ts">
	import { onMount } from 'svelte';
	import { ApiClientError, createClientFromConfig } from '$lib/api/client';
	import type {
		LocalProviderCredential,
		OpenRouterModelsResponse,
		OpenRouterValidateResponse
	} from '$lib/api/types';
	import { Badge, Button, Card, InputField, Section, StateTemplate } from '$lib/components/ui';
	import { navigateToAppPath } from '$lib/navigation';
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

	let loading = $state(true);
	let credentials = $state<LocalProviderCredential[]>([]);
	let validationResult = $state<OpenRouterValidateResponse | null>(null);
	let modelCatalog = $state<OpenRouterModelsResponse | null>(null);
	let error = $state<string | null>(null);
	let modelCatalogError = $state<string | null>(null);
	let apiKey = $state('');
	let label = $state('OpenRouter key');
	let saveKey = $state(true);
	let acceptedDisclosure = $state(false);
	let validating = $state(false);
	let modelCatalogLoading = $state(true);
	let modelCatalogRefreshing = $state(false);

	let openRouterCredentials = $derived(
		credentials.filter((credential) => credential.provider === 'openrouter')
	);
	let readyOpenRouterCredentials = $derived(
		openRouterCredentials.filter((credential) => isReadyOpenRouterCredential(credential))
	);
	let primaryOpenRouterCredential = $derived(openRouterCredentials[0] ?? null);
	let readyCredentialCount = $derived(
		openRouterCredentials.filter((credential) => credential.status === 'valid').length
	);
	let localSetupUnavailableTitle = $derived(
		localOpenRouterSetupUnavailableTitle(openRouterCredentials)
	);
	let localSetupUnavailableMessage = $derived(
		localOpenRouterSetupUnavailableMessage(openRouterCredentials)
	);
	let freeModelPreview = $derived(
		(modelCatalog?.models ?? []).filter((model) => model.free).slice(0, 6)
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
					disabled={modelCatalogRefreshing}
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

				<Button type="submit" loading={validating} disabled={validating}>
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
								<Badge variant={providerStatusVariant(credential.status)}
									>{providerStatusLabel(credential.status)}</Badge
								>
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
						</div>
					{/each}
				</div>
			{/if}
		</Card>
	</div>

	<Card
		title="Local action builder"
		subtitle="Create form-specific OpenRouter actions from the Actions screen."
		data-testid="providers-actions-builder-redirect-card"
	>
		{#if readyOpenRouterCredentials.length === 0}
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
						<Badge variant="info">Actions owns setup</Badge>
					</div>
					<p class="sf:max-w-2xl sf:text-sm sf:text-slate-600">
						Choose a form in Actions, then use Direct OpenRouter to create a local action and
						mapping from the same screen where you manage hooks, run mode, and mapping health.
					</p>
				</div>
				<Button onclick={() => navigateToAppPath('/actions')} data-testid="providers-open-actions">
					Open Actions
				</Button>
			</div>
		{/if}
	</Card>
</Section>
