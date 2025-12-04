<script lang="ts">
	import { onMount } from 'svelte';
	import { Section, Card, Button, Badge, Alert, InputField, TextareaField } from '$lib/components/ui';
	import ValidationSummary from '$lib/components/ui/validation-summary.svelte';
import { customActionsStore, customActionsState } from '$lib/stores/custom-actions';
	import { parsePromptOverridesInput, sanitizeCustomActionCode } from '$lib/utils/custom-actions';
	import type { CustomAction } from '$lib/api/types';

	const customState = customActionsState;

	let statusFilter = $state<'active' | 'archived' | 'all'>(
		(customState.filters.status as 'active' | 'archived') ?? 'active'
	);
	const promptOverridesPlaceholder = '{"summaryTone":"friendly"}';

	let templateId = $state('');
	let code = $state('');
	let displayName = $state('');
	let description = $state('');
	let promptOverrides = $state('');
	let modelHint = $state('');
	let baseCreditCost = $state('');
	let formError: string | null = $state(null);

	onMount(() => {
		customActionsStore.load(customState.filters);
	});

	function applyStatusFilter(filter: 'active' | 'archived' | 'all'): void {
		statusFilter = filter;
		const filters =
			filter === 'all'
				? { include_archived: true }
				: { status: filter };
		customActionsStore.setFilters(filters);
		customActionsStore.load(filters);
	}

	function formatDate(value: string | null | undefined): string {
		if (!value) return '—';
		return new Intl.DateTimeFormat(undefined, {
			dateStyle: 'medium',
			timeStyle: 'short'
		}).format(new Date(value));
	}

	function resetForm(): void {
		templateId = '';
		code = '';
		displayName = '';
		description = '';
		promptOverrides = '';
		modelHint = '';
		baseCreditCost = '';
		formError = null;
	}

	async function handleCreate(event: SubmitEvent) {
		event.preventDefault();
		formError = null;

		const normalizedCode = sanitizeCustomActionCode(code);
		if (!templateId.trim()) {
			formError = 'Template ID is required.';
			return;
		}
		if (!normalizedCode) {
			formError = 'Code must contain at least one letter or number.';
			return;
		}
		if (!displayName.trim()) {
			formError = 'Display name is required.';
			return;
		}

		const { result: overrides, error: overridesError } = parsePromptOverridesInput(promptOverrides);
		if (overridesError) {
			formError = overridesError;
			return;
		}

		const payload = {
			template_id: templateId.trim(),
			code: normalizedCode,
			display_name: displayName.trim(),
			description: description.trim() ? description.trim() : null,
			prompt_overrides: overrides,
			model_hint: modelHint.trim() ? modelHint.trim() : null,
			base_credit_cost: baseCreditCost.trim() ? Number(baseCreditCost) : null
		};

		try {
			await customActionsStore.create(payload);
			resetForm();
		} catch {
			// errors already surfaced via notifications
		}
	}

	function archive(action: CustomAction) {
		customActionsStore.archive(action.id);
	}

	function reactivate(action: CustomAction) {
		customActionsStore.reactivate(action.id);
	}

	const statusFilters = [
		{ label: 'Active', value: 'active' },
		{ label: 'Archived', value: 'archived' },
		{ label: 'All', value: 'all' }
	] as const;
</script>

<Section
	heading="Custom Actions"
	description="Create tenant-specific workflows backed by CPS custom actions."
>
	<div slot="actions" class="sf:flex sf:gap-2 sf:flex-wrap">
		<Button variant="secondary" onclick={() => customActionsStore.reload()} disabled={customState.loading}>
			Refresh
		</Button>
	</div>

	{#if customState.error}
		<Alert variant="danger" class="sf:mb-4">{customState.error}</Alert>
	{/if}

		{#if customState.error && customState.actions.length === 0}
			<Alert variant="warning" class="sf:mb-4" data-testid="custom-actions-api-warning">
				{customState.error}
			</Alert>
		{:else if customState.supportsCustomActions === false}
			<Alert variant="warning" class="sf:mb-4" data-testid="custom-actions-api-warning">
				Custom Actions are disabled or not supported on this CPS backend
				{#if customState.cpsVersion}(current {customState.cpsVersion}){/if}
				{#if customState.requiredCustomActionsVersion}
					(Requires CPS ≥ {customState.requiredCustomActionsVersion})
				{/if}. Deploy CPS with custom-actions enabled to manage them here.
			</Alert>
		{/if}

		{#if customState.quota}
			<Card class="sf:mb-4">
				<div class="sf:flex sf:items-center sf:justify-between sf:gap-4 sf:flex-col sf:md:flex-row">
					<div>
						<p class="sf:text-sm sf:text-slate-500">Quota usage</p>
						<p class="sf:text-xl sf:font-semibold">
							{customState.quota.quota_used} / {customState.quota.quota_max}
						</p>
						<p class="sf:text-xs sf:text-slate-500">
							{customState.quota.quota_remaining} remaining actions
						</p>
					</div>
					<div class="sf:w-full sf:md:w-1/2">
						<div class="sf:h-2 sf:rounded-full sf:bg-slate-200">
							<div
								class="sf:h-2 sf:rounded-full sf:bg-indigo-500"
								style={`width: ${Math.min(
									100,
									(customState.quota.quota_used / Math.max(1, customState.quota.quota_max)) * 100
								)}%`}
							></div>
						</div>
					</div>
				</div>
			</Card>
		{/if}

	<Card class="sf:mb-6">
		<div class="sf:flex sf:flex-wrap sf:gap-2 sf:items-center sf:justify-between">
			<div class="sf:flex sf:gap-2">
				{#each statusFilters as filter}
					<Button
						variant={statusFilter === filter.value ? 'primary' : 'secondary'}
						onclick={() => applyStatusFilter(filter.value)}
						size="sm"
					>
						{filter.label}
					</Button>
				{/each}
			</div>
			<span class="sf:text-xs sf:text-slate-500">
				Last synced:
				{customState.lastLoadedAt ? new Date(customState.lastLoadedAt).toLocaleTimeString() : 'never'}
			</span>
		</div>

		{#if customState.loading}
			<p class="sf:mt-4 sf:text-sm sf:text-slate-600">Loading custom actions…</p>
		{:else if customState.actions.length === 0}
			<p class="sf:mt-4 sf:text-sm sf:text-slate-600">
				No {statusFilter === 'archived' ? 'archived' : 'active'} custom actions yet.
			</p>
		{:else}
			<div class="sf:mt-4 sf:overflow-auto">
				<table class="sf:min-w-full sf:text-sm" data-testid="custom-actions-table">
					<thead>
						<tr class="sf:text-left sf:text-slate-500">
							<th class="sf:p-2">Name</th>
							<th class="sf:p-2">Code</th>
							<th class="sf:p-2">Template ID</th>
							<th class="sf:p-2">Status</th>
							<th class="sf:p-2">Updated</th>
							<th class="sf:p-2 sf:text-right">Actions</th>
						</tr>
					</thead>
					<tbody>
						{#each customState.actions as action (action.id)}
							<tr class="sf:border-t sf:border-slate-100">
								<td class="sf:p-2 sf:font-semibold sf:text-slate-800">{action.display_name}</td>
								<td class="sf:p-2 sf:font-mono sf:text-xs">{action.code}</td>
								<td class="sf:p-2 sf:font-mono sf:text-xs sf:break-all">{action.template_id}</td>
								<td class="sf:p-2">
									<Badge variant={action.status === 'active' ? 'success' : 'neutral'}>
										{action.status}
									</Badge>
								</td>
								<td class="sf:p-2 sf:text-xs sf:text-slate-500">{formatDate(action.updated_at)}</td>
								<td class="sf:p-2 sf:text-right sf:flex sf:gap-2 sf:justify-end">
									{#if action.status === 'active'}
										<Button size="sm" variant="secondary" onclick={() => archive(action)}>
											Archive
										</Button>
									{:else}
										<Button size="sm" variant="primary" onclick={() => reactivate(action)}>
											Reactivate
										</Button>
									{/if}
								</td>
							</tr>
						{/each}
					</tbody>
				</table>
			</div>
		{/if}
	</Card>

	<Card>
		<h2 class="sf:text-base sf:font-semibold sf:text-slate-800">Create custom action</h2>
		<p class="sf:text-sm sf:text-slate-500 sf:mb-4">
			Provide the CPS template ID plus overrides to tailor the action to this site.
		</p>

		<form class="sf:flex sf:flex-col sf:gap-4" data-testid="custom-action-form" onsubmit={handleCreate}>
			<InputField
				id="custom-action-template-id"
				label="Template ID"
				bind:value={templateId}
				required
				placeholder="UUID from CPS template"
			/>
			<InputField
				id="custom-action-code"
				label="Code"
				bind:value={code}
				placeholder="e.g., follow_up_reply"
				description="Lowercase letters, numbers, and dashes only."
			/>
			<InputField
				id="custom-action-display-name"
				label="Display name"
				bind:value={displayName}
				placeholder="Marketing follow-up"
				required
			/>
			<TextareaField
				id="custom-action-description"
				label="Description"
				bind:value={description}
				rows={3}
				placeholder="Optional summary shown in the admin UI."
			/>
			<TextareaField
				id="custom-action-overrides"
				label="Prompt overrides (JSON)"
				bind:value={promptOverrides}
				rows={4}
				placeholder={promptOverridesPlaceholder}
			/>
			<div class="sf:grid sf:gap-4 sf:md:grid-cols-2">
				<InputField
					id="custom-action-model-hint"
					label="Model hint"
					bind:value={modelHint}
					placeholder="models/gemini-pro"
				/>
				<InputField
					id="custom-action-credit-cost"
					label="Base credit cost"
					type="number"
					min="0"
					bind:value={baseCreditCost}
					placeholder="Optional override"
				/>
			</div>

			{#if formError}
				<ValidationSummary issues={[{ message: formError }]} />
			{/if}

				<div class="sf:flex sf:justify-end">
					<Button type="submit" disabled={customState.creating}>
						{customState.creating ? 'Creating…' : 'Create action'}
					</Button>
				</div>
			</form>
	</Card>
</Section>
