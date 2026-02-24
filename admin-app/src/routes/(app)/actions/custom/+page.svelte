<script lang="ts">
	import { onMount } from 'svelte';
	import { Section, Card, Button, Badge, Alert } from '$lib/components/ui';
	import { customActionsStore, customActionsState } from '$lib/stores/custom-actions';
	import { navigateToAppPath } from '$lib/navigation';
	import { formatTimeOnly, formatTimestamp } from '$lib/utils/date-time';
	import type { CustomAction } from '$lib/api/types';

	const customState = customActionsState;

	let statusFilter = $state<'active' | 'archived' | 'all'>(
		(customState.filters.status as 'active' | 'archived') ?? 'active'
	);

	onMount(() => {
		customActionsStore.load(customState.filters);
	});

	function applyStatusFilter(filter: 'active' | 'archived' | 'all'): void {
		statusFilter = filter;
		const filters = filter === 'all' ? { include_archived: true } : { status: filter };
		customActionsStore.setFilters(filters);
		customActionsStore.load(filters);
	}

	function archive(action: CustomAction) {
		customActionsStore.archive(action.id);
	}

	function reactivate(action: CustomAction) {
		customActionsStore.reactivate(action.id);
	}

	function editAction(action: CustomAction) {
		void navigateToAppPath(`/actions/custom/${action.id}`);
	}

	function createAction() {
		void navigateToAppPath('/actions/custom/new');
	}

	const statusFilters = [
		{ label: 'Active', value: 'active' },
		{ label: 'Archived', value: 'archived' },
		{ label: 'All', value: 'all' }
	] as const;

	const canCreateAction = $derived(customState.quota && customState.quota.quota_remaining > 0);
</script>

<Section
	heading="Custom Actions"
	description="Create tenant-specific workflows backed by CPS custom actions."
>
	{#snippet actions()}
		<div class="sf:flex sf:gap-2 sf:flex-wrap">
			<Button
				variant="secondary"
				onclick={() => customActionsStore.reload()}
				disabled={customState.loading}
			>
				Refresh
			</Button>
			<Button
				variant="primary"
				onclick={createAction}
				disabled={!canCreateAction || customState.loading}
			>
				+ Create Action
			</Button>
		</div>
	{/snippet}

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

	<Card>
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
					{formatTimeOnly(customState.lastLoadedAt, 'never')}
				</span>
			</div>

		{#if customState.loading}
			<p class="sf:mt-4 sf:text-sm sf:text-slate-600">Loading custom actions…</p>
		{:else if customState.actions.length === 0}
			<div class="sf:mt-4 sf:text-center sf:py-8">
				<p class="sf:text-sm sf:text-slate-600 sf:mb-4">
					No {statusFilter === 'archived' ? 'archived' : 'active'} custom actions yet.
				</p>
				{#if statusFilter !== 'archived' && canCreateAction}
					<Button variant="primary" onclick={createAction}>Create Your First Action</Button>
				{/if}
			</div>
		{:else}
			<div class="sf:mt-4 sf:overflow-auto">
				<table class="sf:min-w-full sf:text-sm" data-testid="custom-actions-table">
					<thead>
						<tr class="sf:text-left sf:text-slate-500">
							<th class="sf:p-2">Name</th>
							<th class="sf:p-2">Code</th>
							<th class="sf:p-2">Kind</th>
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
								<td class="sf:p-2">
									{#if action.action_kind === 'custom_definition'}
										<Badge variant="info">Custom</Badge>
									{:else}
										<Badge variant="neutral">Override</Badge>
									{/if}
								</td>
								<td
									class="sf:p-2 sf:font-mono sf:text-xs sf:break-all sf:max-w-xs sf:truncate"
									title={action.template_id}
								>
									{action.template_id.slice(0, 8)}…
								</td>
								<td class="sf:p-2">
									<Badge variant={action.status === 'active' ? 'success' : 'neutral'}>
										{action.status}
									</Badge>
								</td>
									<td class="sf:p-2 sf:text-xs sf:text-slate-500">
										{formatTimestamp(action.updated_at)}
									</td>
								<td class="sf:p-2 sf:text-right sf:flex sf:gap-2 sf:justify-end">
									<Button size="sm" variant="secondary" onclick={() => editAction(action)}>
										Edit
									</Button>
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
</Section>
