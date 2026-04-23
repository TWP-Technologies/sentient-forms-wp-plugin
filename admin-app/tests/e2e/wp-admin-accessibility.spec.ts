import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Locator, type Page } from '@playwright/test';
import { requireWpRestHealthy } from './utils/wp-e2e-helpers';
import { ensureSentientFormsSpa, loginToWpAdmin } from './utils/wp-admin';

const runWpE2E = process.env.SENTIENT_RUN_WP_E2E === '1';

type RouteAudit = {
	name: string;
	path: string;
	ready: (page: Page) => Locator;
};

const routeAudits: RouteAudit[] = [
	{
		name: 'dashboard',
		path: '/dashboard',
		ready: (page) => page.getByTestId('dashboard-local-first-summary')
	},
	{
		name: 'providers',
		path: '/providers',
		ready: (page) => page.getByText('Validate OpenRouter key')
	},
	{
		name: 'actions overview',
		path: '/actions',
		ready: (page) => page.getByRole('heading', { name: 'Actions' })
	},
	{
		name: 'licensing',
		path: '/licensing',
		ready: (page) => page.getByRole('heading', { name: 'License management' })
	},
	{
		name: 'settings',
		path: '/settings',
		ready: (page) => page.getByRole('heading', { name: 'Privacy & Local Processing' })
	},
	{
		name: 'site context',
		path: '/settings/context',
		ready: (page) => page.getByRole('heading', { name: 'Site Context' })
	},
	{
		name: 'cutover',
		path: '/settings/migration',
		ready: (page) => page.getByRole('heading', { name: 'Local-First Cutover' })
	}
];

async function expectNoSeriousAxeViolations(page: Page, routeName: string): Promise<void> {
	const results = await new AxeBuilder({ page }).include('#sentient-forms-admin-app').analyze();
	const blockingViolations = results.violations
		.filter((violation) => ['serious', 'critical'].includes(violation.impact ?? ''))
		.map((violation) => ({
			id: violation.id,
			impact: violation.impact,
			nodes: violation.nodes.map((node) => ({
				target: node.target.join(' > '),
				summary: (node.failureSummary ?? '').replace(/\s+/g, ' ').trim()
			}))
		}));

	expect(blockingViolations, `${routeName} should not have serious or critical axe violations`).toEqual(
		[]
	);
}

test.describe('WP admin accessibility @wp-a11y', () => {
	test.skip(!runWpE2E, 'Set SENTIENT_RUN_WP_E2E=1 to exercise WordPress admin accessibility.');

	test.beforeEach(async ({ page }) => {
		await requireWpRestHealthy(page);
		await loginToWpAdmin(page);
	});

	for (const route of routeAudits) {
		test(`${route.name} has no serious or critical accessibility violations`, async ({ page }) => {
			await ensureSentientFormsSpa(page, route.path);
			await expect(route.ready(page)).toBeVisible();
			await expectNoSeriousAxeViolations(page, route.name);
		});
	}
});
