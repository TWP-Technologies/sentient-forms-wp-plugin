<script lang="ts">
	import { badgeStyles } from '$lib/components/ui/badgeStyles';
	type Variant = 'neutral' | 'success' | 'warning' | 'danger' | 'info';

	export let variant: Variant = 'neutral';
</script>

<span class={badgeStyles({ variant })}>
	<slot />
</span>
