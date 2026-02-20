import { Page } from '@playwright/test';

type Routes = {
	actions?: {
		forms?: Record<string, unknown[]>;
		definitions?: unknown;
		status?: unknown;
		settings?: Record<string, unknown>;
		formsActions?: unknown[];
		formFields?: unknown[];
		creditBalance?: unknown;
		executionStatus?: Record<number, unknown>;
		createResponse?: (payload: Record<string, unknown>) => unknown;
	};
	customActions?: {
		list?: unknown;
		create?: unknown;
	};
};

export async function mockWpJson(page: Page, routes: Routes, formId = 1) {
	await page.context().unroute('**/wp-json/sentient-forms/v1/**').catch(() => {});

	const settingsState: Record<string, unknown> = {
		enable_logging: true,
		execution_global_disabled: false,
		execution_provider_disabled: {},
		...(routes.actions?.settings ?? {})
	};

	await page.context().route('**/wp-json/sentient-forms/v1/**', (route) => {
		const url = route.request().url();
		const method = route.request().method();

		const formsMatch = url.match(/\/([^/]+)\/forms$/);
		if (routes.actions?.forms && formsMatch && method === 'GET') {
			const slug = formsMatch[1];
			const forms = routes.actions.forms[slug] ?? [];
			return route.fulfill({
				status: 200,
				headers: { 'content-type': 'application/json' },
				body: JSON.stringify(forms)
			});
		}

		if (routes.actions?.definitions && url.endsWith('/actions/definitions')) {
			return route.fulfill({
				status: 200,
				headers: { 'content-type': 'application/json' },
				body: JSON.stringify(routes.actions.definitions)
			});
		}

		if (url.endsWith('/settings') && method === 'GET') {
			return route.fulfill({
				status: 200,
				headers: { 'content-type': 'application/json' },
				body: JSON.stringify(settingsState)
			});
		}

		if (url.endsWith('/settings') && method === 'PUT') {
			const body = (route.request().postDataJSON() as Record<string, unknown>) ?? {};
			Object.assign(settingsState, body);
			return route.fulfill({
				status: 200,
				headers: { 'content-type': 'application/json' },
				body: JSON.stringify(settingsState)
			});
		}

		if (url.endsWith('/meta/capabilities')) {
			return route.fulfill({
				status: 200,
				headers: { 'content-type': 'application/json' },
				body: JSON.stringify({
					success: true,
					data: {
						features: [],
						form_sources: ['gravity_forms'],
						actions: ['spam_detection_v1']
					}
				})
			});
		}

		if (routes.actions?.creditBalance && url.endsWith('/credits/balance')) {
			return route.fulfill({
				status: 200,
				headers: { 'content-type': 'application/json' },
				body: JSON.stringify(routes.actions.creditBalance)
			});
		}

		if (routes.actions?.status && url.endsWith('/actions/status')) {
			return route.fulfill({
				status: 200,
				headers: { 'content-type': 'application/json' },
				body: JSON.stringify(routes.actions.status)
			});
		}

		if (routes.actions?.formsActions && /forms\/\d+\/actions$/.test(url) && method === 'GET') {
			return route.fulfill({
				status: 200,
				headers: { 'content-type': 'application/json' },
				body: JSON.stringify(routes.actions.formsActions)
			});
		}

		if (routes.actions?.formFields && /forms\/\d+\/actions\/fields$/.test(url) && method === 'GET') {
			return route.fulfill({
				status: 200,
				headers: { 'content-type': 'application/json' },
				body: JSON.stringify(routes.actions.formFields)
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
				body: JSON.stringify(newLinkage)
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
				body: JSON.stringify({
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
				body: JSON.stringify(merged)
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
					body: JSON.stringify(payload)
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
