<script lang="ts">
	import { badgeStyles } from '$lib/components/ui/badgeStyles';
	type Variant = 'neutral' | 'success' | 'warning' | 'danger' | 'info';

	interface Props {
		variant?: Variant;
		class?: string;
		children?: import('svelte').Snippet;
	}

	let { variant = 'neutral', class: className = '', children }: Props = $props();
</script>

<span class={[badgeStyles({ variant }), className].filter(Boolean).join(' ')}>
	{@render children?.()}
</span>
