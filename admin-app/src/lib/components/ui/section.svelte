<script lang="ts">
	export let heading: string;
	export let description: string | null = null;
</script>

<section class="sf-space-y-4">
	<header class="sf-flex sf-items-center sf-justify-between sf-gap-2">
		<div>
			<h2 class="sf-text-2xl sf-font-semibold sf-text-slate-900">{heading}</h2>
			{#if description}
				<p class="sf-text-sm sf-text-slate-500 sf-mt-1">{description}</p>
			{/if}
		</div>
		<slot name="actions" />
	</header>
	<slot />
</section>
