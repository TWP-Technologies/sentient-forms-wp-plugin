<script lang="ts">
	import { onMount } from 'svelte';
	import { Section, Card, Button, Alert, Toggle } from '$lib/components/ui';
	import { wpFetch } from '$lib/wp';
	import { notifications } from '$lib/stores/notifications';

	/**
	 * Site Context Management Page (CB-SA-001)
	 * Allows webmasters to generate, view, and edit their site context summary
	 * for personalized spam detection.
	 */

	interface SiteContext {
		id: string;
		license_id: string;
		summary_text: string;
		source: string;
		auto_include: boolean;
		pii_ack: boolean;
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

	async function loadContext() {
		loading = true;
		error = null;
		try {
			const response = await wpFetch<SiteContext>('site-context');
			if (response) {
				context = response;
				editedText = context.summary_text;
				autoInclude = context.auto_include;
				piiAck = context.pii_ack;
			}
		} catch (e) {
			console.error('Failed to load site context', e);
			error = e instanceof Error ? e.message : 'Failed to load site context';
		} finally {
			loading = false;
		}
	}

	async function generateContext() {
		if (!piiAck) {
			showPiiWarning = true;
			return;
		}
		showPiiWarning = false;
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

	onMount(() => {
		loadContext();
	});
</script>

<Section
	heading="Site Context"
	description="Configure your site's context to improve spam detection accuracy. This summary helps the AI understand your business and typical form submissions."
>
	<div slot="actions">
		<Button variant="secondary" onclick={loadContext} disabled={loading}>
			{loading ? 'Loading...' : 'Refresh'}
		</Button>
	</div>

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
							</p>
						</div>
						<Button size="sm" variant="secondary" onclick={generateContext} disabled={generating}>
							{generating ? 'Regenerating...' : 'Regenerate'}
						</Button>
					</div>

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
							class="sf:w-full sf:rounded-md sf:border sf:border-slate-300 sf:px-3 sf:py-2 sf:text-sm sf:placeholder-slate-400 focus:sf:border-indigo-500 focus:sf:outline-none focus:sf:ring-1 focus:sf:ring-indigo-500"
							placeholder="Describe your business, services, and typical customer inquiries..."
						></textarea>
						<p class="sf:text-xs sf:text-slate-500 sf:mt-1">
							{characterCount} characters
							{#if characterCount > 2000}
								<span class="sf:text-amber-600">
									(Consider keeping under 2000 characters for optimal performance)
								</span>
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
						<Button onclick={saveContext} disabled={saving || !hasChanges}>
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
