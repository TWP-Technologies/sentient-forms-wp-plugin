<script lang="ts">
	interface Props {
		heading: string;
		description?: string | null;
		actions?: import('svelte').Snippet;
		children?: import('svelte').Snippet;
	}

	let {
		heading,
		description = null,
		actions,
		children
	}: Props = $props();
</script>

<section class="sf:min-w-0 sf:space-y-4">
	<header
		class="sf:flex sf:min-w-0 sf:flex-col sf:gap-3 sf:sm:flex-row sf:sm:items-start sf:sm:justify-between"
	>
		<div class="sf:min-w-0 sf:flex-1">
			<h2 class="sf:text-2xl sf:font-semibold sf:text-slate-900 sf:break-words">{heading}</h2>
			{#if description}
				<p class="sf:text-sm sf:text-slate-500 sf:mt-1">{description}</p>
			{/if}
		</div>
		{#if actions}
			<div class="sf:flex sf:w-full sf:flex-wrap sf:gap-2 sf:sm:w-auto sf:sm:justify-end">
				{@render actions?.()}
			</div>
		{/if}
	</header>
	{@render children?.()}
</section>
