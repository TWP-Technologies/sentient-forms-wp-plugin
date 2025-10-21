<script lang="ts">
	type Variant = 'info' | 'success' | 'warning' | 'danger';
	export let variant: Variant = 'info';

	const variantClasses: Record<Variant, string> = {
		info: 'sf-bg-primary-50 sf-text-primary-700 sf-border-primary-100',
		success: 'sf-bg-success-50 sf-text-success-700 sf-border-success-100',
		warning: 'sf-bg-warning-50 sf-text-warning-700 sf-border-warning-100',
		danger: 'sf-bg-danger-50 sf-text-danger-700 sf-border-danger-100'
	};
</script>

<svelte:options accessors={true} />

<div
	class={`sf-rounded-lg sf-border sf-p-4 sf-text-sm ${variantClasses[variant]} ${$$restProps.class || ''}`.trim()}
	role="alert"
	{...$$restProps}
>
	<slot />
</div>
