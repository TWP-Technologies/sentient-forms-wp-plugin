<script lang="ts">
	import { onMount } from 'svelte';
	import { Section, Card, Button, Alert, Toggle, StateTemplate } from '$lib/components/ui';
	import { wpFetch } from '$lib/wp';
	import { notifications } from '$lib/stores/notifications';
	import {
		canConfirmSiteContextRegeneration,
		resolveSiteContextRegenerationMode
	} from '$lib/utils/site-context-refresh';

	/**
	 * Site Context Management Page (CB-SA-001)
	 * Allows webmasters to generate, view, and edit their site context summary
	 * for personalized spam detection.
	 */
	// CB-SA-006: Context length guard thresholds
	const CONTEXT_SOFT_LIMIT = 2000;
	const CONTEXT_WARN_LIMIT = 4500;
	const CONTEXT_HARD_LIMIT = 5000;

	interface SiteContext {
		id: string;
		license_id: string;
		summary_text: string;
		source: string;
		auto_include: boolean;
		pii_ack: boolean;
		free_refresh_available: boolean;
		next_free_refresh_at: string | null;
		created_at: string;
		updated_at: string;
	}

	type SiteContextResponse =
		| SiteContext
		| null
		| {
				context?: SiteContext | null;
				data?: SiteContext | null;
		  };

	let loading = $state(true);
	let saving = $state(false);
	let generating = $state(false);
	let context = $state<SiteContext | null>(null);
	let editedText = $state('');
	let autoInclude = $state(true);
	let piiAck = $state(false);
	let error = $state<string | null>(null);
	let showPiiWarning = $state(false);
	let showRegenConfirm = $state(false);

	function normalizeSiteContextResponse(response: SiteContextResponse): SiteContext | null {
		if (!response) {
			return null;
		}

		if ('summary_text' in response) {
			return response;
		}

		if ('context' in response) {
			return response.context ?? null;
		}

		if ('data' in response) {
			return response.data ?? null;
		}

		return null;
	}

	async function loadContext() {
		loading = true;
		error = null;
		try {
			const response = normalizeSiteContextResponse(await wpFetch<SiteContextResponse>('site-context'));
			context = response;
			if (context) {
				editedText = context.summary_text;
				autoInclude = context.auto_include;
				piiAck = context.pii_ack;
			} else {
				editedText = '';
				autoInclude = true;
			}
		} catch (e) {
			console.error('Failed to load site context', e);
			error = e instanceof Error ? e.message : 'Failed to load site context';
		} finally {
			loading = false;
		}
	}

	/** Prompt user for regeneration confirmation. */
	function promptRegenerate() {
		if (!piiAck) {
			showPiiWarning = true;
			return;
		}
		showRegenConfirm = true;
	}

	async function generateContext() {
		if (!piiAck) {
			showPiiWarning = true;
			return;
		}
		showPiiWarning = false;
		showRegenConfirm = false;
		generating = true;
		error = null;
		try {
			const response = await wpFetch<SiteContext>('site-context', {
				method: 'POST',
				body: JSON.stringify({ pii_ack: piiAck })
			});
			if (response) {
				context = response;
				editedText = context.summary_text;
				autoInclude = context.auto_include;
				notifications.success('Site context generated');
			}
		} catch (e) {
			console.error('Failed to generate site context', e);
			error = e instanceof Error ? e.message : 'Failed to generate site context';
		} finally {
			generating = false;
		}
	}

	async function saveContext() {
		saving = true;
		error = null;
		try {
			const response = await wpFetch<SiteContext>('site-context', {
				method: 'PUT',
				body: JSON.stringify({
					summary_text: editedText,
					auto_include: autoInclude,
					pii_ack: piiAck
				})
			});
			if (response) {
				context = response;
				notifications.success('Site context saved');
			}
		} catch (e) {
			console.error('Failed to save site context', e);
			error = e instanceof Error ? e.message : 'Failed to save site context';
		} finally {
			saving = false;
		}
	}

	function confirmPii() {
		piiAck = true;
		showPiiWarning = false;
	}

	const characterCount = $derived(editedText?.length ?? 0);
	const isOverLimit = $derived(characterCount > CONTEXT_HARD_LIMIT);
	const limitPercent = $derived(Math.min((characterCount / CONTEXT_HARD_LIMIT) * 100, 100));
	const hasChanges = $derived(
		context && (editedText !== context.summary_text || autoInclude !== context.auto_include)
	);

	/**
	 * Safely format a date string from the API.
	 * Handles the time crate's default format (e.g., "2024-01-15 2:30:00.123456 +00:00:00")
	 * which JavaScript's Date cannot parse directly due to:
	 * - Space instead of 'T' separator
	 * - Single-digit hours (e.g., "2:30" instead of "02:30")
	 * - Non-standard timezone offset format
	 */
	function formatDate(dateStr: string | null | undefined): string {
		if (!dateStr) return 'Never';

		// Try parsing as-is first (works for ISO 8601 formats)
		let date = new Date(dateStr);

		// If invalid, try normalizing the time crate format
		if (isNaN(date.getTime())) {
			// Match the time crate format: "2024-01-15 2:30:00.123456 +00:00:00"
			const match = dateStr.match(
				/^(\d{4}-\d{2}-\d{2})\s+(\d{1,2}):(\d{1,2}):(\d{1,2})(?:\.(\d+))?\s+([+-]\d{2}:\d{2}(?::\d{2})?)?$/
			);

			if (match) {
				const [, datePart, hour, minute, second, , timezone] = match;
				// Pad time components to 2 digits
				const paddedTime = `${hour.padStart(2, '0')}:${minute.padStart(2, '0')}:${second.padStart(2, '0')}`;
				// Build ISO 8601 format: 2024-01-15T02:30:00Z
				const normalized = `${datePart}T${paddedTime}${timezone ? 'Z' : ''}`;
				date = new Date(normalized);
			} else {
				// Fallback: simple replacement approach
				const normalized = dateStr.replace(' ', 'T').replace(/\s*\+\d{2}:\d{2}(:\d{2})?$/, 'Z');
				date = new Date(normalized);
			}
		}

		if (isNaN(date.getTime())) return 'Unknown';

		return date.toLocaleDateString();
	}

	const freeRefreshAvailable = $derived(context?.free_refresh_available ?? false);
	const regenerationMode = $derived(resolveSiteContextRegenerationMode(context));
	const canConfirmRegeneration = $derived(
		canConfirmSiteContextRegeneration(regenerationMode)
	);
	const nextFreeRefreshLabel = $derived(
		context?.next_free_refresh_at ? formatDate(context.next_free_refresh_at) : null
	);

	onMount(() => {
		loadContext();
	});
</script>

<Section
	heading="Site Context"
	description="Configure your site's context to improve spam detection accuracy. This summary helps the AI understand your business and typical form submissions."
>
	{#snippet actions()}
		<Button variant="secondary" onclick={loadContext} disabled={loading}>
			{loading ? 'Loading...' : 'Refresh'}
		</Button>
	{/snippet}

	{#if error}
		<StateTemplate
			variant="error"
			title="Site context request failed"
			message={error}
			actionLabel="Retry"
			onAction={() => {
				void loadContext();
			}}
			testId="site-context-error-state"
		/>
	{/if}

	{#if showPiiWarning}
		<Alert variant="warning" class="sf:mb-4">
			<div class="sf:space-y-3">
				<p class="sf:font-medium">⚠️ Privacy Notice</p>
				<p>
					Sentient Forms will create the starter summary locally from this WordPress site's public
					metadata. When auto-include is enabled, this context can be included in future provider
					prompts for enabled actions.
				</p>
				<p class="sf:text-sm">
					No personal customer data or form submissions are included in context generation.
				</p>
				<div class="sf:flex sf:flex-wrap sf:gap-2 sf:mt-3">
					<Button size="sm" onclick={confirmPii}>I Understand, Continue</Button>
					<Button size="sm" variant="secondary" onclick={() => (showPiiWarning = false)}>
						Cancel
					</Button>
				</div>
			</div>
		</Alert>
	{/if}

	<div class="sf:grid sf:gap-6">
		{#if loading}
			<StateTemplate
				variant="loading"
				title="Loading site context"
				message="Fetching your current context summary and refresh settings."
				testId="site-context-loading-state"
			/>
		{:else if !context}
			<Card>
				<div class="sf:space-y-4">
					<StateTemplate
						variant="empty"
						title="No site context configured"
						message="Generate a context summary to help the AI understand your site and improve spam detection accuracy."
						inline
						dense
						testId="site-context-empty-state"
					/>

					<Alert variant="info">
						<p>
							<strong>What is site context?</strong> A brief summary describing your business, target
							audience, and typical form submissions. This helps the AI distinguish between legitimate
							inquiries and spam.
						</p>
					</Alert>

					<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-3 sf:pt-2">
						<label class="sf:flex sf:items-start sf:gap-2 sf:text-sm">
							<input
								type="checkbox"
								bind:checked={piiAck}
								class="sf:form-checkbox sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
							/>
							<span>
								I understand this local context can be included in future provider prompts for
								enabled actions
							</span>
						</label>
					</div>

					<Button onclick={generateContext} disabled={generating}>
						{generating ? 'Generating...' : 'Generate Site Context'}
					</Button>
					<p class="sf:text-xs sf:text-green-700">
						The starter summary is generated locally and does not call OpenRouter or Sentient.
					</p>
				</div>
			</Card>
		{:else}
			<Card>
				<div class="sf:space-y-4">
					<div class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:sm:flex-row sf:sm:items-center">
						<div>
							<p class="sf:font-medium sf:text-slate-800">Site Context Summary</p>
							<p class="sf:text-xs sf:text-slate-500">
								Source: {context.source} · Last updated: {formatDate(context.updated_at)}
								{#if context.free_refresh_available}
									· Local refresh available
								{:else if nextFreeRefreshLabel}
									· Next free refresh: {nextFreeRefreshLabel}
								{/if}
							</p>
						</div>
						<Button size="sm" variant="secondary" onclick={promptRegenerate} disabled={generating}>
							{generating ? 'Regenerating...' : 'Regenerate'}
						</Button>
					</div>

					{#if showRegenConfirm}
						<Alert variant="warning">
							<div class="sf:space-y-3">
								<p class="sf:font-medium">⚡ Confirm Regeneration</p>
								{#if freeRefreshAvailable}
									<p class="sf:text-sm">
										This regeneration will refresh the local starter summary.
									</p>
									<p class="sf:text-sm sf:text-slate-600">
										Your saved edits will be replaced by the newly generated local summary.
									</p>
								{:else}
									<p class="sf:text-sm">
										This regeneration updates the local starter summary.
									</p>
									<p class="sf:text-sm sf:text-slate-600">
										Edit the saved text after regeneration when you want business-specific detail
										that WordPress metadata cannot infer.
									</p>
									{#if nextFreeRefreshLabel}
										<p class="sf:text-xs sf:text-slate-500">
											Next free refresh: {nextFreeRefreshLabel}
										</p>
									{:else}
										<p class="sf:text-xs sf:text-slate-500">Free refresh status unavailable.</p>
									{/if}
								{/if}
								<div class="sf:flex sf:flex-wrap sf:gap-2">
									<Button
										size="sm"
										onclick={generateContext}
										disabled={generating || !canConfirmRegeneration}
									>
										{#if generating}
											Regenerating…
										{:else if freeRefreshAvailable}
											Confirm Refresh
										{:else}
											Confirm Regeneration
										{/if}
									</Button>
									<Button size="sm" variant="secondary" onclick={() => (showRegenConfirm = false)}>
										Cancel
									</Button>
								</div>
							</div>
						</Alert>
					{/if}

					<div>
						<label
							for="context-text"
							class="sf:block sf:text-sm sf:font-medium sf:text-slate-700 sf:mb-1"
						>
							Context Text
						</label>
						<textarea
							id="context-text"
							bind:value={editedText}
							rows={8}
							maxlength={CONTEXT_HARD_LIMIT}
							class="sf:w-full sf:rounded-md sf:border sf:px-3 sf:py-2 sf:text-sm sf:placeholder-slate-400 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white {characterCount >= CONTEXT_WARN_LIMIT ? 'sf:border-danger-500 sf:focus-visible:border-danger-500 sf:focus-visible:ring-danger-500' : characterCount > CONTEXT_SOFT_LIMIT ? 'sf:border-warning-600 sf:focus-visible:border-warning-600 sf:focus-visible:ring-warning-600' : 'sf:border-slate-300 sf:focus-visible:border-primary-600 sf:focus-visible:ring-primary-500'}"
							placeholder="Describe your business, services, and typical customer inquiries..."
						></textarea>
						<!-- CB-SA-006: Progress bar -->
						<div class="sf:mt-1 sf:h-1 sf:w-full sf:rounded-full sf:bg-slate-100 sf:overflow-hidden">
							<div
								class="sf:h-full sf:rounded-full sf:transition-all sf:duration-300 {characterCount >= CONTEXT_WARN_LIMIT ? 'sf:bg-red-500' : characterCount > CONTEXT_SOFT_LIMIT ? 'sf:bg-amber-500' : 'sf:bg-indigo-500'}"
								style="width: {limitPercent}%"
							></div>
						</div>
						<!-- CB-SA-006: Escalating character count warnings -->
						<p class="sf:text-xs sf:mt-1 {characterCount >= CONTEXT_WARN_LIMIT ? 'sf:text-red-600 sf:font-medium' : characterCount > CONTEXT_SOFT_LIMIT ? 'sf:text-amber-600' : 'sf:text-slate-500'}">
							{characterCount} / {CONTEXT_HARD_LIMIT} characters
							{#if characterCount >= CONTEXT_HARD_LIMIT}
								— <strong>Maximum limit reached</strong>
							{:else if characterCount >= CONTEXT_WARN_LIMIT}
								— Approaching {CONTEXT_HARD_LIMIT} character limit
							{:else if characterCount > CONTEXT_SOFT_LIMIT}
								— Consider keeping under {CONTEXT_SOFT_LIMIT} for optimal performance
							{/if}
						</p>
					</div>

					<div class="sf:border-t sf:border-slate-200 sf:pt-4">
						<Toggle
							label="Auto-include in spam detection"
							description="When enabled, this context will be automatically included in spam analysis prompts."
							bind:checked={autoInclude}
						/>
					</div>

					<div class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:sm:flex-row sf:sm:items-center sf:pt-2">
						<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
							{#if context.pii_ack}
								<span class="sf:text-xs sf:text-green-600">✓ PII acknowledgment on file</span>
							{/if}
						</div>
						<Button onclick={saveContext} disabled={saving || !hasChanges || isOverLimit}>
							{saving ? 'Saving...' : 'Save Changes'}
						</Button>
					</div>
				</div>
			</Card>

			<Card>
				<p class="sf:text-sm sf:font-medium sf:text-slate-700">Usage Tips</p>
				<ul class="sf:mt-2 sf:space-y-1 sf:text-sm sf:text-slate-600">
					<li>• Include your business industry and primary services</li>
					<li>• Mention typical customer types (B2B, B2C, etc.)</li>
					<li>• Describe common legitimate form submissions</li>
					<li>• Note any specific keywords that are always legitimate</li>
				</ul>
			</Card>
		{/if}
	</div>
</Section>
