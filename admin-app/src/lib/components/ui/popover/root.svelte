<script lang="ts">
	import type { Snippet } from 'svelte';
	import { setPopoverContext } from './context';

	interface Props {
		open?: boolean;
		class?: string;
		children?: Snippet;
	}

	let { open = $bindable(), class: className = '', children }: Props = $props();
	let rootElement = $state<HTMLSpanElement | null>(null);

	function close(): void {
		if (open === true) open = false;
	}

	function handleDocumentClick(event: MouseEvent): void {
		if (open !== true || rootElement === null || !(event.target instanceof Node)) return;
		if (!rootElement.contains(event.target)) close();
	}

	function handleDocumentKeydown(event: KeyboardEvent): void {
		if (open === true && event.key === 'Escape') close();
	}

	function handleFocusOut(event: FocusEvent): void {
		if (open !== true || rootElement === null) return;
		if (event.relatedTarget instanceof Node && rootElement.contains(event.relatedTarget)) return;
		close();
	}

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

<svelte:document onclick={handleDocumentClick} onkeydown={handleDocumentKeydown} />

<span
	bind:this={rootElement}
	class={['sf:relative sf:inline-flex', className].filter(Boolean).join(' ')}
	onfocusout={handleFocusOut}
>
	{@render children?.()}
</span>
