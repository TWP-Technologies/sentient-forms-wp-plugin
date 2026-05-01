import { describe, expect, it } from 'vitest';
import type { ModelInfo } from '$lib/api/types';
import {
	filterAndSortModels,
	modelCostLabel,
	providerMonogram,
	type ModelSelectorFilters
} from '$lib/utils/model-selector-presentation';

function model(overrides: Partial<ModelInfo>): ModelInfo {
	return {
		id: 'openrouter/auto',
		display_name: 'OpenRouter Auto',
		provider: 'openrouter',
		provider_family: 'OpenRouter',
		developer: 'OpenRouter',
		speed_tier: 'balanced',
		cost_tier: 'unknown',
		capabilities: {
			reasoning: false,
			code: false,
			vision: false,
			tools: false,
			structured: false,
			web_search: false,
			long_context: false,
			files: false
		},
		context_window: 0,
		is_preview: false,
		tags: [],
		recommended_for: [],
		...overrides
	};
}

const baseFilters: ModelSelectorFilters = {
	searchTerm: '',
	costLimit: 'all',
	provider: 'all',
	category: 'all',
	maxRank: 0,
	minContext: 0,
	requiredCapabilities: [],
	sortMode: 'name'
};

describe('model selector presentation utilities', () => {
	const models = [
		model({
			id: 'anthropic/claude-sonnet-4.6',
			display_name: 'Claude Sonnet 4.6',
			developer: 'Anthropic',
			provider_family: 'Anthropic',
			cost_tier: 'medium',
			cost_symbol: '$$',
			context_window: 1000000,
			created: 20,
			capabilities: {
				reasoning: true,
				code: true,
				vision: true,
				tools: true,
				structured: true,
				web_search: false,
				long_context: true,
				files: true
			},
			category_rankings: { programming: 4, legal: 8, finance: 5 }
		}),
		model({
			id: 'moonshotai/kimi-k2.6',
			display_name: 'Kimi K2.6',
			developer: 'MoonshotAI',
			provider_family: 'MoonshotAI',
			cost_tier: 'low',
			cost_symbol: '$',
			context_window: 262000,
			created: 30,
			capabilities: {
				reasoning: true,
				code: true,
				vision: true,
				tools: true,
				structured: true,
				web_search: false,
				long_context: true,
				files: false
			},
			category_rankings: { programming: 2, academia: 8, finance: 9 }
		}),
		model({
			id: 'inclusionai/ling-2.6-1t:free',
			display_name: 'inclusionAI: Ling-2.6-1T (free)',
			developer: 'inclusionAI',
			provider_family: 'inclusionAI',
			cost_tier: 'free',
			context_window: 200000,
			created: 10,
			capabilities: {
				reasoning: false,
				code: false,
				vision: false,
				tools: true,
				structured: false,
				web_search: false,
				long_context: true,
				files: false
			},
			category_rankings: { programming: 7 }
		})
	];

	it('uses dollar cost labels instead of ambiguous words', () => {
		expect(modelCostLabel(models[0])).toBe('$$');
		expect(modelCostLabel(models[2])).toBe('Free');
	});

	it('filters by category rank, cost ceiling, provider, context, and capabilities', () => {
		const filtered = filterAndSortModels(models, {
			...baseFilters,
			costLimit: 'low',
			provider: 'moonshotai',
			category: 'programming',
			maxRank: 3,
			minContext: 200000,
			requiredCapabilities: ['reasoning', 'tools'],
			sortMode: 'rank'
		});

		expect(filtered.map((candidate) => candidate.id)).toEqual(['moonshotai/kimi-k2.6']);
	});

	it('sorts by category rank with alphabetical fallback', () => {
		const sorted = filterAndSortModels(models, {
			...baseFilters,
			category: 'programming',
			sortMode: 'rank'
		});

		expect(sorted.map((candidate) => candidate.id)).toEqual([
			'moonshotai/kimi-k2.6',
			'anthropic/claude-sonnet-4.6',
			'inclusionai/ling-2.6-1t:free'
		]);
	});

	it('builds neutral provider monograms without remote logo dependencies', () => {
		expect(providerMonogram(models[0])).toEqual({ label: 'Anthropic', initials: 'AN' });
		expect(providerMonogram(null)).toEqual({ label: 'OpenRouter', initials: 'OP' });
	});
});
