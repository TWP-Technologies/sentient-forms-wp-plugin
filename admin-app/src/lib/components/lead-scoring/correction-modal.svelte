<script lang="ts">
	import { Button, SelectField, TextareaField } from '$lib/components/ui';
	import type { LeadGrade, LeadScoringEntry } from '$lib/api/types';

	type Props = {
		entry: LeadScoringEntry | null;
		grade: LeadGrade;
		justification: string;
		correcting?: boolean;
		onClose: () => void;
		onSave: () => void;
		onGradeChange: (grade: LeadGrade) => void;
		onJustificationChange: (value: string) => void;
	};

	let {
		entry,
		grade,
		justification,
		correcting = false,
		onClose,
		onSave,
		onGradeChange,
		onJustificationChange
	}: Props = $props();

	const gradeChoices: LeadGrade[] = ['A', 'B', 'C', 'Reject'];

	function closeFromBackdrop(event: MouseEvent) {
		if (event.target === event.currentTarget) onClose();
	}
</script>

{#if entry}
	<div
		class="sf:fixed sf:inset-0 sf:z-[1000000] sf:flex sf:items-center sf:justify-center sf:bg-slate-950/40 sf:p-4"
		role="presentation"
		onclick={closeFromBackdrop}
	>
		<div
			data-lead-scoring-correction-modal
			class="sf:w-full sf:max-w-xl sf:overflow-hidden sf:rounded-2xl sf:border sf:border-slate-200 sf:bg-white sf:shadow-2xl"
			role="dialog"
			aria-modal="true"
			aria-labelledby="lead-scoring-correction-title"
		>
			<div class="sf:border-b sf:border-slate-100 sf:px-6 sf:py-5">
				<div class="sf:flex sf:items-start sf:justify-between sf:gap-4">
					<div>
						<h2 id="lead-scoring-correction-title" class="sf:text-2xl sf:font-semibold sf:text-slate-950">
							Correct this grade
						</h2>
						<p class="sf:mt-2 sf:text-lg sf:text-slate-500">
							Corrections calibrate future scoring.
						</p>
					</div>
					<Button variant="secondary" size="sm" onclick={onClose}>Close</Button>
				</div>
			</div>
			<div class="sf:space-y-6 sf:p-6">
				<SelectField
					id={`lead-grade-correction-${entry.entry_id}`}
					label="Corrected Grade"
					value={grade}
					options={gradeChoices.map((choice) => ({ value: choice, label: choice }))}
					onchange={(event) => onGradeChange(event.currentTarget.value as LeadGrade)}
				/>
				<TextareaField
					id={`lead-grade-correction-justification-${entry.entry_id}`}
					label="Correction Justification"
					rows={5}
					value={justification}
					placeholder="Explain why this entry should receive the corrected grade..."
					oninput={(event) => onJustificationChange(event.currentTarget.value)}
				/>
				<Button
					variant="dark"
					size="lg"
					class="sf:min-h-14 sf:w-full sf:rounded-xl sf:text-xl"
					onclick={onSave}
					disabled={correcting}
				>
					{correcting ? 'Saving...' : 'Save Correction'}
				</Button>
			</div>
		</div>
	</div>
{/if}

<style>
	:global([data-lead-scoring-correction-modal]) {
		animation: lead-scoring-modal-enter 160ms ease-out;
	}

	@keyframes lead-scoring-modal-enter {
		from {
			opacity: 0;
			transform: translateY(16px) scale(0.98);
		}
		to {
			opacity: 1;
			transform: translateY(0) scale(1);
		}
	}

	@media (prefers-reduced-motion: reduce) {
		:global([data-lead-scoring-correction-modal]) {
			animation: none;
		}
	}
</style>
