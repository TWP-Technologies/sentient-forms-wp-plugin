import { Page } from '@playwright/test';

type Routes = {
	actions?: {
		forms?: Record<string, unknown[]>;
		definitions?: unknown;
		mappingTemplates?: unknown[];
		status?: unknown;
		settings?: Record<string, unknown>;
		actionDefaultsById?: Record<string, Record<string, unknown>>;
		formsActions?: unknown[];
		formFields?: unknown[];
		creditBalance?: unknown;
		disableState?: unknown;
		workflowPlan?: unknown;
		executionStatus?: Record<number, unknown>;
		createResponse?: (payload: Record<string, unknown>) => unknown;
		requestTrace?: unknown | ((payload: Record<string, unknown>) => unknown);
	};
	customActions?: {
		list?: unknown;
		create?: unknown;
	};
	localProviders?: {
		credentials?: unknown[];
	};
};

const defaultLicense = {
	status: 'inactive',
	license_key_masked: '',
	proxy_key_present: false,
	tier: null,
	expires_at: null,
	last_synced: null,
	license_id: null,
	site_id: null,
	site_url: 'https://example.test'
};

const defaultCreditBalance = {
	current_balance: 1000,
	tier: {
		code: 'free',
		display_name: 'Free',
		monthly_credit_quota: 1000
	}
};

const defaultModelCatalog = {
	models: [
		{
			id: 'openrouter/free',
			display_name: 'OpenRouter Free Models Router',
			provider: 'openrouter',
			speed_tier: 'fast',
			cost_tier: 'free',
			capabilities: {
				reasoning: true,
				code: false,
				vision: true,
				tools: true,
				structured: true,
				web_search: false,
				long_context: true
			},
			context_window: 200000,
			is_preview: false,
			tags: ['free', 'structured-output', 'bundled-recommendation'],
			supported_parameters: ['reasoning', 'response_format', 'structured_outputs', 'tools'],
			pricing: { prompt: '0', completion: '0' },
			recommended_for: ['Free testing']
		},
		{
			id: 'openai/gpt-5.5',
			display_name: 'OpenAI: GPT-5.5',
			provider: 'openrouter',
			speed_tier: 'balanced',
			cost_tier: 'medium',
			capabilities: {
				reasoning: true,
				code: false,
				vision: true,
				tools: true,
				structured: true,
				web_search: false,
				long_context: true
			},
			context_window: 400000,
			is_preview: false,
			tags: ['structured-output', 'reasoning', 'long-context', 'bundled-recommendation'],
			supported_parameters: ['reasoning', 'response_format', 'structured_outputs', 'tools'],
			pricing: { prompt: '0.00000125', completion: '0.00001' },
			recommended_for: ['General purpose', 'Structured output', 'Paid model quality']
		}
	],
	presets: [
		{
			code: 'sf_default',
			display_name: 'Recommended',
			description: 'Recommended paid model for production workflows.',
			category: 'local',
			resolved_model_id: 'openai/gpt-5.5',
			auto_upgrade: true
		},
		{
			code: 'sf_free',
			display_name: 'Free model',
			description: 'Use the OpenRouter free models router for workflow proof.',
			category: 'local',
			resolved_model_id: 'openrouter/free',
			auto_upgrade: true
		}
	],
	pricing_policy_version: 'mock'
};

export async function mockWpJson(page: Page, routes: Routes, formId = 1) {
	await page.context().unroute('**/wp-json/sentient-forms/v1/**').catch(() => {});
	const envelope = (data: unknown) =>
		JSON.stringify({
			success: true,
			data
		});

	const settingsState: Record<string, unknown> = {
		enable_logging: true,
		execution_event_retention_days: 90,
		delete_data_on_uninstall: true,
		store_full_ai_outputs: false,
		privacy_setup_profile: 'balanced',
		privacy_setup_completed_at: '2026-04-21T00:00:00Z',
		execution_global_disabled: false,
		execution_provider_disabled: {},
		...(routes.actions?.settings ?? {})
	};
	const actionDefaultsState: Record<string, Record<string, unknown>> = {
		...(routes.actions?.actionDefaultsById ?? {})
	};
	const disableState: Record<string, boolean> = {
		sf_disabled: false,
		global_disabled: false,
		provider_disabled: false,
		effective_disabled: false,
		...((routes.actions?.disableState as Record<string, boolean> | undefined) ?? {})
	};

	await page.context().route('**/wp-json/sentient-forms/v1/**', (route) => {
		const url = route.request().url();
		const urlWithoutQuery = url.split('?')[0] ?? url;
		const method = route.request().method();

		if (urlWithoutQuery.endsWith('/license') && method === 'GET') {
			return route.fulfill({
				status: 200,
				headers: { 'content-type': 'application/json' },
				body: envelope(defaultLicense)
			});
		}

		if (urlWithoutQuery.endsWith('/license/bootstrap') && method === 'POST') {
			return route.fulfill({
				status: 200,
				headers: { 'content-type': 'application/json' },
				body: envelope(defaultLicense)
			});
		}

		if (urlWithoutQuery.endsWith('/local/providers/credentials') && method === 'GET') {
			return route.fulfill({
				status: 200,
				headers: { 'content-type': 'application/json' },
				body: JSON.stringify(routes.localProviders?.credentials ?? [])
			});
		}

		if (urlWithoutQuery.endsWith('/local/action-templates') && method === 'GET') {
			return route.fulfill({
				status: 200,
				headers: { 'content-type': 'application/json' },
				body: JSON.stringify({ success: true, data: [] })
			});
		}

		if (urlWithoutQuery.endsWith('/local/custom-actions') && method === 'GET') {
			return route.fulfill({
				status: 200,
				headers: { 'content-type': 'application/json' },
				body: JSON.stringify({ success: true, data: [] })
			});
		}

		if (urlWithoutQuery.endsWith('/local/execution-events') && method === 'GET') {
			return route.fulfill({
				status: 200,
				headers: { 'content-type': 'application/json' },
				body: JSON.stringify({ success: true, data: [] })
			});
		}

		if (urlWithoutQuery.endsWith('/local/support-bundle') && method === 'GET') {
			return route.fulfill({
				status: 200,
				headers: { 'content-type': 'application/json' },
				body: JSON.stringify({
					success: true,
					data: {
						generated_at: '2026-04-22T00:00:00Z',
						plugin: {},
						wordpress: {},
						local_tables: {},
						providers: [],
						external_consents: [],
						execution_summary: {},
						retention: {}
					}
				})
			});
		}

		const formsMatch = url.match(/\/([^/]+)\/forms$/);
		if (routes.actions?.forms && formsMatch && method === 'GET') {
			const slug = formsMatch[1];
			const forms = routes.actions.forms[slug] ?? [];
			return route.fulfill({
				status: 200,
				headers: { 'content-type': 'application/json' },
				body: envelope(forms)
			});
		}

		if (routes.actions?.definitions && urlWithoutQuery.endsWith('/actions/definitions')) {
			return route.fulfill({
				status: 200,
				headers: { 'content-type': 'application/json' },
				body: envelope(routes.actions.definitions)
			});
		}

		if (urlWithoutQuery.endsWith('/settings') && method === 'GET') {
			return route.fulfill({
				status: 200,
				headers: { 'content-type': 'application/json' },
				body: envelope(settingsState)
			});
		}

			if (urlWithoutQuery.endsWith('/settings') && method === 'PUT') {
				const body = (route.request().postDataJSON() as Record<string, unknown>) ?? {};
				Object.assign(settingsState, body);
				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: envelope(settingsState)
				});
			}

			const actionDefaultsMatch = urlWithoutQuery.match(/\/actions\/([^/]+)\/defaults$/);
			if (actionDefaultsMatch && method === 'GET') {
				const actionId = decodeURIComponent(actionDefaultsMatch[1]);
				const config = actionDefaultsState[actionId] ?? {};
				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify({
						success: true,
						data: {
							form_source: 'global',
							form_id: 0,
							action_id: actionId,
							config
						}
					})
				});
			}

			if (actionDefaultsMatch && method === 'POST') {
				const actionId = decodeURIComponent(actionDefaultsMatch[1]);
				const body = (route.request().postDataJSON() as Record<string, unknown>) ?? {};
				actionDefaultsState[actionId] = {
					...(actionDefaultsState[actionId] ?? {}),
					...body
				};
				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify({
						success: true,
						data: {
							form_source: 'global',
							form_id: 0,
							action_id: actionId,
							config: actionDefaultsState[actionId]
						}
					})
				});
			}

		if (urlWithoutQuery.endsWith('/meta/capabilities')) {
			return route.fulfill({
				status: 200,
				headers: { 'content-type': 'application/json' },
				body: JSON.stringify({
					success: true,
					data: {
						supports_custom_actions: true,
						supports_credits: false,
						supports_status: true,
						cps_version: '1.2.0',
						features: [],
						form_sources: ['gravity_forms'],
						actions: ['spam_detection_v1']
					}
				})
			});
		}

		if (url.endsWith('/mappings/templates') && method === 'GET') {
			return route.fulfill({
				status: 200,
				headers: { 'content-type': 'application/json' },
				body: envelope(routes.actions?.mappingTemplates ?? [])
			});
		}

		if (urlWithoutQuery.endsWith('/models') && method === 'GET') {
			return route.fulfill({
				status: 200,
				headers: { 'content-type': 'application/json' },
				body: envelope(defaultModelCatalog)
			});
		}

		if (urlWithoutQuery.endsWith('/models/resolve') && method === 'POST') {
			const payload = (route.request().postDataJSON() as Record<string, unknown>) ?? {};
			const mapping = payload.mapping_selection as Record<string, unknown> | undefined;
			const action = payload.action_selection as Record<string, unknown> | undefined;
			const primary = String(mapping?.primary ?? action?.primary ?? payload.template_model_hint ?? 'openai/gpt-5.5');
			const resolvedModelId =
				primary === 'sf_default' ? 'openai/gpt-5.5' : primary === 'sf_free' ? 'openrouter/free' : primary;
			return route.fulfill({
				status: 200,
				headers: { 'content-type': 'application/json' },
				body: envelope({
					model_id: resolvedModelId,
					display_name: resolvedModelId,
					resolution_source: mapping ? 'mapping' : action ? 'action' : 'fallback',
					override_chain: [],
					backup_model_id: typeof mapping?.backup === 'string' ? mapping.backup : null
				})
			});
		}

		if (urlWithoutQuery.endsWith('/models/estimate') && method === 'POST') {
			const payload = (route.request().postDataJSON() as Record<string, unknown>) ?? {};
			const mapping = payload.mapping_selection as Record<string, unknown> | undefined;
			const action = payload.action_selection as Record<string, unknown> | undefined;
			const primary = String(mapping?.primary ?? action?.primary ?? payload.template_model_hint ?? 'openai/gpt-5.5');
			const resolvedModelId =
				primary === 'sf_default' ? 'openai/gpt-5.5' : primary === 'sf_free' ? 'openrouter/free' : primary;
			const actionId = String(payload.action_id ?? 'mock_action');
			return route.fulfill({
				status: 200,
				headers: { 'content-type': 'application/json' },
				body: envelope({
					resolved_model: {
						model_id: resolvedModelId,
						display_name: resolvedModelId,
						resolution_source: 'mock',
						override_chain: [],
						backup_model_id: null
					},
					pricing_estimate: {
						action_id: actionId,
						resolved_model_id: resolvedModelId,
						base_floor_credits: 0,
						normalized_actual_credits: 0,
						estimated_debit_credits: 0,
						pricing_policy_version: 'mock',
						estimate_source: 'fallback'
					}
				})
			});
		}

		if (urlWithoutQuery.endsWith('/credits/balance') && method === 'GET') {
			return route.fulfill({
				status: 200,
				headers: { 'content-type': 'application/json' },
				body: envelope(routes.actions?.creditBalance ?? defaultCreditBalance)
			});
		}

		if (/\/actions\/status$/.test(urlWithoutQuery)) {
			return route.fulfill({
				status: 200,
				headers: { 'content-type': 'application/json' },
				body: envelope(
					routes.actions?.status ?? {
						status: 'unknown',
						last_run_at: null,
						last_error_code: null,
						message: ''
					}
				)
			});
		}

		if (routes.actions?.formsActions && /forms\/\d+\/actions$/.test(url) && method === 'GET') {
			return route.fulfill({
				status: 200,
				headers: { 'content-type': 'application/json' },
				body: envelope(routes.actions.formsActions)
			});
		}

		if (routes.actions?.formFields && /forms\/\d+\/actions\/fields$/.test(url) && method === 'GET') {
			return route.fulfill({
				status: 200,
				headers: { 'content-type': 'application/json' },
				body: envelope(routes.actions.formFields)
			});
		}

		if (/forms\/\d+\/actions\/fields$/.test(url) && method === 'GET') {
			return route.fulfill({
				status: 200,
				headers: { 'content-type': 'application/json' },
				body: envelope([])
			});
		}

		if (/forms\/\d+\/actions\/disable$/.test(urlWithoutQuery) && method === 'GET') {
			return route.fulfill({
				status: 200,
				headers: { 'content-type': 'application/json' },
				body: envelope(disableState)
			});
		}

		if (/forms\/\d+\/actions\/disable$/.test(urlWithoutQuery) && method === 'PUT') {
			const payload = (route.request().postDataJSON() as Record<string, unknown>) ?? {};
			disableState.sf_disabled = Boolean(payload.sf_disabled);
			disableState.effective_disabled = Boolean(
				disableState.sf_disabled ||
					disableState.global_disabled ||
					disableState.provider_disabled
			);
			return route.fulfill({
				status: 200,
				headers: { 'content-type': 'application/json' },
				body: envelope(disableState)
			});
		}

		if (/forms\/\d+\/actions\/workflow-plan$/.test(urlWithoutQuery) && method === 'GET') {
			return route.fulfill({
				status: 200,
				headers: { 'content-type': 'application/json' },
				body: envelope(
					routes.actions?.workflowPlan ?? {
						authority: 'local_fallback',
						authority_reason: 'mock',
						cps_unreachable: false,
						policy_version: '2026-02-mixed-sync-async-v1',
						hook_scope: 'all',
						available_hooks: ['gform_validation', 'gform_after_submission'],
						nodes: [],
						edges: [],
						hooks: [],
						policy_violations: []
					}
				)
			});
		}

		if (/forms\/\d+\/actions\/request-trace$/.test(url) && method === 'POST') {
			const payload = (route.request().postDataJSON() as Record<string, unknown>) ?? {};
			const manualValues =
				payload.entry_values && typeof payload.entry_values === 'object'
					? (payload.entry_values as Record<string, string>)
					: {};
			const fallback = {
				authority: 'wp_rest',
				policy_version: '2026-02-request-tracer-v1',
				hook_scope: String(payload.hook_scope ?? 'all'),
				available_hooks: ['gform_validation', 'gform_after_submission'],
				input: {
					source: Object.keys(manualValues).length > 0 ? 'manual' : 'empty',
					entry_id:
						typeof payload.entry_id === 'number' && Number.isFinite(payload.entry_id)
							? payload.entry_id
							: null,
					field_scope: 'mapped_and_rule',
					values: manualValues,
					manual_field_ids: Object.keys(manualValues),
					imported_field_ids: [],
					overridden_field_ids: [],
					warnings: [],
					include_drafts: Boolean(payload.include_drafts),
					draft_applied: Boolean(payload.include_drafts)
				},
				hooks: [],
				policy_violations: []
			};
			const responsePayload =
				typeof routes.actions?.requestTrace === 'function'
					? routes.actions.requestTrace(payload)
					: routes.actions?.requestTrace ?? fallback;
			return route.fulfill({
				status: 200,
				headers: { 'content-type': 'application/json' },
				body: envelope(responsePayload)
			});
		}

		if (routes.actions?.formsActions && /forms\/\d+\/actions$/.test(url) && method === 'POST') {
			const body = (route.request().postDataJSON() as Record<string, unknown>) ?? {};
			const newLinkage =
				routes.actions.createResponse?.(body) ??
				({
					local_mapping_id: `local-${Date.now()}`,
					central_action_id: String(body.central_action_id ?? 'unknown'),
					action_type_indicator: (body.action_type_indicator as string) ?? 'master',
					trigger_hooks: (body.trigger_hooks as string[] | undefined) ?? [],
					is_action_enabled_for_form: true,
					action_name_label: (body.action_name_label as string | undefined) ?? 'New action'
				} satisfies Record<string, unknown>);

			(routes.actions.formsActions as unknown[]).push(newLinkage);

			return route.fulfill({
				status: 201,
				headers: { 'content-type': 'application/json' },
				body: envelope(newLinkage)
			});
		}

		const actionConfigMatch = urlWithoutQuery.match(/\/forms\/[^/]+\/(\d+)\/action-config\/([^/]+)$/);
		if (actionConfigMatch && method === 'GET') {
			return route.fulfill({
				status: 200,
				headers: { 'content-type': 'application/json' },
				body: JSON.stringify({
					success: true,
					data: {
						form_source_slug: 'gravity_forms',
						form_id: Number(actionConfigMatch[1]),
						action_id: decodeURIComponent(actionConfigMatch[2]),
						config: {}
					}
				})
			});
		}

		if (
			routes.actions?.formsActions &&
			/forms\/\d+\/actions\/[^/]+\/duplicate$/.test(url) &&
			method === 'POST'
		) {
			const payload = (route.request().postDataJSON() as Record<string, unknown>) ?? {};
			const parent =
				(payload.parent && typeof payload.parent === 'object'
					? payload.parent
					: {}) as Record<string, unknown>;
			const sourceId = decodeURIComponent(url.split('/').at(-2) ?? '');
			const source = routes.actions.formsActions.find(
				(item) =>
					typeof item === 'object' &&
					item !== null &&
					(item as Record<string, unknown>).local_mapping_id === sourceId
			) as Record<string, unknown> | undefined;

			if (!source) {
				return route.fulfill({
					status: 404,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify({ success: false, message: 'Not found' })
				});
			}

			const selectedHook = String(parent.hook ?? '').trim();
			const parentType = String(parent.type ?? '').trim();
			const selectedParentId = String(parent.mapping_id ?? '').trim();
			const sourceHooks = Array.isArray(source.trigger_hooks)
				? source.trigger_hooks.filter((hook): hook is string => typeof hook === 'string')
				: [];
			if (!selectedHook || !sourceHooks.includes(selectedHook)) {
				return route.fulfill({
					status: 400,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify({ success: false, message: 'Invalid duplicate parent hook.' })
				});
			}

			const readTriggerSources = (
				linkage: Record<string, unknown>
			): Record<string, { type: 'hook_root' | 'mapping'; mapping_id?: string }> => {
				const hooks = Array.isArray(linkage.trigger_hooks)
					? linkage.trigger_hooks.filter((hook): hook is string => typeof hook === 'string')
					: [];
				const explicit =
					linkage.settings &&
					typeof linkage.settings === 'object' &&
					!Array.isArray(linkage.settings) &&
					(linkage.settings as Record<string, unknown>).trigger_sources &&
					typeof (linkage.settings as Record<string, unknown>).trigger_sources === 'object' &&
					!Array.isArray((linkage.settings as Record<string, unknown>).trigger_sources)
						? (structuredClone(
								(linkage.settings as Record<string, unknown>).trigger_sources
							) as Record<string, { type: 'hook_root' | 'mapping'; mapping_id?: string }>)
						: {};

				const normalized: Record<
					string,
					{ type: 'hook_root' | 'mapping'; mapping_id?: string }
				> = {};
				for (const hook of hooks) {
					const sourceEntry = explicit[hook];
					if (
						sourceEntry &&
						sourceEntry.type === 'mapping' &&
						typeof sourceEntry.mapping_id === 'string' &&
						sourceEntry.mapping_id.trim().length > 0
					) {
						normalized[hook] = {
							type: 'mapping',
							mapping_id: sourceEntry.mapping_id
						};
					} else {
						normalized[hook] = { type: 'hook_root' };
					}
				}
				return normalized;
			};

			const deriveDependencyIds = (
				sources: Record<string, { type: 'hook_root' | 'mapping'; mapping_id?: string }>
			): string[] =>
				Array.from(
					new Set(
						Object.values(sources)
							.filter(
								(entry) => entry.type === 'mapping' && typeof entry.mapping_id === 'string'
							)
							.map((entry) => entry.mapping_id as string)
					)
				);

			const duplicateId = `${sourceId}_dup_${Date.now()}`;
			const duplicate = structuredClone(source);
			duplicate.local_mapping_id = duplicateId;
			const duplicateSources = readTriggerSources(duplicate);
			duplicateSources[selectedHook] =
				parentType === 'mapping'
					? { type: 'mapping', mapping_id: selectedParentId }
					: { type: 'hook_root' };
			const duplicateDependencyIds = deriveDependencyIds(duplicateSources);
			duplicate.settings =
				duplicate.settings && typeof duplicate.settings === 'object' && !Array.isArray(duplicate.settings)
					? duplicate.settings
					: {};
			(duplicate.settings as Record<string, unknown>).trigger_sources = duplicateSources;
			if (duplicateDependencyIds.length > 0) {
				(duplicate.settings as Record<string, unknown>).dependency_ids = duplicateDependencyIds;
			} else {
				delete (duplicate.settings as Record<string, unknown>).dependency_ids;
			}

			const childrenToMove = (routes.actions.formsActions as unknown[])
				.filter((item) => {
					if (!item || typeof item !== 'object') return false;
					const mapping = item as Record<string, unknown>;
					const hooks = Array.isArray(mapping.trigger_hooks)
						? mapping.trigger_hooks.filter((hook): hook is string => typeof hook === 'string')
						: [];
					if (!hooks.includes(selectedHook)) return false;
					const sources = readTriggerSources(mapping);
					const hookSource = sources[selectedHook] ?? { type: 'hook_root' as const };
					if (parentType === 'mapping') {
						return hookSource.type === 'mapping' && hookSource.mapping_id === selectedParentId;
					}
					return hookSource.type === 'hook_root';
				})
				.map((item) => item as Record<string, unknown>);

			const movedChildren: string[] = [];
			for (const child of childrenToMove) {
				const childId = String(child.local_mapping_id ?? '').trim();
				if (!childId) continue;
				const index = routes.actions.formsActions.findIndex(
					(item) =>
						typeof item === 'object' &&
						item !== null &&
						(item as Record<string, unknown>).local_mapping_id === childId
				);
				if (index === -1) continue;
				const current = routes.actions.formsActions[index] as Record<string, unknown>;
				const childSources = readTriggerSources(current);
				childSources[selectedHook] = { type: 'mapping', mapping_id: duplicateId };
				const childDependencyIds = deriveDependencyIds(childSources);
				const childSettings =
					current.settings &&
					typeof current.settings === 'object' &&
					!Array.isArray(current.settings)
						? { ...(current.settings as Record<string, unknown>) }
						: {};
				childSettings.trigger_sources = childSources;
				if (childDependencyIds.length > 0) {
					childSettings.dependency_ids = childDependencyIds;
				} else {
					delete childSettings.dependency_ids;
				}
				routes.actions.formsActions[index] = {
					...current,
					settings: childSettings
				};
				movedChildren.push(childId);
			}

			(routes.actions.formsActions as unknown[]).push(duplicate);
			return route.fulfill({
				status: 201,
				headers: { 'content-type': 'application/json' },
				body: envelope({
					duplicate,
					insertion: {
						parent: {
							type: parentType === 'mapping' ? 'mapping' : 'hook_root',
							hook: selectedHook,
							...(parentType === 'mapping' && selectedParentId
								? { mapping_id: selectedParentId }
								: {})
						},
						moved_children: movedChildren,
						skipped_children: [],
						warnings: []
					}
				})
			});
		}

		if (
			routes.actions?.formsActions &&
			/forms\/\d+\/actions\/[^/]+$/.test(url) &&
			method === 'PUT'
		) {
			const body = (route.request().postDataJSON() as Record<string, unknown>) ?? {};
			const localMappingId = decodeURIComponent(url.split('/').pop() ?? '');
			const index = routes.actions.formsActions.findIndex(
				(item) =>
					typeof item === 'object' &&
					item !== null &&
					(item as Record<string, unknown>).local_mapping_id === localMappingId
			);

			if (index === -1) {
				return route.fulfill({
					status: 404,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify({ success: false, message: 'Not found' })
				});
			}

			const current = routes.actions.formsActions[index] as Record<string, unknown>;
			const merged = {
				...current,
				...body,
				settings: {
					...(typeof current.settings === 'object' && current.settings ? current.settings : {}),
					...(typeof body.settings === 'object' && body.settings ? body.settings : {})
				}
			};

			routes.actions.formsActions[index] = merged;

			return route.fulfill({
				status: 200,
				headers: { 'content-type': 'application/json' },
				body: envelope(merged)
			});
		}

		const execMatch = url.match(/forms\/(\d+)\/actions\/entries\/(\d+)\/status$/);
		if (routes.actions?.executionStatus && execMatch) {
			const entryId = Number(execMatch[2]);
			const payload = routes.actions.executionStatus[entryId];
			if (payload) {
				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: envelope(payload)
				});
			}
		}

		if (routes.customActions?.list && url.includes('/custom-actions') && method === 'GET') {
			return route.fulfill({
				status: 200,
				headers: { 'content-type': 'application/json' },
				body: JSON.stringify(routes.customActions.list)
			});
		}

		if (routes.customActions?.create && url.includes('/custom-actions') && method === 'POST') {
			return route.fulfill({
				status: 201,
				headers: { 'content-type': 'application/json' },
				body: JSON.stringify(routes.customActions.create)
			});
		}

		return route.continue();
	});
}
