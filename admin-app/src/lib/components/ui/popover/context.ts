import { getContext, setContext } from 'svelte';

const POPOVER_CONTEXT = Symbol('sentient-forms-popover');

export interface PopoverContext {
	readonly open: boolean;
	setOpen(open: boolean): void;
	toggle(): void;
}

export function setPopoverContext(context: PopoverContext): void {
	setContext(POPOVER_CONTEXT, context);
}

export function getPopoverContext(): PopoverContext {
	return getContext<PopoverContext>(POPOVER_CONTEXT);
}
