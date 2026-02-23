<script lang="ts">
	import { onMount } from 'svelte';
	import { Section, Button, Alert } from '$lib/components/ui';
	import CustomActionForm from '$lib/components/custom-action-form.svelte';
	import { customActionsStore, customActionsState } from '$lib/stores/custom-actions';
	import { navigateToAppPath } from '$lib/navigation';
	import type { CustomActionCreateInput } from '$lib/schemas/custom-action';

	let submitting = $state(false);
	let error = $state<string | null>(null);

	// Ensure definitions are loaded when navigating directly to this page
	onMount(async () => {
		if (customActionsState.definitions.length === 0) {
			await customActionsStore.load();
		}
	});

	async function handleSubmit(data: CustomActionCreateInput) {
		submitting = true;
		error = null;
		try {
			await customActionsStore.create(data);
			// Navigate back to list on success
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
	heading="Create Custom Action"
	description="Configure a new custom action based on a CPS template."
>
	{#snippet actions()}
		<Button variant="secondary" onclick={handleCancel}>← Back to List</Button>
	{/snippet}

	{#if error}
		<Alert variant="danger" class="sf:mb-4">{error}</Alert>
	{/if}

	{#if customActionsState.loading}
		<p class="sf:text-slate-500 sf:italic">Loading templates…</p>
	{:else}
		<CustomActionForm
			definitions={customActionsState.definitions}
			onSubmit={handleSubmit}
			onCancel={handleCancel}
			{submitting}
		/>
	{/if}
</Section>
