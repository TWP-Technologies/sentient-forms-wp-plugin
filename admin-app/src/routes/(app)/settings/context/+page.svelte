<script lang="ts">
	import { onMount } from 'svelte';
	import { Section, Card, Button, Alert, Toggle } from '$lib/components/ui';
	import { wpFetch } from '$lib/wp';
	import { notifications } from '$lib/stores/notifications';
	import type { CreditBalanceResponse } from '$lib/api/types';
	import {
		canConfirmSiteContextRegeneration,
		resolveSiteContextRegenerationMode
	} from '$lib/utils/site-context-refresh';

	/**
	 * Site Context Management Page (CB-SA-001)
	 * Allows webmasters to generate, view, and edit their site context summary
	 * for personalized spam detection.
	 */

	const SITE_CONTEXT_CREDIT_COST = 20;

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
	let creditBalance = $state<CreditBalanceResponse | null>(null);
	let creditsLoading = $state(false);

	async function loadCredits() {
		creditsLoading = true;
		try {
			creditBalance = await wpFetch<CreditBalanceResponse>('credits/balance');
		} catch (e) {
			console.error('Failed to fetch credit balance', e);
		} finally {
			creditsLoading = false;
		}
	}

	async function loadContext() {
		loading = true;
		error = null;
		try {
			const response = await wpFetch<SiteContext | null>('site-context');
			context = response;
			if (response) {
				editedText = response.summary_text;
				autoInclude = response.auto_include;
				piiAck = response.pii_ack;
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

	/** Prompt user for regeneration confirmation (shows cost + balance) */
	function promptRegenerate() {
		if (!piiAck) {
			showPiiWarning = true;
			return;
		}
		showRegenConfirm = true;
		// Refresh credit balance for paid refreshes only.
		if (regenerationIsPaid) {
			loadCredits();
		}
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
		const wasPaidRegeneration = regenerationIsPaid;
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
				if (wasPaidRegeneration) {
					// Refresh credit balance only when credits were debited.
					loadCredits();
				}
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

	const hasInsufficientCredits = $derived(
		creditBalance !== null && creditBalance.current_balance < SITE_CONTEXT_CREDIT_COST
	);
	const freeRefreshAvailable = $derived(context?.free_refresh_available ?? false);
	const regenerationMode = $derived(resolveSiteContextRegenerationMode(context));
	const regenerationIsPaid = $derived(regenerationMode === 'paid_refresh');
	const canConfirmRegeneration = $derived(
		canConfirmSiteContextRegeneration(regenerationMode, hasInsufficientCredits)
	);
	const nextFreeRefreshLabel = $derived(
		context?.next_free_refresh_at ? formatDate(context.next_free_refresh_at) : null
	);

	onMount(() => {
		loadContext();
		loadCredits();
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
		<Alert variant="danger" class="sf:mb-4">
			{error}
		</Alert>
	{/if}

	{#if showPiiWarning}
		<Alert variant="warning" class="sf:mb-4">
			<div class="sf:space-y-3">
				<p class="sf:font-medium">⚠️ Privacy Notice</p>
				<p>
					By generating a site context, you acknowledge that information about your site (URL, meta
					descriptions, and publicly available content) will be sent to external AI providers
					(Google Gemini) for processing.
				</p>
				<p class="sf:text-sm">
					No personal customer data or form submissions are included in context generation.
				</p>
				<div class="sf:flex sf:gap-2 sf:mt-3">
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
			<Card>
				<p class="sf:text-slate-600">Loading site context...</p>
			</Card>
		{:else if !context}
			<Card>
				<div class="sf:space-y-4">
					<div class="sf:flex sf:items-start sf:justify-between">
						<div>
							<p class="sf:font-medium sf:text-slate-800">No site context configured</p>
							<p class="sf:text-sm sf:text-slate-600 sf:mt-1">
								Generate a context summary to help the AI better understand your site's purpose and
								improve spam detection accuracy.
							</p>
						</div>
					</div>

					<Alert variant="info">
						<p>
							<strong>What is site context?</strong> A brief summary describing your business, target
							audience, and typical form submissions. This helps the AI distinguish between legitimate
							inquiries and spam.
						</p>
					</Alert>

					<div class="sf:flex sf:items-center sf:gap-3 sf:pt-2">
						<label class="sf:flex sf:items-center sf:gap-2 sf:text-sm">
							<input type="checkbox" bind:checked={piiAck} class="sf:form-checkbox" />
							<span>I acknowledge this data will be processed by external AI providers</span>
						</label>
					</div>

					<Button onclick={generateContext} disabled={generating}>
						{generating ? 'Generating...' : 'Generate Site Context'}
					</Button>
					<p class="sf:text-xs sf:text-green-700">
						Initial generation is free and does not consume your yearly free refresh.
					</p>
				</div>
			</Card>
		{:else}
			<Card>
				<div class="sf:space-y-4">
					<div class="sf:flex sf:items-center sf:justify-between">
						<div>
							<p class="sf:font-medium sf:text-slate-800">Site Context Summary</p>
							<p class="sf:text-xs sf:text-slate-500">
								Source: {context.source} · Last updated: {formatDate(context.updated_at)}
								{#if context.free_refresh_available}
									· Yearly free refresh available
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
										This regeneration will use your <strong>yearly free refresh</strong>.
									</p>
									<p class="sf:text-sm sf:text-slate-600">
										After this run, your next free refresh will be available in 365 days.
									</p>
								{:else}
									<p class="sf:text-sm">
										Regenerating will use <strong>{SITE_CONTEXT_CREDIT_COST} credits</strong>.
									</p>
									<p class="sf:text-sm">
										{#if creditsLoading}
											Checking your balance…
										{:else if creditBalance}
											Current balance: <strong>{creditBalance.current_balance} credits</strong>
											{#if hasInsufficientCredits}
												<span class="sf:text-red-600 sf:font-medium sf:block sf:mt-1">
													⚠ Insufficient credits. You need at least {SITE_CONTEXT_CREDIT_COST} credits.
												</span>
											{/if}
										{:else}
											<span class="sf:text-slate-500">Unable to check credit balance.</span>
										{/if}
									</p>
									{#if nextFreeRefreshLabel}
										<p class="sf:text-xs sf:text-slate-500">
											Next free refresh: {nextFreeRefreshLabel}
										</p>
									{:else}
										<p class="sf:text-xs sf:text-slate-500">Free refresh status unavailable.</p>
									{/if}
								{/if}
								<div class="sf:flex sf:gap-2">
									<Button
										size="sm"
										onclick={generateContext}
										disabled={generating || !canConfirmRegeneration}
									>
										{#if generating}
											Regenerating…
										{:else if freeRefreshAvailable}
											Confirm — Use Free Refresh
										{:else}
											{`Confirm — Use ${SITE_CONTEXT_CREDIT_COST} Credits`}
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
							class="sf:w-full sf:rounded-md sf:border sf:px-3 sf:py-2 sf:text-sm sf:placeholder-slate-400 focus:sf:outline-none focus:sf:ring-1 {characterCount >= CONTEXT_WARN_LIMIT ? 'sf:border-red-400 focus:sf:border-red-500 focus:sf:ring-red-500' : characterCount > CONTEXT_SOFT_LIMIT ? 'sf:border-amber-400 focus:sf:border-amber-500 focus:sf:ring-amber-500' : 'sf:border-slate-300 focus:sf:border-indigo-500 focus:sf:ring-indigo-500'}"
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

					<div class="sf:flex sf:items-center sf:justify-between sf:pt-2">
						<div class="sf:flex sf:items-center sf:gap-2">
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
