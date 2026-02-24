<script lang="ts">
	import { Badge, Card } from '$lib/components/ui';
	import Button from './button.svelte';
	import type { InputMapping, FormFieldInfo } from '$lib/api/types';

	interface Props {
		/** Available form fields */
		fields: FormFieldInfo[];
		/** Current input mapping configuration */
		value: InputMapping;
		/** Callback when mapping changes */
		onchange: (mapping: InputMapping) => void;
		/** Disabled state */
		disabled?: boolean;
	}

	let { fields, value, onchange, disabled = false }: Props = $props();

	// Field types to exclude from selection (non-user-input fields)
	const EXCLUDED_TYPES = ['html', 'page', 'section', 'captcha'];

	const selectableFields = $derived(
		fields.filter((f) => !EXCLUDED_TYPES.includes(f.type.toLowerCase()))
	);

	const selectedIds = $derived(new Set(value.field_ids ?? []));

	const allSelected = $derived(
		value.mode === 'all' ||
			(value.mode === 'selected' && selectedIds.size === selectableFields.length)
	);

	function handleModeChange(event: Event) {
		const target = event.target as HTMLSelectElement;
		const newMode = target.value as InputMapping['mode'];

		onchange({
			...value,
			mode: newMode,
			field_ids: newMode === 'all' ? undefined : (value.field_ids ?? [])
		});
	}

	function toggleField(fieldId: string) {
		if (disabled || value.mode === 'all') return;

		const newIds = new Set(value.field_ids ?? []);
		if (newIds.has(fieldId)) {
			newIds.delete(fieldId);
		} else {
			newIds.add(fieldId);
		}

		onchange({
			...value,
			field_ids: Array.from(newIds)
		});
	}

	function toggleAll() {
		if (disabled || value.mode === 'all') return;

		if (allSelected) {
			// Deselect all
			onchange({
				...value,
				field_ids: []
			});
		} else {
			// Select all
			onchange({
				...value,
				field_ids: selectableFields.map((f) => f.id)
			});
		}
	}

	function toggleMetadata() {
		if (disabled) return;

		onchange({
			...value,
			include_metadata: !value.include_metadata
		});
	}
</script>

<Card class="sf:p-4">
	<div class="sf:flex sf:flex-col sf:gap-4">
		<div class="sf:flex sf:items-center sf:justify-between">
			<div>
				<h4 class="sf:text-sm sf:font-medium sf:text-slate-800">Field Selection</h4>
				<p class="sf:text-xs sf:text-slate-500">
					Choose which form fields to send for action processing
				</p>
			</div>
			<select
				class="sf:px-3 sf:py-1.5 sf:text-sm sf:border sf:border-slate-300 sf:rounded sf:bg-white sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
				value={value.mode}
				onchange={handleModeChange}
				{disabled}
			>
				<option value="all">Send all fields</option>
				<option value="selected">Selected fields only</option>
				<option value="exclude">Exclude selected fields</option>
			</select>
		</div>

		{#if value.mode === 'all'}
			<div
				class="sf:rounded sf:border sf:border-amber-200 sf:bg-amber-50 sf:px-3 sf:py-2 sf:text-xs sf:text-amber-900"
			>
				Send all fields is an explicit full-entry option. Use it only when required.
			</div>
		{/if}

		{#if value.mode !== 'all'}
			<div class="sf:border-t sf:border-slate-100 sf:pt-3">
				<div class="sf:flex sf:items-center sf:justify-between sf:mb-2">
					<span class="sf:text-xs sf:font-medium sf:text-slate-500 sf:uppercase sf:tracking-wide">
						{value.mode === 'selected' ? 'Include' : 'Exclude'} Fields
					</span>
					<Button
						type="button"
						variant="ghost"
						size="sm"
						class="sf:h-auto sf:border-transparent sf:bg-transparent sf:px-1 sf:py-0 sf:text-xs sf:text-primary-700 sf:underline hover:sf:bg-primary-50 hover:sf:text-primary-700"
						onclick={toggleAll}
						{disabled}
					>
						{allSelected ? 'Deselect all' : 'Select all'}
					</Button>
				</div>

				{#if selectableFields.length === 0}
					<p class="sf:text-sm sf:text-slate-500 sf:italic">No selectable fields found</p>
				{:else}
					<div class="sf:grid sf:gap-2 sf:max-h-48 sf:overflow-y-auto">
						{#each selectableFields as field (field.id)}
							<label
								class="sf:flex sf:items-center sf:gap-2 sf:p-2 sf:rounded sf:border sf:border-slate-100 hover:sf:bg-slate-50 sf:cursor-pointer"
								class:sf:bg-blue-50={selectedIds.has(field.id)}
								class:sf:border-blue-200={selectedIds.has(field.id)}
							>
								<input
									type="checkbox"
									class="sf:w-4 sf:h-4 sf:text-primary-600 sf:rounded sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
									checked={selectedIds.has(field.id)}
									onchange={() => toggleField(field.id)}
									{disabled}
								/>
								<div class="sf:flex-1 sf:min-w-0">
									<span class="sf:text-sm sf:font-medium sf:text-slate-800 sf:truncate sf:block">
										{field.adminLabel || field.label}
									</span>
									<span class="sf:text-xs sf:text-slate-500">ID: {field.id}</span>
								</div>
								<Badge variant="neutral">{field.type}</Badge>
							</label>
						{/each}
					</div>
				{/if}
			</div>
		{/if}

		<div class="sf:border-t sf:border-slate-100 sf:pt-3">
			<label class="sf:flex sf:items-center sf:gap-2 sf:cursor-pointer">
				<input
					type="checkbox"
					class="sf:w-4 sf:h-4 sf:text-primary-600 sf:rounded sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
					checked={value.include_metadata ?? true}
					onchange={toggleMetadata}
					{disabled}
				/>
				<span class="sf:text-sm sf:text-slate-700"
					>Include form metadata (form title, entry ID)</span
				>
			</label>
		</div>
	</div>
</Card>
