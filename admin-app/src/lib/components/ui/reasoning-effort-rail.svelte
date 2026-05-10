<script lang="ts">
	import type { ModelReasoningEffort } from '$lib/utils/model-selection';

	type ReasoningRailValue = 'default' | ModelReasoningEffort;

	interface ReasoningRailOption {
		value: ReasoningRailValue;
		label: string;
	}

	interface Props {
		id?: string;
		value?: ReasoningRailValue;
		options: ReasoningRailOption[];
		disabled?: boolean;
		onchange?: (value: ReasoningRailValue) => void;
	}

	let {
		id = 'reasoning-effort',
		value = $bindable('default' as ReasoningRailValue),
		options,
		disabled = false,
		onchange
	}: Props = $props();

	const effortOptions = $derived(options.filter((option) => option.value !== 'default'));
	const activeEffortIndex = $derived(effortOptions.findIndex((option) => option.value === value));
	const progress = $derived.by(() => {
		if (activeEffortIndex < 0 || effortOptions.length <= 1) return 0;
		return Math.round((activeEffortIndex / (effortOptions.length - 1)) * 100);
	});

	function optionPosition(index: number): number {
		if (effortOptions.length <= 1) return 0;
		return (index / (effortOptions.length - 1)) * 100;
	}

	function choose(nextValue: ReasoningRailValue) {
		if (disabled) return;
		value = nextValue;
		onchange?.(nextValue);
	}

	function optionTone(option: ReasoningRailOption): string {
		switch (option.value) {
			case 'none':
				return 'tone-off';
			case 'minimal':
				return 'tone-minimal';
			case 'low':
				return 'tone-low';
			case 'medium':
				return 'tone-medium';
			case 'high':
				return 'tone-high';
			case 'xhigh':
				return 'tone-xhigh';
			default:
				return 'tone-default';
		}
	}
</script>

<div
	class="sf-reasoning-rail"
	class:is-disabled={disabled}
	class:is-default={value === 'default'}
	style={`--sf-reasoning-progress: ${progress}%;`}
	data-testid="reasoning-effort-rail"
>
	<div class="sf-reasoning-rail__header">
		<label id={`${id}-label`} class="sf-reasoning-rail__label" for={`${id}-default`}>
			Reasoning effort
		</label>
		<button
			id={`${id}-default`}
			type="button"
			class="sf-reasoning-rail__default"
			class:is-active={value === 'default'}
			aria-pressed={value === 'default'}
			{disabled}
			onclick={() => choose('default')}
			data-testid="reasoning-effort-default"
		>
			<span class="sf-reasoning-rail__default-icon" aria-hidden="true">
				<svg viewBox="0 0 20 20" focusable="false">
					<path d="M9.5 2.6 11 6.8l4.2 1.5L11 9.8 9.5 14 8 9.8 3.8 8.3 8 6.8z" />
					<path d="M15.2 1.7 15.8 3.4 17.5 4l-1.7.6-.6 1.7-.6-1.7-1.7-.6 1.7-.6z" />
					<path d="M15.1 12.7 16 15l2.3.9-2.3.8-.9 2.4-.8-2.4-2.4-.8 2.4-.9z" />
				</svg>
			</span>
			<span>Model Default</span>
		</button>
	</div>

	<div class="sf-reasoning-rail__scale" role="group" aria-labelledby={`${id}-label`}>
		<div class="sf-reasoning-rail__track" aria-hidden="true" data-testid="reasoning-effort-track">
			<span class="sf-reasoning-rail__track-fill" data-testid="reasoning-effort-track-fill"></span>
		</div>
		<div class="sf-reasoning-rail__options">
			{#each effortOptions as option, index}
				<button
					type="button"
					class={`sf-reasoning-rail__choice ${optionTone(option)}`}
					class:is-active={option.value === value}
					style={`--sf-option-left: ${optionPosition(index)}%;`}
					aria-pressed={option.value === value}
					{disabled}
					onclick={() => choose(option.value)}
					data-testid={`reasoning-effort-${option.value}`}
				>
					<span class="sf-reasoning-rail__dot" aria-hidden="true"></span>
					<span class="sf-reasoning-rail__choice-label">{option.label}</span>
				</button>
			{/each}
		</div>
	</div>
</div>

<style>
	.sf-reasoning-rail {
		--sf-reasoning-accent: rgb(234 88 12);
		--sf-reasoning-muted: rgb(226 232 240);
		--sf-reasoning-text: rgb(15 23 42);
		--sf-reasoning-subtle: rgb(100 116 139);
		--sf-reasoning-endcap: clamp(1.35rem, 2vw, 2rem);
		--sf-reasoning-gradient: linear-gradient(
			90deg,
			rgb(14 165 233) 0%,
			rgb(20 184 166) 20%,
			rgb(132 204 22) 40%,
			rgb(245 158 11) 60%,
			rgb(234 88 12) 80%,
			rgb(225 29 72) 100%
		);
		width: 100%;
		min-width: 0;
		container-type: inline-size;
		padding: 1rem clamp(1.35rem, 2vw, 1.9rem) 1.05rem;
		border: 1px solid rgb(226 232 240);
		border-radius: 0.5rem;
		background: linear-gradient(180deg, rgb(248 250 252), rgb(255 255 255) 58%), rgb(255 255 255);
		box-shadow:
			inset 0 1px 0 rgb(255 255 255),
			0 1px 2px rgb(15 23 42 / 0.04);
	}

	.sf-reasoning-rail.is-disabled {
		opacity: 0.68;
	}

	.sf-reasoning-rail__header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 0.75rem;
		margin-bottom: 0.9rem;
	}

	.sf-reasoning-rail__label {
		font-size: 0.875rem;
		font-weight: 700;
		line-height: 1.2;
		color: var(--sf-reasoning-text);
	}

	.sf-reasoning-rail__default {
		position: relative;
		flex: none;
		display: inline-flex;
		align-items: center;
		justify-content: center;
		gap: 0.42rem;
		min-height: 2rem;
		padding: 0.3rem 0.85rem;
		border: 1px solid rgb(203 213 225);
		border-radius: 999px;
		background: rgb(255 255 255);
		color: rgb(71 85 105);
		font-size: 0.78rem;
		font-weight: 700;
		line-height: 1.1;
		box-shadow: 0 1px 1px rgb(15 23 42 / 0.04);
		transition:
			background-color 150ms ease,
			border-color 150ms ease,
			color 150ms ease,
			box-shadow 150ms ease,
			transform 150ms ease;
	}

	.sf-reasoning-rail__default:hover:not(:disabled) {
		border-color: rgb(148 163 184);
		color: rgb(15 23 42);
		transform: translateY(-1px);
	}

	.sf-reasoning-rail__default.is-active {
		border-color: rgb(168 85 247 / 0.55);
		background:
			linear-gradient(90deg, rgb(124 58 237), rgb(147 51 234), rgb(14 165 233), rgb(124 58 237))
				0 50% / 220% 100%;
		color: rgb(255 255 255);
		box-shadow:
			0 0 0 1px rgb(255 255 255 / 0.6) inset,
			0 10px 24px rgb(124 58 237 / 0.24),
			0 0 26px rgb(14 165 233 / 0.22);
		text-shadow: 0 1px 1px rgb(15 23 42 / 0.28);
	}

	.sf-reasoning-rail__default.is-active:hover:not(:disabled) {
		border-color: rgb(14 165 233 / 0.75);
		color: rgb(255 255 255);
		box-shadow:
			0 0 0 1px rgb(255 255 255 / 0.7) inset,
			0 12px 28px rgb(124 58 237 / 0.28),
			0 0 30px rgb(14 165 233 / 0.26);
	}

	.sf-reasoning-rail__default:focus-visible {
		outline: 2px solid rgb(59 130 246);
		outline-offset: 2px;
	}

	.sf-reasoning-rail__default-icon {
		display: inline-flex;
		width: 1rem;
		height: 1rem;
		color: currentColor;
		filter: drop-shadow(0 1px 2px rgb(15 23 42 / 0.2));
	}

	.sf-reasoning-rail__default-icon svg {
		display: block;
		width: 100%;
		height: 100%;
		fill: currentColor;
	}

	.sf-reasoning-rail__scale {
		position: relative;
		min-height: 4.25rem;
		padding-inline: var(--sf-reasoning-endcap);
	}

	.sf-reasoning-rail__track {
		position: absolute;
		top: 0.5rem;
		right: var(--sf-reasoning-endcap);
		left: var(--sf-reasoning-endcap);
		height: 0.36rem;
		overflow: hidden;
		border-radius: 999px;
		background: var(--sf-reasoning-muted);
	}

	.sf-reasoning-rail__track-fill {
		position: absolute;
		inset: 0;
		display: block;
		border-radius: inherit;
		background: var(--sf-reasoning-gradient);
		clip-path: inset(0 calc(100% - var(--sf-reasoning-progress)) 0 0 round 999px);
		opacity: 1;
		transition: clip-path 180ms ease;
	}

	.sf-reasoning-rail.is-default .sf-reasoning-rail__track-fill {
		clip-path: inset(0 100% 0 0 round 999px);
	}

	.sf-reasoning-rail__options {
		position: absolute;
		z-index: 1;
		top: 0;
		right: var(--sf-reasoning-endcap);
		bottom: 0;
		left: var(--sf-reasoning-endcap);
		overflow: visible;
	}

	.sf-reasoning-rail__choice {
		position: absolute;
		z-index: 1;
		top: 0;
		left: var(--sf-option-left);
		width: clamp(4.25rem, 8vw, 6.5rem);
		min-width: 0;
		padding: 0;
		border: 0;
		background: transparent;
		color: var(--sf-reasoning-subtle);
		font: inherit;
		text-align: center;
		transform: translateX(-50%);
	}

	.sf-reasoning-rail__dot {
		display: block;
		width: 1rem;
		height: 1rem;
		margin: 0 auto 0.55rem;
		border: 0.18rem solid rgb(226 232 240);
		border-radius: 999px;
		background: rgb(203 213 225);
		box-shadow: 0 0 0 0.16rem rgb(248 250 252);
		transition:
			background-color 150ms ease,
			border-color 150ms ease,
			box-shadow 150ms ease,
			transform 150ms ease;
	}

	.sf-reasoning-rail__choice:hover:not(:disabled) .sf-reasoning-rail__dot {
		transform: scale(1.08);
	}

	.sf-reasoning-rail__choice:focus-visible {
		outline: none;
	}

	.sf-reasoning-rail__choice:focus-visible .sf-reasoning-rail__dot {
		box-shadow:
			0 0 0 0.18rem rgb(255 255 255),
			0 0 0 0.35rem rgb(37 99 235),
			0 0 0 0.52rem rgb(191 219 254);
	}

	.sf-reasoning-rail__choice-label {
		display: block;
		overflow-wrap: normal;
		word-break: normal;
		font-size: 0.76rem;
		font-weight: 700;
		line-height: 1.2;
		white-space: normal;
	}

	.sf-reasoning-rail__choice.is-active {
		color: var(--sf-reasoning-active-text, var(--sf-reasoning-accent));
	}

	.sf-reasoning-rail__choice.is-active .sf-reasoning-rail__dot {
		border-color: var(--sf-reasoning-accent);
		background: rgb(255 255 255);
		box-shadow:
			0 0 0 0.18rem rgb(255 255 255),
			0 0 0 0.33rem var(--sf-reasoning-accent);
	}

	.sf-reasoning-rail__choice.tone-off.is-active {
		--sf-reasoning-accent: rgb(14 165 233);
		--sf-reasoning-active-text: rgb(2 132 199);
	}

	.sf-reasoning-rail__choice.tone-minimal.is-active {
		--sf-reasoning-accent: rgb(20 184 166);
		--sf-reasoning-active-text: rgb(15 118 110);
	}

	.sf-reasoning-rail__choice.tone-low.is-active {
		--sf-reasoning-accent: rgb(132 204 22);
		--sf-reasoning-active-text: rgb(77 124 15);
	}

	.sf-reasoning-rail__choice.tone-medium.is-active {
		--sf-reasoning-accent: rgb(245 158 11);
		--sf-reasoning-active-text: rgb(194 65 12);
	}

	.sf-reasoning-rail__choice.tone-high.is-active {
		--sf-reasoning-accent: rgb(234 88 12);
		--sf-reasoning-active-text: rgb(194 65 12);
	}

	.sf-reasoning-rail__choice.tone-xhigh.is-active {
		--sf-reasoning-accent: rgb(225 29 72);
		--sf-reasoning-active-text: rgb(190 18 60);
	}

	@media (prefers-reduced-motion: no-preference) {
		.sf-reasoning-rail__default.is-active {
			animation: sf-reasoning-default-shimmer 9s ease-in-out infinite;
		}
	}

	@keyframes sf-reasoning-default-shimmer {
		0%,
		100% {
			background-position: 0% 50%;
		}

		50% {
			background-position: 100% 50%;
		}
	}

	@media (max-width: 640px) {
		.sf-reasoning-rail {
			padding: 0.9rem 1.2rem;
			--sf-reasoning-endcap: 1.25rem;
		}

		.sf-reasoning-rail__header {
			align-items: flex-start;
			flex-direction: column;
		}

		.sf-reasoning-rail__default {
			width: 100%;
		}

		.sf-reasoning-rail__choice-label {
			font-size: 0.68rem;
		}
	}

	@container (max-width: 32rem) {
		.sf-reasoning-rail {
			--sf-reasoning-endcap: 0.9rem;
			padding: 0.9rem 0.85rem 1rem;
		}

		.sf-reasoning-rail__header {
			align-items: flex-start;
			gap: 0.55rem;
		}

		.sf-reasoning-rail__default {
			padding-inline: 0.7rem;
			font-size: 0.72rem;
		}

		.sf-reasoning-rail__scale {
			min-height: 5.2rem;
		}

		.sf-reasoning-rail__choice {
			width: 3.35rem;
		}

		.sf-reasoning-rail__choice-label {
			font-size: 0.67rem;
			line-height: 1.05;
		}
	}

	@container (max-width: 24rem) {
		.sf-reasoning-rail {
			--sf-reasoning-endcap: 0.7rem;
			padding-inline: 0.65rem;
		}

		.sf-reasoning-rail__header {
			flex-direction: column;
		}

		.sf-reasoning-rail__default {
			align-self: flex-end;
		}

		.sf-reasoning-rail__choice {
			width: 2.75rem;
		}

		.sf-reasoning-rail__choice-label {
			font-size: 0.61rem;
		}
	}
</style>
