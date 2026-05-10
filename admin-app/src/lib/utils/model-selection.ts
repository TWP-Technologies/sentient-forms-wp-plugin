import type { ModelInfo, ModelSelection } from '$lib/api/types';

export const MODEL_REASONING_EFFORTS = [
	'none',
	'minimal',
	'low',
	'medium',
	'high',
	'xhigh'
] as const;

export type ModelReasoningEffort = (typeof MODEL_REASONING_EFFORTS)[number];
export type ModelReasoningControlValue = 'default' | ModelReasoningEffort;

export interface ModelReasoningOption {
	value: ModelReasoningControlValue;
	label: string;
}

const MODEL_REASONING_EFFORT_SET = new Set<string>(MODEL_REASONING_EFFORTS);

const MODEL_REASONING_LABELS: Record<ModelReasoningControlValue, string> = {
	default: 'Model default',
	none: 'Off',
	minimal: 'Minimal',
	low: 'Low',
	medium: 'Medium',
	high: 'High',
	xhigh: 'Extra High'
};

export function normalizeModelReasoningEffort(value: unknown): ModelReasoningEffort | undefined {
	if (typeof value !== 'string') {
		return undefined;
	}

	const normalized = value.trim().toLowerCase();
	return MODEL_REASONING_EFFORT_SET.has(normalized)
		? (normalized as ModelReasoningEffort)
		: undefined;
}

export function modelSupportsReasoning(model: ModelInfo | null | undefined): boolean {
	if (!model) {
		return false;
	}

	const supportedParameters = Array.isArray(model.supported_parameters)
		? model.supported_parameters
		: [];

	return Boolean(
		model.capabilities?.reasoning ||
		supportedParameters.includes('reasoning') ||
		supportedParameters.includes('reasoning_effort')
	);
}

export function reasoningOptionsForModel(
	model: ModelInfo | null | undefined
): ModelReasoningOption[] {
	if (!modelSupportsReasoning(model)) {
		return [{ value: 'default', label: MODEL_REASONING_LABELS.default }];
	}

	const supportedEfforts = [...MODEL_REASONING_EFFORTS];
	const modelId = String(model?.id ?? '').toLowerCase();

	if (modelId.startsWith('google/gemini-3')) {
		const xhighIndex = supportedEfforts.indexOf('xhigh');
		if (xhighIndex >= 0) supportedEfforts.splice(xhighIndex, 1);
	}

	return [
		{ value: 'default', label: MODEL_REASONING_LABELS.default },
		...supportedEfforts.map((effort) => ({
			value: effort,
			label: MODEL_REASONING_LABELS[effort]
		}))
	];
}

export function formatModelSelectionPrimary(selection: ModelSelection | null | undefined): string {
	const primary = selection?.primary?.toString().trim();
	if (!primary || primary === 'sf_default') {
		return 'Recommended preset';
	}

	if (primary === 'sf_free') {
		return 'Free preset';
	}

	if (primary === 'openrouter/auto') {
		return 'OpenRouter Auto';
	}

	if (primary.startsWith('sf_')) {
		return `${primary.replace(/^sf_/, '').replaceAll('_', ' ')} preset`;
	}

	return primary;
}

export function formatTemplateModelHint(hint: string | null | undefined): string {
	const normalized = hint?.trim();
	if (!normalized || normalized === 'openrouter/auto' || normalized === 'sf_default') {
		return 'Recommended preset';
	}

	if (normalized === 'sf_free') {
		return 'Free preset';
	}

	return normalized;
}
