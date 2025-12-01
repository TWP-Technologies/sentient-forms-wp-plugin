<script lang="ts">
	type Variant = 'info' | 'success' | 'warning' | 'danger';
	interface Props {
		variant?: Variant;
		children?: import('svelte').Snippet;
		[key: string]: any
	}

	let { variant = 'info', children, ...rest }: Props = $props();

	const variantClasses: Record<Variant, string> = {
		info: 'sf:bg-primary-50 sf:text-primary-700 sf:border-primary-100',
		success: 'sf:bg-success-50 sf-text-success-700 sf-border-success-100',
		warning: 'sf:bg-warning-50 sf-text-warning-700 sf-border-warning-100',
		danger: 'sf:bg-danger-50 sf-text-danger-700 sf-border-danger-100'
	};

	export {
		variant,
	}
</script>

<svelte:options runes={true} />

<div
	class={`sf:rounded-lg sf:border sf:p-4 sf:text-sm ${variantClasses[variant]} ${rest.class || ''}`.trim()}
	role="alert"
	{...rest}
>
	{@render children?.()}
</div>
