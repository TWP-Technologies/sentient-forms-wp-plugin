<script lang="ts">
	import { onMount } from 'svelte';
import { Section, Card, Button, Badge, Alert, InputField, SelectField } from '$lib/components/ui';
	import { navigateToAppPath } from '$lib/navigation';
	import { formActionsStore, formActionsState } from '$lib/stores/form-actions.svelte';
	import { customActionsStore, customActionsState } from '$lib/stores/custom-actions';
	import { notifications } from '$lib/stores/notifications';
	import type {
		ActionDefinition,
		CustomAction,
		FormActionLinkage,
		FormExecutionStatus
	} from '$lib/api/types';

	type Props = { data: { formSourceSlug: string; formId: number } };
	let { data }: Props = $props();

	const FALLBACK_HOOK_LABELS: Record<string, string> = {
		gform_validation: 'During validation (Gravity Forms)',
		gform_after_submission: 'After submission (Gravity Forms)'
	};

	const actionsState = formActionsState;
	const customState = customActionsState;

let createKind = $state<'template' | 'custom'>('template');
let selectedTemplateId = $state('');
let selectedCustomId = $state('');
let selectedHooks = $state<Set<string>>(new Set());
let createError = $state<string | null>(null);
let creating = $state(false);
let showAddPanel = $state(false);
let searchTerm = $state('');

let editingLinkageId = $state<string | null>(null);
let draftHooks = $state<Set<string>>(new Set());
let pendingRemovalId = $state<string | null>(null);
let entryLookupId = $state('');
	let refreshInterval: number | null = null;

	const definitions = $derived(actionsState.definitions ?? []);
	const customActions = $derived(customState.actions.filter((action) => action.status === 'active'));
	const definitionLookup = $derived(() =>
		actionsState.definitions.reduce<Record<string, ActionDefinition>>((acc, definition) => {
			acc[definition.id] = definition;
			return acc;
		}, {})
	);
	const customLookup = $derived(() =>
		customActions.reduce<Record<string, CustomAction>>((acc, action) => {
			acc[action.id] = action;
			return acc;
		}, {})
	);

	let hookOptions = $state<Record<string, string>>({ ...FALLBACK_HOOK_LABELS });

	$effect(() => {
		const next: Record<string, string> = { ...FALLBACK_HOOK_LABELS };
		for (const definition of actionsState.definitions ?? []) {
			if (!definition?.hooks) continue;

			if (Array.isArray(definition.hooks)) {
				for (const hook of definition.hooks) {
					const key = hook?.toString();
					if (key) next[key] = next[key] ?? key;
				}
			} else if (typeof definition.hooks === 'object') {
				for (const [hook, label] of Object.entries(definition.hooks)) {
					if (hook) next[hook] = label?.toString() ?? hook;
				}
			}
		}
		hookOptions = next;
	});

	const hookEntries = $derived(() => Object.entries(hookOptions));

	const hasDefinitions = $derived(definitions.length > 0);
	const hasCpsDefinitions = $derived(
		hasDefinitions && definitions.some((definition) => definition.source === 'cps')
	);
	const hasLocalDefinitions = $derived(
		hasDefinitions && definitions.some((definition) => (definition.source ?? 'local') !== 'cps')
	);
	const definitionsBadgeVariant = $derived(hasCpsDefinitions ? 'success' : 'warning');
	const definitionsBadgeLabel = $derived(hasCpsDefinitions ? 'CPS templates' : 'Local fallback');

	const selectedDefinition = $derived(
		selectedTemplateId ? definitionLookup[selectedTemplateId] ?? null : null
	);
	const selectedCustomAction = $derived(
		selectedCustomId ? customLookup[selectedCustomId] ?? null : null
	);
const selectedActionKey = $derived(
	`${createKind}:${createKind === 'template' ? selectedTemplateId : selectedCustomId}`
);

let lastPresetKey = $state<string | null>(null);
const LAST_HOOKS_KEY = 'sentient_forms_last_hooks';
	$effect(() => {
		if (!selectedActionKey || selectedActionKey === lastPresetKey) return;
		const presetHooks =
			createKind === 'template'
				? normalizeDefinitionHooks(selectedDefinition?.hooks)
				: ['gform_validation'];
		const normalized = presetHooks.length > 0 ? presetHooks : ['gform_validation'];
		selectedHooks = new Set(normalized);
		lastPresetKey = selectedActionKey;
	});

	$effect(() => {
		if (!selectedTemplateId && hasDefinitions) {
			selectedTemplateId = definitions[0]?.id ?? '';
		}
	});

	$effect(() => {
		if (!selectedCustomId && customActions.length > 0) {
			selectedCustomId = customActions[0]?.id ?? '';
		}
	});

	$effect(() => {
	if (createKind === 'template' && !hasDefinitions && customActions.length > 0) {
		createKind = 'custom';
	}
});

	$effect(() => {
		// Ensure at least one hook is preselected when opening the drawer
		if (showAddPanel && selectedHooks.size === 0) {
			const firstHook = Object.keys(hookOptions)[0] ?? 'gform_validation';
			selectedHooks = new Set([firstHook]);
		}
	});

	function persistLastHooks(hooks: string[]) {
		try {
			localStorage.setItem(LAST_HOOKS_KEY, JSON.stringify(hooks));
		} catch (err) {
			console.warn('Could not persist hooks', err);
		}
	}

	function restoreLastHooks() {
		try {
			const raw = localStorage.getItem(LAST_HOOKS_KEY);
			if (!raw) return;
			const parsed = JSON.parse(raw);
			if (Array.isArray(parsed) && parsed.every((h) => typeof h === 'string')) {
				selectedHooks = new Set(parsed);
			}
		} catch (err) {
			console.warn('Could not restore hooks', err);
		}
	}

onMount(() => {
	formActionsStore.load(data.formSourceSlug, data.formId);
	customActionsStore.load({ status: 'active' });
	restoreLastHooks();

	refreshInterval = window.setInterval(
		() => formActionsStore.refresh(data.formSourceSlug, data.formId),
		30_000
	);

		return () => {
			if (refreshInterval) {
				window.clearInterval(refreshInterval);
			}
			formActionsStore.reset();
		};
	});

	function normalizeDefinitionHooks(hooks?: Record<string, string> | string[]): string[] {
		if (!hooks) return [];
		return Array.isArray(hooks) ? hooks : Object.keys(hooks);
	}

	function summarizeDefinitionHooks(hooks?: Record<string, string> | string[]): string {
		if (!hooks) return 'Default (gform_validation)';
		if (Array.isArray(hooks)) {
			return hooks.length > 0 ? hooks.join(', ') : 'Default (gform_validation)';
		}
		const labels = Object.values(hooks);
		return labels.length > 0 ? labels.join(', ') : 'Default (gform_validation)';
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
		if (status.message && status.message.length > 0) return status.message;
		if (status.status === 'unknown') return 'Sentient Forms has not processed any entries yet.';
		return 'Sentient Forms recently attempted to run. Review the guidance below for next steps.';
	};

	type AdviceActionId = 'refresh' | 'licensing';
	type AdviceAction = { id: AdviceActionId; label: string; variant?: 'primary' | 'secondary' };
	type StatusAdvice = {
		variant: 'info' | 'success' | 'warning' | 'danger';
		title: string;
		description: string;
		actions?: AdviceAction[];
	};

	const deriveStatusAdvice = (status: FormExecutionStatus | null): StatusAdvice | null => {
		if (!status) return null;
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

	const statusAdvice = $derived(deriveStatusAdvice(actionsState.status));

	const formatBaseCreditCost = (definition: ActionDefinition) =>
		typeof definition.baseCreditCost === 'number' ? `${definition.baseCreditCost}` : '—';
	const formatModelHint = (definition: ActionDefinition) => definition.modelHint ?? '—';
	const definitionSourceBadgeVariant = (definition: ActionDefinition) =>
		definition.source === 'cps' ? 'success' : 'warning';

	function statusVariant(linkage: FormActionLinkage) {
		return linkage.is_action_enabled_for_form === false ? 'warning' : 'success';
	}

	function statusLabel(linkage: FormActionLinkage) {
		return linkage.is_action_enabled_for_form === false ? 'Disabled' : 'Enabled';
	}

	function friendlyActionLabel(linkage: FormActionLinkage): string {
		if (linkage.action_name_label) return linkage.action_name_label;
		const template = definitionLookup[linkage.central_action_id];
		if (template?.label) return template.label;
		const custom = customLookup[linkage.central_action_id];
		if (custom?.display_name) return custom.display_name;
		return linkage.central_action_id ?? 'Unnamed action';
	}

	function toggleHookSelection(hook: string) {
		const next = new Set(selectedHooks);
		next.has(hook) ? next.delete(hook) : next.add(hook);
		selectedHooks = next;
		persistLastHooks(Array.from(next));
	}

	function startEditingHooks(linkage: FormActionLinkage) {
		editingLinkageId = linkage.local_mapping_id;
		const initialHooks =
			linkage.trigger_hooks && linkage.trigger_hooks.length > 0
				? linkage.trigger_hooks
				: [hookEntries[0]?.[0] ?? 'gform_validation'];
		draftHooks = new Set(initialHooks);
	}

	function cancelEditingHooks() {
		editingLinkageId = null;
		draftHooks = new Set();
	}

	function toggleDraftHook(hook: string) {
		const next = new Set(draftHooks);
		next.has(hook) ? next.delete(hook) : next.add(hook);
		draftHooks = next;
	}

	async function saveHookChanges(linkage: FormActionLinkage) {
		if (draftHooks.size === 0) {
			notifications.error('Select at least one trigger hook.');
			return;
		}
		await formActionsStore.updateHooks(
			data.formSourceSlug,
			data.formId,
			linkage,
			Array.from(draftHooks)
		);
		editingLinkageId = null;
		draftHooks = new Set();
	}

	async function handleCreate(event?: Event) {
		if (event?.preventDefault) {
			try {
				event.preventDefault();
			} catch (err) {
				console.warn('handleCreate preventDefault failed', err);
			}
		}
		createError = null;
		console.log('handleCreate start', {
			createKind,
			selectedTemplateId,
			selectedCustomId,
			selectedHooks: Array.from(selectedHooks)
		});

		const hooks = Array.from(selectedHooks).filter(Boolean);
		if (hooks.length === 0) {
			console.log('handleCreate abort: no hooks');
			createError = 'Select at least one trigger hook.';
			return;
		}
		console.log('handleCreate after hooks check', hooks);

		if (createKind === 'template' && !selectedDefinition) {
			console.log('handleCreate abort: no selectedDefinition');
			createError = 'Select a CPS template to link.';
			return;
		}
		console.log('handleCreate after selectedDefinition check', selectedDefinition);

		if (createKind === 'custom' && !selectedCustomAction) {
			console.log('handleCreate abort: no selectedCustomAction');
			createError = 'Select a custom action to link.';
			return;
		}
		console.log('handleCreate after selectedCustomAction check');

		console.log('handleCreate about to enter try');

		try {
			console.log('handleCreate inside try', { eventType: event.type });
			creating = true;
			const chosenDefinition =
				createKind === 'template'
					? selectedDefinition ?? definitions.find((def) => def.id === selectedTemplateId) ?? null
					: null;
			const chosenCustom =
				createKind === 'custom'
					? selectedCustomAction ??
						customActions.find((action) => action.id === selectedCustomId) ??
						null
					: null;
			const centralActionId =
				createKind === 'template'
					? chosenDefinition?.id ?? selectedTemplateId
					: chosenCustom?.id ?? selectedCustomId;
			if (!centralActionId) {
				console.error('handleCreate abort: missing centralActionId', {
					createKind,
					chosenDefinition,
					chosenCustom
				});
				createError = 'Select an action to link.';
				return;
			}
			const label =
				createKind === 'template'
					? chosenDefinition?.label ?? centralActionId
					: chosenCustom?.display_name ?? chosenCustom?.code ?? centralActionId;
			console.log('handleCreate calling store.create', {
				central_action_id: centralActionId,
				action_type_indicator: createKind === 'template' ? 'master' : 'custom',
				trigger_hooks: hooks,
				action_name_label: label
			});
			await formActionsStore.create(data.formSourceSlug, data.formId, {
				central_action_id: centralActionId,
				action_type_indicator: createKind === 'template' ? 'master' : 'custom',
				trigger_hooks: hooks,
				action_name_label: label
			});
			console.log('handleCreate after store.create');
			pendingRemovalId = null;
			showAddPanel = false;
		} catch (error) {
			console.error('handleCreate error', error);
			createError =
				error instanceof Error ? error.message : 'Failed to create action mapping';
		} finally {
			creating = false;
		}
	}

	function requestRemove(linkage: FormActionLinkage) {
		pendingRemovalId = linkage.local_mapping_id;
	}

	function cancelRemove() {
		pendingRemovalId = null;
	}

	async function confirmRemove(linkage: FormActionLinkage) {
		await formActionsStore.remove(data.formSourceSlug, data.formId, linkage);
		pendingRemovalId = null;
	}

	async function toggleEnabled(linkage: FormActionLinkage) {
		const enabled = linkage.is_action_enabled_for_form !== false;
		await formActionsStore.toggleEnabled(
			data.formSourceSlug,
			data.formId,
			linkage,
			!enabled
		);
	}

	function refresh() {
		formActionsStore.refresh(data.formSourceSlug, data.formId);
	}

	async function checkEntryStatus(event: SubmitEvent) {
		event.preventDefault();
		const parsed = Number.parseInt(entryLookupId.trim(), 10);
		if (!entryLookupId.trim() || Number.isNaN(parsed) || parsed <= 0) {
			createError = 'Entry ID must be a positive number.';
			return;
		}
		createError = null;

		try {
			const status = await formActionsStore.fetchExecutionStatus(
				data.formSourceSlug,
				data.formId,
				parsed
			);

			if (status.status === 'error' && status.last_error) {
				notifications.error(status.last_error);
			} else if (status.last_response) {
				notifications.success('Sentient Forms processed the entry successfully.');
			} else {
				notifications.info('No Sentient Forms execution data found for this entry.');
			}
		} catch (error) {
			console.error(error);
		}
	}

	function performStatusAction(actionId: AdviceActionId) {
		if (actionId === 'refresh') {
			refresh();
			return;
		}
		if (actionId === 'licensing') {
			navigateToAppPath('/licensing');
		}
	}
</script>

<Section heading="Actions" description="Link CPS templates or custom actions to this form.">
	<div slot="actions" class="sf:flex sf:flex-wrap sf:gap-2">
		<Button variant="secondary" onclick={() => navigateToAppPath('/actions')}>All forms</Button>
		<Button variant="secondary" onclick={refresh}>Refresh</Button>
		<Button onclick={() => (showAddPanel = true)}>Add action</Button>
		<Button variant="secondary" onclick={checkEntryStatus}>Check entry status</Button>
	</div>

	<div class="sf:grid sf:gap-4 sf:xl:grid-cols-3">
		<Card class="sf:xl:col-span-2" data-testid="action-definitions-card">
			<div class="sf:flex sf:flex-col sf:gap-3 sf:md:flex-row sf:md:items-center sf:md:justify-between">
				<div>
					<p class="sf:text-sm sf:font-medium sf:text-slate-700">Action library</p>
					<p class="sf:text-xs sf:text-slate-500 sf:mt-1">
						Browse CPS templates and custom actions you can map to this form.
					</p>
				</div>
				<Badge variant={definitionsBadgeVariant}>{definitionsBadgeLabel}</Badge>
			</div>

			{#if !hasDefinitions}
				<Alert variant="warning" class="sf:mt-3">
					CPS templates are unavailable right now. You can still link custom actions below.
				</Alert>
			{:else if hasLocalDefinitions}
				<Alert variant="info" class="sf:mt-3">
					Some templates come from local extensions and may not exist in CPS. Confirm availability before linking.
				</Alert>
			{/if}

			<div class="sf:mt-4 sf:grid sf:gap-3 sf:lg:grid-cols-2">
				<Card class="sf:border-dashed">
					<p class="sf:text-xs sf:uppercase sf:tracking-wide sf:text-slate-500 sf:mb-2">
						Built-in templates
					</p>
					{#if definitions.length === 0}
						<p class="sf:text-sm sf:text-slate-600">No templates available.</p>
					{:else}
						<ul class="sf:space-y-2">
							{#each definitions.slice(0, 5) as definition (definition.id)}
								<li class="sf:flex sf:items-start sf:justify-between sf:gap-3">
									<div>
										<p class="sf:text-sm sf:font-semibold sf:text-slate-800">
											{definition.label ?? definition.id}
										</p>
										<p class="sf:text-xs sf:text-slate-500">
											Hooks: {summarizeDefinitionHooks(definition.hooks)}
										</p>
										<p class="sf:text-xs sf:text-slate-500">
											Cost: {formatBaseCreditCost(definition)} · Model: {formatModelHint(definition)}
										</p>
									</div>
									<Badge variant={definitionSourceBadgeVariant(definition)}>
										{definition.source === 'cps' ? 'CPS' : 'Local'}
									</Badge>
								</li>
							{/each}
						</ul>
					{/if}
				</Card>

				<Card class="sf:border-dashed">
					<div class="sf:flex sf:items-start sf:justify-between sf:gap-3">
						<div>
							<p class="sf:text-xs sf:uppercase sf:tracking-wide sf:text-slate-500 sf:mb-1">
								Custom actions
							</p>
							<p class="sf:text-sm sf:text-slate-700">
								{customActions.length > 0
									? `${customActions.length} active`
									: 'No active custom actions'}
							</p>
						</div>
						<Button size="sm" variant="secondary" onclick={() => navigateToAppPath('/actions/custom')}>
							Manage
						</Button>
					</div>
					{#if customActions.length > 0}
						<ul class="sf:mt-3 sf:space-y-2">
							{#each customActions.slice(0, 4) as action (action.id)}
								<li class="sf:flex sf:items-start sf:justify-between sf:gap-3">
									<div>
										<p class="sf:text-sm sf:font-semibold sf:text-slate-800">
											{action.display_name}
										</p>
										<p class="sf:text-xs sf:text-slate-500">Code: {action.code}</p>
									</div>
									<Badge variant="success">Active</Badge>
								</li>
							{/each}
						</ul>
					{/if}
				</Card>
			</div>

			<div class="sf:mt-6 sf:flex sf:items-center sf:justify-between">
				<div>
					<p class="sf:text-sm sf:font-medium sf:text-slate-700">Link actions to this form</p>
					<p class="sf:text-xs sf:text-slate-500">Choose a CPS template or custom action, then select hooks.</p>
				</div>
				<Button size="sm" onclick={() => (showAddPanel = true)}>Add action</Button>
			</div>
		</Card>

		<Card class="sf:space-y-3">
			<div class="sf:flex sf:items-center sf:justify-between">
				<p class="sf:text-sm sf:font-medium sf:text-slate-700">Execution status</p>
				{#if actionsState.status}
					<Badge variant={statusBadgeVariant(actionsState.status)}>{actionsState.status.status}</Badge>
				{/if}
			</div>
			{#if actionsState.balance}
				<p class="sf:text-sm sf:text-slate-600">
					Credit balance: <strong>{actionsState.balance.current_balance}</strong>
				</p>
			{/if}
			{#if actionsState.status}
				<p class="sf:text-sm sf:text-slate-700">{statusHeadline(actionsState.status)}</p>
				<p class="sf:text-sm sf:text-slate-600">{statusDescription(actionsState.status)}</p>
				{#if actionsState.status.updated_at}
					<p class="sf:text-xs sf:text-slate-500">
						Updated {new Date(actionsState.status.updated_at).toLocaleString()}
					</p>
				{/if}
			{:else}
				<p class="sf:text-sm sf:text-slate-600">Status not loaded yet.</p>
			{/if}

			{#if statusAdvice}
				<Alert variant={statusAdvice.variant}>
					<div class="sf:flex sf:flex-col sf:gap-2">
						<p class="sf:font-medium">{statusAdvice.title}</p>
						<p>{statusAdvice.description}</p>
						{#if statusAdvice.actions && statusAdvice.actions.length > 0}
							<div class="sf:flex sf:flex-wrap sf:gap-2">
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

			<form class="sf:pt-2 sf:space-y-2" onsubmit={checkEntryStatus}>
				<InputField
					id="entry-id-input"
					label="Check entry status"
					placeholder="Enter entry ID"
					bind:value={entryLookupId}
				/>
				<div class="sf:flex sf:justify-end">
					<Button type="submit" variant="secondary" size="sm">Check</Button>
				</div>
			</form>
		</Card>
	</div>

	{#if actionsState.error}
		<Alert variant="danger" class="sf:mt-4">
			<div class="sf:flex sf:flex-col sf:md:flex-row sf:items-start sf:md:items-center sf:gap-3">
				<span>{actionsState.error}</span>
				<Button size="sm" variant="secondary" onclick={refresh}>Retry</Button>
			</div>
		</Alert>
	{/if}

	<Card class="sf:mt-4">
		<div class="sf:flex sf:items-center sf:justify-between sf:gap-3 sf:mb-3">
			<div>
				<p class="sf:text-sm sf:font-medium sf:text-slate-700">Linked actions</p>
				<p class="sf:text-xs sf:text-slate-500">
					Enable, disable, or retarget hooks for actions connected to this form.
				</p>
			</div>
			<Button variant="secondary" size="sm" onclick={refresh}>Refresh</Button>
		</div>

		{#if actionsState.loading}
			<p class="sf:text-sm sf:text-slate-600">Loading action mappings…</p>
		{:else if actionsState.items.length === 0}
			<p class="sf:text-sm sf:text-slate-600">No CPS actions linked to this form yet.</p>
		{:else}
			<div class="sf:overflow-x-auto">
				<table class="sf:min-w-full sf:divide-y sf:divide-slate-200" data-testid="form-actions-table">
					<thead class="sf:bg-slate-50">
						<tr class="sf:text-left sf:text-xs sf:font-semibold sf:uppercase sf:tracking-wide sf:text-slate-600">
							<th class="sf:px-4 sf:py-3">Action</th>
							<th class="sf:px-4 sf:py-3">Hooks</th>
							<th class="sf:px-4 sf:py-3">Type</th>
							<th class="sf:px-4 sf:py-3">Status</th>
							<th class="sf:px-4 sf:py-3 sf:text-right">Actions</th>
						</tr>
					</thead>
					<tbody class="sf:divide-y sf:divide-slate-200">
						{#each actionsState.items as linkage (linkage.local_mapping_id)}
							<tr class="sf:text-sm sf:text-slate-700">
								<td class="sf:px-4 sf:py-3 sf:font-medium">
									{friendlyActionLabel(linkage)}
									<p class="sf:text-xs sf:text-slate-500">
										ID: {linkage.central_action_id}
									</p>
								</td>
								<td class="sf:px-4 sf:py-3">
									<div class="sf:flex sf:flex-wrap sf:gap-2">
										{#if linkage.trigger_hooks && linkage.trigger_hooks.length > 0}
											{#each linkage.trigger_hooks as hook (hook)}
												<Badge variant="info">{hookOptions[hook] ?? hook}</Badge>
											{/each}
										{:else}
											<span class="sf:text-xs sf:text-slate-500">No hooks configured</span>
										{/if}
									</div>
									<div class="sf:mt-2 sf:space-x-2">
										<Button size="sm" variant="ghost" onclick={() => startEditingHooks(linkage)}>
											Edit hooks
										</Button>
									</div>
									{#if editingLinkageId === linkage.local_mapping_id}
										<div class="sf:mt-3 sf:rounded-md sf:border sf:border-slate-200 sf:p-3 sf:space-y-2">
											{#each hookEntries as [hookKey, hookLabel] (hookKey)}
												<label class="sf:flex sf:items-center sf:gap-2 sf:text-sm">
													<input
														type="checkbox"
														class="sf:form-checkbox"
														checked={draftHooks.has(hookKey)}
														onchange={() => toggleDraftHook(hookKey)}
													/>
													<span>{hookLabel}</span>
												</label>
											{/each}
											<div class="sf:flex sf:gap-2 sf:flex-wrap sf:pt-2">
												<Button size="sm" onclick={() => saveHookChanges(linkage)}>Save</Button>
												<Button size="sm" variant="secondary" onclick={cancelEditingHooks}>
													Cancel
												</Button>
											</div>
										</div>
									{/if}
								</td>
								<td class="sf:px-4 sf:py-3">
									<Badge variant={linkage.action_type_indicator === 'custom' ? 'info' : 'neutral'}>
										{linkage.action_type_indicator === 'custom' ? 'Custom' : 'CPS template'}
									</Badge>
								</td>
								<td class="sf:px-4 sf:py-3">
									<Badge variant={statusVariant(linkage)}>{statusLabel(linkage)}</Badge>
								</td>
								<td class="sf:px-4 sf:py-3 sf:text-right sf:space-x-2">
									<Button size="sm" variant="secondary" onclick={() => toggleEnabled(linkage)}>
										{linkage.is_action_enabled_for_form === false ? 'Enable' : 'Disable'}
									</Button>
									{#if pendingRemovalId === linkage.local_mapping_id}
										<Button size="sm" variant="danger" onclick={() => confirmRemove(linkage)}>
											Confirm
										</Button>
										<Button size="sm" variant="ghost" onclick={cancelRemove}>Cancel</Button>
									{:else}
										<Button size="sm" variant="ghost" onclick={() => requestRemove(linkage)}>
											Remove
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

	{#if showAddPanel}
		<div class="sf:fixed sf:inset-0 sf:z-30 sf:bg-black/40 sf:flex sf:justify-end">
			<div class="sf:h-full sf:w-full sf:max-w-xl sf:bg-white sf:shadow-2xl sf:flex sf:flex-col">
				<div class="sf:flex sf:items-center sf:justify-between sf:border-b sf:border-slate-200 sf:px-4 sf:py-3">
					<div>
						<p class="sf:text-sm sf:font-semibold sf:text-slate-800">Add action</p>
						<p class="sf:text-xs sf:text-slate-500">Link a CPS template or custom action.</p>
					</div>
					<Button variant="ghost" size="sm" onclick={() => (showAddPanel = false)}>Close</Button>
				</div>

				<div class="sf:flex sf:flex-wrap sf:items-end sf:gap-2 sf:px-4 sf:py-3">
					<Button
						size="sm"
						variant={createKind === 'template' ? 'primary' : 'secondary'}
						onclick={() => (createKind = 'template')}
						disabled={!hasDefinitions}
					>
						CPS templates
					</Button>
					<Button
						size="sm"
						variant={createKind === 'custom' ? 'primary' : 'secondary'}
						onclick={() => (createKind = 'custom')}
						disabled={customActions.length === 0}
					>
						Custom actions
					</Button>
					<div class="sf:flex-1 sf:min-w-[200px]">
						<InputField
							id="action-search"
							label="Search"
							placeholder="Search by name or id"
							bind:value={searchTerm}
						/>
					</div>
				</div>

				<form class="sf:flex sf:flex-col sf:gap-4 sf:px-4 sf:pb-4 sf:overflow-y-auto" data-testid="link-action-form">
					{#if createKind === 'template'}
						{#if !hasDefinitions}
							<Alert variant="warning">No CPS templates available right now.</Alert>
						{:else}
							<div class="sf:space-y-2">
								{#each definitions.filter((definition) => {
									const term = searchTerm.toLowerCase();
									if (!term) return true;
									const label = (definition.label ?? '').toLowerCase();
									return definition.id.toLowerCase().includes(term) || label.includes(term);
								}) as definition (definition.id)}
									<label class="sf:flex sf:items-start sf:gap-3 sf:border sf:border-slate-200 sf:rounded-md sf:p-3 sf:cursor-pointer sf:hover:border-primary-300">
										<input
											type="radio"
											name="template-choice"
											class="sf:mt-1"
											checked={selectedTemplateId === definition.id}
											onchange={() => (selectedTemplateId = definition.id)}
										/>
										<div class="sf:flex sf:flex-col sf:gap-1">
											<p class="sf:text-sm sf:font-semibold sf:text-slate-800">
												{definition.label ?? definition.id}
											</p>
											<p class="sf:text-xs sf:text-slate-500">ID: {definition.id}</p>
											<p class="sf:text-xs sf:text-slate-500">
												Cost: {formatBaseCreditCost(definition)} · Model: {formatModelHint(definition)}
											</p>
											<p class="sf:text-xs sf:text-slate-500">
												Hooks: {summarizeDefinitionHooks(definition.hooks)}
											</p>
										</div>
									</label>
								{/each}
							</div>
						{/if}
					{:else}
						{#if customActions.length === 0}
							<Alert variant="info">No active custom actions. Create one first.</Alert>
						{:else}
							<div class="sf:space-y-2">
								{#each customActions.filter((action) => {
									const term = searchTerm.toLowerCase();
									if (!term) return true;
									return (
										action.display_name.toLowerCase().includes(term) ||
										action.code.toLowerCase().includes(term) ||
										action.id.toLowerCase().includes(term)
									);
								}) as action (action.id)}
									<label class="sf:flex sf:items-start sf:gap-3 sf:border sf:border-slate-200 sf:rounded-md sf:p-3 sf:cursor-pointer sf:hover:border-primary-300">
										<input
											type="radio"
											name="custom-choice"
											class="sf:mt-1"
											checked={selectedCustomId === action.id}
											onchange={() => (selectedCustomId = action.id)}
										/>
										<div class="sf:flex sf:flex-col sf:gap-1">
											<p class="sf:text-sm sf:font-semibold sf:text-slate-800">
												{action.display_name}
											</p>
											<p class="sf:text-xs sf:text-slate-500">Code: {action.code}</p>
											{#if action.base_credit_cost !== null}
												<p class="sf:text-xs sf:text-slate-500">
													Cost: {action.base_credit_cost} credits
												</p>
											{/if}
										</div>
									</label>
								{/each}
							</div>
						{/if}
					{/if}

					<div>
						<p class="sf:text-sm sf:font-medium sf:text-slate-700 sf:mb-2">Trigger hooks</p>
					<div class="sf:flex sf:flex-wrap sf:gap-3">
						{#if hookEntries.length === 0}
							{#each Object.entries(FALLBACK_HOOK_LABELS) as [hookKey, hookLabel]}
								<label class="sf:flex sf:items-center sf:gap-2 sf:text-sm sf:text-slate-700 sf:border sf:border-slate-200 sf:rounded-md sf:px-3 sf:py-2">
									<input
										type="checkbox"
										class="sf:form-checkbox"
										checked={selectedHooks.has(hookKey)}
										onchange={() => toggleHookSelection(hookKey)}
									/>
									<span>{hookLabel}</span>
								</label>
							{/each}
						{:else}
							{#each hookEntries as [hookKey, hookLabel] (hookKey)}
								<label class="sf:flex sf:items-center sf:gap-2 sf:text-sm sf:text-slate-700 sf:border sf:border-slate-200 sf:rounded-md sf:px-3 sf:py-2">
									<input
										type="checkbox"
										class="sf:form-checkbox"
										checked={selectedHooks.has(hookKey)}
										onchange={() => toggleHookSelection(hookKey)}
									/>
									<span>{hookLabel}</span>
								</label>
							{/each}
						{/if}
					</div>
			<!-- debug output to verify hook options during e2e; remove after stabilization -->
			<pre class="sf:text-[11px] sf:text-slate-400" data-testid="hook-debug">{JSON.stringify(hookOptions)}</pre>
		</div>

					{#if createError}
						<Alert variant="danger">{createError}</Alert>
					{/if}

					<div class="sf:flex sf:justify-end sf:gap-2 sf:pb-2">
						<Button type="button" variant="secondary" onclick={() => (showAddPanel = false)}>
							Cancel
						</Button>
							<Button
								type="button"
								onclick={() => handleCreate(new Event('submit', { cancelable: true }))}
								disabled={creating || selectedHooks.size === 0 || (!hasDefinitions && createKind === 'template')}
							>
								{creating ? 'Linking…' : 'Link action'}
							</Button>
						</div>
					</form>
			</div>
		</div>
	{/if}
</Section>
