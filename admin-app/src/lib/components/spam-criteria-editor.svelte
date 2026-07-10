<script lang="ts">
	import { Button, TextareaField } from '$lib/components/ui';
	import type {
		SpamGuidanceEntrySearchAvailability,
		SpamGuidanceEntrySearchEntry,
		SpamGuidanceEntrySearchResponse,
		SpamGuidanceEntrySourceType,
		SpamGuidanceEntryStatusFilter,
		SpamGuidanceExample,
		SpamGuidanceExampleAppendPayload,
		SpamGuidanceExampleAppendResponse,
		SpamGuidanceExampleLabel,
		SpamGuidanceTargetScope
	} from '$lib/api/types';

	type ExampleKind = 'positive' | 'negative';

	interface Props {
		positiveExamples?: SpamGuidanceExample[];
		negativeExamples?: SpamGuidanceExample[];
		initiallyExpanded?: boolean;
		inheritedPositive?: SpamGuidanceExample[];
		inheritedNegative?: SpamGuidanceExample[];
		inheritanceSource?: 'form' | 'action' | null;
		formSourceSlug?: string;
		formId?: string | number;
		targetScope?: SpamGuidanceTargetScope;
		mappingId?: number | string;
		searchHistoricalEntries?: (params: {
			q?: string;
			limit?: number;
			status?: SpamGuidanceEntryStatusFilter;
		}) => Promise<SpamGuidanceEntrySearchResponse>;
		saveHistoricalExample?: (
			payload: SpamGuidanceExampleAppendPayload
		) => Promise<SpamGuidanceExampleAppendResponse>;
		onchange?: (data: { positive: SpamGuidanceExample[]; negative: SpamGuidanceExample[] }) => void;
	}

	let {
		positiveExamples = [],
		negativeExamples = [],
		initiallyExpanded = false,
		inheritedPositive = [],
		inheritedNegative = [],
		inheritanceSource = null,
		formSourceSlug,
		formId,
		targetScope = 'form',
		mappingId,
		searchHistoricalEntries,
		saveHistoricalExample,
		onchange
	}: Props = $props();

	let expanded = $state(initiallyExpanded);
	let overriding = $state(false);
	let pickerOpen = $state(false);
	let pickerQuery = $state('');
	let pickerStatus = $state<SpamGuidanceEntryStatusFilter>('all');
	let pickerLoading = $state(false);
	let pickerError = $state('');
	let pickerResult = $state<SpamGuidanceEntrySearchResponse | null>(null);
	let reviewEntry = $state<SpamGuidanceEntrySearchEntry | null>(null);
	let reviewLabel = $state<SpamGuidanceExampleLabel>('ham');
	let reviewText = $state('');
	let reviewScope = $state<SpamGuidanceTargetScope>(normalizeReviewScope(targetScope));
	let reviewSaving = $state(false);
	let reviewError = $state('');

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
	const canUseHistorical = $derived(
		Boolean(formSourceSlug && formId !== undefined && searchHistoricalEntries && saveHistoricalExample)
	);
	const canUseMappingScope = $derived(hasUsableMappingId());
	const currentReviewExamples = $derived(
		reviewLabel === 'ham' ? positiveExamples : negativeExamples
	);
	const reviewDuplicate = $derived(
		Boolean(
			reviewEntry &&
				currentReviewExamples.some((example) => {
					const existingEntryId = example.source?.entry_id?.trim();
					const existingText = example.text.trim().toLowerCase();
					return (
						(existingEntryId && existingEntryId === reviewEntry?.id) ||
						(existingText && existingText === reviewText.trim().toLowerCase())
					);
				})
		)
	);
	const reviewCapReached = $derived(currentReviewExamples.length >= MAX_EXAMPLES);
	const canSaveReview = $derived(
		Boolean(
			reviewEntry &&
				reviewText.trim() &&
				!reviewDuplicate &&
				!reviewCapReached &&
				!reviewSaving
		)
	);
	const currentFormSourceLabel = $derived(formSourceLabel(formSourceSlug ?? pickerResult?.form_source));
	const pickerAvailabilityMessage = $derived(
		pickerResult ? availabilityMessage(pickerResult.availability, currentFormSourceLabel) : ''
	);

	function hasUsableMappingId(): boolean {
		if (typeof mappingId === 'number') {
			return Number.isInteger(mappingId) && mappingId > 0;
		}
		return typeof mappingId === 'string' && mappingId.trim() !== '';
	}

	function normalizeReviewScope(scope: SpamGuidanceTargetScope): SpamGuidanceTargetScope {
		return scope === 'mapping' && !hasUsableMappingId() ? 'form' : scope;
	}

	function trimExample(example: SpamGuidanceExample): SpamGuidanceExample {
		const trimmed: SpamGuidanceExample = {
			text: example.text.trim().slice(0, MAX_LENGTH),
			rationale: example.rationale.trim().slice(0, MAX_LENGTH)
		};
		if (example.source) {
			trimmed.source = example.source;
		}
		return trimmed;
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

	function addExample(kind: ExampleKind) {
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
		kind: ExampleKind,
		index: number,
		field: 'text' | 'rationale',
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

	function removeExample(kind: ExampleKind, index: number) {
		onchange?.({
			positive:
				kind === 'positive' ? positiveExamples.filter((_, current) => current !== index) : positiveExamples,
			negative:
				kind === 'negative' ? negativeExamples.filter((_, current) => current !== index) : negativeExamples
		});
	}

	function apiErrorMessage(error: unknown, fallback: string): string {
		const payload =
			error && typeof error === 'object' && 'payload' in error
				? (error as { payload?: unknown }).payload
				: null;
		if (payload && typeof payload === 'object') {
			const message = (payload as { message?: unknown }).message;
			if (typeof message === 'string' && message.trim()) {
				return message.trim();
			}

			const nestedMessage = (payload as { error?: { message?: unknown } }).error?.message;
			if (typeof nestedMessage === 'string' && nestedMessage.trim()) {
				return nestedMessage.trim();
			}
		}

		if (error instanceof Error && error.message.trim()) {
			return error.message.trim();
		}

		return fallback;
	}

	async function openHistoricalPicker(kind: ExampleKind) {
		if (!canUseHistorical || isInheriting) return;
		reviewLabel = kind === 'positive' ? 'ham' : 'spam';
		reviewScope = normalizeReviewScope(targetScope);
		pickerOpen = true;
		await runHistoricalSearch();
	}

	async function runHistoricalSearch() {
		if (!searchHistoricalEntries) return;
		pickerLoading = true;
		pickerError = '';
		try {
			pickerResult = await searchHistoricalEntries({
				q: pickerQuery.trim() || undefined,
				limit: 10,
				status: pickerStatus
			});
		} catch (error) {
			pickerResult = null;
			pickerError = apiErrorMessage(error, 'Could not search submissions.');
		} finally {
			pickerLoading = false;
		}
	}

	function reviewHistoricalEntry(entry: SpamGuidanceEntrySearchEntry) {
		reviewEntry = entry;
		reviewLabel = entry.status === 'spam' ? 'spam' : reviewLabel;
		reviewText = excerptFromEntry(entry);
		reviewError = '';
	}

	async function saveReview() {
		if (!reviewEntry || !saveHistoricalExample || !canSaveReview) return;

		reviewSaving = true;
		reviewError = '';
		try {
			const payload: SpamGuidanceExampleAppendPayload = {
				target_scope: reviewScope,
				label: reviewLabel,
				entry_id: reviewEntry.id
			};
			if (reviewScope === 'mapping' && hasUsableMappingId()) {
				payload.mapping_id = mappingId;
			}
			const response = await saveHistoricalExample(payload);
			onchange?.({
				positive: response.config.spam_positive_examples ?? positiveExamples,
				negative: response.config.spam_negative_examples ?? negativeExamples
			});
			reviewEntry = null;
			pickerOpen = false;
		} catch (error) {
			reviewError = apiErrorMessage(error, 'Could not save this example.');
		} finally {
			reviewSaving = false;
		}
	}

	function excerptFromEntry(entry: SpamGuidanceEntrySearchEntry): string {
		return entry.field_summary
			.map((field) => `${field.label}: ${field.value}`)
			.join('\n')
			.slice(0, MAX_LENGTH);
	}

	function firstPreview(entry: SpamGuidanceEntrySearchEntry): string {
		return entry.field_summary
			.slice(0, 3)
			.map((field) => `${field.label}: ${field.value}`)
			.join(' | ');
	}

	function formSourceLabel(slug?: string): string {
		switch (slug) {
			case 'gravity_forms':
				return 'Gravity Forms';
			case 'contact_form_7':
				return 'Contact Form 7';
			case 'wpforms':
				return 'WPForms';
			case 'elementor_pro_forms':
				return 'Elementor Pro Forms';
			default:
				return slug ? titleCaseSlug(slug) : 'this Form Source';
		}
	}

	function titleCaseSlug(slug: string): string {
		return slug
			.split(/[_-]+/)
			.filter(Boolean)
			.map((part) => part.charAt(0).toUpperCase() + part.slice(1))
			.join(' ');
	}

	function entrySourceLabel(sourceType: SpamGuidanceEntrySourceType): string {
		return sourceType === 'native' ? 'Native entry' : 'Submission Ledger';
	}

	function unavailableReasonMessage(reason?: string | null): string {
		switch (reason) {
			case 'submission_ledger_disabled':
				return 'Enable Submission Ledger to capture future submissions for this form.';
			case 'submission_ledger_unavailable':
				return 'Submission Ledger records are unavailable for this form.';
			case 'native_entry_storage_unavailable':
				return 'This Form Source does not store native historical submissions.';
			case 'wpforms_native_entry_storage_unavailable':
				return 'WPForms native entry storage is unavailable; WPForms Lite needs Submission Ledger records.';
			case 'elementor_form_submissions_unavailable':
				return 'Elementor Pro Forms submissions are unavailable; Submission Ledger records can be used when enabled.';
			case 'requires_pro':
				return 'Elementor Pro Forms submissions are required to read historical Elementor submissions.';
			case 'gravity_forms_unavailable':
				return 'Gravity Forms native entries are unavailable.';
			default:
				return 'This Form Source cannot read old entries from the site.';
		}
	}

	function availabilityMessage(
		availability: SpamGuidanceEntrySearchAvailability,
		sourceLabel: string
	): string {
		if (availability.native_read) {
			return `${sourceLabel} native entry search is available.`;
		}

		if (availability.ledger_read) {
			const nativeReason = unavailableReasonMessage(availability.native_unavailable_reason);
			return `${nativeReason} Showing Sentient Forms Submission Ledger records.`;
		}

		return unavailableReasonMessage(availability.unavailable_reason);
	}

	function duplicateIndexes(examples: SpamGuidanceExample[]): Set<number> {
		const seen = new Map<string, number>();
		const duplicates = new Set<number>();
		examples.forEach((example, index) => {
			const key = example.text.trim().toLowerCase();
			if (!key) return;
			const previous = seen.get(key);
			if (previous !== undefined) {
				duplicates.add(previous);
				duplicates.add(index);
			}
			seen.set(key, index);
		});
		return duplicates;
	}

	function isDuplicateExample(examples: SpamGuidanceExample[], index: number): boolean {
		return duplicateIndexes(examples).has(index);
	}

	function pickerEmptyMessage(): string {
		const reason = pickerResult?.availability.unavailable_reason;
		if (reason === 'submission_ledger_disabled') {
			return 'Enable Submission Ledger to capture future submissions for this form.';
		}
		if (reason && reason !== 'submission_ledger_disabled') {
			return unavailableReasonMessage(reason);
		}
		return 'No matching entries found.';
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
					<div class="sf:flex sf:flex-wrap sf:items-center sf:justify-between sf:gap-2">
						<p class="sf:text-xs sf:font-semibold sf:text-emerald-800">
							Legitimate examples ({effectivePositive.length}/{MAX_EXAMPLES})
						</p>
						{#if !isInheriting}
							<div class="sf:flex sf:flex-wrap sf:gap-2">
								<Button
									size="sm"
									variant="secondary"
									onclick={() => openHistoricalPicker('positive')}
									disabled={!canUseHistorical || positiveExamples.length >= MAX_EXAMPLES}
									data-testid="spam-guidance-use-history-positive"
								>
									Use past submissions
								</Button>
								<Button
									size="sm"
									variant="secondary"
									onclick={() => addExample('positive')}
									disabled={positiveExamples.length >= MAX_EXAMPLES}
								>
									Add manually
								</Button>
							</div>
						{/if}
					</div>

					{#if positiveExamples.length >= MAX_EXAMPLES && !isInheriting}
						<p class="sf-guidance-warning">Capacity warning: 10/10 legitimate examples are already saved.</p>
					{/if}

					{#if effectivePositive.length === 0}
						<p class="sf:rounded sf:border sf:border-dashed sf:border-slate-300 sf:px-3 sf:py-3 sf:text-xs sf:text-slate-500">
							No legitimate examples set.
						</p>
					{/if}

					{#each effectivePositive as example, index (index)}
						<div class="sf-guidance-row">
							{#if isDuplicateExample(effectivePositive, index)}
								<p class="sf-guidance-warning">Duplicate warning: a similar legitimate example already exists.</p>
							{/if}
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
					<div class="sf:flex sf:flex-wrap sf:items-center sf:justify-between sf:gap-2">
						<p class="sf:text-xs sf:font-semibold sf:text-red-800">
							Spam examples ({effectiveNegative.length}/{MAX_EXAMPLES})
						</p>
						{#if !isInheriting}
							<div class="sf:flex sf:flex-wrap sf:gap-2">
								<Button
									size="sm"
									variant="secondary"
									onclick={() => openHistoricalPicker('negative')}
									disabled={!canUseHistorical || negativeExamples.length >= MAX_EXAMPLES}
									data-testid="spam-guidance-use-history-negative"
								>
									Use past submissions
								</Button>
								<Button
									size="sm"
									variant="secondary"
									onclick={() => addExample('negative')}
									disabled={negativeExamples.length >= MAX_EXAMPLES}
								>
									Add manually
								</Button>
							</div>
						{/if}
					</div>

					{#if negativeExamples.length >= MAX_EXAMPLES && !isInheriting}
						<p class="sf-guidance-warning">Capacity warning: 10/10 spam examples are already saved.</p>
					{/if}

					{#if effectiveNegative.length === 0}
						<p class="sf:rounded sf:border sf:border-dashed sf:border-slate-300 sf:px-3 sf:py-3 sf:text-xs sf:text-slate-500">
							No spam examples set.
						</p>
					{/if}

					{#each effectiveNegative as example, index (index)}
						<div class="sf-guidance-row">
							{#if isDuplicateExample(effectiveNegative, index)}
								<p class="sf-guidance-warning">Duplicate warning: a similar spam example already exists.</p>
							{/if}
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

			<div class="sf-auto-improve-panel">
				<div>
					<p class="sf:text-sm sf:font-semibold sf:text-slate-900">Auto-improve guidance</p>
					<p class="sf:mt-1 sf:text-xs sf:text-slate-500">
						Manual examples stay local. Historical examples and generated suggestions use an LLM step and require an active managed-service account.
					</p>
				</div>
				<div class="sf-auto-improve-grid">
					<span>Manual examples</span>
					<span>Type the submission text and rationale directly.</span>
					<span>Past submissions</span>
					<span>Managed Service credits are used first; a ready paid OpenRouter key is the fallback when credits are unavailable.</span>
					<span>Generated suggestions</span>
					<span>Always open as a preview before applying.</span>
				</div>
			</div>
		</div>
	{/if}

	{#if pickerOpen}
		<div
			class="sf-picker"
			role="dialog"
			aria-modal="true"
			aria-label="Historical submission picker"
			data-testid="spam-guidance-picker"
		>
			<div class="sf-picker-header">
				<div>
					<p class="sf:text-sm sf:font-semibold sf:text-slate-900">
						Historical submissions for {currentFormSourceLabel}
					</p>
					<p class="sf:text-xs sf:text-slate-500">
						Choose a native entry or Submission Ledger record, then review it before saving guidance.
					</p>
				</div>
				<Button size="sm" variant="ghost" onclick={() => (pickerOpen = false)}>Close</Button>
			</div>
			<div class="sf-picker-controls">
				<label class="sf-picker-field">
					<span>Search</span>
					<input
						bind:value={pickerQuery}
						class="sf-picker-input"
						type="search"
						data-testid="spam-guidance-picker-search"
					/>
				</label>
				<label class="sf-picker-field">
					<span>Status</span>
					<select
						bind:value={pickerStatus}
						class="sf-picker-input"
						data-testid="spam-guidance-picker-status"
					>
						<option value="all">All</option>
						<option value="active">Active</option>
						<option value="spam">Spam</option>
					</select>
				</label>
				<Button
					size="sm"
					variant="secondary"
					onclick={runHistoricalSearch}
					disabled={pickerLoading}
					data-testid="spam-guidance-picker-submit"
				>
					{pickerLoading ? 'Searching...' : 'Search'}
				</Button>
			</div>

			{#if pickerAvailabilityMessage}
				<p class="sf-generation-note">{pickerAvailabilityMessage}</p>
			{/if}

			{#if pickerError}
				<p class="sf-guidance-error">{pickerError}</p>
			{:else if pickerLoading}
				<p class="sf-picker-empty">Searching submissions...</p>
			{:else if pickerResult && pickerResult.entries.length > 0}
				<div class="sf-picker-table-wrap">
					<table class="sf-picker-table">
						<thead>
							<tr>
								<th>Date</th>
								<th>Status</th>
								<th>Source</th>
								<th>Preview</th>
								<th>Action</th>
							</tr>
						</thead>
						<tbody>
							{#each pickerResult.entries as entry (entry.id)}
								<tr>
									<td data-label="Date">{entry.date_created ?? 'Unknown'}</td>
									<td data-label="Status">{entry.status ?? 'Ledger'}</td>
									<td data-label="Source">{entrySourceLabel(entry.source_type)}</td>
									<td data-label="Preview">{firstPreview(entry)}</td>
									<td data-label="Action">
										<div class="sf:flex sf:flex-wrap sf:gap-2">
											{#if entry.native_entry_url}
												<a class="sf-picker-link" href={entry.native_entry_url} target="_blank" rel="noreferrer">
													Native entry
												</a>
											{/if}
											<Button
												size="sm"
												variant="secondary"
												onclick={() => reviewHistoricalEntry(entry)}
												data-testid="spam-guidance-picker-review"
											>
												Review
											</Button>
										</div>
									</td>
								</tr>
							{/each}
						</tbody>
					</table>
				</div>
			{:else if pickerResult}
				<p class="sf-picker-empty">{pickerEmptyMessage()}</p>
			{/if}
		</div>
	{/if}

	{#if reviewEntry}
		<aside
			class="sf-review-drawer"
			aria-label="Example review drawer"
			data-testid="spam-guidance-review-drawer"
		>
			<div class="sf-picker-header">
				<div>
					<p class="sf:text-sm sf:font-semibold sf:text-slate-900">Review example</p>
					<p class="sf:text-xs sf:text-slate-500">
						{entrySourceLabel(reviewEntry.source_type)} · Original status: {reviewEntry.status ?? 'not available'}
					</p>
				</div>
				<Button size="sm" variant="ghost" onclick={() => (reviewEntry = null)}>Close</Button>
			</div>

			<div class="sf-segmented">
				<Button
					type="button"
					size="sm"
					variant={reviewLabel === 'ham' ? 'primary' : 'secondary'}
					onclick={() => (reviewLabel = 'ham')}
					data-testid="spam-guidance-review-label-ham"
				>
					Legitimate
				</Button>
				<Button
					type="button"
					size="sm"
					variant={reviewLabel === 'spam' ? 'primary' : 'secondary'}
					onclick={() => (reviewLabel = 'spam')}
					data-testid="spam-guidance-review-label-spam"
				>
					Spam
				</Button>
			</div>

			<label class="sf-picker-field">
				<span>Excerpt</span>
				<textarea
					bind:value={reviewText}
					class="sf-picker-textarea"
					rows="5"
					data-testid="spam-guidance-review-excerpt"
				></textarea>
			</label>

			<p class="sf-generation-note">
				The rationale is generated before saving. Active Managed Service subscription is required; Managed Service credits are used first, then a ready paid OpenRouter key if credits are unavailable.
			</p>

			<label class="sf-picker-field">
				<span>Target scope</span>
				<select
					id="historical-example-scope"
					bind:value={reviewScope}
					class="sf-picker-input"
					data-testid="spam-guidance-review-scope"
				>
					<option value="form">This form</option>
					{#if canUseMappingScope}
						<option value="mapping">This mapping</option>
					{/if}
					<option value="action">Action defaults</option>
				</select>
			</label>

			{#if reviewScope === 'action'}
				<p class="sf-guidance-warning">
					Action defaults affect every form that uses this action without a closer override.
				</p>
			{/if}
			{#if reviewDuplicate}
				<p class="sf-guidance-warning">Duplicate warning: this entry or excerpt is already saved.</p>
			{/if}
			{#if reviewCapReached}
				<p class="sf-guidance-warning">Capacity warning: this label already has 10 examples.</p>
			{/if}
			{#if reviewError}
				<p class="sf-guidance-error">{reviewError}</p>
			{/if}
			{#if reviewSaving}
				<p class="sf-generation-note">Generating rationale and saving the example...</p>
			{/if}

			<div class="sf-review-actions">
				<Button size="sm" variant="ghost" onclick={() => (reviewEntry = null)}>Cancel</Button>
				<Button
					size="sm"
					variant="primary"
					onclick={saveReview}
					disabled={!canSaveReview}
					data-testid="spam-guidance-review-save"
				>
					{reviewSaving ? 'Generating...' : 'Generate rationale and save'}
				</Button>
			</div>
		</aside>
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

	.sf-guidance-warning,
	.sf-guidance-error,
	.sf-generation-note {
		border-radius: 0.25rem;
		font-size: 0.75rem;
		line-height: 1rem;
		margin: 0;
		padding: 0.5rem 0.625rem;
	}

	.sf-guidance-warning {
		background: rgb(255 251 235);
		border: 1px solid rgb(253 230 138);
		color: rgb(146 64 14);
	}

	.sf-guidance-error {
		background: rgb(254 242 242);
		border: 1px solid rgb(254 202 202);
		color: rgb(153 27 27);
	}

	.sf-generation-note {
		background: rgb(239 246 255);
		border: 1px solid rgb(191 219 254);
		color: rgb(30 64 175);
	}

	.sf-auto-improve-panel,
	.sf-picker,
	.sf-review-drawer {
		background: white;
		border: 1px solid rgb(226 232 240);
		border-radius: 0.375rem;
		display: grid;
		gap: 0.75rem;
		padding: 0.75rem;
	}

	.sf-auto-improve-grid {
		display: grid;
		font-size: 0.75rem;
		gap: 0.5rem 0.75rem;
		grid-template-columns: minmax(8rem, max-content) minmax(0, 1fr);
	}

	.sf-auto-improve-grid span:nth-child(odd) {
		color: rgb(15 23 42);
		font-weight: 600;
	}

	.sf-auto-improve-grid span:nth-child(even) {
		color: rgb(71 85 105);
	}

	.sf-picker {
		margin-top: 1rem;
	}

	.sf-picker-header,
	.sf-picker-controls {
		align-items: end;
		display: flex;
		flex-wrap: wrap;
		gap: 0.75rem;
		justify-content: space-between;
	}

	.sf-picker-field {
		display: grid;
		font-size: 0.75rem;
		font-weight: 600;
		gap: 0.25rem;
		min-width: min(16rem, 100%);
	}

	.sf-picker-input,
	.sf-picker-textarea {
		background: white;
		border: 1px solid rgb(203 213 225);
		border-radius: 0.375rem;
		color: rgb(15 23 42);
		font: inherit;
		font-weight: 400;
		min-width: 0;
		padding: 0.5rem;
	}

	.sf-picker-table-wrap {
		overflow-x: auto;
	}

	.sf-picker-table {
		border-collapse: collapse;
		font-size: 0.75rem;
		width: 100%;
	}

	.sf-picker-table th,
	.sf-picker-table td {
		border-bottom: 1px solid rgb(226 232 240);
		padding: 0.5rem;
		text-align: left;
		vertical-align: top;
	}

	.sf-picker-table th {
		background: rgb(248 250 252);
		color: rgb(71 85 105);
		font-size: 0.6875rem;
		font-weight: 700;
		text-transform: uppercase;
	}

	.sf-picker-empty {
		border: 1px dashed rgb(203 213 225);
		border-radius: 0.375rem;
		color: rgb(71 85 105);
		font-size: 0.8125rem;
		margin: 0;
		padding: 1rem;
	}

	.sf-picker-link {
		color: rgb(29 78 216);
		font-size: 0.75rem;
		text-decoration: underline;
	}

	.sf-review-drawer {
		margin-top: 1rem;
	}

	.sf-segmented {
		display: flex;
		gap: 0.5rem;
	}

	.sf-review-actions {
		display: flex;
		gap: 0.5rem;
		justify-content: flex-end;
	}

	@container (max-width: 760px) {
		.sf-guidance-grid {
			grid-template-columns: 1fr;
		}

		.sf-auto-improve-grid {
			grid-template-columns: 1fr;
		}

		.sf-picker-table thead {
			display: none;
		}

		.sf-picker-table,
		.sf-picker-table tbody,
		.sf-picker-table tr,
		.sf-picker-table td {
			display: block;
			width: 100%;
		}

		.sf-picker-table tr {
			border: 1px solid rgb(226 232 240);
			border-radius: 0.375rem;
			margin-bottom: 0.75rem;
			padding: 0.5rem;
		}

		.sf-picker-table td {
			border-bottom: 0;
			display: grid;
			gap: 0.35rem;
			grid-template-columns: minmax(4.75rem, max-content) minmax(0, 1fr);
			padding: 0.35rem 0;
		}

		.sf-picker-table td::before {
			color: rgb(71 85 105);
			content: attr(data-label);
			font-size: 0.6875rem;
			font-weight: 700;
			text-transform: uppercase;
		}

		.sf-review-actions {
			display: grid;
			grid-template-columns: 1fr;
		}

		.sf-review-actions :global(button) {
			justify-content: center;
			width: 100%;
		}
	}
</style>
