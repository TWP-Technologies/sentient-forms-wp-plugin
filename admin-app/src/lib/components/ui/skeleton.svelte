<script lang="ts">
	interface Props {
		className?: string;
	}

	let { className = '' }: Props = $props();
</script>

<div class={`sf:animate-pulse sf:rounded sf:bg-slate-200 ${className}`}></div>
