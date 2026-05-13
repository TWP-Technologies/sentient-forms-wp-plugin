<script lang="ts">
	import { buttonStyles, type ButtonSize, type ButtonVariant } from '$lib/components/ui/buttonStyles';
	import type { HTMLAnchorAttributes } from 'svelte/elements';

	type Props = {
		variant?: ButtonVariant;
		size?: ButtonSize;
		iconOnly?: boolean;
		disabled?: boolean;
		onClick?: (event: MouseEvent & { currentTarget: EventTarget & HTMLAnchorElement }) => void;
	} & HTMLAnchorAttributes & { children?: () => unknown };

	let {
		variant = 'primary',
		size = 'md',
		iconOnly = false,
		disabled = false,
		href = '#',
		class: className = '',
		onclick: userOnClick,
		onClick: userOnClickCallback,
		children,
		...rest
	}: Props = $props();

	type AnchorClickEvent = MouseEvent & { currentTarget: EventTarget & HTMLAnchorElement };

	function handleClick(event: AnchorClickEvent) {
		if (disabled) {
			event.preventDefault();
			event.stopPropagation();
			return;
		}

		userOnClick?.(event);
		userOnClickCallback?.(event);
	}

	const iconOnlyClass = $derived(
		iconOnly ? (size === 'xs' ? 'sf:w-5 sf:px-0 sf:justify-center' : 'sf:w-10 sf:px-0 sf:justify-center') : ''
	);
</script>

<a
	{href}
	class={[
		buttonStyles({ variant, size }),
		iconOnlyClass,
		disabled ? 'sf:pointer-events-none sf:opacity-50' : '',
		className
	]
		.filter(Boolean)
		.join(' ')}
	aria-disabled={disabled}
	onclick={handleClick}
	{...rest}
>
	{@render children?.()}
</a>
