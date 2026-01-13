/**
 * Action category utilities for taxonomy grouping
 */
import type { ActionCategory, ActionDefinition, CustomAction } from '$lib/api/types';

/**
 * Metadata for each action category
 */
export interface ActionCategoryMeta {
    id: ActionCategory;
    label: string;
    description: string;
    icon?: string;
}

/**
 * Category metadata registry
 */
const CATEGORY_METADATA: Record<ActionCategory, ActionCategoryMeta> = {
    content_quality: {
        id: 'content_quality',
        label: 'Content Quality',
        description: 'Spam detection, content validation, and moderation',
        icon: '🛡️'
    },
    data_processing: {
        id: 'data_processing',
        label: 'Data Processing',
        description: 'Entry summaries, data extraction, and transformation',
        icon: '📊'
    },
    automation: {
        id: 'automation',
        label: 'Automation',
        description: 'Webhooks, notifications, and workflow triggers',
        icon: '⚡'
    },
    custom: {
        id: 'custom',
        label: 'Custom',
        description: 'User-defined custom actions',
        icon: '🎨'
    }
};

/**
 * Get metadata for a category
 */
export function getCategoryMeta(category: ActionCategory): ActionCategoryMeta {
    return CATEGORY_METADATA[category];
}

/**
 * Get all category metadata entries
 */
export function getAllCategories(): ActionCategoryMeta[] {
    return Object.values(CATEGORY_METADATA);
}

/**
 * Infer category from action ID or code for templates without explicit category
 */
export function inferCategory(idOrCode: string): ActionCategory {
    const lower = idOrCode.toLowerCase();

    // Content quality keywords
    if (
        lower.includes('spam') ||
        lower.includes('validation') ||
        lower.includes('moderat') ||
        lower.includes('filter')
    ) {
        return 'content_quality';
    }

    // Data processing keywords
    if (
        lower.includes('summary') ||
        lower.includes('extract') ||
        lower.includes('transform') ||
        lower.includes('parse')
    ) {
        return 'data_processing';
    }

    // Automation keywords
    if (
        lower.includes('webhook') ||
        lower.includes('notify') ||
        lower.includes('trigger') ||
        lower.includes('automat')
    ) {
        return 'automation';
    }

    // Default to data_processing for unknown templates
    return 'data_processing';
}

/**
 * Get the effective category for an action definition
 */
export function getDefinitionCategory(definition: ActionDefinition): ActionCategory {
    return definition.category ?? inferCategory(definition.id);
}

/**
 * Group action definitions by category
 */
export function groupDefinitionsByCategory(
    definitions: ActionDefinition[]
): Map<ActionCategory, ActionDefinition[]> {
    const grouped = new Map<ActionCategory, ActionDefinition[]>();

    // Initialize all categories
    for (const category of Object.keys(CATEGORY_METADATA) as ActionCategory[]) {
        grouped.set(category, []);
    }

    // Group definitions
    for (const def of definitions) {
        const category = getDefinitionCategory(def);
        grouped.get(category)?.push(def);
    }

    // Remove empty categories
    for (const [category, items] of grouped) {
        if (items.length === 0) {
            grouped.delete(category);
        }
    }

    return grouped;
}

/**
 * Group custom actions (always in 'custom' category)
 */
export function groupCustomActions(actions: CustomAction[]): Map<ActionCategory, CustomAction[]> {
    const grouped = new Map<ActionCategory, CustomAction[]>();

    if (actions.length > 0) {
        grouped.set('custom', actions);
    }

    return grouped;
}

/**
 * Merge definition and custom action groups into a single view
 */
export function mergeActionGroups(
    definitions: ActionDefinition[],
    customActions: CustomAction[]
): Map<ActionCategory, Array<ActionDefinition | CustomAction>> {
    const merged = new Map<ActionCategory, Array<ActionDefinition | CustomAction>>();

    // Add definitions
    const defGroups = groupDefinitionsByCategory(definitions);
    for (const [category, items] of defGroups) {
        merged.set(category, [...items]);
    }

    // Add custom actions
    if (customActions.length > 0) {
        const existing = merged.get('custom') ?? [];
        merged.set('custom', [...existing, ...customActions]);
    }

    return merged;
}
