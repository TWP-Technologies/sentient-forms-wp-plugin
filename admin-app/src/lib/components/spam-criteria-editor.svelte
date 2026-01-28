<script lang="ts">
	import { Button, InputField } from '$lib/components/ui';

	interface Props {
		positiveExamples?: string[];
		negativeExamples?: string[];
		onchange?: (data: { positive: string[]; negative: string[] }) => void;
	}

	let { positiveExamples = [], negativeExamples = [], onchange }: Props = $props();

	let newPositive = $state('');
	let newNegative = $state('');
	let expanded = $state(false);

	const MAX_EXAMPLES = 10;
	const MAX_LENGTH = 200;

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

	const hasExamples = $derived(positiveExamples.length > 0 || negativeExamples.length > 0);
</script>

<div class="sf:pt-2">
	<button
		type="button"
		class="sf:flex sf:items-center sf:gap-2 sf:text-sm sf:font-medium sf:text-slate-700 hover:sf:text-slate-900 sf:transition-colors"
		onclick={() => (expanded = !expanded)}
	>
		<span class="sf:text-xs sf:text-slate-400">{expanded ? '▼' : '▶'}</span>
		Classification Guidance
		{#if hasExamples && !expanded}
			<span class="sf:text-xs sf:text-slate-500">
				({positiveExamples.length + negativeExamples.length} examples)
			</span>
		{/if}
	</button>

	{#if expanded}
		<div class="sf:mt-3 sf:space-y-4 sf:pl-4 sf:border-l-2 sf:border-slate-200">
			<p class="sf:text-xs sf:text-slate-500">
				Help the AI understand what's spam for YOUR site. These examples are optional but can
				improve accuracy.
			</p>

			<!-- Positive Examples -->
			<div class="sf:space-y-2">
				<p class="sf:text-xs sf:font-semibold sf:text-green-700">
					✅ Always Legitimate ({positiveExamples.length}/{MAX_EXAMPLES})
				</p>
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
				{#if positiveExamples.length > 0}
					<ul class="sf:space-y-1">
						{#each positiveExamples as example, i}
							<li
								class="sf:flex sf:items-center sf:justify-between sf:bg-green-50 sf:rounded sf:px-2 sf:py-1 sf:text-sm sf:text-green-800"
							>
								<span class="sf:truncate sf:flex-1">{example}</span>
								<button
									type="button"
									class="sf:ml-2 sf:text-green-600 hover:sf:text-red-600 sf:text-xs"
									onclick={() => removePositive(i)}
									aria-label="Remove example"
								>
									×
								</button>
							</li>
						{/each}
					</ul>
				{/if}
			</div>

			<!-- Negative Examples -->
			<div class="sf:space-y-2">
				<p class="sf:text-xs sf:font-semibold sf:text-red-700">
					❌ Always Spam ({negativeExamples.length}/{MAX_EXAMPLES})
				</p>
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
				{#if negativeExamples.length > 0}
					<ul class="sf:space-y-1">
						{#each negativeExamples as example, i}
							<li
								class="sf:flex sf:items-center sf:justify-between sf:bg-red-50 sf:rounded sf:px-2 sf:py-1 sf:text-sm sf:text-red-800"
							>
								<span class="sf:truncate sf:flex-1">{example}</span>
								<button
									type="button"
									class="sf:ml-2 sf:text-red-600 hover:sf:text-red-800 sf:text-xs"
									onclick={() => removeNegative(i)}
									aria-label="Remove example"
								>
									×
								</button>
							</li>
						{/each}
					</ul>
				{/if}
			</div>
		</div>
	{/if}
</div>
