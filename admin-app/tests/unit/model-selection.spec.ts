import { describe, expect, it } from 'vitest';
import type { ModelInfo } from '$lib/api/types';
import {
	modelSupportsReasoning,
	normalizeModelReasoningEffort,
	reasoningOptionsForModel
} from '$lib/utils/model-selection';

const nonReasoningCapabilities = {
	reasoning: false,
	code: false,
	vision: false,
	tools: false,
	long_context: true
};

function model(overrides: Partial<ModelInfo>): ModelInfo {
	return {
		id: 'openai/gpt-5.5',
		display_name: 'OpenAI GPT-5.5',
		provider: 'openrouter',
		speed_tier: 'balanced',
		cost_tier: 'high',
		capabilities: {
			reasoning: true,
			code: false,
			vision: false,
			tools: false,
			long_context: true
		},
		context_window: 128000,
		is_preview: false,
		tags: [],
		recommended_for: [],
		...overrides
	};
}

describe('model selection reasoning metadata', () => {
	it('hides explicit effort options for non-reasoning models', () => {
		const nonReasoningModel = model({
			id: 'openai/gpt-oss-20b:free',
			capabilities: nonReasoningCapabilities,
			supported_parameters: ['response_format']
		});
		const options = reasoningOptionsForModel(nonReasoningModel);

		expect(modelSupportsReasoning(nonReasoningModel)).toBe(false);
		expect(options).toEqual([{ value: 'default', label: 'Model default' }]);
	});

	it('uses OpenRouter reasoning efforts and suppresses unsupported Gemini xhigh', () => {
		expect(
			reasoningOptionsForModel(
				model({
					id: 'anthropic/claude-sonnet-4.6',
					supported_parameters: ['reasoning']
				})
			).map((option) => option.value)
		).toEqual(['default', 'none', 'minimal', 'low', 'medium', 'high', 'xhigh']);

		expect(
			reasoningOptionsForModel(
				model({
					id: 'google/gemini-3.1-pro-preview',
					supported_parameters: ['reasoning']
				})
			).map((option) => option.value)
		).toEqual(['default', 'none', 'minimal', 'low', 'medium', 'high']);
	});

	it('normalizes saved OpenRouter reasoning objects by effort', () => {
		expect(normalizeModelReasoningEffort({ effort: 'high', exclude: true })).toBe('high');
	});
});
