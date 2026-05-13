<script lang="ts">
	import BanIcon from '@lucide/svelte/icons/ban';
	import BriefcaseIcon from '@lucide/svelte/icons/briefcase-business';
	import ClipboardPenIcon from '@lucide/svelte/icons/clipboard-pen';
	import FileTextIcon from '@lucide/svelte/icons/file-text';
	import SparklesIcon from '@lucide/svelte/icons/sparkles';
	import SlidersIcon from '@lucide/svelte/icons/sliders-horizontal';
	import { Badge, Button } from '$lib/components/ui';
	import type { LeadScoringEntry } from '$lib/api/types';
	import { gradeBadgeVariant } from '$lib/components/lead-scoring/utils';

	type Props = {
		entry: LeadScoringEntry | null;
		onClose: () => void;
		onOpenCorrection: (entry: LeadScoringEntry) => void;
		onGenerateReply?: (entry: LeadScoringEntry) => void;
		generatingReply?: boolean;
	};

	let {
		entry,
		onClose,
		onOpenCorrection,
		onGenerateReply,
		generatingReply = false
	}: Props = $props();

	function closeFromBackdrop(event: MouseEvent) {
		if (event.target === event.currentTarget) onClose();
	}

	function gradeAccentClass(value?: string | null): string {
		return value === 'Reject'
			? 'sf:border-rose-100 sf:bg-rose-50/40'
			: 'sf:border-emerald-100 sf:bg-emerald-50/50';
	}

	function gradeIconClass(value?: string | null): string {
		return value === 'Reject'
			? 'sf:bg-rose-500 sf:text-white sf:shadow-rose-200'
			: 'sf:bg-emerald-500 sf:text-white sf:shadow-emerald-200';
	}
</script>

{#if entry}
	<div
		class="sf:fixed sf:inset-0 sf:z-[999999] sf:flex sf:justify-end sf:bg-slate-950/35 sf:backdrop-blur-[1px]"
		role="presentation"
		onclick={closeFromBackdrop}
	>
		<div
			data-lead-scoring-detail-sheet
			class="sf:h-full sf:w-full sf:max-w-3xl sf:overflow-y-auto sf:bg-slate-50 sf:p-6 sf:shadow-2xl sf:sm:p-8"
			role="dialog"
			aria-modal="true"
			aria-labelledby="lead-scoring-entry-detail-title"
		>
			<div class="sf:flex sf:items-start sf:justify-between sf:gap-4 sf:border-b sf:border-slate-200 sf:pb-7">
				<div>
					<p class="sf:text-xs sf:font-bold sf:uppercase sf:tracking-[0.16em] sf:text-slate-400">
						Lead Scoring Detail
					</p>
					<h2
						id="lead-scoring-entry-detail-title"
						class="sf:mt-2 sf:text-3xl sf:font-bold sf:tracking-tight sf:text-slate-950"
					>
						Entry #{entry.entry_id}
					</h2>
				</div>
				<Button variant="secondary" onclick={onClose}>Close</Button>
			</div>

			<div class="sf:mt-9 sf:rounded-3xl sf:bg-white sf:p-6 sf:shadow-[0_20px_45px_rgba(15,23,42,0.08)]">
				<div class="sf:grid sf:gap-6 sf:lg:grid-cols-[minmax(0,1fr)_13rem]">
					<div class={`sf:rounded-2xl sf:border sf:p-7 ${gradeAccentClass(entry.grade)}`}>
						<p class="sf:text-xs sf:font-bold sf:uppercase sf:tracking-[0.16em] sf:text-rose-500">
							Primary outcome
						</p>
						<div class="sf:mt-5 sf:flex sf:items-center sf:gap-5">
							<span
								class={`sf:flex sf:h-14 sf:w-14 sf:items-center sf:justify-center sf:rounded-full sf:shadow-xl ${gradeIconClass(entry.grade)}`}
							>
								{#if entry.grade === 'Reject'}
									<BanIcon class="sf:h-8 sf:w-8" aria-hidden="true" />
								{:else}
									<BriefcaseIcon class="sf:h-8 sf:w-8" aria-hidden="true" />
								{/if}
							</span>
							<div>
								<p class="sf:text-4xl sf:font-bold sf:leading-none sf:text-slate-950">
									{entry.grade || 'Ungraded'}
								</p>
								<p class="sf:mt-2 sf:text-sm sf:text-slate-500">
									Grade assigned by system v{entry.profile_version ?? '-'}
								</p>
							</div>
						</div>
						{#if entry.correction}
							<Badge variant="info" class="sf:mt-5">Human corrected</Badge>
						{/if}
					</div>

					<div class="sf:grid sf:gap-4">
						<div class="sf:flex sf:items-center sf:gap-4 sf:rounded-2xl sf:bg-slate-50 sf:p-5">
							<span class="sf:flex sf:h-11 sf:w-11 sf:items-center sf:justify-center sf:rounded-full sf:border sf:border-slate-200 sf:bg-white sf:text-slate-400">
								<SlidersIcon class="sf:h-5 sf:w-5" aria-hidden="true" />
							</span>
							<div>
								<p class="sf:text-xs sf:font-bold sf:uppercase sf:tracking-[0.14em] sf:text-slate-400">
									Priority
								</p>
								<p class="sf:mt-1 sf:text-xl sf:font-semibold sf:text-slate-950">
									{entry.priority || 'Normal'}
								</p>
							</div>
						</div>
						<Button variant="secondary" onclick={() => onOpenCorrection(entry)}>
							<ClipboardPenIcon class="sf:h-4 sf:w-4" aria-hidden="true" />
							Correct grade
						</Button>
					</div>
				</div>
			</div>

			<section class="sf:mt-9">
				<h3 class="sf:text-2xl sf:font-semibold sf:text-slate-950">Justification</h3>
				<p class="sf:mt-5 sf:whitespace-pre-wrap sf:rounded-2xl sf:border sf:border-primary-100 sf:bg-primary-50/40 sf:p-6 sf:text-base sf:leading-8 sf:text-slate-800">
					{entry.justification || entry.fit_summary || 'No justification stored.'}
				</p>
			</section>

			<section class="sf:mt-9">
				<h3 class="sf:text-2xl sf:font-semibold sf:text-slate-950">
					Suggested reply and next best action
				</h3>
				<div class="sf:mt-5 sf:overflow-hidden sf:rounded-3xl sf:bg-white sf:p-6 sf:shadow-[0_18px_40px_rgba(15,23,42,0.06)]">
					<div class="sf:flex sf:flex-wrap sf:items-center sf:justify-between sf:gap-4">
						<Badge variant={entry.do_not_send ? 'warning' : 'info'}>
							{entry.do_not_send ? 'Review only' : 'Draft state'}
						</Badge>
						{#if onGenerateReply}
							<Button
								variant="secondary"
								onclick={() => onGenerateReply?.(entry)}
								disabled={generatingReply}
							>
								<SparklesIcon class="sf:h-4 sf:w-4" aria-hidden="true" />
								{generatingReply ? 'Generating...' : 'Generate reply'}
							</Button>
						{/if}
					</div>
					<p class="sf:mt-7 sf:text-base sf:leading-7 sf:text-slate-800">
						<strong>Next best action:</strong>
						{entry.next_best_action || 'No recommendation stored.'}
					</p>
					<div class="sf:mt-7 sf:rounded-3xl sf:bg-slate-50 sf:p-6">
						<p class="sf:whitespace-pre-wrap sf:text-base sf:italic sf:leading-8 sf:text-slate-800">
							{entry.suggested_reply_draft || 'No suggested reply draft stored.'}
						</p>
						{#if entry.reply_rationale}
							<div class="sf:my-5 sf:h-1 sf:w-14 sf:rounded-full sf:bg-slate-200"></div>
							<p class="sf:whitespace-pre-wrap sf:text-sm sf:leading-7 sf:text-slate-500">
								{entry.reply_rationale}
							</p>
						{/if}
					</div>
				</div>
			</section>

			<section class="sf:mt-9">
				<h3 class="sf:text-2xl sf:font-semibold sf:text-slate-950">Entry preview</h3>
				<div class="sf:mt-5 sf:grid sf:gap-3">
					{#each entry.entry_snapshot?.field_summary ?? [] as field (`${field.field_id}-${field.label}`)}
						<div class="sf:rounded-2xl sf:border sf:border-slate-200 sf:bg-white sf:p-4">
							<p class="sf:text-xs sf:font-bold sf:uppercase sf:tracking-[0.08em] sf:text-slate-400">
								{field.label}
							</p>
							<p class="sf:mt-2 sf:whitespace-pre-wrap sf:text-base sf:leading-7 sf:text-slate-800">
								{field.value}
							</p>
						</div>
					{:else}
						<p class="sf:text-sm sf:italic sf:text-slate-500">
							No entry preview fields are available for this stored result.
						</p>
					{/each}
				</div>
			</section>

			<section class="sf:mt-9 sf:rounded-2xl sf:border sf:border-slate-200 sf:bg-white sf:p-5">
				<div class="sf:flex sf:items-start sf:gap-3">
					<FileTextIcon class="sf:mt-0.5 sf:h-4 sf:w-4 sf:text-slate-400" aria-hidden="true" />
					<p class="sf:text-xs sf:leading-6 sf:text-slate-500">
						Lead execution: {entry.lead_execution_id || 'not stored'} · Reply execution:
						{entry.reply_execution_id || 'not stored'} · Historical run:
						{entry.historical_run_id ?? 'none'} · Setup v{entry.profile_version ?? '-'}
					</p>
				</div>
			</section>
		</div>
	</div>
{/if}

<style>
	:global([data-lead-scoring-detail-sheet]) {
		animation: lead-scoring-sheet-enter 180ms ease-out;
	}

	@keyframes lead-scoring-sheet-enter {
		from {
			opacity: 0;
			transform: translateX(40px);
		}
		to {
			opacity: 1;
			transform: translateX(0);
		}
	}

	@media (prefers-reduced-motion: reduce) {
		:global([data-lead-scoring-detail-sheet]) {
			animation: none;
		}
	}
</style>
