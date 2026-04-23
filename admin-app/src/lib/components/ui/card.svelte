<script lang="ts">
	interface Props {
		title?: string | null;
		subtitle?: string | null;
		action?: import('svelte').Snippet;
		children?: import('svelte').Snippet;
		class?: string;
		[key: string]: any
	}

	let {
		title = null,
		subtitle = null,
		class: className = '',
		action,
		children,
		...rest
	}: Props = $props();
</script>

<div class={`sf-card sf:min-w-0 sf:max-w-full ${className}`.trim()} {...rest}>
	{#if title}
		<header class="sf-card-header sf:border-b sf:border-slate-200 sf:p-4 sf:sm:p-6">
			<div>
				<h2 class="sf:text-lg sf:font-semibold sf:text-slate-900 sf:break-words">{title}</h2>
				{#if subtitle}
						<p class="sf:mt-1 sf:text-sm sf:text-slate-600">{subtitle}</p>
				{/if}
			</div>
			{#if action}
				<div class="sf:flex sf:w-full sf:flex-wrap sf:gap-2 sf:sm:w-auto sf:sm:justify-end">
					{@render action?.()}
				</div>
			{/if}
		</header>
	{/if}
	<div class="sf-card-body sf:p-4 sf:sm:p-6">
		{@render children?.()}
	</div>
</div>
