import type { ModelInfo } from '$lib/api/types';

export type ModelSelectorSortMode =
	| 'name'
	| 'provider'
	| 'cost'
	| 'context'
	| 'newest'
	| 'capabilities'
	| 'rank';

export type ModelSelectorCostLimit = 'all' | 'free' | 'low' | 'medium' | 'high' | 'premium';

export type ModelSelectorCapabilityKey =
	| 'reasoning'
	| 'structured'
	| 'tools'
	| 'vision'
	| 'files'
	| 'web_search'
	| 'long_context'
	| 'code';

export type OpenRouterServerToolKey = 'web_search' | 'web_fetch' | 'datetime';

export interface ModelSelectorFilters {
	searchTerm: string;
	costLimit: ModelSelectorCostLimit;
	provider: string;
	category: string;
	maxRank: number;
	minContext: number;
	requiredCapabilities: ModelSelectorCapabilityKey[];
	sortMode: ModelSelectorSortMode;
	zdrOnly: boolean;
}

export interface ModelSelectorZdrControlInput {
	managedServiceActive: boolean;
	managedZdrRequired: boolean;
	provider?: string;
	zdrOnly: boolean;
}

export interface ModelSelectorZdrControlState {
	checked: boolean;
	disabled: boolean;
	popover: string | null;
	helperSentences: string[];
}

export const ZDR_MODEL_TAG_HELPER = 'OpenRouter marks this model as available on ZDR routes.';
export const DIRECT_OPENROUTER_ZDR_ENFORCEMENT_HELPER =
	'ZDR enforcement for direct OpenRouter users can only be configured in OpenRouter.';
export const ZDR_PREMIUM_POPOVER =
	'Requires an active Sentient Forms Managed Service subscription.';
export const ZDR_FORCED_POPOVER =
	'Required by Enforce ZDR in Settings. Disable the option to change this filter';
export const ZDR_DIRECT_OPENROUTER_ROUTE_POPOVER =
	'Plugin ZDR enforcement only applies to Sentient Forms Managed Service.';

export const MODEL_RANK_CATEGORIES = [
	'programming',
	'roleplay',
	'marketing',
	'seo',
	'technology',
	'science',
	'translation',
	'legal',
	'finance',
	'health',
	'trivia',
	'academia'
] as const;

const COST_TIER_ORDER: Record<string, number> = {
	free: 0,
	low: 1,
	medium: 2,
	high: 3,
	premium: 4,
	unknown: 5
};

const CAPABILITY_KEYS: ModelSelectorCapabilityKey[] = [
	'reasoning',
	'structured',
	'tools',
	'vision',
	'files',
	'web_search',
	'long_context',
	'code'
];

export function isModelFree(model: ModelInfo): boolean {
	return model.cost_tier === 'free' || model.id.endsWith(':free') || model.id === 'openrouter/free';
}

export function modelSelectorZdrControl(
	input: ModelSelectorZdrControlInput
): ModelSelectorZdrControlState {
	const helperSentences = [ZDR_MODEL_TAG_HELPER];
	if (!input.managedServiceActive) {
		helperSentences.push(DIRECT_OPENROUTER_ZDR_ENFORCEMENT_HELPER);
	}
	const managedRoute = input.provider === 'sentient_managed';

	if (!managedRoute && input.managedServiceActive) {
		return {
			checked: false,
			disabled: true,
			popover: ZDR_DIRECT_OPENROUTER_ROUTE_POPOVER,
			helperSentences
		};
	}

	if (input.managedZdrRequired) {
		return {
			checked: true,
			disabled: true,
			popover: ZDR_FORCED_POPOVER,
			helperSentences
		};
	}

	if (!input.managedServiceActive) {
		return {
			checked: false,
			disabled: true,
			popover: ZDR_PREMIUM_POPOVER,
			helperSentences
		};
	}

	return {
		checked: input.zdrOnly,
		disabled: false,
		popover: null,
		helperSentences
	};
}

export function priceSymbolFromTier(tier: string): string {
	switch (tier) {
		case 'free':
			return 'Free';
		case 'low':
			return '$';
		case 'medium':
			return '$$';
		case 'high':
			return '$$$';
		case 'premium':
			return '$$$$';
		default:
			return 'Varies';
	}
}

export function modelCostLabel(model: ModelInfo): string {
	if (isModelFree(model)) return 'Free';
	const symbol = typeof model.cost_symbol === 'string' ? model.cost_symbol.trim() : '';
	return symbol || priceSymbolFromTier(model.cost_tier);
}

export function modelCostOrder(model: ModelInfo): number {
	if (isModelFree(model)) return COST_TIER_ORDER.free;
	return COST_TIER_ORDER[model.cost_tier] ?? COST_TIER_ORDER.unknown;
}

export function providerDisplayName(model: ModelInfo): string {
	return model.developer || model.provider_family || model.provider || 'OpenRouter';
}

export function providerKey(model: ModelInfo): string {
	return providerDisplayName(model).trim().toLowerCase();
}

export function categoryLabel(category: string): string {
	return category.replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}

export function categoryRankingItems(model: ModelInfo | null): Array<[string, number]> {
	if (!model?.category_rankings) return [];
	return Object.entries(model.category_rankings)
		.filter(([, rank]) => Number.isFinite(rank) && rank > 0)
		.sort((a, b) => a[1] - b[1]);
}

export function modelRankForCategory(model: ModelInfo, category: string): number | null {
	if (!category || category === 'all') return null;
	const rank = model.category_rankings?.[category];
	return Number.isFinite(rank) && Number(rank) > 0 ? Number(rank) : null;
}

export function modelBestRank(model: ModelInfo): number | null {
	const ranks = categoryRankingItems(model).map(([, rank]) => rank);
	return ranks.length > 0 ? ranks[0] : null;
}

export function modelHasCapability(
	model: ModelInfo,
	capability: ModelSelectorCapabilityKey
): boolean {
	return Boolean(model.capabilities[capability]);
}

export function modelSupportsServerTool(
	model: ModelInfo | null | undefined,
	tool: OpenRouterServerToolKey
): boolean {
	if (!model) return false;
	const explicit = model.capabilities.server_tools?.[tool];
	if (typeof explicit === 'boolean') return explicit;
	if (tool === 'web_search') return Boolean(model.capabilities.web_search);
	return false;
}

export function modelSupportsToolChoice(model: ModelInfo | null | undefined): boolean {
	if (!model) return false;
	return Boolean(model.capabilities.tools);
}

export function missingRequiredCapabilities(
	model: ModelInfo | null,
	requiredCapabilities: ModelSelectorCapabilityKey[]
): ModelSelectorCapabilityKey[] {
	if (requiredCapabilities.length === 0) return [];
	if (!model) return requiredCapabilities;
	return requiredCapabilities.filter((capability) => !modelHasCapability(model, capability));
}

export function modelCapabilityCount(model: ModelInfo): number {
	return CAPABILITY_KEYS.filter((capability) => modelHasCapability(model, capability)).length;
}

export function modelMatchesFilters(model: ModelInfo, filters: ModelSelectorFilters): boolean {
	const search = filters.searchTerm.trim().toLowerCase();
	const costLimitOrder = filters.costLimit === 'all' ? null : COST_TIER_ORDER[filters.costLimit];

	if (filters.zdrOnly && model.zdr_eligible !== true) return false;
	if (costLimitOrder !== null && modelCostOrder(model) > costLimitOrder) return false;
	if (filters.provider !== 'all' && providerKey(model) !== filters.provider) return false;
	if (filters.minContext > 0 && Number(model.context_window || 0) < filters.minContext)
		return false;

	for (const capability of filters.requiredCapabilities) {
		if (!modelHasCapability(model, capability)) return false;
	}

	if (filters.category !== 'all') {
		const rank = modelRankForCategory(model, filters.category);
		if (rank === null) return false;
		if (filters.maxRank > 0 && rank > filters.maxRank) return false;
	}

	if (!search) return true;

	const haystack = [
		model.id,
		model.display_name,
		model.provider_family,
		model.developer,
		providerDisplayName(model),
		...(model.recommended_for ?? []),
		...(model.recommendation_categories ?? []),
		...(model.tags ?? []),
		...categoryRankingItems(model).map(
			([category, rank]) => `${category} ${categoryLabel(category)} #${rank}`
		)
	]
		.filter(Boolean)
		.join(' ')
		.toLowerCase();

	return haystack.includes(search);
}

export function sortModels(
	models: ModelInfo[],
	sortMode: ModelSelectorSortMode,
	category: string
): ModelInfo[] {
	const sorted = [...models];
	const byName = (left: ModelInfo, right: ModelInfo) =>
		left.display_name.localeCompare(right.display_name, undefined, { sensitivity: 'base' });

	sorted.sort((left, right) => {
		switch (sortMode) {
			case 'provider': {
				const providerCompare = providerDisplayName(left).localeCompare(
					providerDisplayName(right),
					undefined,
					{ sensitivity: 'base' }
				);
				return providerCompare || byName(left, right);
			}
			case 'cost': {
				const costCompare = modelCostOrder(left) - modelCostOrder(right);
				return costCompare || byName(left, right);
			}
			case 'context': {
				const contextCompare = Number(right.context_window || 0) - Number(left.context_window || 0);
				return contextCompare || byName(left, right);
			}
			case 'newest': {
				const leftCreated = Number(left.created || 0);
				const rightCreated = Number(right.created || 0);
				return rightCreated - leftCreated || byName(left, right);
			}
			case 'capabilities': {
				const capabilityCompare = modelCapabilityCount(right) - modelCapabilityCount(left);
				return capabilityCompare || byName(left, right);
			}
			case 'rank': {
				const leftRank =
					category === 'all'
						? (modelBestRank(left) ?? Number.MAX_SAFE_INTEGER)
						: (modelRankForCategory(left, category) ?? Number.MAX_SAFE_INTEGER);
				const rightRank =
					category === 'all'
						? (modelBestRank(right) ?? Number.MAX_SAFE_INTEGER)
						: (modelRankForCategory(right, category) ?? Number.MAX_SAFE_INTEGER);
				return leftRank - rightRank || byName(left, right);
			}
			case 'name':
			default:
				return byName(left, right);
		}
	});

	return sorted;
}

export function filterAndSortModels(
	models: ModelInfo[],
	filters: ModelSelectorFilters
): ModelInfo[] {
	return sortModels(
		models.filter((model) => modelMatchesFilters(model, filters)),
		filters.sortMode,
		filters.category
	);
}

export function providerMonogram(model: ModelInfo | null): { label: string; initials: string } {
	const label = model ? providerDisplayName(model) : 'OpenRouter';
	const words = label
		.replace(/[^a-z0-9 ]/gi, ' ')
		.trim()
		.split(/\s+/)
		.filter(Boolean);
	const initials =
		words.length >= 2
			? `${words[0][0] ?? ''}${words[1][0] ?? ''}`
			: (words[0]?.slice(0, 2) ?? 'OR');

	return {
		label,
		initials: initials.toUpperCase()
	};
}
