<script lang="ts">
	import { onMount } from 'svelte';
	import { ApiClientError, createClientFromConfig } from '$lib/api/client';
	import type {
		LocalCustomActionRecord,
		LocalFormMappingRecord,
		LocalProviderCredential,
		LocalProviderStatus,
		OpenRouterModelsResponse,
		OpenRouterValidateResponse
	} from '$lib/api/types';
	import { Badge, Button, Card, InputField, Section, StateTemplate } from '$lib/components/ui';
	import { formatTimestamp } from '$lib/utils/date-time';

	type BadgeVariant = 'neutral' | 'success' | 'warning' | 'danger' | 'info';
	type LocalSubmissionSetupResult = {
		action: LocalCustomActionRecord;
		mapping: LocalFormMappingRecord;
	};

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
	let selectedCredentialId = $state('');
	let setupFormId = $state('');
	let setupNameFieldId = $state('1');
	let setupEmailFieldId = $state('2');
	let setupResultMetaKey = $state('sentient_forms_summary');
	let setupActionName = $state('Local OpenRouter summary');
	let localSetupError = $state<string | null>(null);
	let localSetupResult = $state<LocalSubmissionSetupResult | null>(null);
	let creatingLocalSetup = $state(false);
	let modelCatalogLoading = $state(true);
	let modelCatalogRefreshing = $state(false);

	let openRouterCredentials = $derived(
		credentials.filter((credential) => credential.provider === 'openrouter')
	);
	let readyOpenRouterCredentials = $derived(
		openRouterCredentials.filter(
			(credential) => credential.status === 'valid' && credential.secret_configured
		)
	);
	let primaryOpenRouterCredential = $derived(openRouterCredentials[0] ?? null);
	let selectedOpenRouterCredential = $derived(
		readyOpenRouterCredentials.find(
			(credential) => String(credential.id) === selectedCredentialId
		) ?? readyOpenRouterCredentials[0] ?? null
	);
	let readyCredentialCount = $derived(
		openRouterCredentials.filter((credential) => credential.status === 'valid').length
	);
	let localSetupReady = $derived(
		Boolean(selectedOpenRouterCredential) && String(setupFormId).trim().length > 0
	);
	let freeModelPreview = $derived(
		(modelCatalog?.models ?? []).filter((model) => model.free).slice(0, 6)
	);

	$effect(() => {
		const selectedStillAvailable = readyOpenRouterCredentials.some(
			(credential) => String(credential.id) === selectedCredentialId
		);

		if (readyOpenRouterCredentials.length === 0) {
			selectedCredentialId = '';
			return;
		}

		if (!selectedCredentialId || !selectedStillAvailable) {
			selectedCredentialId = String(readyOpenRouterCredentials[0].id);
		}
	});

	function statusVariant(status: LocalProviderStatus | 'missing'): BadgeVariant {
		switch (status) {
			case 'valid':
				return 'success';
			case 'limited':
				return 'warning';
			case 'invalid':
			case 'disabled':
				return 'danger';
			default:
				return 'neutral';
		}
	}

	function statusLabel(status: LocalProviderStatus | 'missing'): string {
		switch (status) {
			case 'valid':
				return 'Ready';
			case 'limited':
				return 'Limited';
			case 'invalid':
				return 'Invalid';
			case 'disabled':
				return 'Disabled';
			default:
				return 'Not connected';
		}
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
			modelCatalog = await client.getOpenRouterModels(
				{ limit: 100 },
				{ showNotifications: false }
			);
		} catch (requestError) {
			modelCatalogError = errorMessage(requestError);
		} finally {
			modelCatalogLoading = false;
		}
	}

	async function refreshOpenRouterModels(): Promise<void> {
		modelCatalogError = null;

		if (!acceptedDisclosure) {
			modelCatalogError = 'Accept the OpenRouter external-service disclosure before refreshing model metadata.';
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

	function normalizeActionCode(value: string): string {
		const normalized = value
			.toLowerCase()
			.replace(/[^a-z0-9]+/g, '_')
			.replace(/^_+|_+$/g, '')
			.slice(0, 48);

		return normalized.length > 0 ? normalized : 'local_openrouter_summary';
	}

	function isSafeMetaKey(value: string): boolean {
		return /^[A-Za-z0-9_:-]+$/.test(value);
	}

	async function createLocalSubmissionSetup(event: SubmitEvent): Promise<void> {
		event.preventDefault();

		localSetupError = null;
		localSetupResult = null;

		const credential = selectedOpenRouterCredential;
		const formId = String(setupFormId).trim();
		const nameFieldId = String(setupNameFieldId).trim();
		const emailFieldId = String(setupEmailFieldId).trim();
		const resultMetaKey = String(setupResultMetaKey).trim();
		const actionName = String(setupActionName).trim() || 'Local OpenRouter summary';

		if (!credential) {
			localSetupError = 'Save and validate an OpenRouter key before creating a local action.';
			return;
		}

		if (!/^\d+$/.test(formId)) {
			localSetupError = 'Enter the numeric Gravity Forms form ID.';
			return;
		}

		if (nameFieldId.length === 0 || emailFieldId.length === 0) {
			localSetupError = 'Enter the Gravity Forms field IDs for name and email.';
			return;
		}

		if (!isSafeMetaKey(resultMetaKey)) {
			localSetupError = 'Use letters, numbers, underscores, colons, or dashes for the result meta key.';
			return;
		}

		creatingLocalSetup = true;

		try {
			const timestamp = Date.now();
			const action = await client.createLocalCustomAction(
				{
					code: `${normalizeActionCode(actionName)}_${timestamp}`,
					display_name: actionName,
					definition_json: {
						system_prompt:
							'You summarize Gravity Forms submissions for a WordPress site owner. Return only compact JSON with a summary field.',
						prompt_template:
							'Form: {{form.title}}\nName: {{name}}\nEmail: {{email}}\n\nReturn JSON shaped as {"summary":"one concise sentence about this submission"}.',
						response_format: { type: 'json_object' },
						max_tokens: 250,
						temperature: 0.2
					},
					model_selection_json: {
						provider: 'openrouter',
						model: 'openrouter/auto',
						credential_id: credential.id
					},
					status: 'active'
				},
				{ showNotifications: false }
			);

			const meta: Record<string, string> = {};
			meta[resultMetaKey] = 'structured.summary';

			const mapping = await client.createLocalFormMapping(
				{
					form_source: 'gravity_forms',
					form_id: formId,
					hook: 'gform_after_submission',
					action_kind: 'custom_action',
					action_id: action.id,
					input_bindings_json: {
						name: nameFieldId,
						email: emailFieldId
					},
					execution_mode: 'sync',
					effect_mapping_json: {
						store_result: true,
						meta
					},
					enabled: true
				},
				{ showNotifications: false }
			);

			localSetupResult = { action, mapping };
		} catch (requestError) {
			localSetupError = errorMessage(requestError);
		} finally {
			creatingLocalSetup = false;
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
		<div class="sf:flex sf:flex-col sf:gap-4 sf:lg:flex-row sf:lg:items-center sf:lg:justify-between">
			<div class="sf:space-y-2">
				<div class="sf:flex sf:flex-wrap sf:gap-2">
					<Badge variant={statusVariant(primaryOpenRouterCredential?.status ?? 'missing')}>
						{statusLabel(primaryOpenRouterCredential?.status ?? 'missing')}
					</Badge>
					<Badge variant="info">Local credential storage</Badge>
				</div>
				<h3 class="sf:text-xl sf:font-semibold sf:text-slate-900">
					{primaryOpenRouterCredential?.label ?? 'OpenRouter direct'}
				</h3>
				<p class="sf:max-w-2xl sf:text-sm sf:text-slate-600">
					OpenRouter receives the prompts and form fields needed for direct model calls. Sentient does not receive those direct-call payloads.
				</p>
			</div>
			<div class="sf:flex sf:gap-6">
				<div>
					<p class="sf:text-xs sf:font-medium sf:text-slate-500">Saved keys</p>
					<p class="sf:text-2xl sf:font-semibold sf:text-slate-900" data-testid="providers-openrouter-count">
						{openRouterCredentials.length}
					</p>
				</div>
				<div>
					<p class="sf:text-xs sf:font-medium sf:text-slate-500">Ready keys</p>
					<p class="sf:text-2xl sf:font-semibold sf:text-slate-900" data-testid="providers-openrouter-ready-count">
						{readyCredentialCount}
					</p>
				</div>
			</div>
		</div>
	</Card>

	<Card title="OpenRouter model catalog" data-testid="providers-openrouter-model-catalog">
		<div class="sf:flex sf:flex-col sf:gap-4 sf:lg:flex-row sf:lg:items-start sf:lg:justify-between">
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
					Refresh pulls model names, pricing, capabilities, and free-model flags from OpenRouter. No form data or prompts are sent.
				</p>
				{#if modelCatalogError}
					<p class="sf:text-sm sf:text-danger-700" role="alert" data-testid="providers-model-catalog-error">
						{modelCatalogError}
					</p>
				{/if}
			</div>

			<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-6">
				<div>
					<p class="sf:text-xs sf:font-medium sf:text-slate-500">Cached models</p>
					<p class="sf:text-2xl sf:font-semibold sf:text-slate-900" data-testid="providers-openrouter-model-count">
						{modelCatalogLoading ? '...' : (modelCatalog?.total_cached ?? 0)}
					</p>
				</div>
				<div>
					<p class="sf:text-xs sf:font-medium sf:text-slate-500">Free models</p>
					<p class="sf:text-2xl sf:font-semibold sf:text-slate-900" data-testid="providers-openrouter-free-model-count">
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
					<div class="sf:rounded sf:border sf:border-slate-200 sf:bg-white sf:p-3" data-testid="providers-openrouter-free-model">
						<div class="sf:flex sf:items-start sf:justify-between sf:gap-2">
							<p class="sf:text-sm sf:font-medium sf:text-slate-900">{model.name}</p>
							<Badge variant={model.stale ? 'warning' : 'success'}>{model.stale ? 'Stale' : 'Free'}</Badge>
						</div>
						<p class="sf:mt-1 sf:break-all sf:text-xs sf:text-slate-500">{model.id}</p>
						<p class="sf:mt-2 sf:text-xs sf:text-slate-600">
							Context {model.context_length ?? 'unknown'} tokens · refreshed {formatTimestamp(model.fetched_at, 'never')}
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
							rel="noreferrer noopener"
						>OpenRouter terms</a>
						and
						<a
							class="sf:font-medium sf:text-slate-900 sf:underline"
							href="https://openrouter.ai/privacy"
							target="_blank"
							rel="noreferrer noopener"
						>privacy policy</a>.
					</span>
				</label>

				<Button type="submit" loading={validating} disabled={validating}>
					{validating ? 'Validating...' : 'Validate key'}
				</Button>
			</form>

			{#if validationResult}
				<div class="sf:mt-4 sf:rounded sf:border sf:border-success-200 sf:bg-success-50 sf:p-4" data-testid="providers-openrouter-validation-result">
					<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
						<Badge variant={statusVariant(validationResult.status)}>
							{statusLabel(validationResult.status)}
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
						<div class="sf:border-l sf:border-slate-300 sf:pl-3" data-testid="providers-openrouter-credential">
							<div class="sf:flex sf:flex-wrap sf:items-center sf:justify-between sf:gap-2">
								<p class="sf:font-medium sf:text-slate-900">{credential.label}</p>
								<Badge variant={statusVariant(credential.status)}>{statusLabel(credential.status)}</Badge>
							</div>
							<p class="sf:mt-1 sf:text-sm sf:text-slate-600">
								{credential.auth_mode} · secret {credential.secret_configured ? 'configured' : 'missing'}
							</p>
							<p class="sf:mt-1 sf:text-xs sf:text-slate-500">
								Last validated {formatTimestamp(credential.last_validated_at, 'never')}
							</p>
						</div>
					{/each}
				</div>
			{/if}
		</Card>
	</div>

	<Card
		title="Local Gravity Forms setup"
		subtitle="Create a direct OpenRouter action and after-submission mapping from local WordPress tables."
		data-testid="providers-local-submission-setup-card"
	>
		<form class="sf:space-y-5" onsubmit={createLocalSubmissionSetup}>
			{#if readyOpenRouterCredentials.length === 0}
				<StateTemplate
					variant="empty"
					title="No ready OpenRouter key"
					message="Validate and save a key before creating a local action."
					dense
					testId="local-setup-no-credential"
				/>
			{:else}
				<div class="sf:grid sf:gap-4 sf:lg:grid-cols-2">
					<div class="sf:space-y-1">
						<label class="sf:text-sm sf:font-medium sf:text-slate-700" for="local-setup-credential">
							OpenRouter key
						</label>
						<select
							id="local-setup-credential"
							class="sf:w-full sf:rounded sf:border sf:border-slate-300 sf:bg-white sf:px-3 sf:py-2 sf:text-sm sf:focus-visible:border-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
							bind:value={selectedCredentialId}
							disabled={creatingLocalSetup}
							data-testid="local-setup-credential"
						>
							{#each readyOpenRouterCredentials as credential}
								<option value={String(credential.id)}>{credential.label} · #{credential.id}</option>
							{/each}
						</select>
					</div>

					<InputField
						id="local-setup-action-name"
						label="Action name"
						placeholder="Local OpenRouter summary"
						bind:value={setupActionName}
						disabled={creatingLocalSetup}
						data-testid="local-setup-action-name"
					/>
				</div>

				<div class="sf:grid sf:gap-4 sf:lg:grid-cols-4">
					<InputField
						id="local-setup-form-id"
						label="Form ID"
						inputmode="numeric"
						placeholder="1"
						bind:value={setupFormId}
						disabled={creatingLocalSetup}
						required
						data-testid="local-setup-form-id"
					/>
					<InputField
						id="local-setup-name-field"
						label="Name field ID"
						placeholder="1"
						bind:value={setupNameFieldId}
						disabled={creatingLocalSetup}
						required
						data-testid="local-setup-name-field"
					/>
					<InputField
						id="local-setup-email-field"
						label="Email field ID"
						placeholder="2"
						bind:value={setupEmailFieldId}
						disabled={creatingLocalSetup}
						required
						data-testid="local-setup-email-field"
					/>
					<InputField
						id="local-setup-result-meta-key"
						label="Result meta key"
						placeholder="sentient_forms_summary"
						bind:value={setupResultMetaKey}
						disabled={creatingLocalSetup}
						required
						data-testid="local-setup-result-meta-key"
					/>
				</div>

				<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-3">
					<Button
						type="submit"
						loading={creatingLocalSetup}
						disabled={creatingLocalSetup || !localSetupReady}
						data-testid="local-setup-submit"
					>
						{creatingLocalSetup ? 'Creating setup...' : 'Create local setup'}
					</Button>
					<p class="sf:text-sm sf:text-slate-600">
						Runs synchronously on Gravity Forms after-submission and stores the JSON summary in entry meta.
					</p>
				</div>
			{/if}
		</form>

		{#if localSetupError}
			<div
				class="sf:mt-4 sf:rounded sf:border sf:border-danger-200 sf:bg-danger-50 sf:p-4 sf:text-sm sf:text-danger-800"
				role="alert"
				data-testid="local-setup-error"
			>
				{localSetupError}
			</div>
		{/if}

		{#if localSetupResult}
			<div
				class="sf:mt-4 sf:rounded sf:border sf:border-success-200 sf:bg-success-50 sf:p-4"
				data-testid="local-setup-result"
			>
				<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
					<Badge variant="success">Ready</Badge>
					<p class="sf:text-sm sf:font-medium sf:text-success-900">
						Action #{localSetupResult.action.id} mapped to form #{localSetupResult.mapping.form_id}.
					</p>
				</div>
				<p class="sf:mt-2 sf:text-sm sf:text-success-800">
					Submit the form once to confirm OpenRouter execution and entry-meta storage.
				</p>
			</div>
		{/if}
	</Card>
</Section>
