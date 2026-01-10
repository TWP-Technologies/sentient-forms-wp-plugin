<script lang="ts">
	import { page } from '$app/state';
	import { onMount } from 'svelte';
	import { Section, Button, Alert, Skeleton } from '$lib/components/ui';
	import CustomActionForm from '$lib/components/custom-action-form.svelte';
	import { customActionsStore, customActionsState } from '$lib/stores/custom-actions';
	import { goto } from '$app/navigation';
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
			goto('#/actions/custom');
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
		goto('#/actions/custom');
	}
</script>

<Section
	heading="Edit Custom Action"
	description="Modify the configuration for this custom action."
>
	<div slot="actions">
		<Button variant="secondary" onclick={handleCancel}>← Back to List</Button>
	</div>

	{#if loading || customState.loading}
		<Skeleton className="sf:h-64" />
	{:else if !action}
		<Alert variant="warning">
			Action not found. It may have been deleted or you don't have access.
		</Alert>
		<div class="sf:mt-4">
			<Button variant="secondary" onclick={handleCancel}>Back to List</Button>
		</div>
	{:else}
		{#if error}
			<Alert variant="danger" class="sf:mb-4">{error}</Alert>
		{/if}

		<CustomActionForm
			initialData={action}
			onSubmit={handleSubmit}
			onCancel={handleCancel}
			{submitting}
		/>
	{/if}
</Section>
