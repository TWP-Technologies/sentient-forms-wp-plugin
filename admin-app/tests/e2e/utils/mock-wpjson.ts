import { Page } from '@playwright/test';

type Routes = {
	actions?: {
		forms?: Record<string, unknown[]>;
		definitions?: unknown;
		status?: unknown;
		formsActions?: unknown[];
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
