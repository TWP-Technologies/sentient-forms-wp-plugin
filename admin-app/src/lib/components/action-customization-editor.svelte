<script lang="ts">
	import { TextareaField } from '$lib/components/ui';

	type CustomizationLevel = 'action' | 'form' | 'mapping';
	type InheritanceSource = 'action' | 'form' | null;

	interface Props {
		id: string;
		actionId?: string | null;
		level?: CustomizationLevel;
		value?: string;
		inheritedValue?: string | null;
		inheritanceSource?: InheritanceSource;
		disabled?: boolean;
	}

	let {
		id,
		actionId = null,
		level = 'action',
		value = $bindable(),
		inheritedValue = null,
		inheritanceSource = null,
		disabled = false
	}: Props = $props();

	const MAX_LENGTH = 2000;

	const levelLabel = $derived(
		level === 'form'
			? 'Form customization'
			: level === 'mapping'
				? 'Mapping customization'
				: 'Action customization'
	);
	const description = $derived(
		level === 'action'
			? 'Optional instructions sent to the AI whenever this action runs. Form and mapping settings can override this.'
			: level === 'form'
				? 'Optional instructions sent to the AI for this action on this form. Mapping settings can override this.'
				: 'Optional instructions sent to the AI for this mapping. Leave blank to inherit broader customization.'
	);
	const placeholder = $derived(getCustomizationPlaceholder(actionId, inheritedValue));
	const normalizedValue = $derived(typeof value === 'string' ? value : '');
	const charCount = $derived(normalizedValue.length);
	const hasInheritedValue = $derived(Boolean(inheritedValue?.trim()));
	const inheritedLabel = $derived(
		inheritanceSource === 'form'
			? 'form customization'
			: inheritanceSource === 'action'
				? 'action customization'
				: 'broader customization'
	);
	const helpText = $derived(
		hasInheritedValue && !normalizedValue.trim()
			? `Currently using ${inheritedLabel}. Add text here to override it.`
			: 'Use business rules, tone, formatting preferences, or action-specific criteria. Do not include secrets.'
	);

	function getCustomizationPlaceholder(actionCode: string | null | undefined, inherited: string | null) {
		const inheritedText = inherited?.trim();
		if (inheritedText) {
			return inheritedText;
		}

		switch (actionCode) {
			case 'spam_detection_v1':
			case 'spam_analysis':
				return 'Example: Treat vague catalog or phone-number requests without project details as spam.';
			case 'content_validation_v1':
				return 'Example: Require a timeline, budget range, and at least one concrete project detail before accepting the entry.';
			case 'entry_summary_v1':
				return 'Example: Start with lead intent, mention urgency and budget if present, and keep the summary under three sentences.';
			case 'clarification_assistant_v1':
				return 'Example: Ask one short follow-up when timeline or budget is missing; avoid requesting sensitive personal details.';
			default:
				return 'Example: Apply our internal review criteria and keep the response concise for the admin team.';
		}
	}
</script>

<div class="sf-action-customization" data-testid={`action-customization-${level}`}>
	<TextareaField
		{id}
		label={levelLabel}
		description={description}
		help={helpText}
		rows={4}
		bind:value
		{disabled}
		maxlength={MAX_LENGTH}
		textareaClass="sf:min-h-28 sf:max-h-56 sf:resize-y"
		placeholder={placeholder}
	/>
	<div class="sf:flex sf:flex-wrap sf:items-center sf:justify-between sf:gap-2 sf:text-xs sf:text-slate-500">
		<span>
			{#if hasInheritedValue && normalizedValue.trim()}
				Overrides {inheritedLabel}.
			{:else if hasInheritedValue}
				Inheriting {inheritedLabel}.
			{:else}
				No inherited customization.
			{/if}
		</span>
		<span class={charCount > MAX_LENGTH * 0.9 ? 'sf:text-amber-700' : ''}>
			{charCount}/{MAX_LENGTH}
		</span>
	</div>
</div>

<style>
	.sf-action-customization {
		container-type: inline-size;
		display: grid;
		gap: 0.375rem;
		min-width: 0;
	}
</style>
