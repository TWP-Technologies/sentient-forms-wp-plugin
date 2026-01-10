<!--
  CustomActionForm.svelte - Unified form for creating/editing custom actions
  Uses Zod validation and new UI components
-->
<script lang="ts">
	import {
		Section,
		Card,
		Button,
		InputField,
		TextareaField,
		ValidationSummary,
		SlugGenerator,
		PromptBuilder,
		ModelSelect
	} from '$lib/components/ui';
	import {
		customActionCreateSchema,
		customActionUpdateSchema,
		type CustomActionCreateInput,
		type CustomActionUpdateInput
	} from '$lib/schemas/custom-action';
	import type { CustomAction } from '$lib/api/types';

	interface Props {
		/** Existing action data for edit mode */
		initialData?: CustomAction | null;
		/** Submit handler - returns void or throws */
		onSubmit: (data: CustomActionCreateInput | CustomActionUpdateInput) => Promise<void>;
		/** Cancel handler */
		onCancel?: () => void;
		/** Whether form is in submitting state */
		submitting?: boolean;
	}

	let { initialData = null, onSubmit, onCancel, submitting = false }: Props = $props();

	const isEditMode = $derived(initialData !== null);

	// Form state
	let templateId = $state(initialData?.template_id ?? '');
	let displayName = $state(initialData?.display_name ?? '');
	let code = $state(initialData?.code ?? '');
	let description = $state(initialData?.description ?? '');
	let promptOverrides = $state<Record<string, unknown>>(initialData?.prompt_overrides ?? {});
	let modelHint = $state<string | null>(initialData?.model_hint ?? null);
	let baseCreditCost = $state(initialData?.base_credit_cost?.toString() ?? '');

	// Validation errors
	let errors = $state<Array<{ path: string; message: string }>>([]);

	function buildPayload(): CustomActionCreateInput | CustomActionUpdateInput {
		const base = {
			display_name: displayName.trim(),
			description: description.trim() || null,
			prompt_overrides: Object.keys(promptOverrides).length > 0 ? promptOverrides : undefined,
			model_hint: modelHint?.trim() || null,
			base_credit_cost: baseCreditCost.trim() ? Number(baseCreditCost) : null
		};

		if (isEditMode) {
			return base as CustomActionUpdateInput;
		}

		return {
			...base,
			template_id: templateId.trim(),
			code: code
		} as CustomActionCreateInput;
	}

	async function handleSubmit(event: SubmitEvent) {
		event.preventDefault();
		errors = [];

		const payload = buildPayload();
		const schema = isEditMode ? customActionUpdateSchema : customActionCreateSchema;
		const result = schema.safeParse(payload);

		if (!result.success) {
			errors = result.error.issues.map((issue) => ({
				path: issue.path.join('.'),
				message: issue.message
			}));
			return;
		}

		try {
			await onSubmit(result.data);
		} catch (e) {
			// Error handling done by parent via notifications
			if (e instanceof Error) {
				errors = [{ path: '', message: e.message }];
			}
		}
	}

	function handlePromptChange(value: Record<string, unknown>) {
		promptOverrides = value;
	}

	function handleModelChange(value: string | null) {
		modelHint = value;
	}

	function handleCodeChange(value: string) {
		code = value;
	}
</script>

<Card>
	<h2 class="sf:text-base sf:font-semibold sf:text-slate-800 sf:mb-1">
		{isEditMode ? 'Edit Custom Action' : 'Create Custom Action'}
	</h2>
	<p class="sf:text-sm sf:text-slate-500 sf:mb-4">
		{isEditMode
			? 'Update the configuration for this custom action.'
			: 'Provide the CPS template ID plus overrides to tailor the action to this site.'}
	</p>

	<form
		class="sf:flex sf:flex-col sf:gap-4"
		data-testid="custom-action-form"
		onsubmit={handleSubmit}
	>
		{#if !isEditMode}
			<InputField
				id="custom-action-template-id"
				label="Template ID"
				bind:value={templateId}
				required
				placeholder="UUID from CPS template"
				description="The CPS action template to base this action on."
			/>
		{/if}

		<InputField
			id="custom-action-display-name"
			label="Display Name"
			bind:value={displayName}
			placeholder="Marketing follow-up"
			required
		/>

		{#if !isEditMode}
			<SlugGenerator
				id="custom-action-code"
				name={displayName}
				bind:value={code}
				onchange={handleCodeChange}
			/>
		{:else}
			<div class="sf:flex sf:flex-col sf:gap-1">
				<span class="sf:text-sm sf:font-medium sf:text-slate-700">Code</span>
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

		<PromptBuilder
			id="custom-action-overrides"
			bind:value={promptOverrides}
			onchange={handlePromptChange}
		/>

		<div class="sf:grid sf:gap-4 sf:md:grid-cols-2">
			<ModelSelect
				id="custom-action-model-hint"
				bind:value={modelHint}
				onchange={handleModelChange}
			/>
			<InputField
				id="custom-action-credit-cost"
				label="Base Credit Cost"
				type="number"
				min="0"
				bind:value={baseCreditCost}
				placeholder="Optional override"
				description="Override the template's default credit cost."
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
			<Button type="submit" disabled={submitting}>
				{#if submitting}
					{isEditMode ? 'Saving…' : 'Creating…'}
				{:else}
					{isEditMode ? 'Save Changes' : 'Create Action'}
				{/if}
			</Button>
		</div>
	</form>
</Card>
