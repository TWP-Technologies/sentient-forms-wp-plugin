<script lang="ts">
	import type {
		CreditSeverity,
		QuotaCtaAction,
		QuotaCtaState
	} from '$lib/utils/license-health-presentation';
	import Alert from './alert.svelte';
	import Button from './button.svelte';

	type CalloutSeverity = Exclude<CreditSeverity, 'normal'>;

	interface Props {
		severity: CalloutSeverity;
		title: string;
		message: string;
		cta: QuotaCtaState;
		onAction?: ((action: QuotaCtaAction) => void) | null;
		testId?: string;
		ctaTestId?: string;
		reasonTestId?: string;
		class?: string;
		[key: string]: unknown;
	}

	let {
		severity,
		title,
		message,
		cta,
		onAction = null,
		testId,
		ctaTestId,
		reasonTestId,
		class: className = '',
		...rest
	}: Props = $props();

	const variant = $derived(
		severity === 'critical' ? 'danger' : severity === 'warning' ? 'warning' : 'info'
	);

	function handleAction() {
		if (!cta.enabled || cta.action === 'none') return;
		onAction?.(cta.action);
	}
</script>

<Alert variant={variant} class={`sf:mt-3 ${className}`.trim()} data-testid={testId} {...rest}>
	<div class="sf:flex sf:flex-col sf:gap-3 sf:sm:flex-row sf:sm:items-start sf:sm:justify-between">
		<div class="sf:space-y-1">
			<p class="sf:font-semibold">{title}</p>
			<p>{message}</p>
			<p class="sf:text-xs sf:opacity-90" data-testid={reasonTestId}>{cta.reason}</p>
		</div>
		<Button
			size="sm"
			variant={cta.enabled ? 'primary' : 'secondary'}
			disabled={!cta.enabled}
			onclick={handleAction}
			data-testid={ctaTestId}
		>
			{cta.label}
		</Button>
	</div>
</Alert>
