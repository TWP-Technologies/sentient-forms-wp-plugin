<script lang="ts">
	import { Button, InputField } from '$lib/components/ui';

	interface Props {
		positiveExamples?: string[];
		negativeExamples?: string[];
		initiallyExpanded?: boolean;
		/** Inherited positive examples from form-level config */
		inheritedPositive?: string[];
		/** Inherited negative examples from form-level config */
		inheritedNegative?: string[];
		/** Source of inheritance: 'form' or null if no inheritance */
		inheritanceSource?: 'form' | 'action' | null;
		onchange?: (data: { positive: string[]; negative: string[] }) => void;
	}

	let {
		positiveExamples = [],
		negativeExamples = [],
		initiallyExpanded = false,
		inheritedPositive = [],
		inheritedNegative = [],
		inheritanceSource = null,
		onchange
	}: Props = $props();

	let newPositive = $state('');
	let newNegative = $state('');
	let expanded = $state(initiallyExpanded);
	/** Whether user has chosen to override inherited examples */
	let overriding = $state(false);

	const MAX_EXAMPLES = 10;
	const MAX_LENGTH = 200;

	// Determine if currently using inherited examples
	const hasLocalExamples = $derived(positiveExamples.length > 0 || negativeExamples.length > 0);
	const hasInheritedExamples = $derived(
		inheritedPositive.length > 0 || inheritedNegative.length > 0
	);
	const isInheriting = $derived(
		inheritanceSource && hasInheritedExamples && !hasLocalExamples && !overriding
	);

	// Effective examples to display (local or inherited)
	const effectivePositive = $derived(isInheriting ? inheritedPositive : positiveExamples);
	const effectiveNegative = $derived(isInheriting ? inheritedNegative : negativeExamples);

	function startOverride() {
		overriding = true;
		// Copy inherited examples as starting point for override
		if (inheritedPositive.length > 0 || inheritedNegative.length > 0) {
			onchange?.({ positive: [...inheritedPositive], negative: [...inheritedNegative] });
		}
	}

	function useInherited() {
		overriding = false;
		// Clear local overrides
		onchange?.({ positive: [], negative: [] });
	}

	function addPositive() {
		const trimmed = newPositive.trim();
		if (!trimmed || positiveExamples.length >= MAX_EXAMPLES) return;
		if (positiveExamples.includes(trimmed)) return;

		const updated = [...positiveExamples, trimmed.slice(0, MAX_LENGTH)];
		newPositive = '';
		onchange?.({ positive: updated, negative: negativeExamples });
	}

	function removePositive(index: number) {
		const updated = positiveExamples.filter((_, i) => i !== index);
		onchange?.({ positive: updated, negative: negativeExamples });
	}

	function addNegative() {
		const trimmed = newNegative.trim();
		if (!trimmed || negativeExamples.length >= MAX_EXAMPLES) return;
		if (negativeExamples.includes(trimmed)) return;

		const updated = [...negativeExamples, trimmed.slice(0, MAX_LENGTH)];
		newNegative = '';
		onchange?.({ positive: positiveExamples, negative: updated });
	}

	function removeNegative(index: number) {
		const updated = negativeExamples.filter((_, i) => i !== index);
		onchange?.({ positive: positiveExamples, negative: updated });
	}

	function handlePositiveKeydown(e: KeyboardEvent) {
		if (e.key === 'Enter') {
			e.preventDefault();
			addPositive();
		}
	}

	function handleNegativeKeydown(e: KeyboardEvent) {
		if (e.key === 'Enter') {
			e.preventDefault();
			addNegative();
		}
	}

	const hasExamples = $derived(effectivePositive.length > 0 || effectiveNegative.length > 0);
</script>

<div class="sf:pt-2">
	<Button
		type="button"
		variant="ghost"
		size="sm"
		class="sf:h-auto sf:w-full sf:justify-start sf:px-2 sf:py-1 sf:text-sm sf:font-medium"
		onclick={() => (expanded = !expanded)}
	>
		<span class="sf:text-xs sf:text-slate-400">{expanded ? '▼' : '▶'}</span>
		Classification Guidance
		{#if hasExamples && !expanded}
			<span class="sf:text-xs sf:text-slate-500">
				({effectivePositive.length + effectiveNegative.length} examples)
			</span>
		{/if}
		{#if isInheriting && !expanded}
			<span class="sf:text-xs sf:text-blue-600 sf:font-medium">📋 Inherited</span>
		{/if}
	</Button>

	{#if expanded}
		<div class="sf:mt-3 sf:space-y-4 sf:pl-4 sf:border-l-2 sf:border-slate-200">
			<!-- Inheritance indicator -->
			{#if inheritanceSource && hasInheritedExamples}
				<div
					class="sf:flex sf:items-center sf:justify-between sf:bg-slate-50 sf:rounded sf:px-3 sf:py-2"
				>
					{#if isInheriting}
						<span class="sf:text-xs sf:text-blue-700 sf:flex sf:items-center sf:gap-1">
							📋 Using form-level defaults ({inheritedPositive.length + inheritedNegative.length} examples)
						</span>
						<Button size="sm" variant="ghost" onclick={startOverride}>Override</Button>
					{:else}
						<span class="sf:text-xs sf:text-slate-600 sf:flex sf:items-center sf:gap-1">
							✏️ Using custom examples
						</span>
						<Button size="sm" variant="ghost" onclick={useInherited}>Use form defaults</Button>
					{/if}
				</div>
			{/if}

			<p class="sf:text-xs sf:text-slate-500">
				Help the AI understand what's spam for YOUR site. These examples are optional but can
				improve accuracy.
			</p>

			<!-- Positive Examples -->
			<div class="sf:space-y-2">
				<p class="sf:text-xs sf:font-semibold sf:text-green-700">
					✅ Always Legitimate ({effectivePositive.length}/{MAX_EXAMPLES})
				</p>
				{#if !isInheriting}
					<div class="sf:flex sf:gap-2">
						<InputField
							id="new-positive"
							label=""
							placeholder="e.g., Inquiries about pricing"
							bind:value={newPositive}
							onkeydown={handlePositiveKeydown}
							maxlength={MAX_LENGTH}
							class="sf:flex-1"
						/>
						<Button
							size="sm"
							variant="secondary"
							onclick={addPositive}
							disabled={!newPositive.trim() || positiveExamples.length >= MAX_EXAMPLES}
						>
							Add
						</Button>
					</div>
				{/if}
				{#if effectivePositive.length > 0}
					<ul class="sf:space-y-1">
						{#each effectivePositive as example, i (example)}
							<li
								class="sf:flex sf:items-center sf:justify-between sf:rounded sf:px-2 sf:py-1 sf:text-sm {isInheriting
									? 'sf:bg-slate-100 sf:text-slate-600'
									: 'sf:bg-green-50 sf:text-green-800'}"
							>
								<span class="sf:truncate sf:flex-1">{example}</span>
								{#if !isInheriting}
									<Button
										type="button"
										size="sm"
										variant="ghost"
										iconOnly
										class="sf:ml-2 sf:h-6 sf:w-6 sf:border-green-300 sf:bg-green-100 sf:text-green-700 hover:sf:border-red-300 hover:sf:bg-red-100 hover:sf:text-red-700"
										onclick={() => removePositive(i)}
										aria-label="Remove legitimate example"
									>
										×
									</Button>
								{/if}
							</li>
						{/each}
					</ul>
				{/if}
			</div>

			<!-- Negative Examples -->
			<div class="sf:space-y-2">
				<p class="sf:text-xs sf:font-semibold sf:text-red-700">
					❌ Always Spam ({effectiveNegative.length}/{MAX_EXAMPLES})
				</p>
				{#if !isInheriting}
					<div class="sf:flex sf:gap-2">
						<InputField
							id="new-negative"
							label=""
							placeholder="e.g., SEO service offers"
							bind:value={newNegative}
							onkeydown={handleNegativeKeydown}
							maxlength={MAX_LENGTH}
							class="sf:flex-1"
						/>
						<Button
							size="sm"
							variant="secondary"
							onclick={addNegative}
							disabled={!newNegative.trim() || negativeExamples.length >= MAX_EXAMPLES}
						>
							Add
						</Button>
					</div>
				{/if}
				{#if effectiveNegative.length > 0}
					<ul class="sf:space-y-1">
						{#each effectiveNegative as example, i (example)}
							<li
								class="sf:flex sf:items-center sf:justify-between sf:rounded sf:px-2 sf:py-1 sf:text-sm {isInheriting
									? 'sf:bg-slate-100 sf:text-slate-600'
									: 'sf:bg-red-50 sf:text-red-800'}"
							>
								<span class="sf:truncate sf:flex-1">{example}</span>
								{#if !isInheriting}
									<Button
										type="button"
										size="sm"
										variant="ghost"
										iconOnly
										class="sf:ml-2 sf:h-6 sf:w-6 sf:border-red-300 sf:bg-red-100 sf:text-red-700 hover:sf:border-red-400 hover:sf:bg-red-200 hover:sf:text-red-800"
										onclick={() => removeNegative(i)}
										aria-label="Remove spam example"
									>
										×
									</Button>
								{/if}
							</li>
						{/each}
					</ul>
				{/if}
			</div>
		</div>
	{/if}
</div>
