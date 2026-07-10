import { afterEach, describe, expect, it } from 'vitest';
import { createClassComponent } from 'svelte/legacy';
import { tick } from 'svelte';
import SpamCriteriaEditor from '$lib/components/spam-criteria-editor.svelte';

afterEach(() => {
	document.body.innerHTML = '';
});

function mount(initialProps: Record<string, unknown>) {
	const target = document.createElement('div');
	document.body.appendChild(target);

	const component = createClassComponent({
		target,
		component: SpamCriteriaEditor,
		props: { ...initialProps }
	});

	return {
		target,
		dispose: () => {
			component.$destroy();
			target.remove();
		}
	};
}

async function clickButton(target: HTMLElement, label: string) {
	const button = Array.from(target.querySelectorAll('button')).find((candidate) =>
		candidate.textContent?.includes(label)
	);
	expect(button).toBeTruthy();
	button?.dispatchEvent(new MouseEvent('click', { bubbles: true }));
	await tick();
	await Promise.resolve();
	await tick();
}

describe('SpamCriteriaEditor', () => {
	it('starts collapsed by default', () => {
		const { target, dispose } = mount({
			positiveExamples: [],
			negativeExamples: []
		});

		expect(target.textContent).not.toContain('Legitimate examples');
		expect(target.textContent).not.toContain('Spam examples');
		dispose();
	});

	it('respects initiallyExpanded when provided', () => {
		const { target, dispose } = mount({
			positiveExamples: [],
			negativeExamples: [],
			initiallyExpanded: true
		});

		expect(target.textContent).toContain('Legitimate examples');
		expect(target.textContent).toContain('Spam examples');
		dispose();
	});

	it('opens historical submissions and saves after label selection without a manual rationale', async () => {
		let savedPayload: Record<string, unknown> | null = null;
		const { target, dispose } = mount({
			positiveExamples: [],
			negativeExamples: [],
			initiallyExpanded: true,
			formSourceSlug: 'gravity_forms',
			formId: 7,
			targetScope: 'form',
			searchHistoricalEntries: async () => ({
				form_source: 'gravity_forms',
				form_id: '7',
				availability: {
					source: 'native',
					native_read: true,
					ledger_read: false,
					unavailable_reason: null
				},
				entries: [
					{
						id: '99',
						source_type: 'native',
						status: 'spam',
						date_created: '2030-01-05T10:00:00Z',
						native_entry_id: '99',
						native_entry_url: 'https://example.test/wp-admin/admin.php?page=gf_entries&id=7&lid=99',
						field_summary: [
							{ field_id: '1', label: 'Email', value: 'spam@example.test' },
							{ field_id: '2', label: 'Message', value: 'Buy crypto traffic now.' }
						]
					}
				]
			}),
			saveHistoricalExample: async (payload: Record<string, unknown>) => {
				savedPayload = payload;
				return {
					target_scope: 'form',
					label: 'spam',
					config: {
						spam_positive_examples: [],
						spam_negative_examples: [
							{
								text: 'Message: Buy crypto traffic now.',
								rationale: 'Known spam offer.'
							}
						]
					}
				};
			}
		});

		await clickButton(target, 'Use past submissions');
		expect(target.textContent).toContain('Buy crypto traffic now.');

		await clickButton(target, 'Review');
		expect(target.textContent).toContain('Review example');
		const saveButton = Array.from(target.querySelectorAll('button')).find((candidate) =>
			candidate.textContent?.includes('Generate rationale and save')
		);
		expect(saveButton).toBeTruthy();
		expect(saveButton?.hasAttribute('disabled')).toBe(false);
		expect(target.querySelector<HTMLTextAreaElement>('#historical-example-rationale')).toBeNull();
		expect(target.textContent).toContain('The rationale is generated before saving.');

		await clickButton(target, 'Spam');
		expect(saveButton?.hasAttribute('disabled')).toBe(false);

		await clickButton(target, 'Generate rationale and save');
		expect(savedPayload).toMatchObject({
			label: 'spam',
			target_scope: 'form',
			entry_id: '99'
		});
		expect(savedPayload).not.toHaveProperty('rationale');
		expect(savedPayload).not.toHaveProperty('text');
		dispose();
	});

	it('shows REST payload messages when rationale generation cannot save', async () => {
		const { target, dispose } = mount({
			positiveExamples: [],
			negativeExamples: [],
			initiallyExpanded: true,
			formSourceSlug: 'gravity_forms',
			formId: 7,
			targetScope: 'form',
			searchHistoricalEntries: async () => ({
				form_source: 'gravity_forms',
				form_id: '7',
				availability: {
					source: 'native',
					native_read: true,
					ledger_read: false,
					unavailable_reason: null
				},
				entries: [
					{
						id: '99',
						source_type: 'native',
						status: 'spam',
						date_created: '2030-01-05T10:00:00Z',
						native_entry_id: '99',
						native_entry_url: null,
						field_summary: [{ field_id: '2', label: 'Message', value: 'Buy crypto traffic now.' }]
					}
				]
			}),
			saveHistoricalExample: async () => {
				throw {
					message: 'Request failed',
					payload: {
						message:
							'Historical spam rationale generation requires an active Sentient Forms managed-service subscription.'
					}
				};
			}
		});

		await clickButton(target, 'Use past submissions');
		await clickButton(target, 'Review');
		await clickButton(target, 'Generate rationale and save');

		expect(target.textContent).toContain(
			'Historical spam rationale generation requires an active Sentient Forms managed-service subscription.'
		);
		dispose();
	});

	it('shows Form Source labels and ledger/native availability copy', async () => {
		const { target, dispose } = mount({
			positiveExamples: [],
			negativeExamples: [],
			initiallyExpanded: true,
			formSourceSlug: 'wpforms',
			formId: 42,
			targetScope: 'form',
			searchHistoricalEntries: async () => ({
				form_source: 'wpforms',
				form_id: '42',
				availability: {
					source: 'ledger',
					native_read: false,
					ledger_read: true,
					ledger_enabled: true,
					unavailable_reason: null,
					native_unavailable_reason: 'wpforms_native_entry_storage_unavailable'
				},
				entries: [
					{
						id: '123e4567-e89b-12d3-a456-426614174000',
						source_type: 'ledger',
						status: 'captured',
						date_created: '2030-01-05T10:00:00Z',
						native_entry_id: null,
						native_entry_url: null,
						field_summary: [{ field_id: '2', label: 'Message', value: 'Need a quote.' }]
					}
				]
			}),
			saveHistoricalExample: async () => ({
				target_scope: 'form',
				label: 'ham',
				config: {
					spam_positive_examples: [
						{
							text: 'Message: Need a quote.',
							rationale: 'Specific buyer request.'
						}
					],
					spam_negative_examples: []
				}
			})
		});

		await clickButton(target, 'Use past submissions');

		expect(target.textContent).toContain('Historical submissions for WPForms');
		expect(target.textContent).toContain('WPForms native entry storage is unavailable');
		expect(target.textContent).toContain('Showing Sentient Forms Submission Ledger records.');
		expect(target.textContent).toContain('Submission Ledger');
		dispose();
	});

	it('distinguishes disabled ledger and Elementor Pro unavailable empty states', async () => {
		const disabledLedger = mount({
			positiveExamples: [],
			negativeExamples: [],
			initiallyExpanded: true,
			formSourceSlug: 'contact_form_7',
			formId: 9,
			targetScope: 'form',
			searchHistoricalEntries: async () => ({
				form_source: 'contact_form_7',
				form_id: '9',
				availability: {
					source: 'ledger',
					native_read: false,
					ledger_read: false,
					ledger_enabled: false,
					unavailable_reason: 'submission_ledger_disabled',
					native_unavailable_reason: 'native_entry_storage_unavailable'
				},
				entries: []
			}),
			saveHistoricalExample: async () => ({
				target_scope: 'form',
				label: 'ham',
				config: { spam_positive_examples: [], spam_negative_examples: [] }
			})
		});

		await clickButton(disabledLedger.target, 'Use past submissions');
		expect(disabledLedger.target.textContent).toContain(
			'Historical submissions for Contact Form 7'
		);
		expect(disabledLedger.target.textContent).toContain(
			'Enable Submission Ledger to capture future submissions for this form.'
		);
		disabledLedger.dispose();

		const requiresPro = mount({
			positiveExamples: [],
			negativeExamples: [],
			initiallyExpanded: true,
			formSourceSlug: 'elementor_pro_forms',
			formId: '123:formabc',
			targetScope: 'form',
			searchHistoricalEntries: async () => ({
				form_source: 'elementor_pro_forms',
				form_id: '123:formabc',
				availability: {
					source: 'native',
					native_read: false,
					ledger_read: false,
					ledger_enabled: false,
					unavailable_reason: 'requires_pro'
				},
				entries: []
			}),
			saveHistoricalExample: async () => ({
				target_scope: 'form',
				label: 'spam',
				config: { spam_positive_examples: [], spam_negative_examples: [] }
			})
		});

		await clickButton(requiresPro.target, 'Use past submissions');
		expect(requiresPro.target.textContent).toContain('Historical submissions for Elementor Pro Forms');
		expect(requiresPro.target.textContent).toContain(
			'Elementor Pro Forms submissions are required to read historical Elementor submissions.'
		);
		requiresPro.dispose();
	});

	it('keeps mapping scope when a numeric mapping id is available', async () => {
		let savedPayload: Record<string, unknown> | null = null;
		const { target, dispose } = mount({
			positiveExamples: [],
			negativeExamples: [],
			initiallyExpanded: true,
			formSourceSlug: 'gravity_forms',
			formId: 7,
			targetScope: 'mapping',
			mappingId: 77,
			searchHistoricalEntries: async () => ({
				form_source: 'gravity_forms',
				form_id: '7',
				availability: {
					source: 'native',
					native_read: true,
					ledger_read: false,
					unavailable_reason: null
				},
				entries: [
					{
						id: '99',
						source_type: 'native',
						status: 'spam',
						date_created: '2030-01-05T10:00:00Z',
						native_entry_id: '99',
						native_entry_url: null,
						field_summary: [{ field_id: '2', label: 'Message', value: 'Buy crypto traffic now.' }]
					}
				]
			}),
			saveHistoricalExample: async (payload: Record<string, unknown>) => {
				savedPayload = payload;
				return {
					target_scope: 'mapping',
					label: 'spam',
					config: {
						spam_positive_examples: [],
						spam_negative_examples: [
							{
								text: 'Message: Buy crypto traffic now.',
								rationale: 'Known spam offer.'
							}
						]
					}
				};
			}
		});

		await clickButton(target, 'Use past submissions');
		await clickButton(target, 'Review');

		const scopeSelect = target.querySelector<HTMLSelectElement>('#historical-example-scope');
		expect(scopeSelect?.value).toBe('mapping');
		expect(Array.from(scopeSelect?.options ?? []).map((option) => option.value)).toContain('mapping');

		await clickButton(target, 'Generate rationale and save');
		expect(savedPayload).toMatchObject({
			target_scope: 'mapping',
			mapping_id: 77
		});
		dispose();
	});

	it('keeps mapping scope when a local mapping id string is available', async () => {
		let savedPayload: Record<string, unknown> | null = null;
		const { target, dispose } = mount({
			positiveExamples: [],
			negativeExamples: [],
			initiallyExpanded: true,
			formSourceSlug: 'gravity_forms',
			formId: 7,
			targetScope: 'mapping',
			mappingId: 'local_first_77',
			searchHistoricalEntries: async () => ({
				form_source: 'gravity_forms',
				form_id: '7',
				availability: {
					source: 'native',
					native_read: true,
					ledger_read: false,
					unavailable_reason: null
				},
				entries: [
					{
						id: '99',
						source_type: 'native',
						status: 'spam',
						date_created: '2030-01-05T10:00:00Z',
						native_entry_id: '99',
						native_entry_url: null,
						field_summary: [{ field_id: '2', label: 'Message', value: 'Buy crypto traffic now.' }]
					}
				]
			}),
			saveHistoricalExample: async (payload: Record<string, unknown>) => {
				savedPayload = payload;
				return {
					target_scope: 'mapping',
					label: 'spam',
					config: {
						spam_positive_examples: [],
						spam_negative_examples: [
							{
								text: 'Message: Buy crypto traffic now.',
								rationale: 'Known spam offer.'
							}
						]
					}
				};
			}
		});

		await clickButton(target, 'Use past submissions');
		await clickButton(target, 'Review');

		const scopeSelect = target.querySelector<HTMLSelectElement>('#historical-example-scope');
		expect(scopeSelect?.value).toBe('mapping');
		expect(Array.from(scopeSelect?.options ?? []).map((option) => option.value)).toContain('mapping');

		await clickButton(target, 'Generate rationale and save');
		expect(savedPayload).toMatchObject({
			target_scope: 'mapping',
			mapping_id: 'local_first_77'
		});
		dispose();
	});

	it('falls back to form scope when mapping scope lacks a usable mapping id', async () => {
		let savedPayload: Record<string, unknown> | null = null;
		const { target, dispose } = mount({
			positiveExamples: [],
			negativeExamples: [],
			initiallyExpanded: true,
			formSourceSlug: 'gravity_forms',
			formId: 7,
			targetScope: 'mapping',
			searchHistoricalEntries: async () => ({
				form_source: 'gravity_forms',
				form_id: '7',
				availability: {
					source: 'native',
					native_read: true,
					ledger_read: false,
					unavailable_reason: null
				},
				entries: [
					{
						id: '99',
						source_type: 'native',
						status: 'spam',
						date_created: '2030-01-05T10:00:00Z',
						native_entry_id: '99',
						native_entry_url: null,
						field_summary: [{ field_id: '2', label: 'Message', value: 'Buy crypto traffic now.' }]
					}
				]
			}),
			saveHistoricalExample: async (payload: Record<string, unknown>) => {
				savedPayload = payload;
				return {
					target_scope: 'form',
					label: 'spam',
					config: {
						spam_positive_examples: [],
						spam_negative_examples: [
							{
								text: 'Message: Buy crypto traffic now.',
								rationale: 'Known spam offer.'
							}
						]
					}
				};
			}
		});

		await clickButton(target, 'Use past submissions');
		await clickButton(target, 'Review');

		const scopeSelect = target.querySelector<HTMLSelectElement>('#historical-example-scope');
		expect(scopeSelect?.value).toBe('form');
		expect(Array.from(scopeSelect?.options ?? []).map((option) => option.value)).not.toContain(
			'mapping'
		);

		await clickButton(target, 'Generate rationale and save');
		expect(savedPayload).toMatchObject({
			target_scope: 'form'
		});
		expect(savedPayload).not.toHaveProperty('mapping_id');
		dispose();
	});
});
