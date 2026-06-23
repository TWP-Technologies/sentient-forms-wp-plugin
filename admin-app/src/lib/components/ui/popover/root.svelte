<script lang="ts">
	import type { Snippet } from 'svelte';
	import { setPopoverContext } from './context';

	interface Props {
		open?: boolean;
		class?: string;
		children?: Snippet;
	}

	let { open = $bindable(), class: className = '', children }: Props = $props();

	setPopoverContext({
		get open() {
			return open === true;
		},
		setOpen(nextOpen: boolean) {
			open = nextOpen;
		},
		toggle() {
			open = open !== true;
		}
	});
</script>

<span class={['sf:relative sf:inline-flex', className].filter(Boolean).join(' ')}>
	{@render children?.()}
</span>
