<script lang="ts">
	import { Badge, Button } from '$lib/components/ui';
	import InfoIcon from '@lucide/svelte/icons/info';
	import type { SpamDeliveryHolds, SpamFailureDeliveryPolicy } from '$lib/utils/spam-failure-delivery';
	let { policy, holds, disabled = false, onchange }: {
		policy: SpamFailureDeliveryPolicy; holds: SpamDeliveryHolds; disabled?: boolean;
		onchange: (value: SpamFailureDeliveryPolicy) => void;
	} = $props();
	let helpOpen = $state(false);
	const locked = $derived(disabled || holds.state !== 'active');
	function dismissHelp(event: KeyboardEvent) {
		if (event.key === 'Escape' && helpOpen) {
			event.preventDefault(); event.stopPropagation(); helpOpen = false;
		}
	}
</script>

<div class="sf:border-t sf:border-slate-200 sf:pt-4" data-testid="spam-failure-delivery-setting">
	<div class="sf:flex sf:items-center sf:gap-2 sf:relative">
		<h3 id="spam-failure-heading" class="sf:text-sm sf:font-semibold sf:text-slate-900">If spam classification fails</h3>
		<div class="sf:relative">
			<Button variant="inline" size="sm" iconOnly class="sf:text-slate-500 sf:rounded-full"
				onkeydown={dismissHelp} aria-label="About classification failures" aria-expanded={helpOpen} aria-controls="spam-failure-tooltip"
				aria-describedby={helpOpen ? 'spam-failure-tooltip' : undefined}
				onfocus={() => { helpOpen = true; }} onblur={() => { helpOpen = false; }}
				onclick={() => { helpOpen = true; }}>
				<InfoIcon class="sf:h-4 sf:w-4" aria-hidden="true" />
			</Button>
			{#if helpOpen}
				<div id="spam-failure-tooltip" role="tooltip" class="sf:absolute sf:right-0 sf:bottom-full sf:z-50 sf:mb-2 sf:w-56 sf:rounded-md sf:bg-slate-950 sf:p-3 sf:text-xs sf:leading-relaxed sf:text-white sf:shadow-lg">
					A failed request or invalid spam result counts as a failure. A check still running does not.
				</div>
			{/if}
		</div>
	</div>
	<p class="sf:mt-2 sf:mb-3 sf:text-sm sf:text-slate-600" data-testid="spam-failure-applicability">
		{#if holds.state === 'unavailable'}Delivery hold availability is not confirmed.
		{:else if holds.state === 'inactive'}This mapping has no configured delivery holds.
		{:else}Applies to: {holds.channels.join(' and ')}.{/if}
	</p>
	<fieldset disabled={locked} aria-labelledby="spam-failure-heading" class="sf:grid sf:gap-2 sf:min-w-0">
		{#each [
			{ value: 'hold' as const, label: 'Hold configured delivery', risk: 'Keep affected delivery on hold. Legitimate submissions may be delayed.' },
			{ value: 'allow_delivery' as const, label: 'Allow delivery without classification', risk: 'Spam may reach recipients or connected services.' }
		] as option}
			<label class={`sf:flex sf:items-start sf:gap-2 sf:rounded-md sf:border sf:p-3 sf:text-sm ${policy === option.value ? 'sf:border-primary-400 sf:bg-primary-50' : 'sf:border-slate-300 sf:bg-white'} ${locked ? 'sf:opacity-60' : 'sf:cursor-pointer'}`}>
				<input type="radio" name="spam-failure-delivery" value={option.value} checked={policy === option.value}
					class="sf:mt-1 sf:shrink-0 sf:accent-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-2"
					onchange={() => onchange(option.value)} />
				<span class="sf:min-w-0">
					<span class="sf:flex sf:flex-wrap sf:items-center sf:gap-2 sf:font-medium sf:text-slate-900">
						{option.label}{#if option.value === 'hold'}<Badge variant="success">Recommended</Badge>{/if}
					</span>
					<span class="sf:block sf:mt-1 sf:text-slate-600">{option.risk}</span>
				</span>
			</label>
		{/each}
	</fieldset>
	<p class="sf:mt-3 sf:border-t sf:border-slate-200 sf:pt-3 sf:text-xs sf:text-slate-600">
		Saving affects future spam checks. It does not release submissions already on hold.
	</p>
</div>
