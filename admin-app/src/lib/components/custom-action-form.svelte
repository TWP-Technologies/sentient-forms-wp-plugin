<!--
  CustomActionForm.svelte - guided form for creating/editing custom actions.
  Template IDs and action codes are derived from action definitions so webmasters configure intent and effects.
-->
<script lang="ts">
	import {
		Alert,
		Badge,
		Button,
		Card,
		InputField,
		ModelSelect,
		SelectField,
		TextareaField,
		Toggle,
		ValidationSummary
	} from '$lib/components/ui';
	import {
		customActionCreateSchema,
		customActionUpdateSchema,
		type CustomActionCreateInput,
		type CustomActionUpdateInput
	} from '$lib/schemas/custom-action';
	import type {
		ActionDefinition,
		ActionDefinitionPayload,
		CustomAction,
		CustomActionPostExecutionActionPayload
	} from '$lib/api/types';
	import { generateCustomActionCode } from '$lib/utils/custom-actions';

	interface Props {
		/** Existing action data for edit mode */
		initialData?: CustomAction | null;
		/** Available action definitions with template IDs */
		definitions?: ActionDefinition[];
		/** Submit handler - returns void or throws */
		onSubmit: (data: CustomActionCreateInput | CustomActionUpdateInput) => Promise<void>;
		/** Cancel handler */
		onCancel?: () => void;
		/** Whether form is in submitting state */
		submitting?: boolean;
	}

	const UUID_RE =
		/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

	const LOCAL_TEMPLATE_ID_RE = /^[1-9]\d*$/;

	let {
		initialData = null,
		definitions = [],
		onSubmit,
		onCancel,
		submitting = false
	}: Props = $props();

	const isEditMode = $derived(initialData !== null);

	function isRecord(value: unknown): value is Record<string, unknown> {
		return Boolean(value && typeof value === 'object' && !Array.isArray(value));
	}

	function resolveTemplateId(definition: ActionDefinition): string | null {
		const candidate = definition.templateId ?? definition.id;
		return UUID_RE.test(candidate ?? '') || LOCAL_TEMPLATE_ID_RE.test(candidate ?? '')
			? (candidate as string)
			: null;
	}

	function definitionLabel(definition: ActionDefinition): string {
		return definition.label ?? definition.id;
	}

	function getPostExecutionActions(
		definition: ActionDefinitionPayload | null | undefined
	): CustomActionPostExecutionActionPayload[] {
		const defaults = definition?.execution_defaults;
		if (!isRecord(defaults)) return [];

		const actions = defaults.post_execution_actions;
		return Array.isArray(actions)
			? actions.filter((action): action is CustomActionPostExecutionActionPayload => isRecord(action))
			: [];
	}

	function firstEffect(
		type: CustomActionPostExecutionActionPayload['type']
	): CustomActionPostExecutionActionPayload | null {
		return initialEffects.find((effect) => effect.type === type) ?? null;
	}

	const selectableDefinitions = $derived(
		definitions
			.map((definition) => ({
				definition,
				templateId: resolveTemplateId(definition)
			}))
			.filter((item): item is { definition: ActionDefinition; templateId: string } =>
				Boolean(item.templateId)
			)
	);
	const baseActionOptions = $derived(
		selectableDefinitions.map((item) => ({
			value: item.templateId,
			label: `${definitionLabel(item.definition)}${
				item.definition.baseCreditCost !== null && item.definition.baseCreditCost !== undefined
					? ` (${item.definition.baseCreditCost} credits)`
					: ''
			}`
		}))
	);

	const initialEffects = getPostExecutionActions(initialData?.definition);
	const initialEntryNote = firstEffect('entry_note');
	const initialEmail = firstEffect('send_email');
	const initialHook = firstEffect('wp_hook');
	const initialWebhook = firstEffect('webhook');

	let templateId = $state(initialData?.template_id ?? '');
	let displayName = $state(initialData?.display_name ?? '');
	let description = $state(initialData?.description ?? '');
	let promptOverrides = $state<Record<string, unknown>>(initialData?.prompt_overrides ?? {});
	let customInstructions = $state(
		typeof initialData?.prompt_overrides?.custom_instructions === 'string'
			? initialData.prompt_overrides.custom_instructions
			: ''
	);
	let modelHint = $state<string | null>(initialData?.model_hint ?? null);
	let addEntryNote = $state(initialData === null || Boolean(initialEntryNote));
	let entryNoteMessage = $state(
		typeof initialEntryNote?.message === 'string'
			? initialEntryNote.message
			: 'Sentient Forms completed {{action_label}}. Result: {{llm_output}}'
	);
	let sendEmail = $state(Boolean(initialEmail));
	let emailRecipients = $state(
		Array.isArray(initialEmail?.to)
			? initialEmail.to.join(', ')
			: typeof initialEmail?.to === 'string'
				? initialEmail.to
				: ''
	);
	let emailSubject = $state(
		typeof initialEmail?.subject === 'string'
			? initialEmail.subject
			: 'Sentient Forms completed {{action_label}}'
	);
	let emailBody = $state(
		typeof initialEmail?.body === 'string'
			? initialEmail.body
			: '{{llm_output}}\n\n{{justification}}'
	);
	let triggerHook = $state(Boolean(initialHook));
	let hookName = $state(
		typeof initialHook?.hook_name === 'string'
			? initialHook.hook_name
			: 'sentient_forms_custom_action_completed'
	);
	let callWebhook = $state(Boolean(initialWebhook));
	let webhookUrl = $state(typeof initialWebhook?.url === 'string' ? initialWebhook.url : '');

	const selectedDefinition = $derived(
		selectableDefinitions.find((item) => item.templateId === templateId)?.definition ?? null
	);
	const selectedOutputContract = $derived(
		initialData?.output_contract ??
			(selectedDefinition?.structuredOutputSchema
				? { schema: selectedDefinition.structuredOutputSchema }
				: null)
	);
	const generatedCode = $derived(generateCustomActionCode(displayName));
	const baseTemplateUnavailable = $derived(!isEditMode && selectableDefinitions.length === 0);

	let errors = $state<Array<{ path: string; message: string }>>([]);

	$effect(() => {
		if (!isEditMode && !templateId && selectableDefinitions[0]) {
			templateId = selectableDefinitions[0].templateId;
		}
	});

	function buildPostExecutionActions(): CustomActionPostExecutionActionPayload[] {
		const actions: CustomActionPostExecutionActionPayload[] = [];

		if (addEntryNote) {
			actions.push({
				type: 'entry_note',
				message: entryNoteMessage.trim() || 'Sentient Forms completed {{action_label}}.'
			});
		}

		if (sendEmail) {
			actions.push({
				type: 'send_email',
				to: emailRecipients.trim(),
				subject: emailSubject.trim() || 'Sentient Forms completed {{action_label}}',
				body: emailBody.trim() || '{{llm_output}}'
			});
		}

		if (triggerHook) {
			actions.push({
				type: 'wp_hook',
				hook_name: hookName.trim() || 'sentient_forms_custom_action_completed'
			});
		}

		if (callWebhook) {
			actions.push({
				type: 'webhook',
				url: webhookUrl.trim(),
				method: 'POST'
			});
		}

		return actions;
	}

	function basePromptTemplate(): string {
		if (
			isRecord(initialData?.definition) &&
			typeof initialData.definition.prompt_template === 'string' &&
			initialData.definition.prompt_template.trim().length > 0
		) {
			return initialData.definition.prompt_template.trim();
		}

		if (typeof selectedDefinition?.promptTemplate === 'string') {
			const prompt = selectedDefinition.promptTemplate.trim();
			if (prompt.length > 0) return prompt;
		}

		return 'Review this WordPress form submission and return the requested structured output. Form: {{form.title}} Entry: {{entry}}';
	}

	function buildDefinition(): ActionDefinitionPayload {
		const baseDefinition = isRecord(initialData?.definition) ? initialData.definition : {};
		const executionDefaults = isRecord(baseDefinition.execution_defaults)
			? baseDefinition.execution_defaults
			: {};
		const instructions = customInstructions.trim();
		const promptTemplate = instructions
			? `${basePromptTemplate()}\n\nCustom webmaster instructions:\n${instructions}`
			: basePromptTemplate();

		return {
			...baseDefinition,
			description: description.trim() || null,
			prompt_template: promptTemplate,
			...(selectedDefinition?.structuredOutputSchema
				? { structured_output_schema: selectedDefinition.structuredOutputSchema }
				: {}),
			...(instructions ? { meta_prompt: instructions, goal: instructions } : {}),
			execution_defaults: {
				...executionDefaults,
				post_execution_actions: buildPostExecutionActions()
			}
		} as ActionDefinitionPayload;
	}

	function buildPromptOverrides(): Record<string, unknown> | undefined {
		const next = { ...promptOverrides };
		const instructions = customInstructions.trim();

		if (instructions) {
			next.custom_instructions = instructions;
		} else {
			delete next.custom_instructions;
		}

		return Object.keys(next).length > 0 ? next : undefined;
	}

	function buildPayload(): CustomActionCreateInput | CustomActionUpdateInput {
		const base = {
			display_name: displayName.trim(),
			description: description.trim() || null,
			prompt_overrides: buildPromptOverrides(),
			model_hint: modelHint?.trim() || null,
			action_kind: initialData?.action_kind ?? 'template_override',
			definition: buildDefinition(),
			definition_version: initialData?.definition_version ?? 1,
			output_contract: selectedOutputContract,
			supported_execution_modes: initialData?.supported_execution_modes ?? ['after_submission']
		};

		if (isEditMode) {
			return base as CustomActionUpdateInput;
		}

		return {
			...base,
			template_id: templateId.trim(),
			code: generatedCode
		} as CustomActionCreateInput;
	}

	async function handleSubmit(event: SubmitEvent) {
		event.preventDefault();
		errors = [];

		if (baseTemplateUnavailable) {
			errors = [
				{
					path: 'template_id',
					message:
						'Action template IDs are unavailable. Refresh action templates before creating a custom action.'
				}
			];
			return;
		}

		const payload = buildPayload();
		const schema = isEditMode ? customActionUpdateSchema : customActionCreateSchema;
		const result = schema.safeParse(payload);

		if (result.success === false) {
			errors = result.error.issues.map((issue) => ({
				path: issue.path.join('.'),
				message: issue.message
			}));
			return;
		}

		try {
			await onSubmit(result.data);
		} catch (e) {
			if (e instanceof Error) {
				errors = [{ path: '', message: e.message }];
			}
		}
	}

	function handleModelChange(value: string | null) {
		modelHint = value;
	}
</script>

<Card>
	<h2 class="sf:text-base sf:font-semibold sf:text-slate-800 sf:mb-1">
		{isEditMode ? 'Edit Custom Action' : 'Create Custom Action'}
	</h2>
	<p class="sf:text-sm sf:text-slate-500 sf:mb-4">
		{isEditMode
			? 'Update instructions and post-execution abilities for this custom action.'
			: 'Choose the base action template, describe what should happen, and select concrete abilities.'}
	</p>

	<form
		class="sf:flex sf:flex-col sf:gap-5"
		data-testid="custom-action-form"
		onsubmit={handleSubmit}
	>
		{#if baseTemplateUnavailable}
			<Alert variant="warning">
				Action template IDs are not available in the current definitions response. Custom actions
				need a base action template.
			</Alert>
		{/if}

		{#if !isEditMode}
			<SelectField
				id="custom-action-base-action"
				label="Base Action"
				bind:value={templateId}
				options={baseActionOptions}
				required
				disabled={baseTemplateUnavailable}
				placeholder="Select a base action"
				description="The action template this custom action extends. The technical template ID is handled automatically."
			/>
		{:else}
			<div class="sf:flex sf:flex-col sf:gap-1">
				<span class="sf:text-sm sf:font-medium sf:text-slate-700">Base Action</span>
				<p
					class="sf:text-sm sf:text-slate-600 sf:bg-slate-50 sf:px-3 sf:py-2 sf:rounded-md sf:border sf:border-slate-200"
				>
					{selectedDefinition ? definitionLabel(selectedDefinition) : 'Existing action template'}
				</p>
			</div>
		{/if}

		<InputField
			id="custom-action-display-name"
			label="Display Name"
			bind:value={displayName}
			placeholder="Marketing follow-up"
			required
		/>

		{#if !isEditMode}
			<div class="sf:flex sf:flex-col sf:gap-1">
				<span class="sf:text-sm sf:font-medium sf:text-slate-700">Generated Code</span>
				<p
					class="sf:text-sm sf:font-mono sf:text-slate-600 sf:bg-slate-100 sf:px-3 sf:py-2 sf:rounded-md"
					data-testid="custom-action-generated-code"
				>
					{generatedCode || 'Enter a display name to generate a code'}
				</p>
				<p class="sf:text-xs sf:text-slate-500">
					This stable internal code is generated from the display name and is not edited by the
					webmaster.
				</p>
			</div>
		{:else}
			<div class="sf:flex sf:flex-col sf:gap-1">
				<span class="sf:text-sm sf:font-medium sf:text-slate-700">Generated Code</span>
				<p
					class="sf:text-sm sf:font-mono sf:text-slate-600 sf:bg-slate-100 sf:px-3 sf:py-2 sf:rounded-md"
				>
					{initialData?.code}
				</p>
				<p class="sf:text-xs sf:text-slate-500">Code cannot be changed after creation.</p>
			</div>
		{/if}

		<TextareaField
			id="custom-action-description"
			label="Description"
			bind:value={description}
			rows={3}
			placeholder="Optional summary shown in the admin UI."
		/>

		<TextareaField
			id="custom-action-instructions"
			label="Custom Instructions"
			bind:value={customInstructions}
			rows={5}
			placeholder="Tell the AI exactly what to do, what tone to use, and what output you need."
			description="These instructions are stored locally and appended to the base prompt for this action."
		/>

		<div class="sf:rounded-lg sf:border sf:border-slate-200 sf:bg-slate-50 sf:p-4 sf:space-y-4">
			<div class="sf:flex sf:items-center sf:justify-between sf:gap-3">
				<div>
					<h3 class="sf:text-sm sf:font-semibold sf:text-slate-800">Action Abilities</h3>
					<p class="sf:text-xs sf:text-slate-500">
						Choose what WordPress should do after the provider returns a result.
					</p>
				</div>
				<Badge variant="info">{buildPostExecutionActions().length} enabled</Badge>
			</div>

			<div class="sf:grid sf:gap-4">
				<div class="sf:rounded-md sf:border sf:border-white sf:bg-white sf:p-3 sf:space-y-3">
					<Toggle
						id="custom-action-ability-entry-note"
						bind:checked={addEntryNote}
						label="Add an entry note"
						description="Write the action result back to the Gravity Forms entry for demo-visible auditability."
					/>
					{#if addEntryNote}
						<TextareaField
							id="custom-action-entry-note-message"
							label="Entry Note Message"
							bind:value={entryNoteMessage}
							rows={3}
							placeholder={'Follow up with {{field:1}} about {{llm_output}}.'}
						/>
					{/if}
				</div>

				<div class="sf:rounded-md sf:border sf:border-white sf:bg-white sf:p-3 sf:space-y-3">
					<Toggle
						id="custom-action-ability-email"
						bind:checked={sendEmail}
						label="Send an email"
						description="Email the site admin or a configured recipient after the action completes."
					/>
					{#if sendEmail}
						<InputField
							id="custom-action-email-recipients"
							label="Recipients"
							bind:value={emailRecipients}
							placeholder="Leave blank for site admin, or use {{field:2}}"
						/>
						<InputField
							id="custom-action-email-subject"
							label="Email Subject"
							bind:value={emailSubject}
							placeholder={'Sentient Forms completed {{action_label}}'}
						/>
						<TextareaField
							id="custom-action-email-body"
							label="Email Body"
							bind:value={emailBody}
							rows={4}
							placeholder={'{{llm_output}}'}
						/>
					{/if}
				</div>

				<div class="sf:rounded-md sf:border sf:border-white sf:bg-white sf:p-3 sf:space-y-3">
					<Toggle
						id="custom-action-ability-wp-hook"
						bind:checked={triggerHook}
						label="Trigger a WordPress hook"
						description="Let site code react to this action using do_action()."
					/>
					{#if triggerHook}
						<InputField
							id="custom-action-hook-name"
							label="Hook Name"
							bind:value={hookName}
							placeholder="sentient_forms_custom_action_completed"
						/>
					{/if}
				</div>

				<div class="sf:rounded-md sf:border sf:border-white sf:bg-white sf:p-3 sf:space-y-3">
					<Toggle
						id="custom-action-ability-webhook"
						bind:checked={callWebhook}
						label="Call a webhook"
						description="POST the action context and result to an external HTTP endpoint."
					/>
					{#if callWebhook}
						<InputField
							id="custom-action-webhook-url"
							label="Webhook URL"
							type="url"
							bind:value={webhookUrl}
							placeholder="https://example.com/sentient-forms-webhook"
						/>
					{/if}
				</div>
			</div>

			<p class="sf:text-xs sf:text-slate-500">
				Available placeholders include {'{{entry_id}}'}, {'{{form_id}}'},
				{'{{action_label}}'}, {'{{llm_output}}'}, {'{{justification}}'}, and
				{'{{field:1}}'}.
			</p>
		</div>

		<div class="sf:grid sf:gap-4 sf:md:grid-cols-1">
			<ModelSelect
				id="custom-action-model-hint"
				bind:value={modelHint}
				onchange={handleModelChange}
			/>
		</div>

		{#if errors.length > 0}
			<ValidationSummary
				issues={errors.map((e) => ({
					message: e.path ? `${e.path}: ${e.message}` : e.message
				}))}
			/>
		{/if}

		<div class="sf:flex sf:justify-end sf:gap-2">
			{#if onCancel}
				<Button type="button" variant="secondary" onclick={onCancel}>Cancel</Button>
			{/if}
			<Button type="submit" disabled={submitting || baseTemplateUnavailable || (!isEditMode && !generatedCode)}>
				{#if submitting}
					{isEditMode ? 'Saving…' : 'Creating…'}
				{:else}
					{isEditMode ? 'Save Changes' : 'Create Action'}
				{/if}
			</Button>
		</div>
	</form>
</Card>
