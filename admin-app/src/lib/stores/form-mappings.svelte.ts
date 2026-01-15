/**
 * Phase 7: Form Mappings Store (CSM)
 * 
 * Manages CPS-backed form mappings for cross-site portability.
 * Provides template library functionality and local caching.
 */

import { createClientFromConfig } from '$lib/api/client';
import type { FormMapping, CreateFormMappingRequest, UpdateFormMappingRequest, CloneTemplateMappingRequest } from '$lib/api/types';

// Reactive state using Svelte 5 runes
let mappings = $state<FormMapping[]>([]);
let templates = $state<FormMapping[]>([]);
let loading = $state(false);
let error = $state<string | null>(null);
let lastFetch = $state<Date | null>(null);

// Cache TTL: 5 minutes
const CACHE_TTL_MS = 5 * 60 * 1000;

function isCacheStale(): boolean {
    if (!lastFetch) return true;
    return Date.now() - lastFetch.getTime() > CACHE_TTL_MS;
}

/**
 * Fetch all mappings for the current license.
 * CSM-002: Sync from CPS on page load.
 */
async function fetchMappings(force = false): Promise<void> {
    if (!force && !isCacheStale() && mappings.length > 0) {
        return; // Use cached data
    }

    loading = true;
    error = null;

    try {
        const client = createClientFromConfig();
        mappings = await client.getFormMappings();
        lastFetch = new Date();
    } catch (e) {
        error = e instanceof Error ? e.message : 'Failed to fetch mappings';
        console.error('[FormMappingsStore] fetchMappings error:', e);
        // Keep stale data for offline fallback
    } finally {
        loading = false;
    }
}

/**
 * Fetch template mappings only.
 * CSM-003: List reusable templates.
 */
async function fetchTemplates(force = false): Promise<void> {
    if (!force && templates.length > 0) {
        return;
    }

    loading = true;
    error = null;

    try {
        const client = createClientFromConfig();
        templates = await client.getFormMappingTemplates();
    } catch (e) {
        error = e instanceof Error ? e.message : 'Failed to fetch templates';
        console.error('[FormMappingsStore] fetchTemplates error:', e);
    } finally {
        loading = false;
    }
}

/**
 * Create a new mapping.
 */
async function createMapping(request: CreateFormMappingRequest): Promise<FormMapping | null> {
    loading = true;
    error = null;

    try {
        const client = createClientFromConfig();
        const newMapping = await client.createFormMapping(request);
        mappings = [...mappings, newMapping];

        // Update templates if this is a template
        if (newMapping.is_template) {
            templates = [...templates, newMapping];
        }

        return newMapping;
    } catch (e) {
        error = e instanceof Error ? e.message : 'Failed to create mapping';
        console.error('[FormMappingsStore] createMapping error:', e);
        return null;
    } finally {
        loading = false;
    }
}

/**
 * Update an existing mapping.
 */
async function updateMapping(id: string, request: UpdateFormMappingRequest): Promise<FormMapping | null> {
    loading = true;
    error = null;

    try {
        const client = createClientFromConfig();
        const updated = await client.updateFormMapping(id, request);

        mappings = mappings.map(m => m.id === id ? updated : m);
        templates = templates.map(t => t.id === id ? updated : t);

        return updated;
    } catch (e) {
        error = e instanceof Error ? e.message : 'Failed to update mapping';
        console.error('[FormMappingsStore] updateMapping error:', e);
        return null;
    } finally {
        loading = false;
    }
}

/**
 * Delete a mapping.
 */
async function deleteMapping(id: string): Promise<boolean> {
    loading = true;
    error = null;

    try {
        const client = createClientFromConfig();
        await client.deleteFormMapping(id);

        mappings = mappings.filter(m => m.id !== id);
        templates = templates.filter(t => t.id !== id);

        return true;
    } catch (e) {
        error = e instanceof Error ? e.message : 'Failed to delete mapping';
        console.error('[FormMappingsStore] deleteMapping error:', e);
        return false;
    } finally {
        loading = false;
    }
}

/**
 * Clone a template to a specific site/form.
 * CSM-004: Import from Library.
 */
async function cloneTemplate(
    templateId: string,
    request: CloneTemplateMappingRequest
): Promise<FormMapping | null> {
    loading = true;
    error = null;

    try {
        const client = createClientFromConfig();
        const cloned = await client.cloneFormMappingTemplate(templateId, request);
        mappings = [...mappings, cloned];
        return cloned;
    } catch (e) {
        error = e instanceof Error ? e.message : 'Failed to clone template';
        console.error('[FormMappingsStore] cloneTemplate error:', e);
        return null;
    } finally {
        loading = false;
    }
}

/**
 * Mark a mapping as a template (save as template).
 * CSM-003: Save as Template.
 */
async function saveAsTemplate(mappingId: string, displayName?: string): Promise<FormMapping | null> {
    return updateMapping(mappingId, {
        display_name: displayName,
        is_template: true
    });
}

/**
 * Get mappings for a specific form.
 */
function getMappingsForForm(siteId: string, formSource: string, formId: number): FormMapping[] {
    return mappings.filter(m =>
        m.site_id === siteId &&
        m.form_source === formSource &&
        m.form_id === formId
    );
}

/**
 * Clear cache and force refresh.
 */
function invalidateCache(): void {
    lastFetch = null;
    mappings = [];
    templates = [];
}

// Export reactive getters and actions
export const formMappingsStore = {
    // Reactive state (read-only getters)
    get mappings() { return mappings; },
    get templates() { return templates; },
    get loading() { return loading; },
    get error() { return error; },
    get lastFetch() { return lastFetch; },

    // Actions
    fetchMappings,
    fetchTemplates,
    createMapping,
    updateMapping,
    deleteMapping,
    cloneTemplate,
    saveAsTemplate,
    getMappingsForForm,
    invalidateCache,
    isCacheStale
};
