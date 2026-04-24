<!--
  SlugGenerator.svelte - Auto-generates URL-safe code from display name
  Uses Svelte 5 $derived for reactive transformation
-->
<script lang="ts">
	import { sanitizeCustomActionCode } from '$lib/utils/custom-actions';
	import Button from './button.svelte';

	interface Props {
		/** Display name to generate slug from */
		name?: string;
		/** Current slug value (for manual override) */
		value?: string;
		/** Callback when slug changes */
		onchange?: (slug: string) => void;
		/** Input ID for label association */
		id?: string;
		/** Disable auto-generation (manual mode only) */
		manualOnly?: boolean;
	}

	let {
		name,
		value = $bindable(),
		onchange,
		id = 'slug-generator',
		manualOnly = false
	}: Props = $props();

	// Track if user has manually edited
	let isManuallyEdited = $state(false);

	// Auto-generate slug from name when not manually edited
	const generatedSlug = $derived.by(() => {
		const currentValue = value ?? '';
		if (manualOnly || isManuallyEdited) return currentValue;
		// Transform: lowercase, replace spaces/underscores with dashes, strip invalid chars
		const transformed = (name ?? '')
			.toLowerCase()
			.replace(/[\s_]+/g, '-')
			.replace(/[^a-z0-9-]/g, '');
		return sanitizeCustomActionCode(transformed);
	});

	// Sync generated slug to value when auto-generating
	$effect(() => {
		if (!manualOnly && !isManuallyEdited && generatedSlug !== (value ?? '')) {
			value = generatedSlug;
			onchange?.(generatedSlug);
		}
	});

	function handleInput(event: Event) {
		const target = event.target as HTMLInputElement;
		const sanitized = sanitizeCustomActionCode(target.value);
		isManuallyEdited = true;
		value = sanitized;
		onchange?.(sanitized);
	}

	function resetToAuto() {
		isManuallyEdited = false;
	}
</script>

<div class="sf:flex sf:flex-col sf:gap-1">
	<label for={id} class="sf:text-sm sf:font-medium sf:text-slate-700">
		Code
		{#if !manualOnly && !isManuallyEdited}
			<span class="sf:text-xs sf:text-slate-400 sf:ml-1">(auto-generated)</span>
		{/if}
	</label>
	<div class="sf:flex sf:gap-2 sf:items-center">
		<input
			type="text"
			{id}
			value={value ?? ''}
			oninput={handleInput}
			placeholder="e.g., follow-up-reply"
			class="sf:flex-1 sf:rounded-md sf:border sf:border-slate-300 sf:px-3 sf:py-2 sf:text-sm sf:font-mono
				   sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white sf:focus-visible:border-primary-600"
		/>
		{#if isManuallyEdited && !manualOnly}
			<Button
				type="button"
				variant="ghost"
				size="sm"
				class="sf:h-auto sf:border-transparent sf:bg-transparent sf:px-1 sf:py-0 sf:text-xs sf:text-primary-700"
				onclick={resetToAuto}
			>
				Reset to auto
			</Button>
		{/if}
	</div>
	<p class="sf:text-xs sf:text-slate-500">Lowercase letters, numbers, and dashes only.</p>
</div>
