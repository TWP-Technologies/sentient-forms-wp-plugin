<script lang="ts">
	import { page } from '$app/state';
	import { onMount } from 'svelte';
	import { Section, Button, StateTemplate } from '$lib/components/ui';
	import CustomActionForm from '$lib/components/custom-action-form.svelte';
	import { customActionsStore, customActionsState } from '$lib/stores/custom-actions';
	import { navigateToAppPath } from '$lib/navigation';
	import type { CustomActionUpdateInput } from '$lib/schemas/custom-action';
	import type { CustomAction } from '$lib/api/types';

	const customState = customActionsState;

	let actionId = $derived(page.params.id);
	let action = $derived<CustomAction | undefined>(
		customState.actions.find((a) => a.id === actionId)
	);
	let loading = $state(true);
	let submitting = $state(false);
	let error = $state<string | null>(null);

	onMount(async () => {
		// Ensure actions are loaded
		if (customState.actions.length === 0 || !customState.lastLoadedAt) {
			await customActionsStore.load({ include_archived: true });
		}
		loading = false;
	});

	async function handleSubmit(data: CustomActionUpdateInput) {
		if (!actionId) return;
		submitting = true;
		error = null;
		try {
			await customActionsStore.update(actionId, data);
			await navigateToAppPath('/actions/custom');
		} catch (e) {
			if (e instanceof Error) {
				error = e.message;
			}
			throw e;
		} finally {
			submitting = false;
		}
	}

	function handleCancel() {
		void navigateToAppPath('/actions/custom');
	}
</script>

<Section
	heading="Edit Custom Action"
	description="Modify the configuration for this custom action."
>
	{#snippet actions()}
		<Button variant="secondary" onclick={handleCancel}>← Back to List</Button>
	{/snippet}

	{#if loading || customState.loading}
		<StateTemplate
			variant="loading"
			title="Loading custom action"
			message="Retrieving action details for editing."
			testId="custom-action-edit-loading-state"
		/>
	{:else if !action}
		<StateTemplate
			variant="empty"
			title="Custom action not found"
			message="This action may have been removed or you may no longer have access."
			actionLabel="Back to list"
			onAction={handleCancel}
			testId="custom-action-edit-empty-state"
		/>
	{:else}
		{#if error}
			<StateTemplate
				variant="error"
				title="Unable to update custom action"
				message={error}
				actionLabel="Back to list"
				onAction={handleCancel}
				testId="custom-action-edit-error-state"
			/>
		{/if}

		<CustomActionForm
			initialData={action}
			definitions={customState.definitions}
			onSubmit={handleSubmit}
			onCancel={handleCancel}
			{submitting}
		/>
	{/if}
</Section>
