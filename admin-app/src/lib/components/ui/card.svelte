<script lang="ts">
	export let title: string | null = null;
	export let subtitle: string | null = null;
</script>

<div class={`sf-card ${$$restProps.class || ''}`.trim()} {...$$restProps}>
	{#if title}
		<header class="sf-card-header sf-border-b sf-border-slate-200 sf-p-6">
			<div>
				<h2 class="sf-text-lg sf-font-semibold sf-text-slate-900">{title}</h2>
				{#if subtitle}
					<p class="sf-text-sm sf-text-slate-500 sf-mt-1">{subtitle}</p>
				{/if}
			</div>
			<slot name="action" />
		</header>
	{/if}
	<div class="sf-card-body sf-p-6">
		<slot />
	</div>
</div>
