import { z } from 'zod';

const promptOverridesSchema = z.record(z.string(), z.json());

export function sanitizeCustomActionCode(value: string): string {
	return value.toLowerCase().replace(/[^a-z0-9-]/g, '');
}

export function generateCustomActionCode(value: string): string {
	return value
		.trim()
		.toLowerCase()
		.replace(/[^a-z0-9]+/g, '-')
		.replace(/^-+|-+$/g, '');
}

export function parsePromptOverridesInput(raw: string): {
	result?: Record<string, unknown>;
	error?: string;
} {
	if (!raw.trim()) {
		return { result: undefined, error: undefined };
	}

	try {
		const parsed = promptOverridesSchema.safeParse(JSON.parse(raw));
		if (!parsed.success) {
			return { error: 'Prompt overrides must be a JSON object.' };
		}
		return { result: parsed.data };
	} catch (error) {
		return {
			error:
				error instanceof Error
					? error.message || 'Prompt overrides must be valid JSON.'
					: 'Prompt overrides must be valid JSON.'
		};
	}
}

export interface CustomActionMergeTagOption {
	group: string;
	token: string;
	label: string;
	description?: string;
}

export function coerceDisplayString(value: unknown, fallback = ''): string {
	if (typeof value === 'string') {
		return value;
	}

	if (typeof value === 'number' || typeof value === 'boolean') {
		return String(value);
	}

	if (value && typeof value === 'object') {
		const candidate = value as Record<string, unknown>;
		for (const key of ['label', 'name', 'title', 'display_name', 'id', 'value']) {
			const normalized = coerceDisplayString(candidate[key], '');
			if (normalized.trim().length > 0) {
				return normalized;
			}
		}
	}

	return fallback;
}

export function sanitizeMergeTagOptions(
	tokens: readonly Partial<CustomActionMergeTagOption>[]
): CustomActionMergeTagOption[] {
	return tokens
		.map((token) => {
			const normalizedToken = coerceDisplayString(token.token).trim();
			const label = coerceDisplayString(token.label, normalizedToken).trim();
			if (!normalizedToken || !label) {
				return null;
			}

			const group = coerceDisplayString(token.group, 'Common').trim() || 'Common';
			const description = coerceDisplayString(token.description).trim();
			return {
				group,
				token: normalizedToken,
				label,
				...(description ? { description } : {})
			};
		})
		.filter((token): token is CustomActionMergeTagOption => Boolean(token));
}

export function buildDefaultCustomActionMergeTags(): CustomActionMergeTagOption[] {
	return sanitizeMergeTagOptions([
		{
			group: 'Entry',
			token: 'entry_id',
			label: 'Entry ID',
			description: 'Gravity Forms entry ID.'
		},
		{ group: 'Entry', token: 'entry', label: 'Entry JSON', description: 'Rendered entry data.' },
		{
			group: 'Entry',
			token: 'summary_text',
			label: 'Entry summary',
			description: 'Plain-language entry summary.'
		},
		{ group: 'Form', token: 'form_id', label: 'Form ID' },
		{ group: 'Form', token: 'form.title', label: 'Form title' },
		{
			group: 'Fields',
			token: 'field:type:name.full',
			label: 'Name: full name',
			description: 'Resolve the first visible name field as a full name.'
		},
		{
			group: 'Fields',
			token: 'field:type:name.first',
			label: 'Name: first name',
			description: 'Resolve the first visible name field first-name input when available.'
		},
		{
			group: 'Fields',
			token: 'field:type:name.last',
			label: 'Name: last name',
			description: 'Resolve the first visible name field last-name input when available.'
		},
		{
			group: 'Fields',
			token: 'field:type:email',
			label: 'Email field',
			description: 'Resolve the first visible email field.'
		},
		{
			group: 'Fields',
			token: 'field:type:phone',
			label: 'Phone field',
			description: 'Resolve the first visible phone field.'
		},
		{
			group: 'Fields',
			token: 'field:label_contains:reason for calling',
			label: 'Label contains...',
			description: 'Replace the text after label_contains with a real form label phrase.'
		},
		{ group: 'Action', token: 'action_label', label: 'Action label' },
		{ group: 'Action', token: 'action_code', label: 'Action code' },
		{ group: 'Result', token: 'llm_output', label: 'AI output' },
		{ group: 'Result', token: 'justification', label: 'Justification' },
		{ group: 'Result', token: 'classification', label: 'Classification' },
		{ group: 'Result', token: 'confidence', label: 'Confidence' }
	]);
}
