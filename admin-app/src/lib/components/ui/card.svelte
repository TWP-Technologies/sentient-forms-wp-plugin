<script lang="ts">
	interface Props {
		title?: string | null;
		subtitle?: string | null;
		action?: import('svelte').Snippet;
		children?: import('svelte').Snippet;
		[key: string]: any
	}

	let {
		title = null,
		subtitle = null,
		action,
		children,
		...rest
	}: Props = $props();
</script>

<div class={`sf-card ${rest.class || ''}`.trim()} {...rest}>
	{#if title}
		<header class="sf-card-header sf:border-b sf:border-slate-200 sf:p-6">
			<div>
				<h2 class="sf:text-lg sf:font-semibold sf:text-slate-900">{title}</h2>
				{#if subtitle}
					<p class="sf:text-sm sf:text-slate-500 sf:mt-1">{subtitle}</p>
				{/if}
			</div>
			{@render action?.()}
		</header>
	{/if}
	<div class="sf-card-body sf:p-6">
		{@render children?.()}
	</div>
</div>
