import { describe, expect, it } from 'vitest';
import type { ModelInfo } from '$lib/api/types';
import {
	filterAndSortModels,
	missingRequiredCapabilities,
	modelCostLabel,
	modelSelectorZdrControl,
	modelSupportsToolChoice,
	modelSupportsServerTool,
	providerMonogram,
	resolveReadyCredentialId,
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
	sortMode: 'name',
	zdrOnly: false
};

describe('model selector presentation utilities', () => {
	it('replaces a stale saved credential with a ready credential for the selected provider', () => {
		const credentials = [
			{ id: 64, provider: 'openrouter' },
			{ id: 62, provider: 'sentient_managed' }
		];

		expect(resolveReadyCredentialId(credentials, 'openrouter', 61)).toBe(64);
		expect(resolveReadyCredentialId(credentials, 'openrouter', 64)).toBe(64);
		expect(resolveReadyCredentialId(credentials, 'sentient_managed', 61)).toBe(62);
		expect(resolveReadyCredentialId([], 'openrouter', 61)).toBeNull();
	});

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

	it('matches model selector search against display names', () => {
		const filtered = filterAndSortModels(models, {
			...baseFilters,
			searchTerm: 'claude sonnet'
		});

		expect(filtered.map((candidate) => candidate.id)).toEqual(['anthropic/claude-sonnet-4.6']);
	});

	it('matches model selector search against API model slugs', () => {
		const filtered = filterAndSortModels(models, {
			...baseFilters,
			searchTerm: 'moonshotai/kimi'
		});

		expect(filtered.map((candidate) => candidate.id)).toEqual(['moonshotai/kimi-k2.6']);
	});

	it('filters schema-required actions to structured-output capable models', () => {
		const filtered = filterAndSortModels(
			[
				...models,
				model({
					id: 'openrouter/auto',
					display_name: 'OpenRouter Auto',
					developer: 'OpenRouter',
					provider_family: 'OpenRouter',
					capabilities: {
						reasoning: false,
						code: false,
						vision: false,
						tools: false,
						structured: false,
						web_search: false,
						long_context: false,
						files: false
					}
				})
			],
			{
				...baseFilters,
				requiredCapabilities: ['structured']
			}
		);

		expect(filtered.map((candidate) => candidate.id)).toEqual([
			'anthropic/claude-sonnet-4.6',
			'moonshotai/kimi-k2.6'
		]);
	});

	it('filters ZDR-only results to models OpenRouter marks as ZDR eligible', () => {
		const filtered = filterAndSortModels(
			[
				model({
					id: 'openai/gpt-5.5',
					display_name: 'OpenAI GPT-5.5',
					zdr_eligible: true,
					zdr_source: 'openrouter_models_zdr_filter',
					zdr_checked_at: '2026-06-22T22:00:00+00:00'
				}),
				model({
					id: 'anthropic/fable-preview',
					display_name: 'Anthropic Fable Preview',
					zdr_eligible: false,
					zdr_source: 'openrouter_models_zdr_filter',
					zdr_checked_at: '2026-06-22T22:00:00+00:00'
				}),
				model({
					id: 'unknown/provider',
					display_name: 'Unknown Provider',
					zdr_eligible: null
				})
			],
			{
				...baseFilters,
				zdrOnly: true
			}
		);

		expect(filtered.map((candidate) => candidate.id)).toEqual(['openai/gpt-5.5']);
	});

	it('describes premium and forced ZDR-only selector control states', () => {
		expect(
			modelSelectorZdrControl({
				managedServiceActive: false,
				managedZdrRequired: false,
				provider: 'sentient_managed',
				zdrOnly: false
			})
		).toMatchObject({
			checked: false,
			disabled: true,
			popover: 'Requires an active Sentient Forms Managed Service subscription.',
			helperSentences: [
				'OpenRouter marks this model as available on ZDR routes.',
				'ZDR enforcement for direct OpenRouter users can only be configured in OpenRouter.'
			]
		});

		expect(
			modelSelectorZdrControl({
				managedServiceActive: true,
				managedZdrRequired: false,
				provider: 'sentient_managed',
				zdrOnly: false
			})
		).toMatchObject({
			checked: false,
			disabled: false,
			popover: null,
			helperSentences: ['OpenRouter marks this model as available on ZDR routes.']
		});

		expect(
			modelSelectorZdrControl({
				managedServiceActive: true,
				managedZdrRequired: true,
				provider: 'sentient_managed',
				zdrOnly: false
			})
		).toMatchObject({
			checked: true,
			disabled: true,
			popover: 'Required by Enforce ZDR in Settings. Disable the option to change this filter'
		});
	});

	it('disables the ZDR enforcement toggle for direct OpenRouter routes', () => {
		expect(
			modelSelectorZdrControl({
				managedServiceActive: false,
				managedZdrRequired: false,
				provider: 'openrouter',
				zdrOnly: true
			})
		).toMatchObject({
			checked: false,
			disabled: true,
			popover: 'Requires an active Sentient Forms Managed Service subscription.',
			helperSentences: [
				'OpenRouter marks this model as available on ZDR routes.',
				'ZDR enforcement for direct OpenRouter users can only be configured in OpenRouter.'
			]
		});

		expect(
			modelSelectorZdrControl({
				managedServiceActive: true,
				managedZdrRequired: true,
				provider: 'openrouter',
				zdrOnly: true
			})
		).toMatchObject({
			checked: false,
			disabled: true,
			popover: 'Plugin ZDR enforcement only applies to Sentient Forms Managed Service.',
			helperSentences: ['OpenRouter marks this model as available on ZDR routes.']
		});
	});

	it('disables the ZDR enforcement toggle for unknown non-managed routes', () => {
		expect(
			modelSelectorZdrControl({
				managedServiceActive: true,
				managedZdrRequired: false,
				provider: 'local_provider',
				zdrOnly: true
			})
		).toMatchObject({
			checked: false,
			disabled: true,
			popover: 'Plugin ZDR enforcement only applies to Sentient Forms Managed Service.'
		});
	});

	it('reports incompatible saved selections that are missing required structured output', () => {
		const missing = missingRequiredCapabilities(models[2], ['structured', 'tools']);

		expect(missing).toEqual(['structured']);
		expect(missingRequiredCapabilities(null, ['structured'])).toEqual(['structured']);
		expect(missingRequiredCapabilities(models[0], ['structured'])).toEqual([]);
	});

	it('distinguishes OpenRouter server tools from generic tool calling', () => {
		const latestAlias = model({
			id: '~openai/gpt-latest',
			display_name: 'OpenAI GPT Latest',
			capabilities: {
				reasoning: true,
				code: true,
				vision: true,
				tools: true,
				structured: true,
				web_search: true,
				server_tools: {
					web_search: true,
					web_fetch: false,
					datetime: false
				},
				long_context: true,
				files: true
			}
		});

		expect(modelSupportsServerTool(latestAlias, 'web_search')).toBe(true);
		expect(modelSupportsServerTool(latestAlias, 'web_fetch')).toBe(false);
		expect(modelSupportsServerTool(latestAlias, 'datetime')).toBe(false);
		expect(modelSupportsServerTool(models[0], 'web_fetch')).toBe(false);
	});

	it('does not treat web-search-only capability as tool-choice support', () => {
		const webSearchOptionsModel = model({
			id: 'search/options-only',
			display_name: 'Search Options Only',
			capabilities: {
				reasoning: false,
				code: false,
				vision: false,
				tools: false,
				structured: true,
				web_search: true,
				server_tools: {
					web_search: true,
					web_fetch: false,
					datetime: false
				},
				long_context: false,
				files: false
			}
		});

		expect(modelSupportsServerTool(webSearchOptionsModel, 'web_search')).toBe(true);
		expect(modelSupportsToolChoice(webSearchOptionsModel)).toBe(false);
		expect(modelSupportsToolChoice(models[0])).toBe(true);
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
