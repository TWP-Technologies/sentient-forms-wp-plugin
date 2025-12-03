<script lang="ts">
	import { badgeStyles } from '$lib/components/ui/badgeStyles';
	type Variant = 'neutral' | 'success' | 'warning' | 'danger' | 'info';

	interface Props {
		variant?: Variant;
		children?: import('svelte').Snippet;
	}

	let { variant = 'neutral', children }: Props = $props();
</script>

<span class={badgeStyles({ variant })}>
	{@render children?.()}
</span>
