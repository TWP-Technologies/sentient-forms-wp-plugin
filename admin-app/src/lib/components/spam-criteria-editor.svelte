<script lang="ts">
	import { Button, TextareaField } from '$lib/components/ui';
	import type { SpamGuidanceExample } from '$lib/api/types';

	interface Props {
		positiveExamples?: SpamGuidanceExample[];
		negativeExamples?: SpamGuidanceExample[];
		initiallyExpanded?: boolean;
		inheritedPositive?: SpamGuidanceExample[];
		inheritedNegative?: SpamGuidanceExample[];
		inheritanceSource?: 'form' | 'action' | null;
		onchange?: (data: { positive: SpamGuidanceExample[]; negative: SpamGuidanceExample[] }) => void;
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

	let expanded = $state(initiallyExpanded);
	let overriding = $state(false);

	const MAX_EXAMPLES = 10;
	const MAX_LENGTH = 800;

	const hasLocalExamples = $derived(positiveExamples.length > 0 || negativeExamples.length > 0);
	const hasInheritedExamples = $derived(
		inheritedPositive.length > 0 || inheritedNegative.length > 0
	);
	const isInheriting = $derived(
		Boolean(inheritanceSource && hasInheritedExamples && !hasLocalExamples && !overriding)
	);
	const effectivePositive = $derived(isInheriting ? inheritedPositive : positiveExamples);
	const effectiveNegative = $derived(isInheriting ? inheritedNegative : negativeExamples);
	const totalExamples = $derived(effectivePositive.length + effectiveNegative.length);

	function trimExample(example: SpamGuidanceExample): SpamGuidanceExample {
		return {
			text: example.text.trim().slice(0, MAX_LENGTH),
			rationale: example.rationale.trim().slice(0, MAX_LENGTH)
		};
	}

	function startOverride() {
		overriding = true;
		onchange?.({
			positive: inheritedPositive.map(trimExample),
			negative: inheritedNegative.map(trimExample)
		});
	}

	function useInherited() {
		overriding = false;
		onchange?.({ positive: [], negative: [] });
	}

	function addExample(kind: 'positive' | 'negative') {
		if (isInheriting) return;

		if (kind === 'positive') {
			if (positiveExamples.length >= MAX_EXAMPLES) return;
			onchange?.({
				positive: [...positiveExamples, { text: '', rationale: '' }],
				negative: negativeExamples
			});
			return;
		}

		if (negativeExamples.length >= MAX_EXAMPLES) return;
		onchange?.({
			positive: positiveExamples,
			negative: [...negativeExamples, { text: '', rationale: '' }]
		});
	}

	function updateExample(
		kind: 'positive' | 'negative',
		index: number,
		field: keyof SpamGuidanceExample,
		value: string
	) {
		const source = kind === 'positive' ? positiveExamples : negativeExamples;
		const next = source.map((example, currentIndex) =>
			currentIndex === index ? trimExample({ ...example, [field]: value }) : example
		);
		onchange?.({
			positive: kind === 'positive' ? next : positiveExamples,
			negative: kind === 'negative' ? next : negativeExamples
		});
	}

	function removeExample(kind: 'positive' | 'negative', index: number) {
		onchange?.({
			positive:
				kind === 'positive' ? positiveExamples.filter((_, current) => current !== index) : positiveExamples,
			negative:
				kind === 'negative' ? negativeExamples.filter((_, current) => current !== index) : negativeExamples
		});
	}
</script>

<div class="sf-spam-guidance sf:pt-2">
	<Button
		type="button"
		variant="ghost"
		size="sm"
		class="sf:h-auto sf:w-full sf:justify-start sf:px-2 sf:py-1 sf:text-sm sf:font-medium"
		onclick={() => (expanded = !expanded)}
	>
		<span class="sf:text-xs sf:text-slate-400">{expanded ? 'v' : '>'}</span>
		Classification guidance
		{#if totalExamples > 0 && !expanded}
			<span class="sf:text-xs sf:text-slate-500">({totalExamples} examples)</span>
		{/if}
		{#if isInheriting && !expanded}
			<span class="sf:text-xs sf:font-medium sf:text-blue-700">Inherited</span>
		{/if}
	</Button>

	{#if expanded}
		<div class="sf:mt-3 sf:space-y-4 sf:border-l-2 sf:border-slate-200 sf:pl-4">
			{#if inheritanceSource && hasInheritedExamples}
				<div class="sf:flex sf:flex-wrap sf:items-center sf:justify-between sf:gap-2 sf:rounded sf:bg-slate-50 sf:px-3 sf:py-2">
					<span class="sf:text-xs sf:font-medium sf:text-slate-700">
						{isInheriting ? `Using ${inheritanceSource} defaults` : 'Using custom examples'}
					</span>
					<Button size="sm" variant="ghost" onclick={isInheriting ? startOverride : useInherited}>
						{isInheriting ? 'Copy into override' : `Use ${inheritanceSource} defaults`}
					</Button>
				</div>
			{/if}

			<div class="sf-guidance-grid">
				<section class="sf-guidance-pane sf-guidance-pane-good">
					<div class="sf:flex sf:items-center sf:justify-between sf:gap-2">
						<p class="sf:text-xs sf:font-semibold sf:text-emerald-800">
							Legitimate examples ({effectivePositive.length}/{MAX_EXAMPLES})
						</p>
						{#if !isInheriting}
							<Button
								size="sm"
								variant="secondary"
								onclick={() => addExample('positive')}
								disabled={positiveExamples.length >= MAX_EXAMPLES}
							>
								Add
							</Button>
						{/if}
					</div>

					{#if effectivePositive.length === 0}
						<p class="sf:rounded sf:border sf:border-dashed sf:border-slate-300 sf:px-3 sf:py-3 sf:text-xs sf:text-slate-500">
							No legitimate examples set.
						</p>
					{/if}

					{#each effectivePositive as example, index (index)}
						<div class="sf-guidance-row">
							<TextareaField
								id={`positive-example-${index}`}
								label="Submitted text"
								rows={2}
								disabled={isInheriting}
								value={example.text}
								oninput={(event) =>
									updateExample('positive', index, 'text', event.currentTarget.value)}
								placeholder="A real inquiry that should pass"
							/>
							<TextareaField
								id={`positive-rationale-${index}`}
								label="Why this is legitimate"
								rows={2}
								disabled={isInheriting}
								value={example.rationale}
								oninput={(event) =>
									updateExample('positive', index, 'rationale', event.currentTarget.value)}
								placeholder="Explains intent, project details, or a known customer pattern"
							/>
							{#if !isInheriting}
								<Button size="sm" variant="ghost" onclick={() => removeExample('positive', index)}>
									Remove
								</Button>
							{/if}
						</div>
					{/each}
				</section>

				<section class="sf-guidance-pane sf-guidance-pane-spam">
					<div class="sf:flex sf:items-center sf:justify-between sf:gap-2">
						<p class="sf:text-xs sf:font-semibold sf:text-red-800">
							Spam examples ({effectiveNegative.length}/{MAX_EXAMPLES})
						</p>
						{#if !isInheriting}
							<Button
								size="sm"
								variant="secondary"
								onclick={() => addExample('negative')}
								disabled={negativeExamples.length >= MAX_EXAMPLES}
							>
								Add
							</Button>
						{/if}
					</div>

					{#if effectiveNegative.length === 0}
						<p class="sf:rounded sf:border sf:border-dashed sf:border-slate-300 sf:px-3 sf:py-3 sf:text-xs sf:text-slate-500">
							No spam examples set.
						</p>
					{/if}

					{#each effectiveNegative as example, index (index)}
						<div class="sf-guidance-row">
							<TextareaField
								id={`negative-example-${index}`}
								label="Submitted text"
								rows={2}
								disabled={isInheriting}
								value={example.text}
								oninput={(event) =>
									updateExample('negative', index, 'text', event.currentTarget.value)}
								placeholder="A submission that should be treated as spam"
							/>
							<TextareaField
								id={`negative-rationale-${index}`}
								label="Why this is spam"
								rows={2}
								disabled={isInheriting}
								value={example.rationale}
								oninput={(event) =>
									updateExample('negative', index, 'rationale', event.currentTarget.value)}
								placeholder="The business-specific signal or evasion pattern"
							/>
							{#if !isInheriting}
								<Button size="sm" variant="ghost" onclick={() => removeExample('negative', index)}>
									Remove
								</Button>
							{/if}
						</div>
					{/each}
				</section>
			</div>
		</div>
	{/if}
</div>

<style>
	.sf-spam-guidance {
		container-type: inline-size;
	}

	.sf-guidance-grid {
		display: grid;
		gap: 0.875rem;
		grid-template-columns: repeat(2, minmax(0, 1fr));
	}

	.sf-guidance-pane {
		border: 1px solid rgb(226 232 240);
		border-radius: 0.375rem;
		display: grid;
		gap: 0.75rem;
		min-width: 0;
		padding: 0.75rem;
	}

	.sf-guidance-pane-good {
		background: rgb(240 253 244);
		border-color: rgb(187 247 208);
	}

	.sf-guidance-pane-spam {
		background: rgb(254 242 242);
		border-color: rgb(254 202 202);
	}

	.sf-guidance-row {
		background: rgba(255, 255, 255, 0.82);
		border: 1px solid rgb(226 232 240);
		border-radius: 0.375rem;
		display: grid;
		gap: 0.625rem;
		min-width: 0;
		padding: 0.625rem;
	}

	@container (max-width: 760px) {
		.sf-guidance-grid {
			grid-template-columns: 1fr;
		}
	}
</style>
