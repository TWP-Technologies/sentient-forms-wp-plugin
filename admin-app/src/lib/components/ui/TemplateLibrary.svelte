<script lang="ts">
	/**
	 * Phase 7: Template Library Modal (CSM-004)
	 *
	 * Displays available templates and allows importing to the current form.
	 * Handles field re-mapping when template fields don't match target form.
	 */
	import { formMappingsStore } from '$lib/stores/form-mappings.svelte';
	import type { FormMapping, FormFieldInfo, CloneTemplateMappingRequest } from '$lib/api/types';
	import { onMount } from 'svelte';
	import Button from './button.svelte';

	// Props
	let {
		open = $bindable(false),
		siteId,
		formSource,
		formId,
		formFields = [],
		onImport
	}: {
		open: boolean;
		siteId: string;
		formSource: string;
		formId: number;
		formFields: FormFieldInfo[];
		onImport?: (mapping: FormMapping) => void;
	} = $props();

	// Local state
	let selectedTemplate = $state<FormMapping | null>(null);
	let fieldMappings = $state<Record<string, string>>({});
	let importing = $state(false);
	let step = $state<'select' | 'remap'>('select');

	// Load templates on mount
	onMount(() => {
		formMappingsStore.fetchTemplates();
	});

	// Reset state when modal opens
	$effect(() => {
		if (open) {
			selectedTemplate = null;
			fieldMappings = {};
			step = 'select';
		}
	});

	function selectTemplate(template: FormMapping) {
		selectedTemplate = template;

		// Check if we need field re-mapping
		const portableFields = template.settings.portable_fields || [];
		if (portableFields.length > 0) {
			// Initialize field mappings with best guesses
			for (const pf of portableFields) {
				const match = formFields.find(
					(f) => f.label.toLowerCase() === pf.label.toLowerCase() || f.type === pf.type
				);
				if (match) {
					fieldMappings[pf.label] = match.id;
				}
			}
			step = 'remap';
		} else {
			// No portable fields, direct import
			handleImport();
		}
	}

	async function handleImport() {
		if (!selectedTemplate) return;

		importing = true;

		const request: CloneTemplateMappingRequest = {
			site_id: siteId,
			form_source: formSource,
			form_id: formId,
			field_mapping: Object.keys(fieldMappings).length > 0 ? fieldMappings : undefined
		};

		const cloned = await formMappingsStore.cloneTemplate(selectedTemplate.id, request);

		importing = false;

		if (cloned) {
			onImport?.(cloned);
			open = false;
		}
	}

	function close() {
		open = false;
	}
</script>

{#if open}
	<div
		class="sf-template-library-overlay"
		onclick={close}
		onkeydown={(e) => e.key === 'Escape' && close()}
		role="button"
		tabindex="-1"
	>
		<div
			class="sf-template-library-modal"
			onclick={(e) => e.stopPropagation()}
			role="dialog"
			aria-modal="true"
		>
			<header class="sf-template-library-header">
				<h2>
					{#if step === 'select'}
						Import from Template Library
					{:else}
						Map Fields
					{/if}
				</h2>
				<Button
					type="button"
					variant="ghost"
					size="sm"
					iconOnly
					class="sf:text-lg"
					onclick={close}
					aria-label="Close"
				>
					×
				</Button>
			</header>

			<div class="sf-template-library-content">
				{#if formMappingsStore.loading}
					<div class="sf-loading">Loading templates...</div>
				{:else if formMappingsStore.error}
					<div class="sf-error">{formMappingsStore.error}</div>
				{:else if step === 'select'}
					{#if formMappingsStore.templates.length === 0}
						<div class="sf-empty-state">
							<p>No templates available yet.</p>
							<p class="sf-hint">
								Save a form action configuration as a template to reuse it across sites.
							</p>
						</div>
					{:else}
						<ul class="sf-template-list">
							{#each formMappingsStore.templates as template (template.id)}
								<li>
									<Button
										type="button"
										variant="secondary"
										size="sm"
										class="sf-template-item sf:h-auto sf:w-full sf:justify-between sf:px-4 sf:py-3 sf:text-left"
										onclick={() => selectTemplate(template)}
									>
										<span class="sf-template-name">{template.display_name}</span>
										<span class="sf-template-source">{template.form_source}</span>
									</Button>
								</li>
							{/each}
						</ul>
					{/if}
				{:else if step === 'remap' && selectedTemplate}
					<p class="sf-remap-intro">Map template fields to your form fields:</p>
					<div class="sf-field-mappings">
						{#each selectedTemplate.settings.portable_fields || [] as pf}
							<div class="sf-field-mapping-row">
								<label>
									<span class="sf-portable-field">{pf.label} ({pf.type})</span>
									<select bind:value={fieldMappings[pf.label]}>
										<option value="">— Skip —</option>
										{#each formFields as field}
											<option value={field.id}>{field.label} ({field.type})</option>
										{/each}
									</select>
								</label>
							</div>
						{/each}
					</div>
					<div class="sf-remap-actions">
						<Button
							type="button"
							variant="secondary"
							onclick={() => {
								step = 'select';
							}}
						>
							Back
						</Button>
						<Button type="button" variant="primary" onclick={handleImport} disabled={importing}>
							{importing ? 'Importing...' : 'Import'}
						</Button>
					</div>
				{/if}
			</div>
		</div>
	</div>
{/if}

<style>
	.sf-template-library-overlay {
		position: fixed;
		inset: 0;
		background: rgba(0, 0, 0, 0.5);
		display: flex;
		align-items: center;
		justify-content: center;
		z-index: 100000;
	}

	.sf-template-library-modal {
		background: #fff;
		border-radius: 8px;
		box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
		width: 90%;
		max-width: 500px;
		max-height: 80vh;
		display: flex;
		flex-direction: column;
	}

	.sf-template-library-header {
		display: flex;
		justify-content: space-between;
		align-items: center;
		padding: 16px 20px;
		border-bottom: 1px solid #e0e0e0;
	}

	.sf-template-library-header h2 {
		margin: 0;
		font-size: 18px;
		font-weight: 600;
	}

	.sf-template-library-content {
		padding: 20px;
		overflow-y: auto;
		flex: 1;
	}

	.sf-loading,
	.sf-error,
	.sf-empty-state {
		text-align: center;
		padding: 40px 20px;
		color: #666;
	}

	.sf-error {
		color: #d32f2f;
	}

	.sf-hint {
		font-size: 13px;
		color: #999;
		margin-top: 8px;
	}

	.sf-template-list {
		list-style: none;
		margin: 0;
		padding: 0;
	}

	.sf-template-item {
		display: flex;
		justify-content: space-between;
		align-items: center;
		width: 100%;
		padding: 12px 16px;
		border: 1px solid #e0e0e0;
		border-radius: 6px;
		background: #fafafa;
		cursor: pointer;
		margin-bottom: 8px;
		transition:
			background 0.15s,
			border-color 0.15s;
	}

	.sf-template-item:hover {
		background: #f0f0f0;
		border-color: #2271b1;
	}

	.sf-template-name {
		font-weight: 500;
	}

	.sf-template-source {
		font-size: 12px;
		color: #666;
		background: #e0e0e0;
		padding: 2px 8px;
		border-radius: 4px;
	}

	.sf-remap-intro {
		margin-bottom: 16px;
		color: #333;
	}

	.sf-field-mappings {
		display: flex;
		flex-direction: column;
		gap: 12px;
	}

	.sf-field-mapping-row label {
		display: flex;
		flex-direction: column;
		gap: 4px;
	}

	.sf-portable-field {
		font-size: 13px;
		font-weight: 500;
		color: #333;
	}

	.sf-field-mapping-row select {
		padding: 8px;
		border: 1px solid #ccc;
		border-radius: 4px;
		font-size: 14px;
	}

	.sf-remap-actions {
		display: flex;
		justify-content: flex-end;
		gap: 12px;
		margin-top: 20px;
		padding-top: 16px;
		border-top: 1px solid #e0e0e0;
	}

</style>
