<script lang="ts">
	import type { Snippet } from 'svelte';
	import Button from './button.svelte';
	import type { ButtonVariant } from './buttonStyles';

	type StateTemplateVariant = 'loading' | 'empty' | 'error';

	interface Props {
		variant: StateTemplateVariant;
		title: string;
		message?: string | null;
		actionLabel?: string | null;
		onAction?: (() => void) | null;
		actionVariant?: ButtonVariant;
		inline?: boolean;
		dense?: boolean;
		testId?: string;
		children?: Snippet;
		[key: string]: unknown;
	}

	let {
		variant,
		title,
		message = null,
		actionLabel = null,
		onAction = null,
		actionVariant = variant === 'error' ? 'danger' : 'secondary',
		inline = false,
		dense = false,
		testId,
		children,
		...rest
	}: Props = $props();

	const wrapperClasses: Record<StateTemplateVariant, string> = {
		loading: 'sf:border-slate-200 sf:bg-slate-50 sf:text-slate-700',
		empty: 'sf:border-slate-200 sf:bg-white sf:text-slate-700',
		error: 'sf:border-danger-200 sf:bg-danger-50 sf:text-danger-700'
	};

	const headingClasses: Record<StateTemplateVariant, string> = {
		loading: 'sf:text-slate-900',
		empty: 'sf:text-slate-900',
		error: 'sf:text-danger-900'
	};

	const descriptionClasses: Record<StateTemplateVariant, string> = {
		loading: 'sf:text-slate-600',
		empty: 'sf:text-slate-600',
		error: 'sf:text-danger-700'
	};

	let containerClass = $derived(
		[
			'sf:rounded-lg sf:border sf:w-full',
			wrapperClasses[variant],
			inline ? 'sf:shadow-none' : 'sf:shadow-sm',
			dense ? 'sf:p-3' : 'sf:p-4'
		]
			.filter(Boolean)
			.join(' ')
	);

	let hasAction = $derived(Boolean(actionLabel) && typeof onAction === 'function');
</script>

<div
	class={containerClass}
	role={variant === 'error' ? 'alert' : 'status'}
	aria-live={variant === 'error' ? 'assertive' : 'polite'}
	aria-busy={variant === 'loading' ? true : undefined}
	data-testid={testId}
	{...rest}
>
	<div class="sf:flex sf:flex-col sf:gap-3 sf:sm:flex-row sf:sm:items-center sf:sm:justify-between">
		<div class="sf:space-y-1">
			<p class={`sf:text-sm sf:font-semibold ${headingClasses[variant]}`}>{title}</p>
			{#if message}
				<p class={`sf:text-sm ${descriptionClasses[variant]}`}>{message}</p>
			{/if}
			{@render children?.()}
		</div>
		{#if hasAction}
			<Button size="sm" variant={actionVariant} onclick={() => onAction?.()}>{actionLabel}</Button>
		{/if}
	</div>
</div>
