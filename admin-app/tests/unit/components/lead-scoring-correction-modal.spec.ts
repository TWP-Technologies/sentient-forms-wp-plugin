import { afterEach, describe, expect, it } from 'vitest';
import { createClassComponent } from 'svelte/legacy';
import CorrectionModal from '$lib/components/lead-scoring/correction-modal.svelte';
import type { LeadScoringEntry } from '$lib/api/types';

afterEach(() => {
	document.body.innerHTML = '';
});

const entry: LeadScoringEntry = {
	form_source: 'gravity_forms',
	form_id: '123',
	form_title: 'Lead intake',
	entry_id: '1001',
	entry_snapshot: {
		date_created: '2030-01-05T10:00:00Z',
		status: 'active',
		field_summary: [{ field_id: '1', label: 'Name', value: 'Ada Lovelace' }]
	},
	grade: 'A',
	confidence: 0.92,
	priority: 'urgent',
	justification: 'The entry names a concrete paid implementation project.',
	next_best_action: 'Route to sales for same-day follow-up.',
	suggested_reply_draft: null,
	reply_rationale: null,
	profile_version: 3,
	lead_execution_id: 'lead:1001',
	reply_execution_id: null
};

function mount() {
	const target = document.createElement('div');
	document.body.appendChild(target);

	const component = createClassComponent({
		target,
		component: CorrectionModal,
		props: {
			entry,
			grade: 'A',
			justification: entry.justification,
			onClose: () => {},
			onSave: () => {},
			onGradeChange: () => {},
			onJustificationChange: () => {}
		}
	});

	return {
		target,
		dispose: () => {
			component.$destroy();
			target.remove();
		}
	};
}

describe('Lead Scoring correction modal', () => {
	it('keeps correction save and cancel controls in a persistent footer', () => {
		const { target, dispose } = mount();

		const footer = target.querySelector('[data-testid="lead-scoring-correction-footer"]');
		expect(footer).toBeTruthy();
		expect(footer?.textContent).toContain('Cancel');
		expect(footer?.textContent).toContain('Save Correction');

		dispose();
	});
});
