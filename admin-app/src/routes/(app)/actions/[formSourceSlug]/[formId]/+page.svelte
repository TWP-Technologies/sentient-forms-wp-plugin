<script lang="ts">
	import { goto } from '$app/navigation';
	import { onMount } from 'svelte';
	import { Section, Button, Card, Badge } from '$lib/components/ui';
	import Alert from '$lib/components/ui/alert.svelte';
import { formActionsStore } from '$lib/stores/form-actions';
import type { FormActionsState } from '$lib/stores/form-actions.svelte';
import { notifications } from '$lib/stores/notifications';
	import type {
		ActionDefinition,
		CreditBalanceResponse,
		FormActionLinkage,
		FormExecutionStatus
	} from '$lib/api/types';

export let data: {
	formSourceSlug: string;
	formId: number;
};

let state: FormActionsState = {
	loading: true,
	error: null,
	items: [],
	balance: null,
	definitions: [],
	status: null
};
const unsubscribe = formActionsStore.subscribe((value) => {
	state = value;
});

const FALLBACK_HOOK_LABELS: Record<string, string> = {
	'gform_validation': 'During validation (Gravity Forms)',
	'gform_after_submission': 'After submission (Gravity Forms)'
};

function normalizeDefinitionHooks(hooks?: Record<string, string> | string[]): string[] {
	if (!hooks) {
		return [];
	}

	return Array.isArray(hooks) ? hooks : Object.keys(hooks);
}

function summarizeDefinitionHooks(hooks?: Record<string, string> | string[]): string {
	if (!hooks) {
		return 'Default (gform_validation)';
	}

	if (Array.isArray(hooks)) {
		return hooks.length > 0 ? hooks.join(', ') : 'Default (gform_validation)';
	}

	const labels = Object.values(hooks);
	return labels.length > 0 ? labels.join(', ') : 'Default (gform_validation)';
}

let hookOptions: Record<string, string> = { ...FALLBACK_HOOK_LABELS };
let hookEntries: [string, string][] = Object.entries(hookOptions);
let editingLinkageId: string | null = null;
let draftHooks: Set<string> = new Set();

type BadgeVariant = 'neutral' | 'success' | 'warning' | 'danger' | 'info';
let definitionsBadgeVariant: BadgeVariant = 'warning';
let definitionsBadgeLabel = 'Local fallback';

$: hasDefinitions = state.definitions.length > 0;
$: hasCpsDefinitions = hasDefinitions && state.definitions.some((definition) => definition.source === 'cps');
$: hasLocalDefinitions =
	hasDefinitions && state.definitions.some((definition) => (definition.source ?? 'local') !== 'cps');
$: definitionsBadgeVariant = hasCpsDefinitions ? 'success' : 'warning';
$: definitionsBadgeLabel = hasCpsDefinitions ? 'CPS templates' : 'Local fallback';

	type AdviceActionId = 'refresh' | 'licensing';
	type AdviceAction = {
		id: AdviceActionId;
		label: string;
		variant?: 'primary' | 'secondary';
	};
	type StatusAdvice = {
		variant: 'info' | 'success' | 'warning' | 'danger';
		title: string;
		description: string;
		actions?: AdviceAction[];
	};

	let statusAdvice: StatusAdvice | null = null;

$: hookOptions = state.definitions.reduce<Record<string, string>>((acc, definition) => {
	if (!definition?.hooks) {
		return acc;
	}

	if (Array.isArray(definition.hooks)) {
		definition.hooks.forEach((hook) => {
			const key = hook?.toString();
			if (key) {
				acc[key] = acc[key] ?? key;
			}
		});
	} else if (typeof definition.hooks === 'object') {
		Object.entries(definition.hooks).forEach(([hook, label]) => {
			if (hook) {
				acc[hook] = label?.toString() ?? hook;
			}
		});
	}

	return acc;
}, { ...FALLBACK_HOOK_LABELS });

$: hookEntries = Object.entries(hookOptions);

	onMount(() => {
		formActionsStore.load(data.formSourceSlug, data.formId);
		const interval = window.setInterval(
			() => formActionsStore.refresh(data.formSourceSlug, data.formId),
			30_000
		);
		return () => {
			window.clearInterval(interval);
			unsubscribe();
			formActionsStore.reset();
		};
	});

	const statusVariant = (linkage: FormActionLinkage) => {
		return linkage.is_action_enabled_for_form === false ? 'warning' : 'success';
	};

	const statusLabel = (linkage: FormActionLinkage) => {
		return linkage.is_action_enabled_for_form === false ? 'Disabled' : 'Enabled';
	};

function handleCreate() {
	const definitions = state.definitions ?? [];
const actionList = definitions
	.map((definition) => {
		const label = definition.label ?? definition.id;
			const cost =
				typeof definition.baseCreditCost === 'number'
					? ` (≈${definition.baseCreditCost} credits)`
					: '';
			return `${definition.id} — ${label}${cost}`;
		})
		.join('\n');

	const promptMessage = actionList.length
		? `Enter the CPS central action ID to link:\n${actionList}`
		: 'Enter the CPS central action ID to link';

		const centralActionId = window.prompt(promptMessage);
		if (!centralActionId) {
			return;
		}

		const selectedDefinition = definitions.find(
			(definition) => definition.id === centralActionId.trim()
		);
	const normalizedHooks = normalizeDefinitionHooks(selectedDefinition?.hooks);
	const triggerHooks = normalizedHooks.length > 0 ? normalizedHooks : ['gform_validation'];

		formActionsStore.create(data.formSourceSlug, data.formId, {
			central_action_id: centralActionId.trim(),
			action_type_indicator: 'master',
			trigger_hooks: triggerHooks
		});
	}

	function toggle(linkage: FormActionLinkage) {
		const enabled = linkage.is_action_enabled_for_form !== false;
		formActionsStore.toggleEnabled(data.formSourceSlug, data.formId, linkage, !enabled);
	}

	function remove(linkage: FormActionLinkage) {
		if (window.confirm('Remove this action mapping?')) {
			formActionsStore.remove(data.formSourceSlug, data.formId, linkage);
		}
	}

function refresh() {
	formActionsStore.refresh(data.formSourceSlug, data.formId);
}

function ensureDraftHooks(): string[] {
	const available = Object.keys(hookOptions);
	if (available.length === 0) {
		return ['gform_validation'];
	}

	return available;
}

function startEditingHooks(linkage: FormActionLinkage) {
	editingLinkageId = linkage.local_mapping_id;
	const initialHooks = linkage.trigger_hooks && linkage.trigger_hooks.length > 0 ? linkage.trigger_hooks : ensureDraftHooks().slice(0, 1);
	draftHooks = new Set(initialHooks);
}

function cancelEditingHooks() {
	editingLinkageId = null;
	draftHooks = new Set();
}

function toggleHookSelection(hook: string) {
	const next = new Set(draftHooks);
	if (next.has(hook)) {
		next.delete(hook);
	} else {
		next.add(hook);
	}
	draftHooks = next;
}

async function saveHookChanges(linkage: FormActionLinkage) {
	if (draftHooks.size === 0) {
		notifications.error('Select at least one trigger hook.');
		return;
	}

	await formActionsStore.updateHooks(data.formSourceSlug, data.formId, linkage, Array.from(draftHooks));
	editingLinkageId = null;
	draftHooks = new Set();
}

	const formatBaseCreditCost = (definition: ActionDefinition) => {
		if (typeof definition.baseCreditCost === 'number') {
			return `${definition.baseCreditCost}`;
		}

		return '—';
	};

	const formatModelHint = (definition: ActionDefinition) => definition.modelHint ?? '—';

	const definitionSourceBadgeVariant = (definition: ActionDefinition) =>
		definition.source === 'cps' ? 'success' : 'warning';

	function performStatusAction(actionId: AdviceActionId) {
		if (actionId === 'refresh') {
			refresh();
			return;
		}

		if (actionId === 'licensing') {
			goto('/licensing');
		}
	}

	const statusBadgeVariant = (status: FormExecutionStatus) => {
		if (status.status === 'error') return 'danger';
		if (status.status === 'success') return 'success';
		return 'info';
	};

	const statusHeadline = (status: FormExecutionStatus) => {
		switch (status.status) {
			case 'success':
				return 'Last run succeeded';
			case 'error':
				return 'Last run failed';
			default:
				return 'Awaiting first run';
		}
	};

	const statusDescription = (status: FormExecutionStatus) => {
		if (status.message && status.message.length > 0) {
			return status.message;
		}

		if (status.status === 'unknown') {
			return 'Sentient Forms has not processed any entries yet.';
		}

		return 'Sentient Forms recently attempted to run. Review the guidance below for next steps.';
	};

	const deriveStatusAdvice = (status: FormExecutionStatus | null): StatusAdvice | null => {
		if (!status) {
			return null;
		}

		if (status.status === 'error') {
			const code = status.last_error_code ?? '';

			switch (code) {
				case 'insufficient_credits':
					return {
						variant: 'warning',
						title: 'Out of credits',
						description:
							'Sentient Forms could not execute the last submission because this license is out of credits. Visit the Licensing tab to add credits before retrying.',
						actions: [
							{ id: 'licensing', label: 'Open Licensing', variant: 'primary' },
							{ id: 'refresh', label: 'Refresh status' }
						]
					};
				case 'cps_missing_proxy_key':
					return {
						variant: 'warning',
						title: 'License activation required',
						description:
							'Sentient Forms proxy credentials are missing. Activate your license on the Licensing tab, then retry the submission.',
						actions: [
							{ id: 'licensing', label: 'Open Licensing', variant: 'primary' },
							{ id: 'refresh', label: 'Refresh status' }
						]
					};
				case 'timeout':
					return {
						variant: 'danger',
						title: 'CPS timed out',
						description:
							'Sentient Forms timed out while contacting the CPS service. Retry the request shortly. If timeouts persist, inspect your network connectivity.',
						actions: [{ id: 'refresh', label: 'Retry now', variant: 'primary' }]
					};
				case 'rate_limited':
					return {
						variant: 'warning',
						title: 'Rate limited by CPS',
						description:
							'The CPS service temporarily rate limited this action. Wait about a minute before retrying.',
						actions: [{ id: 'refresh', label: 'Refresh status' }]
					};
				case 'duplicate_execution':
					return {
						variant: 'info',
						title: 'Already processed',
						description:
							'Sentient Forms already processed this submission. Refresh the status to review the existing result.',
						actions: [{ id: 'refresh', label: 'Refresh status', variant: 'primary' }]
					};
				case 'llm_error':
					return {
						variant: 'warning',
						title: 'Upstream LLM error',
						description:
							'The upstream LLM provider reported an error. Retry shortly and contact support if it keeps happening.',
						actions: [{ id: 'refresh', label: 'Refresh status' }]
					};
				case 'invalid_action_id':
					return {
						variant: 'danger',
						title: 'Action mapping is invalid',
						description:
							'The linked CPS action no longer exists or is misconfigured. Edit the action mapping to point at a valid CPS action before retrying.',
						actions: [{ id: 'refresh', label: 'Refresh status' }]
					};
				default:
					return {
						variant: 'danger',
						title: 'Sentient Forms execution failed',
						description:
							status.message ??
							'Sentient Forms could not complete the last submission. Review the error details, then retry the request.',
						actions: [{ id: 'refresh', label: 'Refresh status', variant: 'primary' }]
					};
			}
		}

		if (status.status === 'unknown') {
			return {
				variant: 'info',
				title: 'Awaiting first run',
				description:
					'Sentient Forms has not processed any entries for this form yet. Submit a test entry, then refresh this status panel.',
				actions: [{ id: 'refresh', label: 'Refresh status' }]
			};
		}

		if (status.status === 'success') {
			return {
				variant: 'success',
				title: 'Sentient Forms ran successfully',
				description:
					status.message ??
					'The most recent submission completed successfully. You can refresh to see the latest credit balance or run another test.',
				actions: [{ id: 'refresh', label: 'Refresh status' }]
			};
		}

		return null;
	};

	$: statusAdvice = deriveStatusAdvice(state.status);

	async function checkEntryStatus() {
		const entryInput = window.prompt('Enter the Gravity Forms entry ID to inspect');
		if (!entryInput) {
			return;
		}

		const entryId = Number.parseInt(entryInput, 10);
		if (Number.isNaN(entryId) || entryId <= 0) {
			notifications.error('Entry ID must be a positive number.');
			return;
		}

		try {
			const status = await formActionsStore.fetchExecutionStatus(
				data.formSourceSlug,
				data.formId,
				entryId
			);

			if (status.status === 'error' && status.last_error) {
				notifications.error(status.last_error);
			} else if (status.last_response) {
				notifications.success('Sentient Forms processed the entry successfully.');
			} else {
				notifications.info('No Sentient Forms execution data found for this entry.');
			}
		} catch (error) {
			// fetchExecutionStatus already surfaced notifications; swallow error to avoid console noise.
			console.error(error);
		}
	}
</script>

<Section heading="Actions" description="Configure CPS-backed workflows for your form submissions.">
	<div slot="actions" class="sf-flex sf-gap-2">
	<Button variant="secondary" onclick={refresh}>Refresh</Button>
	<Button onclick={handleCreate}>New action</Button>
	<Button variant="secondary" onclick={checkEntryStatus}>Check entry status</Button>
	</div>

	{#if hasDefinitions}
		<Card class="sf-mb-4" data-testid="action-definitions-card">
			<div class="sf-flex sf-items-center sf-justify-between sf-gap-3">
				<div>
					<p class="sf-text-sm sf-font-medium sf-text-slate-700">CPS action templates</p>
					<p class="sf-text-xs sf-text-slate-500 sf-mt-1">
						Templates available for linking to this form. Credit costs are estimated per execution.
					</p>
				</div>
				<Badge variant={definitionsBadgeVariant}>{definitionsBadgeLabel}</Badge>
			</div>

			{#if !hasCpsDefinitions}
				<Alert variant="warning" class="sf-mt-4">
					CPS templates could not be fetched. Showing locally registered definitions until CPS access is restored.
				</Alert>
			{:else if hasLocalDefinitions}
				<Alert variant="info" class="sf-mt-4">
					Some templates come from local extensions and may not exist in CPS. Confirm availability before linking.
				</Alert>
			{/if}

			<div class="sf-overflow-x-auto sf-mt-4">
				<table class="sf-min-w-full sf-divide-y sf-divide-slate-200">
					<thead class="sf-bg-slate-50">
						<tr class="sf-text-left sf-text-xs sf-font-semibold sf-uppercase sf-tracking-wide sf-text-slate-600">
							<th class="sf-px-4 sf-py-3">Template</th>
							<th class="sf-px-4 sf-py-3">Credit cost</th>
							<th class="sf-px-4 sf-py-3">Model hint</th>
							<th class="sf-px-4 sf-py-3">Hooks</th>
						</tr>
					</thead>
					<tbody class="sf-divide-y sf-divide-slate-200">
						{#each state.definitions as definition (definition.id)}
							<tr class="sf-text-sm sf-text-slate-700">
								<td class="sf-px-4 sf-py-3 sf-align-top">
									<div class="sf-flex sf-flex-col sf-gap-1">
										<span class="sf-font-medium">{definition.label ?? definition.id}</span>
										<span class="sf-text-xs sf-text-slate-500">ID: {definition.id}</span>
										{#if definition.source}
											<Badge variant={definitionSourceBadgeVariant(definition)}>
												{definition.source === 'cps' ? 'CPS' : 'Local'}
											</Badge>
										{/if}
									</div>
								</td>
								<td class="sf-px-4 sf-py-3 sf-align-top">{formatBaseCreditCost(definition)}</td>
								<td class="sf-px-4 sf-py-3 sf-align-top">{formatModelHint(definition)}</td>
								<td class="sf-px-4 sf-py-3 sf-align-top">
										{summarizeDefinitionHooks(definition.hooks)}
								</td>
							</tr>
						{/each}
					</tbody>
				</table>
			</div>
		</Card>
	{/if}

	{#if state.loading}
		<p class="sf-text-sm sf-text-slate-600">Loading action mappings…</p>
{:else if state.error}
		<Alert variant="danger" class="sf-mb-4">
			<div class="sf-flex sf-flex-col md:sf-flex-row sf-items-start md:sf-items-center sf-gap-3">
				<span>{state.error}</span>
			<Button size="sm" variant="secondary" onclick={refresh}>Retry</Button>
			</div>
		</Alert>
{:else}
		{#if state.balance}
			<p class="sf-mb-4 sf-text-sm sf-text-slate-600">
				Current credit balance: <strong>{state.balance.current_balance}</strong>
			</p>
		{/if}

		{#if state.status}
			<Card class="sf-mb-4">
				<div class="sf-flex sf-items-start sf-justify-between sf-gap-4">
					<div>
						<p class="sf-text-sm sf-font-medium sf-text-slate-600">
							{statusHeadline(state.status)}
						</p>
						<p class="sf-text-sm sf-text-slate-700 sf-mt-1">
							{statusDescription(state.status)}
						</p>
					</div>
					<Badge variant={statusBadgeVariant(state.status)}>{state.status.status}</Badge>
				</div>
				{#if state.status.updated_at}
					<p class="sf-mt-2 sf-text-xs sf-text-slate-500">
						Updated {new Date(state.status.updated_at).toLocaleString()}
					</p>
				{/if}
				{#if statusAdvice}
					<Alert variant={statusAdvice.variant} class="sf-mt-4">
						<div class="sf-flex sf-flex-col sf-gap-2">
							<p class="sf-font-medium">{statusAdvice.title}</p>
							<p>{statusAdvice.description}</p>
							{#if statusAdvice.actions && statusAdvice.actions.length > 0}
								<div class="sf-flex sf-flex-wrap sf-gap-2 sf-mt-2">
									{#each statusAdvice.actions as action (action.id)}
											<Button
												size="sm"
												variant={action.variant ?? 'secondary'}
												onclick={() => performStatusAction(action.id)}
											>
											{action.label}
										</Button>
									{/each}
								</div>
							{/if}
						</div>
					</Alert>
				{/if}
			</Card>
		{/if}

		{#if state.items.length === 0}
			<p class="sf-text-sm sf-text-slate-600">No CPS actions linked to this form yet.</p>
		{:else}
			<Card>
				<div class="sf-overflow-x-auto">
					<table class="sf-min-w-full sf-divide-y sf-divide-slate-200" data-testid="form-actions-table">
						<thead class="sf-bg-slate-50">
							<tr class="sf-text-left sf-text-xs sf-font-semibold sf-uppercase sf-tracking-wide sf-text-slate-600">
								<th class="sf-px-4 sf-py-3">Action</th>
								<th class="sf-px-4 sf-py-3">Hooks</th>
								<th class="sf-px-4 sf-py-3">Status</th>
								<th class="sf-px-4 sf-py-3 sf-text-right">Actions</th>
							</tr>
						</thead>
						<tbody class="sf-divide-y sf-divide-slate-200">
							{#each state.items as linkage}
								<tr class="sf-text-sm sf-text-slate-700">
									<td class="sf-px-4 sf-py-3 sf-font-medium">
										{linkage.action_name_label ?? linkage.central_action_id ?? 'Unnamed action'}
									</td>
							<td class="sf-px-4 sf-py-3">
								<div class="sf-flex sf-flex-wrap sf-gap-2">
									{#if linkage.trigger_hooks && linkage.trigger_hooks.length > 0}
										{#each linkage.trigger_hooks as hook (hook)}
											<Badge variant="info">{hookOptions[hook] ?? hook}</Badge>
										{/each}
									{:else}
										<span class="sf-text-xs sf-text-slate-500">No hooks configured</span>
									{/if}
								</div>
								<div class="sf-mt-2 sf-space-x-2">
						<Button size="sm" variant="ghost" onclick={() => startEditingHooks(linkage)}>
										Edit hooks
									</Button>
								</div>
								{#if editingLinkageId === linkage.local_mapping_id}
									<div class="sf-mt-3 sf-rounded-md sf-border sf-border-slate-200 sf-p-3 sf-space-y-2">
										{#each hookEntries as [hookKey, hookLabel] (hookKey)}
											<label class="sf-flex sf-items-center sf-gap-2 sf-text-sm">
												<input
													type="checkbox"
													class="sf-form-checkbox"
													checked={draftHooks.has(hookKey)}
													onchange={() => toggleHookSelection(hookKey)}
												/>
												<span>{hookLabel}</span>
											</label>
										{/each}
										<div class="sf-flex sf-gap-2 sf-flex-wrap sf-pt-2">
										<Button size="sm" onclick={() => saveHookChanges(linkage)}>Save</Button>
										<Button size="sm" variant="secondary" onclick={cancelEditingHooks}>Cancel</Button>
										</div>
									</div>
								{/if}
							</td>
									<td class="sf-px-4 sf-py-3">
										<Badge variant={statusVariant(linkage)}>{statusLabel(linkage)}</Badge>
									</td>
									<td class="sf-px-4 sf-py-3 sf-text-right sf-space-x-2">
						<Button size="sm" variant="secondary" onclick={() => toggle(linkage)}>
											{linkage.is_action_enabled_for_form === false ? 'Enable' : 'Disable'}
										</Button>
						<Button size="sm" variant="ghost" onclick={() => remove(linkage)}>
											Remove
										</Button>
									</td>
								</tr>
							{/each}
						</tbody>
					</table>
				</div>
			</Card>
		{/if}
	{/if}
</Section>
