<script lang="ts">
	import '$lib/styles/tailwind.css';
	interface Props {
		children?: import('svelte').Snippet;
	}

	let { children }: Props = $props();
</script>

<svelte:head>
	<title>Sentient Forms Admin</title>
</svelte:head>

{@render children?.()}
