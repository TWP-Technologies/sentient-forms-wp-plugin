<script lang="ts">
	import type { SelectOption } from '$lib/components/ui/types';

	type SelectValue = string | number | undefined;

	interface AlignedSelectItem {
		id: string;
		label: string;
		description?: string | null;
		value?: SelectValue;
		options: SelectOption[];
		disabled?: boolean;
		onchange?: (event: Event & { currentTarget: HTMLSelectElement }) => void;
	}

	interface Props {
		items: AlignedSelectItem[];
		columns?: 2 | 3;
		class?: string;
	}

	let { items, columns = 3, class: className = '' }: Props = $props();

	const gridClass = $derived(columns === 2 ? 'sf-aligned-select-grid--two' : 'sf-aligned-select-grid--three');
</script>

<div class={`sf-aligned-select-grid ${gridClass} ${className}`} data-testid="aligned-select-grid">
	{#each items as item}
		<div class="sf-aligned-select-grid__field">
			<label class="sf-aligned-select-grid__label" for={item.id}>{item.label}</label>
			<p class="sf-aligned-select-grid__description" id={`${item.id}-description`}>
				{item.description ?? ''}
			</p>
			<select
				id={item.id}
				value={item.value}
				disabled={item.disabled}
				aria-describedby={item.description ? `${item.id}-description` : undefined}
				class="sf-aligned-select-grid__select"
				onchange={item.onchange}
			>
				{#each item.options as option}
					<option value={option.value} disabled={option.disabled}>{option.label}</option>
				{/each}
			</select>
		</div>
	{/each}
</div>

<style>
	.sf-aligned-select-grid {
		display: grid;
		grid-template-columns: minmax(0, 1fr);
		gap: 1rem;
	}

	.sf-aligned-select-grid__field {
		display: grid;
		grid-template-rows: auto minmax(0, 1fr) auto;
		align-items: start;
		min-width: 0;
	}

	.sf-aligned-select-grid__label {
		color: rgb(15 23 42);
		font-size: 0.875rem;
		font-weight: 650;
		line-height: 1.25rem;
	}

	.sf-aligned-select-grid__description {
		margin-top: 0.45rem;
		color: rgb(71 85 105);
		font-size: 0.875rem;
		line-height: 1.35;
	}

	.sf-aligned-select-grid__select {
		width: 100%;
		min-width: 0;
		margin-top: 0.7rem;
		border: 1px solid rgb(203 213 225);
		border-radius: 0.25rem;
		background-color: rgb(255 255 255);
		padding: 0.5rem 0.75rem;
		color: rgb(15 23 42);
		font-size: 0.875rem;
		line-height: 1.25rem;
	}

	.sf-aligned-select-grid__select:focus-visible {
		border-color: rgb(37 99 235);
		outline: none;
		box-shadow:
			0 0 0 1px rgb(37 99 235),
			0 0 0 3px rgb(191 219 254);
	}

	.sf-aligned-select-grid__select:disabled {
		background-color: rgb(241 245 249);
		color: rgb(148 163 184);
	}

	@media (min-width: 768px) {
		.sf-aligned-select-grid--two {
			grid-template-columns: repeat(2, minmax(0, 1fr));
		}

		.sf-aligned-select-grid--three {
			grid-template-columns: repeat(3, minmax(0, 1fr));
		}
	}
</style>
