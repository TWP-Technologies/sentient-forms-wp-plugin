<script lang="ts">
	import ChevronRightIcon from '@lucide/svelte/icons/chevron-right';
	import { Badge, Button, ModelSelector, Toggle } from '$lib/components/ui';
	import type { ModelSelection } from '$lib/api/types';

	type BadgeVariant = 'neutral' | 'success' | 'warning' | 'danger' | 'info';

	interface Props {
		stepNumber?: number | string | null;
		statusLabel?: string;
		statusVariant?: BadgeVariant;
		loading?: boolean;
		saving?: boolean;
		generating?: boolean;
		saveDisabled?: boolean;
		generateDisabled?: boolean;
		saveLabel?: string;
		generatingLabel?: string;
		manualLabel?: string;
		description?: string;
		contextText?: string;
		generationConsent?: boolean;
		autoRefreshEnabled?: boolean;
		autoRefreshDays?: number;
		refreshDayOptions?: readonly number[];
		modelSelection?: ModelSelection;
		onSave?: () => void;
		onGenerate?: () => void;
	}

	let {
		stepNumber = null,
		statusLabel = 'Ready',
		statusVariant = 'success',
		loading = false,
		saving = false,
		generating = false,
		saveDisabled = false,
		generateDisabled = false,
		saveLabel = 'Save context',
		generatingLabel = 'Generating...',
		manualLabel = 'Manual context',
		description = 'Give actions a site-specific baseline for better spam and summary decisions.',
		contextText = $bindable(),
		generationConsent = $bindable(),
		autoRefreshEnabled = $bindable(),
		autoRefreshDays = $bindable(),
		modelSelection = $bindable(),
		refreshDayOptions = [],
		onSave,
		onGenerate
	}: Props = $props();

	const canUseGeneratedContext = $derived(generationConsent === true);
</script>

<section
	class="sf:rounded-xl sf:border sf:border-primary-100 sf:bg-primary-50 sf:p-5 sf:shadow-sm sf:sm:p-8"
	data-testid="site-context-setup-panel"
>
	<div class="sf:flex sf:flex-col sf:gap-4 sf:sm:flex-row sf:sm:items-start sf:sm:justify-between">
		<div class="sf:flex sf:min-w-0 sf:items-start sf:gap-4">
			{#if stepNumber !== null}
				<span
					class="sf:inline-flex sf:h-11 sf:w-11 sf:shrink-0 sf:items-center sf:justify-center sf:rounded-full sf:bg-primary-600 sf:text-lg sf:font-semibold sf:text-white"
				>
					{stepNumber}
				</span>
			{/if}
			<div class="sf:flex sf:min-w-0 sf:flex-col sf:gap-1 sf:md:flex-row sf:md:items-center sf:md:gap-5">
				<p class="sf:text-2xl sf:font-semibold sf:tracking-normal sf:text-slate-950">
					Site Context
				</p>
				<p class="sf:text-base sf:leading-6 sf:text-slate-600">
					{description}
				</p>
			</div>
		</div>
		<Badge variant={statusVariant} class="sf:self-start sf:px-4 sf:py-1 sf:text-sm">
			{statusLabel}
		</Badge>
	</div>

	{#if loading}
		<p class="sf:mt-8 sf:text-sm sf:text-slate-500">Loading Site Context setup...</p>
	{:else}
		<div class="sf:mt-9 sf:grid sf:gap-8 sf:lg:grid-cols-[minmax(0,1.05fr)_minmax(0,1.35fr)]">
			<Toggle
				label="Allow AI-generated Site Context"
				description="Uses a web-capable model to read public site evidence."
				descriptionClass="sf:text-slate-600"
				bind:checked={generationConsent}
				data-testid="site-context-generation-consent"
			/>

			<div class="sf:grid sf:gap-5 sf:sm:grid-cols-[minmax(15rem,1fr)_12rem] sf:sm:items-start">
				<Toggle
					label="Refresh automatically"
					description="Schedule updates through WordPress."
					descriptionClass="sf:text-slate-600"
					bind:checked={autoRefreshEnabled}
					disabled={!canUseGeneratedContext}
				/>

				<label class="sf:block">
					<span class="sf:text-sm sf:font-semibold sf:text-slate-900">Refresh every</span>
					<select
						class="sf:mt-2 sf:w-full sf:rounded-md sf:border sf:border-slate-300 sf:bg-white sf:px-4 sf:py-3 sf:text-base sf:text-slate-700 sf:shadow-sm sf:focus-visible:border-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white disabled:sf:bg-slate-50 disabled:sf:text-slate-400"
						bind:value={autoRefreshDays}
						disabled={!canUseGeneratedContext || !autoRefreshEnabled}
					>
						{#each refreshDayOptions as days}
							<option value={days}>{days} days</option>
						{/each}
					</select>
				</label>
			</div>
		</div>

		<label class="sf:mt-9 sf:block">
			<span class="sf:text-base sf:font-semibold sf:text-slate-900">{manualLabel}</span>
			<textarea
				id="context-text"
				rows={5}
				bind:value={contextText}
				maxlength={5000}
				class="sf:mt-3 sf:w-full sf:rounded-md sf:border sf:border-slate-300 sf:bg-white sf:px-4 sf:py-3 sf:text-base sf:leading-7 sf:text-slate-900 sf:shadow-sm sf:placeholder-slate-400 sf:focus-visible:border-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
				placeholder="Describe the site, normal inquiries, service area, and suspicious patterns..."
				data-testid="site-context-textarea"
			></textarea>
		</label>

		<details
			class="sf:mt-6 sf:rounded-lg sf:border sf:border-slate-200 sf:bg-white sf:shadow-sm"
			data-testid="site-context-model-tools"
		>
			<summary
				class="sf:flex sf:cursor-pointer sf:list-none sf:items-center sf:gap-3 sf:px-5 sf:py-4 sf:text-base sf:font-semibold sf:text-slate-950 [&::-webkit-details-marker]:sf:hidden"
			>
				<ChevronRightIcon class="sf:h-5 sf:w-5 sf:text-slate-500" aria-hidden="true" />
				Generation model and tools
			</summary>
			<div class="sf:border-t sf:border-slate-200 sf:p-5">
				<ModelSelector
					label="Generation model"
					level="global"
					value={modelSelection}
					readonly={!canUseGeneratedContext}
					requiredCapabilities={['web_search']}
					lockRequiredCapabilities={true}
					onchange={(selection) => {
						modelSelection = selection;
					}}
				/>
			</div>
		</details>

		<div class="sf:mt-8 sf:flex sf:flex-wrap sf:gap-4">
			<Button
				variant="secondary"
				disabled={saving || saveDisabled}
				onclick={onSave}
				data-testid="site-context-save"
			>
				{saving ? 'Saving...' : saveLabel}
			</Button>
			<Button
				disabled={generateDisabled}
				loading={generating}
				onclick={onGenerate}
				data-testid="site-context-generate-now"
			>
				{generating ? generatingLabel : 'Generate now'}
			</Button>
		</div>
	{/if}
</section>
