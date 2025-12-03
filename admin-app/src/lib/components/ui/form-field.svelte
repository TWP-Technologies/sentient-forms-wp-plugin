<script lang="ts">
	interface Props {
		id: string;
		label: string;
		description?: string | null;
		help?: string | null;
		error?: string | null;
		required?: boolean;
		children?: import('svelte').Snippet;
	}

	let {
		id,
		label,
		description = null,
		help = null,
		error = null,
		required = false,
		children
	}: Props = $props();
</script>

<div class="sf:space-y-1" data-testid="form-field">
	<label class="sf:text-sm sf:font-medium sf:text-slate-700" for={id}>
		{label}
		{#if required}
			<span aria-hidden="true" class="sf:text-danger-500 sf:font-semibold">*</span>
		{/if}
	</label>
	{#if description}
		<p class="sf:text-sm sf:text-slate-500" id={`${id}-description`}>
			{description}
		</p>
	{/if}
	{@render children?.()}
	{#if help}
		<p class="sf:text-xs sf:text-slate-500" id={`${id}-help`}>
			{help}
		</p>
	{/if}
	{#if error}
		<p class="sf:text-sm sf:text-danger-600" id={`${id}-error`} role="alert">
			{error}
		</p>
	{/if}
</div>
