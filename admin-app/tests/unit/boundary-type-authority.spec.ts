import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import ts from 'typescript';
import { describe, expect, expectTypeOf, it } from 'vitest';
import type { EndpointName, RegisteredEndpointRequest } from '$lib/api/endpoint-schemas';

type MethodAuthority =
	| { key: EndpointName; mode: 'direct' }
	| { key: EndpointName; mode: 'transform'; transform: string };

const methodAuthority = {
	activateLicense: {
		key: 'license.activate',
		mode: 'transform',
		transform: 'LicenseActivationResult'
	},
	getLicenseInfo: { key: 'license.read', mode: 'direct' },
	deactivateLicense: { key: 'license.deactivate', mode: 'transform', transform: 'void' },
	bootstrapLicense: { key: 'license.bootstrap', mode: 'direct' },
	getBillingState: { key: 'billing.state', mode: 'direct' },
	createCheckoutSession: { key: 'billing.checkout.create', mode: 'direct' },
	startManagedCheckout: { key: 'billing.managedCheckout.start', mode: 'direct' },
	completeManagedCheckout: { key: 'billing.managedCheckout.complete', mode: 'direct' },
	createPortalSession: { key: 'billing.portal.create', mode: 'direct' },
	createTopUpCheckoutSession: { key: 'billing.topUp.create', mode: 'direct' },
	getTelemetrySettings: { key: 'telemetry.read', mode: 'direct' },
	updateTelemetrySettings: { key: 'telemetry.update', mode: 'direct' },
	getAsyncSettings: { key: 'asyncSettings.read', mode: 'direct' },
	getSettings: { key: 'settings.read', mode: 'direct' },
	updateSettings: {
		key: 'settings.update',
		mode: 'transform',
		transform: 'normalizeSettingsUpdate'
	},
	updateAsyncSettings: { key: 'asyncSettings.update', mode: 'direct' },
	getAsyncHealth: { key: 'asyncHealth.read', mode: 'direct' },
	purgeAsyncJobs: { key: 'asyncHealth.purge', mode: 'direct' },
	getLocalProviderCredentials: { key: 'providers.credentials.list', mode: 'direct' },
	deleteLocalProviderCredential: { key: 'providers.credentials.delete', mode: 'direct' },
	validateOpenRouterKey: { key: 'provider.openrouter.validate', mode: 'direct' },
	saveOpenRouterConstant: { key: 'provider.openrouter.constant', mode: 'direct' },
	setupSentientManagedProvider: { key: 'provider.sentientManaged.setup', mode: 'direct' },
	revokeSentientManagedProvider: { key: 'provider.sentientManaged.revoke', mode: 'direct' },
	getOpenRouterModels: { key: 'provider.openrouter.models', mode: 'direct' },
	refreshOpenRouterModels: { key: 'provider.openrouter.modelsRefresh', mode: 'direct' },
	getLocalActionTemplates: { key: 'local.actionTemplates.list', mode: 'direct' },
	getLocalCustomActions: { key: 'local.customActions.list', mode: 'direct' },
	createLocalCustomAction: { key: 'local.customActions.create', mode: 'direct' },
	getLocalFormMappings: { key: 'local.formMappings.list', mode: 'direct' },
	createLocalFormMapping: { key: 'local.formMappings.create', mode: 'direct' },
	getLocalExecutionEvents: { key: 'local.executionEvents.list', mode: 'direct' },
	getLeadProfile: { key: 'lead.profile.read', mode: 'direct' },
	saveLeadProfile: { key: 'lead.profile.save', mode: 'direct' },
	generateLeadProfile: { key: 'lead.profile.generate', mode: 'direct' },
	selfImproveLeadProfile: { key: 'lead.profile.selfImprove', mode: 'direct' },
	refreshLeadProfileAssistant: { key: 'lead.profile.assistant', mode: 'direct' },
	searchLeadValueEntries: { key: 'lead.entries.search', mode: 'direct' },
	searchSpamGuidanceEntries: { key: 'spamGuidance.entries.search', mode: 'direct' },
	appendSpamGuidanceExample: { key: 'spamGuidance.examples.append', mode: 'direct' },
	correctLeadScoringEntry: { key: 'lead.entry.correct', mode: 'direct' },
	generateLeadSuggestedReply: { key: 'lead.entry.suggestReply', mode: 'direct' },
	getLeadValueDashboard: { key: 'lead.dashboard.form', mode: 'direct' },
	getLeadScoringDashboard: { key: 'lead.dashboard.all', mode: 'direct' },
	importLeadProfile: { key: 'lead.profile.import', mode: 'direct' },
	listLeadValueHistoricalRuns: { key: 'lead.historicalRuns.list', mode: 'direct' },
	createLeadValueHistoricalRun: { key: 'lead.historicalRuns.create', mode: 'direct' },
	startLeadValueHistoricalRun: { key: 'lead.historicalRuns.start', mode: 'direct' },
	getLocalSupportBundle: { key: 'local.supportBundle.read', mode: 'direct' },
	getDashboardSummary: { key: 'dashboard.summary', mode: 'direct' },
	getLocalMigrationReadiness: { key: 'migration.readiness', mode: 'direct' },
	createLocalMigrationDryRun: { key: 'migration.dryRun', mode: 'direct' },
	createLocalMigrationImportDryRun: { key: 'migration.import.dryRun', mode: 'direct' },
	runLocalMigrationImportApply: { key: 'migration.import.apply', mode: 'direct' },
	runLocalMigrationApprovedReset: { key: 'migration.approvedReset', mode: 'direct' },
	getActionDefinitions: { key: 'actions.definitions', mode: 'direct' },
	getForms: { key: 'forms.list', mode: 'direct' },
	getFormsOverview: { key: 'forms.overview', mode: 'direct' },
	getFormActionsBootstrap: { key: 'forms.actions.bootstrap', mode: 'direct' },
	getSubmissionLedgerSettings: { key: 'forms.ledger.settings.read', mode: 'direct' },
	updateSubmissionLedgerSettings: { key: 'forms.ledger.settings.update', mode: 'direct' },
	getSubmissionLedgerRecords: { key: 'forms.ledger.records.list', mode: 'direct' },
	getSubmissionLedgerRecord: { key: 'forms.ledger.records.read', mode: 'direct' },
	getFormActions: { key: 'forms.actions.list', mode: 'direct' },
	checkActionCompatibility: { key: 'forms.actions.compatibility', mode: 'direct' },
	getWorkflowPlan: { key: 'forms.workflowPlan.read', mode: 'direct' },
	runRequestTrace: { key: 'forms.requestTrace.run', mode: 'direct' },
	getFormDisabled: { key: 'forms.disabled.read', mode: 'direct' },
	toggleFormDisabled: { key: 'forms.disabled.update', mode: 'direct' },
	getFormFields: { key: 'forms.fields.list', mode: 'direct' },
	getFormExecutionStatus: { key: 'forms.executionStatus.read', mode: 'direct' },
	getCapabilities: { key: 'meta.capabilities', mode: 'direct' },
	createFormAction: { key: 'forms.actions.create', mode: 'direct' },
	duplicateFormAction: { key: 'forms.actions.duplicate', mode: 'direct' },
	updateFormAction: { key: 'forms.actions.update', mode: 'direct' },
	deleteFormAction: { key: 'forms.actions.delete', mode: 'transform', transform: 'void' },
	getFormActionConfigs: {
		key: 'forms.actionConfigs.list',
		mode: 'transform',
		transform: 'extractConfigs'
	},
	getFormActionConfig: {
		key: 'forms.actionConfigs.read',
		mode: 'transform',
		transform: 'extractConfig'
	},
	updateFormActionConfig: {
		key: 'forms.actionConfigs.update',
		mode: 'transform',
		transform: 'extractConfig'
	},
	deleteFormActionConfig: {
		key: 'forms.actionConfigs.delete',
		mode: 'transform',
		transform: 'void'
	},
	getActionDefaults: {
		key: 'actions.defaults.read',
		mode: 'transform',
		transform: 'extractConfig'
	},
	getActionDefaultsBatch: {
		key: 'actions.defaults.batch',
		mode: 'transform',
		transform: 'mergeDefaultsBatches'
	},
	updateActionDefaults: {
		key: 'actions.defaults.update',
		mode: 'transform',
		transform: 'extractConfig'
	},
	getCustomActions: { key: 'customActions.list', mode: 'direct' },
	createCustomAction: { key: 'customActions.create', mode: 'direct' },
	updateCustomAction: { key: 'customActions.update', mode: 'direct' },
	archiveCustomAction: { key: 'customActions.archive', mode: 'direct' },
	reactivateCustomAction: { key: 'customActions.reactivate', mode: 'direct' },
	getExecutionStatus: { key: 'forms.entryExecutionStatus.read', mode: 'direct' }
} satisfies Record<string, MethodAuthority>;

function walk(node: ts.Node, visit: (child: ts.Node) => void): void {
	visit(node);
	node.forEachChild((child) => walk(child, visit));
}

describe('registered endpoint response type authority', () => {
	it('derives consent-bearing request literals from the endpoint registry', () => {
		expectTypeOf<
			RegisteredEndpointRequest<'billing.managedCheckout.start'>['accepted_managed_service_terms']
		>().toEqualTypeOf<true>();
		expectTypeOf<
			RegisteredEndpointRequest<'provider.openrouter.validate'>['accepted_external_service_terms']
		>().toEqualTypeOf<true>();
		expectTypeOf<
			RegisteredEndpointRequest<'provider.sentientManaged.setup'>['accepted_external_service_terms']
		>().toEqualTypeOf<true>();
		expectTypeOf<
			RegisteredEndpointRequest<'provider.sentientManaged.revoke'>['confirm_managed_service_revocation']
		>().toEqualTypeOf<true>();
	});

	it('keeps direct request aliases owned by their endpoint schemas', () => {
		const projectRoot = resolve(import.meta.dirname, '../..');
		const typesSource = readFileSync(resolve(projectRoot, 'src/lib/api/types.ts'), 'utf8');
		const sourceFile = ts.createSourceFile(
			'types.ts',
			typesSource,
			ts.ScriptTarget.Latest,
			true,
			ts.ScriptKind.TS
		);
		const aliases = new Map<string, string>();

		walk(sourceFile, (node) => {
			if (!ts.isTypeAliasDeclaration(node)) return;
			const match = node.type.getText(sourceFile).match(/^RegisteredEndpointRequest<'([^']+)'>$/u);
			if (match) aliases.set(node.name.text, match[1]);
		});

		expect(Object.fromEntries(aliases)).toMatchObject({
			BillingCheckoutSessionRequest: 'billing.checkout.create',
			TopUpCheckoutSessionRequest: 'billing.topUp.create',
			ManagedCheckoutStartRequest: 'billing.managedCheckout.start',
			ManagedCheckoutCompleteRequest: 'billing.managedCheckout.complete',
			BillingPortalSessionRequest: 'billing.portal.create',
			SiteContextUpdateRequest: 'siteContext.update',
			SiteContextGenerateRequest: 'siteContext.generate',
			SentientManagedSetupRequest: 'provider.sentientManaged.setup',
			SentientManagedRevokeRequest: 'provider.sentientManaged.revoke',
			OpenRouterValidateRequest: 'provider.openrouter.validate',
			OpenRouterConstantRequest: 'provider.openrouter.constant',
			OpenRouterModelsRefreshRequest: 'provider.openrouter.modelsRefresh',
			LocalMigrationImportRequest: 'migration.import.dryRun',
			LocalMigrationImportApplyRequest: 'migration.import.apply',
			LocalMigrationApprovedResetRequest: 'migration.approvedReset',
			DuplicateFormActionRequest: 'forms.actions.duplicate',
			RequestTraceRequest: 'forms.requestTrace.run'
		});
	});

	it('parses handwritten request view models through their registered endpoint contracts', () => {
		const expectedTransforms = {
			createLocalCustomAction: 'local.customActions.create',
			createLocalFormMapping: 'local.formMappings.create',
			saveLeadProfile: 'lead.profile.save',
			generateLeadProfile: 'lead.profile.generate',
			selfImproveLeadProfile: 'lead.profile.selfImprove',
			appendSpamGuidanceExample: 'spamGuidance.examples.append',
			correctLeadScoringEntry: 'lead.entry.correct',
			createLeadValueHistoricalRun: 'lead.historicalRuns.create',
			createFormAction: 'forms.actions.create',
			updateFormAction: 'forms.actions.update',
			createCustomAction: 'customActions.create',
			updateCustomAction: 'customActions.update'
		} as const;
		const projectRoot = resolve(import.meta.dirname, '../..');
		const clientSource = readFileSync(resolve(projectRoot, 'src/lib/api/client.ts'), 'utf8');
		const sourceFile = ts.createSourceFile(
			'client.ts',
			clientSource,
			ts.ScriptTarget.Latest,
			true,
			ts.ScriptKind.TS
		);
		const methodBodies = new Map<string, string>();

		walk(sourceFile, (node) => {
			if (!ts.isMethodDeclaration(node) || !node.name) return;
			methodBodies.set(node.name.getText(sourceFile), node.getText(sourceFile));
		});

		const missing = Object.entries(expectedTransforms).filter(([method, endpoint]) => {
			const body = methodBodies.get(method) ?? '';
			return !body.includes(`parseRegisteredEndpointRequest('${endpoint}', payload)`);
		});
		expect(missing).toEqual([]);
	});

	it('does not use handwritten response interfaces for direct registered methods', () => {
		const projectRoot = resolve(import.meta.dirname, '../..');
		const typesSource = readFileSync(resolve(projectRoot, 'src/lib/api/types.ts'), 'utf8');
		const clientSource = readFileSync(resolve(projectRoot, 'src/lib/api/client.ts'), 'utf8');
		const handwrittenResponses = new Set(
			[...typesSource.matchAll(/export interface (\w+Response)\b/gu)].map((match) => match[1])
		);
		const sourceFile = ts.createSourceFile(
			'client.ts',
			clientSource,
			ts.ScriptTarget.Latest,
			true,
			ts.ScriptKind.TS
		);
		const offenders: string[] = [];

		walk(sourceFile, (node) => {
			if (!ts.isMethodDeclaration(node) || !node.type || !node.name) return;
			let usesRegisteredEndpoint = false;
			walk(node, (child) => {
				if (
					ts.isCallExpression(child) &&
					ts.isPropertyAccessExpression(child.expression) &&
					child.expression.name.text === 'requestEndpoint'
				) {
					usesRegisteredEndpoint = true;
				}
			});
			if (!usesRegisteredEndpoint) return;

			const returnType = node.type.getText(sourceFile);
			const handwrittenType = [...handwrittenResponses].find((name) =>
				new RegExp(`\\b${name}\\b`, 'u').test(returnType)
			);
			if (handwrittenType) {
				offenders.push(`${node.name.getText(sourceFile)}:${handwrittenType}`);
			}
		});

		expect(offenders).toEqual([]);
	});

	it('exhaustively pairs every registered client method with exact raw or transform authority', () => {
		const projectRoot = resolve(import.meta.dirname, '../..');
		const typesSource = readFileSync(resolve(projectRoot, 'src/lib/api/types.ts'), 'utf8');
		const clientSource = readFileSync(resolve(projectRoot, 'src/lib/api/client.ts'), 'utf8');
		const typesFile = ts.createSourceFile(
			'types.ts',
			typesSource,
			ts.ScriptTarget.Latest,
			true,
			ts.ScriptKind.TS
		);
		const aliasKeys = new Map<string, string>();
		walk(typesFile, (node) => {
			if (!ts.isTypeAliasDeclaration(node)) return;
			const match = node.type.getText(typesFile).match(/^RegisteredEndpointResponse<'([^']+)'>$/u);
			if (match) aliasKeys.set(node.name.text, match[1]);
		});

		const clientFile = ts.createSourceFile(
			'client.ts',
			clientSource,
			ts.ScriptTarget.Latest,
			true,
			ts.ScriptKind.TS
		);
		const actualMethods = new Map<
			string,
			{ endpointKeys: Set<string>; derivedKeys: Set<string> }
		>();
		const duplicateMethods: string[] = [];
		walk(clientFile, (node) => {
			if (!ts.isMethodDeclaration(node) || !node.name) return;
			const endpointKeys = new Set<string>();
			walk(node, (child) => {
				if (
					ts.isCallExpression(child) &&
					ts.isPropertyAccessExpression(child.expression) &&
					child.expression.name.text === 'requestEndpoint' &&
					child.arguments[0] &&
					ts.isStringLiteral(child.arguments[0])
				) {
					endpointKeys.add(child.arguments[0].text);
				}
			});
			if (endpointKeys.size === 0) return;

			const methodName = node.name.getText(clientFile);
			if (actualMethods.has(methodName)) duplicateMethods.push(methodName);
			const authorityTexts = [node.type?.getText(clientFile) ?? ''];
			walk(node, (child) => {
				if (
					ts.isCallExpression(child) &&
					ts.isPropertyAccessExpression(child.expression) &&
					child.expression.name.text === 'unwrap' &&
					child.typeArguments
				) {
					authorityTexts.push(...child.typeArguments.map((item) => item.getText(clientFile)));
				}
			});
			const authorityText = authorityTexts.join(' ');
			const derivedKeys = new Set<string>();
			for (const match of node
				.getText(clientFile)
				.matchAll(/RegisteredEndpointResponse<'([^']+)'>/gu)) {
				derivedKeys.add(match[1]);
			}
			for (const match of authorityText.matchAll(/RegisteredEndpointResponse<'([^']+)'>/gu)) {
				derivedKeys.add(match[1]);
			}
			for (const [alias, key] of aliasKeys) {
				if (new RegExp(`\\b${alias}\\b`, 'u').test(authorityText)) derivedKeys.add(key);
			}

			actualMethods.set(methodName, { endpointKeys, derivedKeys });
		});

		const inventoryNames = Object.keys(methodAuthority).sort();
		expect(duplicateMethods).toEqual([]);
		expect([...actualMethods.keys()].sort()).toEqual(inventoryNames);

		const gaps: string[] = [];
		for (const methodName of inventoryNames) {
			const expected = methodAuthority[methodName as keyof typeof methodAuthority];
			const actual = actualMethods.get(methodName);
			if (!actual) {
				gaps.push(`${methodName}:missing-method`);
				continue;
			}
			if (actual.endpointKeys.size !== 1 || !actual.endpointKeys.has(expected.key)) {
				gaps.push(
					`${methodName}:endpoint=${[...actual.endpointKeys].join(',')} expected=${expected.key}`
				);
			}
			if (!actual.derivedKeys.has(expected.key)) {
				gaps.push(`${methodName}:zero-exact-authority expected=${expected.key}`);
			}
			if (
				expected.mode === 'direct' &&
				(actual.derivedKeys.size !== 1 || !actual.derivedKeys.has(expected.key))
			) {
				gaps.push(`${methodName}:direct-authority=${[...actual.derivedKeys].join(',')}`);
			}
			if (expected.mode === 'transform' && expected.transform.trim() === '') {
				gaps.push(`${methodName}:missing-transform-name`);
			}
		}

		expect(gaps).toEqual([]);
	});

	it('does not expose the retired form-mapping template library', () => {
		const projectRoot = resolve(import.meta.dirname, '../..');
		const componentPath = resolve(projectRoot, 'src/lib/components/ui/TemplateLibrary.svelte');
		const storePath = resolve(projectRoot, 'src/lib/stores/form-mappings.svelte.ts');
		const pageSource = readFileSync(
			resolve(projectRoot, 'src/routes/(app)/actions/[formSourceSlug]/[formId]/+page.svelte'),
			'utf8'
		);
		const clientSource = readFileSync(resolve(projectRoot, 'src/lib/api/client.ts'), 'utf8');
		const endpointSource = readFileSync(
			resolve(projectRoot, 'src/lib/api/endpoint-schemas.ts'),
			'utf8'
		);
		const typesSource = readFileSync(resolve(projectRoot, 'src/lib/api/types.ts'), 'utf8');
		const uiIndexSource = readFileSync(resolve(projectRoot, 'src/lib/components/ui/index.ts'), 'utf8');
		const mockSource = readFileSync(resolve(projectRoot, 'tests/e2e/utils/mock-wpjson.ts'), 'utf8');

		expect(pageSource).not.toContain('Import from Library');
		expect(pageSource).not.toContain('Save as Template');
		expect(pageSource).not.toContain('TemplateLibrary');
		for (const method of [
			'getFormMappings',
			'getFormMappingTemplates',
			'getFormMapping',
			'createFormMapping',
			'updateFormMapping',
			'deleteFormMapping',
			'cloneFormMappingTemplate'
		]) {
			expect(clientSource).not.toContain(method);
		}
		expect(clientSource).not.toContain("normalizedPath.startsWith('mappings')");
		for (const endpoint of [
			'mappings.list',
			'mappings.templates',
			'mappings.read',
			'mappings.create',
			'mappings.update',
			'mappings.delete',
			'mappings.clone'
		]) {
			expect(endpointSource).not.toContain(`'${endpoint}'`);
		}
		for (const legacyDeclaration of [
			'export interface MappingSettings',
			'export interface FormMapping',
			'export type CreateFormMappingRequest',
			'export type UpdateFormMappingRequest',
			'export type CloneTemplateMappingRequest'
		]) {
			expect(typesSource).not.toContain(legacyDeclaration);
		}
		expect(uiIndexSource).not.toContain('TemplateLibrary');
		expect(mockSource).not.toContain('mappingTemplates');
		expect(mockSource).not.toContain('/mappings/templates');
		expect(existsSync(componentPath)).toBe(false);
		expect(existsSync(storePath)).toBe(false);
	});
});
