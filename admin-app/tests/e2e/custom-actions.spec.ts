import { test, expect } from '@playwright/test';
import { seedRuntimeConfig } from './utils/runtime-config';
import { expectAppUrl } from './utils/app-navigation';

test.describe('Custom actions admin view', () => {
	test.beforeEach(async ({ page }) => {
		const wpHost = process.env.SENTIENT_WP_BASE_URL ?? 'http://localhost:8080';
		await seedRuntimeConfig(page, { apiBaseUrl: `${wpHost}/wp-json/sentient-forms/v1/` });
	});

	test('lists, creates, archives, and reactivates custom actions', async ({ page }) => {
		let actions = [
			{
				id: 'action-alpha',
				template_id: 'tmpl-alpha',
				code: 'alpha',
				display_name: 'Alpha action',
				description: null,
				prompt_overrides: {},
				model_hint: null,
				model_selection: {
					primary: 'sf_default',
					is_preset: true,
					provider: 'openrouter',
					tools: {
						tool_choice: 'auto',
						web_search: {
							mode: 'auto',
							max_results: 3
						},
						datetime: {
							mode: 'auto'
						}
					}
				},
				base_credit_cost: 10,
				status: 'active',
				archived_at: null,
				created_at: '2025-11-10T00:00:00Z',
				updated_at: '2025-11-14T12:00:00Z',
				action_kind: 'template_override',
				definition: null,
				definition_version: 1,
				output_contract: null,
				supported_execution_modes: ['after_submission']
			}
		];

		const quotaMax = 5;
		let lastCreatePayload: Record<string, unknown> | null = null;

		function quotaSummary() {
			const activeCount = actions.filter((action) => action.status === 'active').length;
			return {
				quota_max: quotaMax,
				quota_used: activeCount,
				quota_remaining: Math.max(0, quotaMax - activeCount)
			};
		}

		await page.context().route('**/wp-json/sentient-forms/v1/**', async (route) => {
			const url = route.request().url();
			const method = route.request().method();

			if (method === 'GET' && url.includes('/meta/capabilities')) {
				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify({
						success: true,
						data: {
							supports_custom_actions: true,
							cps_version: '1.2.0'
						}
					})
				});
			}

			if (method === 'GET' && url.includes('/actions/definitions')) {
				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify([
						{
							id: 'spam_detection_v1',
							templateId: '11111111-1111-4111-8111-111111111111',
							code: 'spam_detection_v1',
							label: 'Spam Detection',
							description: 'Detects spam submissions',
							form_sources: ['gravity_forms'],
							baseCreditCost: 10,
							modelHint: 'openai/gpt-5.5',
							source: 'cps'
						}
					])
				});
			}

			if (method === 'GET' && url.includes('/local/providers/credentials')) {
				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify({
						success: true,
						data: [
							{
								id: 1,
								provider: 'openrouter',
								label: 'OpenRouter test key',
								auth_mode: 'constant',
								constant_name: 'SENTIENT_FORMS_OPENROUTER_API_KEY',
								status: 'valid',
								status_json: null,
								last_validated_at: '2026-05-01T12:00:00Z',
								created_at: '2026-05-01T12:00:00Z',
								updated_at: '2026-05-01T12:00:00Z',
								secret_configured: true
							}
						]
					})
				});
			}

			if (method === 'GET' && url.endsWith('/models')) {
				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify({
						success: true,
						data: {
							models: [
								{
									id: 'openai/gpt-5.5',
									display_name: 'OpenAI: GPT-5.5',
									provider: 'openrouter',
									speed_tier: 'balanced',
									cost_tier: 'medium',
									category_rankings: {
										legal: 1,
										finance: 1,
										programming: 3
									},
									capabilities: {
										reasoning: true,
										code: false,
										vision: true,
										tools: true,
										structured: true,
										web_search: true,
										server_tools: {
											web_search: true,
											web_fetch: false,
											datetime: true
										},
										long_context: true
									},
									context_window: 400000,
									is_preview: false,
									tags: ['structured-output', 'reasoning'],
									recommended_for: ['General purpose']
								},
								{
									id: 'deepseek/deepseek-r1-0528',
									display_name: 'DeepSeek: R1 0528',
									provider: 'openrouter',
									speed_tier: 'balanced',
									cost_tier: 'low',
									category_rankings: {
										programming: 1,
										science: 2,
										technology: 3
									},
									capabilities: {
										reasoning: true,
										code: true,
										vision: false,
										tools: true,
										structured: true,
										web_search: false,
										long_context: true
									},
									context_window: 128000,
									is_preview: false,
									tags: ['coding', 'reasoning'],
									recommended_for: ['Programming', 'Science']
								},
								{
									id: 'openrouter/free',
									display_name: 'OpenRouter Free Models Router',
									provider: 'openrouter',
									speed_tier: 'fast',
									cost_tier: 'free',
									category_rankings: {
										programming: 20,
										trivia: 8
									},
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
									tags: ['free', 'structured-output'],
									recommended_for: ['Free testing']
								},
								{
									id: 'openai/gpt-oss-20b:free',
									display_name: 'OpenAI GPT OSS 20B Free',
									provider: 'openrouter',
									speed_tier: 'fast',
									cost_tier: 'free',
									category_rankings: {
										programming: 2
									},
									capabilities: {
										reasoning: false,
										code: false,
										vision: false,
										tools: false,
										structured: false,
										web_search: false,
										long_context: true
									},
									context_window: 131072,
									is_preview: false,
									tags: ['free'],
									recommended_for: ['Cheap smoke tests']
								}
							],
							presets: [
								{
									code: 'sf_default',
									display_name: 'Recommended',
									description: 'Recommended paid model.',
									category: 'local',
									resolved_model_id: 'openai/gpt-5.5',
									auto_upgrade: true,
									rationale:
										'Default favors broad benchmark strength, structured/tool support, and production-stable paid routing.',
									score: 92,
									evidence_confidence: 'high',
									evaluated_at: '2026-05-01',
									score_breakdown: { category_fit: 93, operations: 86, availability: 95 },
									top_candidates: [
										{
											model_id: 'openai/gpt-5.5',
											score: 92,
											notes: 'Best broad default among current cached paid candidates.'
										}
									],
									source_urls: [
										'https://artificialanalysis.ai/models',
										'https://openrouter.ai/models'
									]
								},
								{
									code: 'sf_long_context',
									display_name: 'Long context',
									description:
										'Prefers models with benchmark-backed long-document reasoning, not just the largest advertised context window.',
									category: 'local',
									resolved_model_id: 'openai/gpt-5.5',
									auto_upgrade: true,
									rationale:
										'Long context prioritizes effective long-document reasoning and context-rot resistance.',
									score: 93,
									evidence_confidence: 'high',
									evaluated_at: '2026-05-01',
									score_breakdown: { category_fit: 95, operations: 82, availability: 94 },
									top_candidates: [
										{
											model_id: 'openai/gpt-5.5',
											score: 93,
											notes: 'AA-LCR leader among current selector candidates.'
										}
									],
									source_urls: [
										'https://artificialanalysis.ai/evaluations/artificial-analysis-long-context-reasoning',
										'https://llm-stats.com/leaderboards/best-ai-for-long-context'
									]
								},
								{
									code: 'sf_free',
									display_name: 'Free model',
									description: 'Free workflow proof.',
									category: 'local',
									resolved_model_id: 'openrouter/free',
									auto_upgrade: true
								},
								...Array.from({ length: 12 }, (_, index) => ({
									code: `sf_extra_${index}`,
									display_name: `Specialized preset ${index + 1}`,
									description: `Scrollable recommended preset ${index + 1}.`,
									category: 'local',
									resolved_model_id:
										index % 2 === 0 ? 'openai/gpt-5.5' : 'deepseek/deepseek-r1-0528',
									auto_upgrade: true
								}))
							],
							pricing_policy_version: 'mock'
						}
					})
				});
			}

			if (
				method === 'POST' &&
				(url.endsWith('/models/resolve') || url.endsWith('/models/estimate'))
			) {
				const payload = (route.request().postDataJSON() as Record<string, unknown>) ?? {};
				const actionSelection = payload.action_selection as Record<string, unknown> | undefined;
				const primary = String(actionSelection?.primary ?? 'sf_default');
				const resolvedModelId = primary === 'sf_free' ? 'openrouter/free' : 'openai/gpt-5.5';
				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify({
						success: true,
						data: url.endsWith('/models/resolve')
							? {
									model_id: resolvedModelId,
									display_name: resolvedModelId,
									resolution_source: 'action',
									override_chain: [],
									backup_model_id: null
								}
							: {
									resolved_model: {
										model_id: resolvedModelId,
										display_name: resolvedModelId,
										resolution_source: 'action',
										override_chain: [],
										backup_model_id: null
									},
									pricing_estimate: {
										action_id: String(payload.action_id ?? 'custom'),
										resolved_model_id: resolvedModelId,
										route: 'openrouter',
										kind:
											resolvedModelId === 'openrouter/free'
												? 'openrouter_free'
												: 'openrouter_currency',
										label:
											resolvedModelId === 'openrouter/free' ? 'OR est. $0.00' : 'OR est. $0.01',
										amount_usd: resolvedModelId === 'openrouter/free' ? 0 : 0.01,
										estimate_range: {
											low: 0,
											high: resolvedModelId === 'openrouter/free' ? 0 : 0.02,
											currency: 'USD',
											unit: 'usd'
										},
										estimated_input_tokens: 1900,
										estimated_output_tokens: 320,
										estimated_reasoning_tokens: 0,
										sample_count: 0,
										confidence: 'baseline',
										calibration_source: 'baseline_profile',
										base_floor_credits: 0,
										normalized_actual_credits: 0,
										estimated_debit_credits: 0,
										pricing_policy_version: 'mock',
										estimate_source: 'baseline_profile'
									}
								}
					})
				});
			}

			if (url.includes('/custom-actions')) {
				const reactivateMatch = url.match(/custom-actions\/([^/?]+)\/reactivate$/);
				const actionMatch = url.match(/custom-actions\/([^/?]+)$/);

				if (method === 'GET') {
					return route.fulfill({
						status: 200,
						headers: { 'content-type': 'application/json' },
						body: JSON.stringify({
							actions,
							quota: quotaSummary()
						})
					});
				}

				if (method === 'POST' && !reactivateMatch) {
					const payload = (route.request().postDataJSON() as Record<string, unknown>) ?? {};
					lastCreatePayload = payload;
					const now = new Date().toISOString();
					const newAction = {
						id: `action-${Date.now()}`,
						template_id: String(payload.template_id ?? ''),
						code: String(payload.code ?? ''),
						display_name: String(payload.display_name ?? ''),
						description: (payload.description as string | null | undefined) ?? null,
						prompt_overrides:
							(payload.prompt_overrides as Record<string, unknown> | undefined) ?? {},
						model_hint: (payload.model_hint as string | null | undefined) ?? null,
						model_selection:
							(payload.model_selection as Record<string, unknown> | null | undefined) ?? null,
						base_credit_cost: null,
						status: 'active',
						archived_at: null,
						created_at: now,
						updated_at: now,
						action_kind: String(payload.action_kind ?? 'template_override'),
						definition: (payload.definition as Record<string, unknown> | null | undefined) ?? null,
						definition_version: Number(payload.definition_version ?? 1),
						output_contract:
							(payload.output_contract as Record<string, unknown> | null | undefined) ?? null,
						supported_execution_modes: (payload.supported_execution_modes as
							| string[]
							| undefined) ?? ['after_submission']
					};
					actions = [newAction, ...actions];

					return route.fulfill({
						status: 201,
						headers: { 'content-type': 'application/json' },
						body: JSON.stringify({
							action: newAction,
							quota: quotaSummary()
						})
					});
				}

				if (method === 'DELETE' && actionMatch) {
					const id = actionMatch[1];
					actions = actions.map((action) =>
						action.id === id
							? {
									...action,
									status: 'archived',
									archived_at: new Date().toISOString(),
									updated_at: new Date().toISOString()
								}
							: action
					);

					const archived = actions.find((action) => action.id === id)!;
					return route.fulfill({
						status: 200,
						headers: { 'content-type': 'application/json' },
						body: JSON.stringify({
							action: archived,
							quota: quotaSummary()
						})
					});
				}

				if (method === 'POST' && reactivateMatch) {
					const id = reactivateMatch[1];
					actions = actions.map((action) =>
						action.id === id
							? {
									...action,
									status: 'active',
									archived_at: null,
									updated_at: new Date().toISOString()
								}
							: action
					);

					const reactivated = actions.find((action) => action.id === id)!;
					return route.fulfill({
						status: 200,
						headers: { 'content-type': 'application/json' },
						body: JSON.stringify({
							action: reactivated,
							quota: quotaSummary()
						})
					});
				}
			}

			return route.continue();
		});

		await page.goto('/#/actions/custom', { waitUntil: 'networkidle' });
		await page.waitForFunction(() => document.body.textContent?.includes('Custom Actions'));
		await expect(page.getByRole('heading', { name: 'Custom Actions' })).toBeVisible();

		let tableRows = page.getByTestId('custom-actions-table').locator('tbody tr');
		await expect(tableRows).toHaveCount(1);

		await tableRows.first().getByRole('button', { name: 'Edit' }).click();
		await expectAppUrl(page, '/actions/custom/action-alpha');
		const editForm = page.getByTestId('custom-action-form');
		await editForm.getByTestId('model-selector-open').click();
		await expect(page.getByLabel('Tool choice', { exact: true })).toHaveValue('auto');
		await expect(page.getByLabel('Web search', { exact: true })).toHaveValue('auto');
		await expect(page.getByLabel('Search results per call', { exact: true })).toHaveValue('3');
		await expect(page.getByLabel('Current date/time', { exact: true })).toHaveValue('auto');
		await page.getByTestId('model-selector-tab-models').click();
		await expect(page.getByTestId('model-selector-catalog')).toBeVisible();
		await page.getByTestId('model-selector-tab-custom').click();
		await expect(page.getByTestId('model-custom-input')).toBeVisible();
		await page.getByTestId('model-selector-tab-presets').click();
		await page.getByTestId('model-selector-close').click();
		await page.getByRole('button', { name: /Back to List/i }).click();
		await expectAppUrl(page, '/actions/custom');
		tableRows = page.getByTestId('custom-actions-table').locator('tbody tr');
		await expect(tableRows).toHaveCount(1);

		await page.getByRole('button', { name: /Create Action/i }).click();
		await expectAppUrl(page, '/actions/custom/new');

		const createForm = page.getByTestId('custom-action-form');
		await expect(createForm.getByLabel('Template ID')).toHaveCount(0);
		await expect(createForm.getByLabel('Code')).toHaveCount(0);
		await expect(createForm.getByTestId('model-selector')).toBeVisible();
		await createForm.getByTestId('model-selector-open').click();
		await expect(page.getByTestId('model-selector-dialog')).toBeVisible();
		await expect(
			page.getByTestId('model-selector-dialog').getByLabel('Reasoning effort')
		).toBeVisible();
		const presetRanking = page.getByTestId('model-preset-top-candidates');
		await expect(presetRanking).toContainText('Recommendation ranking');
		await expect(presetRanking).toContainText('92/100');
		await expect(page.getByTestId('model-preset-sf_long_context')).toContainText(
			'not just the largest advertised context window'
		);
		await page.getByTestId('model-preset-sf_long_context').hover();
		await expect(presetRanking).toContainText('93/100');
		await page.setViewportSize({ width: 1024, height: 768 });
		const shellHeight = await page
			.getByTestId('model-selector-dialog')
			.locator('> div')
			.first()
			.boundingBox();
		expect(shellHeight).not.toBeNull();
		expect(shellHeight?.height ?? 0).toBeGreaterThanOrEqual(768 * 0.9 - 2);
		expect(shellHeight?.height ?? 0).toBeLessThanOrEqual(768 * 0.95 + 2);
		const presetsScroll = page.getByTestId('model-selector-presets').locator('..');
		await presetsScroll.evaluate((element) => {
			element.scrollTop = element.scrollHeight;
		});
		await expect(page.getByTestId('model-preset-sf_extra_11')).toBeVisible();
		await page.getByTestId('model-selector-tab-models').click();
		await expect(page.getByTestId('model-selector-catalog')).toBeVisible();
		await expect(
			page.getByTestId('model-selector-catalog').getByLabel('Search models')
		).toBeVisible();
		await expect(page.getByTestId('model-selector-detail')).toBeVisible();
		await expect(page.getByTestId('model-selector-advanced-filters-panel')).toHaveCount(0);
		await expect(page.getByTestId('model-row-openai/gpt-5.5')).toBeVisible();
		await expect(
			page
				.getByTestId('model-row-openai/gpt-5.5')
				.getByText(/Selected|Use model/)
				.first()
		).toBeVisible();
		const dialogHeightBefore = await page.getByTestId('model-selector-dialog').boundingBox();
		await page.getByTestId('model-row-openai/gpt-5.5').hover();
		await page.getByTestId('model-row-deepseek/deepseek-r1-0528').hover();
		const dialogHeightAfter = await page.getByTestId('model-selector-dialog').boundingBox();
		expect(dialogHeightBefore).not.toBeNull();
		expect(dialogHeightAfter).not.toBeNull();
		expect(
			Math.abs((dialogHeightBefore?.height ?? 0) - (dialogHeightAfter?.height ?? 0))
		).toBeLessThanOrEqual(2);
		await page.getByTestId('model-selector-advanced-filters-toggle').click();
		await expect(page.getByTestId('model-selector-advanced-filters-panel')).toBeVisible();
		await expect(page.getByTestId('model-category-filter')).toBeVisible();
		await expect(page.getByTestId('model-rank-filter')).toBeVisible();
		await expect(page.getByTestId('model-selector-catalog').getByLabel('Sort')).toBeVisible();
		await page.getByTestId('model-category-filter').selectOption('programming');
		await page.getByTestId('model-rank-filter').selectOption('3');
		await page.getByTestId('model-selector-advanced-filters-toggle').click();
		await expect(page.getByTestId('model-selector-advanced-filters-panel')).toHaveCount(0);
		await expect(page.getByTestId('model-row-deepseek/deepseek-r1-0528')).toBeVisible();
		await expect(
			page.getByTestId('model-selector-detail').getByText('#1 Programming')
		).toBeVisible();
		await page.getByTestId('model-selector-tab-custom').click();
		await expect(page.getByTestId('model-custom-input')).toBeVisible();
		await page.getByTestId('model-custom-input').fill('provider/new-non-catalog-model');
		await expect(
			page.getByTestId('model-selector-dialog').getByText(/matches a cached OpenRouter model/)
		).toBeVisible();
		await expect(
			page.getByTestId('model-selector-dialog').getByLabel('Reasoning effort')
		).toHaveCount(0);
		await page.getByTestId('model-custom-input').fill('openai/gpt-5.5');
		await expect(
			page.getByTestId('model-selector-dialog').getByLabel('Reasoning effort')
		).toBeVisible();
		await page.getByTestId('model-selector-tab-models').click();
		await page.getByTestId('model-row-openai/gpt-oss-20b:free').click();
		await expect(createForm.getByTestId('model-reasoning-summary')).toHaveCount(0);
		await createForm.getByTestId('model-selector-open').click();
		await page.getByTestId('model-selector-tab-models').click();
		await page.getByTestId('model-row-openai/gpt-5.5').click();
		await expect(createForm.getByTestId('model-reasoning-summary')).toBeVisible();
		await expect(createForm.getByTestId('reasoning-effort-rail')).toBeVisible();
		await expect(createForm.getByTestId('reasoning-effort-default')).toHaveAttribute(
			'aria-pressed',
			'true'
		);
		const summaryBox = await createForm.getByTestId('model-selector-summary').boundingBox();
		const railBox = await createForm.getByTestId('model-reasoning-summary').boundingBox();
		expect(summaryBox).not.toBeNull();
		expect(railBox).not.toBeNull();
		expect(railBox?.width ?? 0).toBeGreaterThan((summaryBox?.width ?? 0) * 0.95);
		await page.emulateMedia({ reducedMotion: 'no-preference' });
		await createForm.getByTestId('reasoning-effort-default').hover();
		await expect(createForm.getByTestId('reasoning-effort-default')).toHaveCSS(
			'color',
			'rgb(255, 255, 255)'
		);
		await expect(createForm.getByTestId('reasoning-effort-default')).not.toHaveCSS(
			'animation-name',
			'none'
		);
		await page.emulateMedia({ reducedMotion: 'reduce' });
		await expect(createForm.getByTestId('reasoning-effort-default')).toHaveCSS(
			'animation-name',
			'none'
		);
		await page.emulateMedia({ reducedMotion: 'no-preference' });

		for (const [effort, progress] of [
			['none', 0],
			['minimal', 0.2],
			['low', 0.4],
			['medium', 0.6],
			['high', 0.8],
			['xhigh', 1]
		] as const) {
			await createForm.getByTestId(`reasoning-effort-${effort}`).click();
			await expect(createForm.getByTestId(`reasoning-effort-${effort}`)).toHaveAttribute(
				'aria-pressed',
				'true'
			);
			const geometry = await createForm
				.getByTestId('reasoning-effort-rail')
				.evaluate((rail, activeEffort) => {
					const track = rail.querySelector<HTMLElement>('[data-testid="reasoning-effort-track"]');
					const dot = rail.querySelector<HTMLElement>(
						`[data-testid="reasoning-effort-${activeEffort}"] .sf-reasoning-rail__dot`
					);
					if (!track || !dot) {
						throw new Error(`Missing rail geometry element for ${activeEffort}`);
					}
					const trackRect = track.getBoundingClientRect();
					const dotRect = dot.getBoundingClientRect();
					return {
						trackLeft: trackRect.left,
						trackRight: trackRect.right,
						trackWidth: trackRect.width,
						dotCenter: dotRect.left + dotRect.width / 2
					};
				}, effort);
			const fillEnd = geometry.trackLeft + geometry.trackWidth * progress;
			expect(Math.abs(geometry.dotCenter - fillEnd)).toBeLessThanOrEqual(2);
			if (effort === 'none') {
				expect(Math.abs(geometry.dotCenter - geometry.trackLeft)).toBeLessThanOrEqual(2);
			}
			if (effort === 'xhigh') {
				expect(Math.abs(geometry.dotCenter - geometry.trackRight)).toBeLessThanOrEqual(2);
			}
		}

		const modelToolRects = await createForm
			.getByTestId('model-tools-layout')
			.evaluate((layout) =>
				Array.from(layout.querySelectorAll<HTMLElement>('.sf-model-tools-select')).map((select) => {
					const rect = select.getBoundingClientRect();
					return {
						left: rect.left,
						right: rect.right,
						width: rect.width
					};
				})
			);
		expect(modelToolRects).toHaveLength(4);
		expect(Math.abs(modelToolRects[0].left - modelToolRects[2].left)).toBeLessThanOrEqual(2);
		expect(Math.abs(modelToolRects[0].right - modelToolRects[2].right)).toBeLessThanOrEqual(2);
		expect(Math.abs(modelToolRects[1].left - modelToolRects[3].left)).toBeLessThanOrEqual(2);
		expect(Math.abs(modelToolRects[1].right - modelToolRects[3].right)).toBeLessThanOrEqual(2);
		expect(
			Math.max(...modelToolRects.map((rect) => rect.width)) -
				Math.min(...modelToolRects.map((rect) => rect.width))
		).toBeLessThanOrEqual(2);

		await createForm.getByTestId('reasoning-effort-medium').click();
		await expect(createForm.getByTestId('reasoning-effort-medium')).toHaveAttribute(
			'aria-pressed',
			'true'
		);
		await expect(page.getByTestId('model-selector-dialog')).toHaveCount(0);
		await createForm.getByLabel('Display Name').fill('Beta action');
		await expect(createForm.getByTestId('custom-action-generated-code')).toContainText(
			'beta-action'
		);
		await createForm
			.getByLabel('Custom Instructions')
			.fill('Write a direct, demo-ready follow-up summary.');
		await createForm.getByRole('switch', { name: 'Trigger a WordPress hook' }).click();
		await createForm.getByRole('button', { name: 'Create Action' }).click();

		await expectAppUrl(page, '/actions/custom');
		expect(lastCreatePayload).toMatchObject({
			template_id: null,
			code: 'beta-action',
			action_kind: 'custom_definition',
			model_selection: {
				primary: 'openai/gpt-5.5',
				is_preset: false,
				reasoning: 'medium'
			},
			prompt_overrides: {
				custom_instructions: 'Write a direct, demo-ready follow-up summary.'
			}
		});
		expect(
			(lastCreatePayload?.definition as Record<string, unknown> | undefined)?.prompt_template
		).not.toContain('Custom webmaster instructions');
		expect(
			(
				(lastCreatePayload?.definition as Record<string, unknown> | undefined)
					?.execution_defaults as Record<string, unknown> | undefined
			)?.post_execution_actions
		).toEqual(
			expect.arrayContaining([
				expect.objectContaining({ type: 'entry_note' }),
				expect.objectContaining({
					type: 'wp_hook',
					hook_name: 'sentient_forms_custom_action_completed'
				})
			])
		);
		tableRows = page.getByTestId('custom-actions-table').locator('tbody tr');

		await expect(tableRows).toHaveCount(2);

		const firstRow = tableRows.first();
		await firstRow.getByRole('button', { name: 'Archive' }).click();
		await expect(firstRow.getByText('archived')).toBeVisible();

		await firstRow.getByRole('button', { name: 'Reactivate' }).click();
		await expect(firstRow.getByText('active')).toBeVisible();
	});

	test('renders without crashing when custom-action timestamps are invalid', async ({ page }) => {
		const actions = [
			{
				id: 'valid-new',
				template_id: 'tmpl-valid',
				code: 'valid-new',
				display_name: 'Valid New',
				description: null,
				prompt_overrides: {},
				model_hint: null,
				base_credit_cost: 10,
				status: 'active',
				archived_at: null,
				created_at: '2026-02-23T12:00:00Z',
				updated_at: '2026-02-23T12:00:00Z',
				action_kind: 'template_override',
				definition: null,
				definition_version: 1,
				output_contract: null,
				supported_execution_modes: ['after_submission']
			},
			{
				id: 'invalid-time',
				template_id: 'tmpl-invalid',
				code: 'invalid-time',
				display_name: 'Invalid Time',
				description: null,
				prompt_overrides: {},
				model_hint: null,
				base_credit_cost: 10,
				status: 'active',
				archived_at: null,
				created_at: '2026-02-23T11:00:00Z',
				updated_at: 'Array',
				action_kind: 'template_override',
				definition: null,
				definition_version: 1,
				output_contract: null,
				supported_execution_modes: ['after_submission']
			}
		];

		const pageErrors: string[] = [];
		page.on('pageerror', (error) => pageErrors.push(error.message));

		await page.context().route('**/wp-json/sentient-forms/v1/**', async (route) => {
			const url = route.request().url();
			const method = route.request().method();

			if (method === 'GET' && url.includes('/meta/capabilities')) {
				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify({
						success: true,
						data: {
							supports_custom_actions: true,
							cps_version: '1.2.0'
						}
					})
				});
			}

			if (method === 'GET' && url.includes('/actions/definitions')) {
				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify([])
				});
			}

			if (method === 'GET' && url.includes('/custom-actions')) {
				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify({
						actions,
						quota: { quota_max: 5, quota_used: 2, quota_remaining: 3 }
					})
				});
			}

			return route.continue();
		});

		await page.goto('/#/actions/custom', { waitUntil: 'networkidle' });
		await page.waitForFunction(() => document.body.textContent?.includes('Custom Actions'));

		await expect(page.getByRole('heading', { name: 'Custom Actions' })).toBeVisible();
		await expect(page.getByText('This view hit an error')).toHaveCount(0);
		expect(pageErrors).toEqual([]);

		const rows = page.getByTestId('custom-actions-table').locator('tbody tr');
		await expect(rows).toHaveCount(2);
		await expect(rows.nth(0)).toContainText('valid-new');

		const invalidRow = rows.filter({ hasText: 'invalid-time' });
		await expect(invalidRow.locator('td').nth(5)).toContainText('—');
	});

	test('shows shared empty state template when no custom actions exist', async ({ page }) => {
		await page.context().route('**/wp-json/sentient-forms/v1/**', async (route) => {
			const url = route.request().url();
			const method = route.request().method();

			if (method === 'GET' && url.includes('/meta/capabilities')) {
				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify({
						success: true,
						data: {
							supports_custom_actions: true,
							cps_version: '1.2.0'
						}
					})
				});
			}

			if (method === 'GET' && url.includes('/actions/definitions')) {
				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify([])
				});
			}

			if (method === 'GET' && url.includes('/custom-actions')) {
				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify({
						actions: [],
						quota: { quota_max: 5, quota_used: 0, quota_remaining: 5 }
					})
				});
			}

			return route.continue();
		});

		await page.goto('/#/actions/custom', { waitUntil: 'networkidle' });
		await expect(page.getByRole('heading', { name: 'Custom Actions' })).toBeVisible();
		await expect(page.getByTestId('custom-actions-empty-state')).toBeVisible();
		await expect(page.getByRole('button', { name: /create your first action/i })).toBeVisible();
	});
});
