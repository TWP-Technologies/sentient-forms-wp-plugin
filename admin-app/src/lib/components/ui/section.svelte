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

<section class="sf:space-y-4">
	<header class="sf:flex sf:items-center sf:justify-between sf:gap-2">
		<div>
			<h2 class="sf:text-2xl sf:font-semibold sf:text-slate-900">{heading}</h2>
			{#if description}
				<p class="sf:text-sm sf:text-slate-500 sf:mt-1">{description}</p>
			{/if}
		</div>
		{@render actions?.()}
	</header>
	{@render children?.()}
</section>
