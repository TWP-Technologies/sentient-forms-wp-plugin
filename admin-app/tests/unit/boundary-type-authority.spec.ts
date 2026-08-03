import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import ts from 'typescript';
import { describe, expect, expectTypeOf, it } from 'vitest';
import type { EndpointName, RegisteredEndpointRequest } from '$lib/api/endpoint-schemas';

type MethodAuthority =
	| { key: EndpointName; mode: 'direct'; preBoundaryFallbackGuard?: string }
	| {
			key: EndpointName;
			mode: 'transform';
			transform:
				| {
						kind: 'return';
						members: readonly (
							| string
							| {
									member: string;
									targetProperty?: string;
									acceptedPaths?: readonly (readonly string[])[];
									allowNeutralFallback?: boolean;
							  }
						)[];
				  }
				| { kind: 'void'; identifier: string }
				| { kind: 'accumulator'; identifier: string; sourceMember: string };
	  };

const methodAuthority = {
	activateLicense: {
		key: 'license.activate',
		mode: 'transform',
		transform: {
			kind: 'return',
			members: [
				'status',
				{
					member: 'tier',
					acceptedPaths: [['tier'], ['tier', 'code']]
				},
				{ member: 'expires_at', targetProperty: 'expiryDate' },
				{
					member: 'license_id',
					targetProperty: 'licenseId',
					allowNeutralFallback: true
				},
				{
					member: 'site_id',
					targetProperty: 'siteId',
					allowNeutralFallback: true
				}
			]
		}
	},
	getLicenseInfo: { key: 'license.read', mode: 'direct' },
	deactivateLicense: {
		key: 'license.deactivate',
		mode: 'transform',
		transform: { kind: 'void', identifier: 'response' }
	},
	bootstrapLicense: { key: 'license.bootstrap', mode: 'direct' },
	getBillingState: { key: 'billing.state', mode: 'direct' },
	createCheckoutSession: { key: 'billing.checkout.create', mode: 'direct' },
	startManagedCheckout: { key: 'billing.managedCheckout.start', mode: 'direct' },
	completeManagedCheckout: { key: 'billing.managedCheckout.complete', mode: 'direct' },
	createPortalSession: { key: 'billing.portal.create', mode: 'direct' },
	createTopUpCheckoutSession: { key: 'billing.topUp.create', mode: 'direct' },
	getLocalDiagnosticsSettings: { key: 'telemetry.read', mode: 'direct' },
	updateLocalDiagnosticsSettings: { key: 'telemetry.update', mode: 'direct' },
	getAsyncSettings: { key: 'asyncSettings.read', mode: 'direct' },
	getSettings: { key: 'settings.read', mode: 'direct' },
	updateSettings: {
		key: 'settings.update',
		mode: 'transform',
		transform: {
			kind: 'return',
			members: [{ member: 'settings', acceptedPaths: [['settings'], []] }]
		}
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
	getFormActionsBootstrap: {
		key: 'forms.actions.bootstrap',
		mode: 'direct',
		preBoundaryFallbackGuard: 'isInvalidFormSourceContext'
	},
	getSubmissionLedgerSettings: { key: 'forms.ledger.settings.read', mode: 'direct' },
	updateSubmissionLedgerSettings: { key: 'forms.ledger.settings.update', mode: 'direct' },
	getSubmissionLedgerRecords: { key: 'forms.ledger.records.list', mode: 'direct' },
	getSubmissionLedgerRecord: { key: 'forms.ledger.records.read', mode: 'direct' },
	getFormActions: {
		key: 'forms.actions.list',
		mode: 'direct',
		preBoundaryFallbackGuard: 'isInvalidFormSourceContext'
	},
	checkActionCompatibility: { key: 'forms.actions.compatibility', mode: 'direct' },
	getWorkflowPlan: {
		key: 'forms.workflowPlan.read',
		mode: 'direct',
		preBoundaryFallbackGuard: 'isInvalidFormSourceContext'
	},
	runRequestTrace: {
		key: 'forms.requestTrace.run',
		mode: 'direct',
		preBoundaryFallbackGuard: 'isInvalidFormSourceContext'
	},
	getFormDisabled: {
		key: 'forms.disabled.read',
		mode: 'direct',
		preBoundaryFallbackGuard: 'isInvalidFormSourceContext'
	},
	toggleFormDisabled: { key: 'forms.disabled.update', mode: 'direct' },
	getFormFields: {
		key: 'forms.fields.list',
		mode: 'direct',
		preBoundaryFallbackGuard: 'isInvalidFormSourceContext'
	},
	getFormExecutionStatus: {
		key: 'forms.executionStatus.read',
		mode: 'direct',
		preBoundaryFallbackGuard: 'isInvalidFormSourceContext'
	},
	getCapabilities: { key: 'meta.capabilities', mode: 'direct' },
	createFormAction: { key: 'forms.actions.create', mode: 'direct' },
	duplicateFormAction: { key: 'forms.actions.duplicate', mode: 'direct' },
	updateFormAction: { key: 'forms.actions.update', mode: 'direct' },
	deleteFormAction: {
		key: 'forms.actions.delete',
		mode: 'transform',
		transform: { kind: 'void', identifier: 'response' }
	},
	getFormActionConfigs: {
		key: 'forms.actionConfigs.list',
		mode: 'transform',
		transform: { kind: 'return', members: ['configs'] }
	},
	getFormActionConfig: {
		key: 'forms.actionConfigs.read',
		mode: 'transform',
		transform: { kind: 'return', members: ['config'] }
	},
	updateFormActionConfig: {
		key: 'forms.actionConfigs.update',
		mode: 'transform',
		transform: { kind: 'return', members: ['config'] }
	},
	deleteFormActionConfig: {
		key: 'forms.actionConfigs.delete',
		mode: 'transform',
		transform: { kind: 'void', identifier: 'response' }
	},
	getActionDefaults: {
		key: 'actions.defaults.read',
		mode: 'transform',
		transform: { kind: 'return', members: ['config'] }
	},
	getActionDefaultsBatch: {
		key: 'actions.defaults.batch',
		mode: 'transform',
		transform: { kind: 'accumulator', identifier: 'defaults', sourceMember: 'defaults' }
	},
	updateActionDefaults: {
		key: 'actions.defaults.update',
		mode: 'transform',
		transform: { kind: 'return', members: ['config'] }
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

type TransformExpectation = Extract<MethodAuthority, { mode: 'transform' }>['transform'];

function isNestedFunction(node: ts.Node): boolean {
	return (
		ts.isArrowFunction(node) ||
		ts.isFunctionExpression(node) ||
		ts.isFunctionDeclaration(node) ||
		ts.isMethodDeclaration(node) ||
		ts.isGetAccessorDeclaration(node) ||
		ts.isSetAccessorDeclaration(node) ||
		ts.isConstructorDeclaration(node)
	);
}

function walkWithoutNestedFunctions(node: ts.Node, visit: (child: ts.Node) => void): void {
	visit(node);
	node.forEachChild((child) => {
		if (isNestedFunction(child)) return;
		walkWithoutNestedFunctions(child, visit);
	});
}

type ResponseOrigin = {
	path: readonly string[];
	awaited: boolean;
};
type ResponseProvenance = ReadonlyMap<string, ResponseOrigin>;
type ReturnTransform = Extract<TransformExpectation, { kind: 'return' }>;
type ReturnMember = ReturnTransform['members'][number];
type NormalizedReturnMember = {
	member: string;
	targetProperty: string;
	acceptedPaths: readonly (readonly string[])[];
	allowNeutralFallback: boolean;
};

function normalizeReturnMember(member: ReturnMember): NormalizedReturnMember {
	if (typeof member === 'string') {
		return {
			member,
			targetProperty: member,
			acceptedPaths: [[member]],
			allowNeutralFallback: false
		};
	}
	return {
		member: member.member,
		targetProperty: member.targetProperty ?? member.member,
		acceptedPaths: member.acceptedPaths ?? [[member.member]],
		allowNeutralFallback: member.allowNeutralFallback ?? false
	};
}

function unwrapTransparentExpression(expression: ts.Expression): ts.Expression {
	let current = expression;
	while (
		ts.isParenthesizedExpression(current) ||
		ts.isAsExpression(current) ||
		ts.isTypeAssertionExpression(current) ||
		ts.isNonNullExpression(current) ||
		ts.isSatisfiesExpression(current) ||
		ts.isAwaitExpression(current)
	) {
		current = current.expression;
	}
	return current;
}

function sameResponsePath(left: readonly string[], right: readonly string[]): boolean {
	return left.length === right.length && left.every((segment, index) => segment === right[index]);
}

function unwrapResponseExpression(expression: ts.Expression): {
	expression: ts.Expression;
	awaited: boolean;
} {
	let current = expression;
	let awaited = false;
	while (
		ts.isParenthesizedExpression(current) ||
		ts.isAsExpression(current) ||
		ts.isTypeAssertionExpression(current) ||
		ts.isNonNullExpression(current) ||
		ts.isSatisfiesExpression(current) ||
		ts.isAwaitExpression(current)
	) {
		if (ts.isAwaitExpression(current)) awaited = true;
		current = current.expression;
	}
	return { expression: current, awaited };
}

function isTrustedRequestEndpointCall(expression: ts.Expression): boolean {
	return (
		ts.isCallExpression(expression) &&
		ts.isPropertyAccessExpression(expression.expression) &&
		expression.expression.name.text === 'requestEndpoint' &&
		expression.expression.expression.kind === ts.SyntaxKind.ThisKeyword
	);
}

function resolveResponseOrigin(
	expression: ts.Expression,
	provenance: ResponseProvenance,
	expectedEndpointKey?: string
): ResponseOrigin | undefined {
	const { expression: current, awaited } = unwrapResponseExpression(expression);
	if (
		isTrustedRequestEndpointCall(current) &&
		expectedEndpointKey &&
		current.arguments[0] &&
		ts.isStringLiteral(current.arguments[0]) &&
		current.arguments[0].text === expectedEndpointKey
	) {
		return { path: [], awaited };
	}
	if (ts.isIdentifier(current)) {
		const origin = provenance.get(current.text);
		return origin ? { path: origin.path, awaited: awaited || origin.awaited } : undefined;
	}
	if (ts.isPropertyAccessExpression(current)) {
		const base = resolveResponseOrigin(current.expression, provenance, expectedEndpointKey);
		return base
			? { path: [...base.path, current.name.text], awaited: awaited || base.awaited }
			: undefined;
	}
	if (
		ts.isElementAccessExpression(current) &&
		current.argumentExpression &&
		(ts.isStringLiteral(current.argumentExpression) ||
			ts.isNumericLiteral(current.argumentExpression))
	) {
		const base = resolveResponseOrigin(current.expression, provenance, expectedEndpointKey);
		return base
			? {
					path: [...base.path, current.argumentExpression.text],
					awaited: awaited || base.awaited
				}
			: undefined;
	}
	if (
		ts.isCallExpression(current) &&
		ts.isPropertyAccessExpression(current.expression) &&
		current.expression.name.text === 'unwrap' &&
		current.expression.expression.kind === ts.SyntaxKind.ThisKeyword &&
		current.arguments[0]
	) {
		const origin = resolveResponseOrigin(current.arguments[0], provenance, expectedEndpointKey);
		return origin ? { path: origin.path, awaited: awaited || origin.awaited } : undefined;
	}
	if (ts.isConditionalExpression(current)) {
		const whenTrue = resolveResponseOrigin(current.whenTrue, provenance, expectedEndpointKey);
		const whenFalse = resolveResponseOrigin(current.whenFalse, provenance, expectedEndpointKey);
		return whenTrue && whenFalse && sameResponsePath(whenTrue.path, whenFalse.path)
			? {
					path: whenTrue.path,
					awaited: awaited || (whenTrue.awaited && whenFalse.awaited)
				}
			: undefined;
	}
	return undefined;
}

function collectAssignedIdentifiers(node: ts.Node, assigned: Set<string>): void {
	walk(node, (child) => {
		if (ts.isIdentifier(child)) assigned.add(child.text);
	});
}

function assignedRootIdentifier(expression: ts.Expression): string | undefined {
	const current = unwrapTransparentExpression(expression);
	if (ts.isIdentifier(current)) return current.text;
	if (ts.isPropertyAccessExpression(current) || ts.isElementAccessExpression(current)) {
		return assignedRootIdentifier(current.expression);
	}
	return undefined;
}

function collectBindingIdentifierNames(
	name: ts.BindingName,
	visit: (identifier: ts.Identifier) => void
): void {
	if (ts.isIdentifier(name)) {
		visit(name);
		return;
	}
	for (const element of name.elements) {
		if (ts.isOmittedExpression(element)) continue;
		collectBindingIdentifierNames(element.name, visit);
	}
}

function collectLexicalBindingIdentifiers(
	node: ts.Node,
	visit: (identifier: ts.Identifier) => void
): void {
	node.forEachChild((child) => {
		if (ts.isFunctionDeclaration(child)) {
			if (child.name) visit(child.name);
			return;
		}
		if (isNestedFunction(child)) return;
		if (ts.isVariableDeclaration(child)) {
			collectBindingIdentifierNames(child.name, visit);
		}
		if (ts.isClassDeclaration(child) || ts.isEnumDeclaration(child)) {
			if (child.name) visit(child.name);
			return;
		}
		collectLexicalBindingIdentifiers(child, visit);
	});
}

function hasMethodLocalBinding(method: ts.MethodDeclaration, name: string): boolean {
	let found = false;
	const visit = (identifier: ts.Identifier): void => {
		if (identifier.text === name) found = true;
	};
	for (const parameter of method.parameters) {
		collectBindingIdentifierNames(parameter.name, visit);
	}
	collectLexicalBindingIdentifiers(method.body ?? method, visit);
	return found;
}

function expressionMayProduceResponse(
	expression: ts.Expression,
	provenance: ResponseProvenance,
	expectedEndpointKey: string
): boolean {
	const current = unwrapTransparentExpression(expression);
	if (resolveResponseOrigin(current, provenance, expectedEndpointKey)) return true;
	if (ts.isConditionalExpression(current)) {
		return (
			expressionMayProduceResponse(current.whenTrue, provenance, expectedEndpointKey) ||
			expressionMayProduceResponse(current.whenFalse, provenance, expectedEndpointKey)
		);
	}
	if (ts.isBinaryExpression(current) && isLogicalOperator(current.operatorToken.kind)) {
		return (
			expressionMayProduceResponse(current.left, provenance, expectedEndpointKey) ||
			expressionMayProduceResponse(current.right, provenance, expectedEndpointKey)
		);
	}
	if (ts.isBinaryExpression(current) && current.operatorToken.kind === ts.SyntaxKind.CommaToken) {
		return expressionMayProduceResponse(current.right, provenance, expectedEndpointKey);
	}
	return false;
}

function collectResponseProvenance(
	method: ts.MethodDeclaration,
	expectedEndpointKey: string
): Map<string, ResponseOrigin> {
	const declarations: Array<{ name: string; initializer: ts.Expression }> = [];
	const destructuredInitializers: ts.Expression[] = [];
	const destructuredAssignments: ts.Expression[] = [];
	const declarationCounts = new Map<string, number>();
	const rebound = new Set<string>();
	const mutated = new Set<string>();
	const countBinding = (identifier: ts.Identifier): void => {
		declarationCounts.set(identifier.text, (declarationCounts.get(identifier.text) ?? 0) + 1);
	};
	for (const parameter of method.parameters) {
		collectBindingIdentifierNames(parameter.name, countBinding);
	}
	collectLexicalBindingIdentifiers(method.body ?? method, countBinding);
	walkWithoutNestedFunctions(method.body ?? method, (node) => {
		if (ts.isVariableDeclaration(node)) {
			if (!node.initializer) return;
			if (ts.isIdentifier(node.name)) {
				declarations.push({ name: node.name.text, initializer: node.initializer });
			} else {
				destructuredInitializers.push(node.initializer);
			}
		}
	});
	walk(method.body ?? method, (node) => {
		if (
			ts.isBinaryExpression(node) &&
			node.operatorToken.kind >= ts.SyntaxKind.FirstAssignment &&
			node.operatorToken.kind <= ts.SyntaxKind.LastAssignment
		) {
			const assignmentTarget = unwrapTransparentExpression(node.left);
			if (
				ts.isObjectLiteralExpression(assignmentTarget) ||
				ts.isArrayLiteralExpression(assignmentTarget)
			) {
				destructuredAssignments.push(node.right);
			}
			if (ts.isIdentifier(node.left)) {
				rebound.add(node.left.text);
			} else {
				const root = assignedRootIdentifier(node.left);
				if (root) mutated.add(root);
				else collectAssignedIdentifiers(node.left, rebound);
			}
		}
		if (
			(ts.isPrefixUnaryExpression(node) || ts.isPostfixUnaryExpression(node)) &&
			(node.operator === ts.SyntaxKind.PlusPlusToken ||
				node.operator === ts.SyntaxKind.MinusMinusToken)
		) {
			const root = assignedRootIdentifier(node.operand);
			if (root && ts.isIdentifier(unwrapTransparentExpression(node.operand))) rebound.add(root);
			else if (root) mutated.add(root);
		}
		if (ts.isDeleteExpression(node)) {
			const root = assignedRootIdentifier(node.expression);
			if (root) mutated.add(root);
		}
		if (
			ts.isCallExpression(node) &&
			ts.isPropertyAccessExpression(node.expression) &&
			node.expression.getText() === 'Object.assign' &&
			node.arguments[0]
		) {
			const root = assignedRootIdentifier(node.arguments[0]);
			if (root) mutated.add(root);
		}
		if (
			(ts.isForInStatement(node) || ts.isForOfStatement(node)) &&
			!ts.isVariableDeclarationList(node.initializer)
		) {
			const root = assignedRootIdentifier(node.initializer);
			if (root && ts.isIdentifier(unwrapTransparentExpression(node.initializer))) rebound.add(root);
			else if (root) mutated.add(root);
			else collectAssignedIdentifiers(node.initializer, rebound);
		}
	});

	const provenance = new Map<string, ResponseOrigin>();
	let changed = true;
	while (changed) {
		changed = false;
		for (const { name, initializer } of declarations) {
			if (provenance.has(name) || rebound.has(name)) continue;
			const origin = resolveResponseOrigin(initializer, provenance, expectedEndpointKey);
			if (!origin) continue;
			provenance.set(name, origin);
			changed = true;
		}
	}
	if ([...declarationCounts.values()].some((count) => count > 1)) return new Map();
	if (
		[...destructuredInitializers, ...destructuredAssignments].some((initializer) =>
			expressionMayProduceResponse(initializer, provenance, expectedEndpointKey)
		)
	) {
		return new Map();
	}
	if ([...mutated].some((name) => provenance.has(name))) return new Map();
	return provenance;
}

function isNeutralFallback(expression: ts.Expression): boolean {
	const current = unwrapTransparentExpression(expression);
	return (
		(ts.isObjectLiteralExpression(current) && current.properties.length === 0) ||
		(ts.isArrayLiteralExpression(current) && current.elements.length === 0) ||
		current.kind === ts.SyntaxKind.NullKeyword ||
		(ts.isIdentifier(current) && current.text === 'undefined')
	);
}

function isLogicalOperator(operator: ts.SyntaxKind): boolean {
	return (
		operator === ts.SyntaxKind.AmpersandAmpersandToken ||
		operator === ts.SyntaxKind.BarBarToken ||
		operator === ts.SyntaxKind.QuestionQuestionToken
	);
}

function expressionPreservesResponse(
	expression: ts.Expression,
	member: NormalizedReturnMember,
	provenance: ResponseProvenance
): boolean {
	const origin = resolveResponseOrigin(expression, provenance);
	if (origin && member.acceptedPaths.some((path) => sameResponsePath(origin.path, path))) return true;
	if (member.allowNeutralFallback && isNeutralFallback(expression)) return true;
	return expressionReturnsParsedMember(expression, member, provenance);
}

function staticPropertyName(name: ts.PropertyName): string | undefined {
	if (ts.isIdentifier(name) || ts.isStringLiteral(name) || ts.isNumericLiteral(name)) {
		return name.text;
	}
	if (
		ts.isComputedPropertyName(name) &&
		(ts.isStringLiteral(name.expression) || ts.isNumericLiteral(name.expression))
	) {
		return name.expression.text;
	}
	return undefined;
}

function expressionReturnsParsedMember(
	expression: ts.Expression,
	member: NormalizedReturnMember,
	provenance: ResponseProvenance
): boolean {
	const current = unwrapTransparentExpression(expression);
	const origin = resolveResponseOrigin(current, provenance);
	if (origin) return member.acceptedPaths.some((path) => sameResponsePath(origin.path, path));

	if (ts.isConditionalExpression(current)) {
		const branches = [current.whenTrue, current.whenFalse];
		return (
			branches.every((branch) => expressionPreservesResponse(branch, member, provenance)) &&
			branches.some((branch) => expressionReturnsParsedMember(branch, member, provenance))
		);
	}

	if (ts.isBinaryExpression(current) && isLogicalOperator(current.operatorToken.kind)) {
		const branches = [current.left, current.right];
		return (
			branches.every((branch) => expressionPreservesResponse(branch, member, provenance)) &&
			branches.some((branch) => expressionReturnsParsedMember(branch, member, provenance))
		);
	}

	if (ts.isBinaryExpression(current) && current.operatorToken.kind === ts.SyntaxKind.CommaToken) {
		return expressionReturnsParsedMember(current.right, member, provenance);
	}

	if (ts.isObjectLiteralExpression(current)) {
		for (let index = current.properties.length - 1; index >= 0; index -= 1) {
			const property = current.properties[index];
			if (ts.isSpreadAssignment(property)) return false;
			if (ts.isShorthandPropertyAssignment(property)) {
				if (property.name.text === member.targetProperty) {
					return expressionReturnsParsedMember(property.name, member, provenance);
				}
				continue;
			}
			const name = staticPropertyName(property.name);
			if (!name) return false;
			if (name !== member.targetProperty) continue;
			return (
				ts.isPropertyAssignment(property) &&
				expressionReturnsParsedMember(property.initializer, member, provenance)
			);
		}
		return false;
	}

	return false;
}

function expressionReturnsWholeParsedMember(
	expression: ts.Expression,
	member: NormalizedReturnMember,
	provenance: ResponseProvenance
): boolean {
	const current = unwrapTransparentExpression(expression);
	const origin = resolveResponseOrigin(current, provenance);
	if (origin) return member.acceptedPaths.some((path) => sameResponsePath(origin.path, path));
	if (ts.isConditionalExpression(current)) {
		const branches = [current.whenTrue, current.whenFalse];
		return (
			branches.every(
				(branch) =>
					expressionReturnsWholeParsedMember(branch, member, provenance) ||
					(member.allowNeutralFallback && isNeutralFallback(branch))
			) &&
			branches.some((branch) => expressionReturnsWholeParsedMember(branch, member, provenance))
		);
	}
	if (
		ts.isBinaryExpression(current) &&
		current.operatorToken.kind === ts.SyntaxKind.AmpersandAmpersandToken
	) {
		return false;
	}
	if (
		ts.isBinaryExpression(current) &&
		(current.operatorToken.kind === ts.SyntaxKind.BarBarToken ||
			current.operatorToken.kind === ts.SyntaxKind.QuestionQuestionToken)
	) {
		return (
			expressionReturnsWholeParsedMember(current.left, member, provenance) &&
			(expressionReturnsWholeParsedMember(current.right, member, provenance) ||
				(member.allowNeutralFallback && isNeutralFallback(current.right)))
		);
	}
	if (ts.isBinaryExpression(current) && current.operatorToken.kind === ts.SyntaxKind.CommaToken) {
		return expressionReturnsWholeParsedMember(current.right, member, provenance);
	}
	return false;
}

function resolveExpectedEndpointKey(
	method: ts.MethodDeclaration,
	explicitKey?: string
): string | undefined {
	if (explicitKey) return explicitKey;
	const keys = new Set<string>();
	walkWithoutNestedFunctions(method.body ?? method, (node) => {
		if (
			ts.isCallExpression(node) &&
			isTrustedRequestEndpointCall(node) &&
			node.arguments[0] &&
			ts.isStringLiteral(node.arguments[0])
		) {
			keys.add(node.arguments[0].text);
		}
	});
	return keys.size === 1 ? [...keys][0] : undefined;
}

function isInsideLoop(node: ts.Node, boundary: ts.Node): boolean {
	let current = node.parent;
	while (current && current !== boundary) {
		if (
			ts.isForStatement(current) ||
			ts.isForInStatement(current) ||
			ts.isForOfStatement(current) ||
			ts.isWhileStatement(current) ||
			ts.isDoStatement(current)
		) {
			return true;
		}
		current = current.parent;
	}
	return false;
}

function transformAuthorityGaps(
	method: ts.MethodDeclaration,
	expected: TransformExpectation,
	expectedEndpointKey?: string
): string[] {
	const returns: Array<{ expression: ts.Expression; end: number; inLoop: boolean }> = [];
	let firstRequestEndpointPosition = Number.POSITIVE_INFINITY;
	const endpointKey = resolveExpectedEndpointKey(method, expectedEndpointKey);
	if (!endpointKey) return ['missing-endpoint-key'];
	walkWithoutNestedFunctions(method.body ?? method, (node) => {
		if (ts.isReturnStatement(node) && node.expression) {
			returns.push({
				expression: node.expression,
				end: node.end,
				inLoop: isInsideLoop(node, method)
			});
		}
		if (
			ts.isCallExpression(node) &&
			isTrustedRequestEndpointCall(node) &&
			node.arguments[0] &&
			ts.isStringLiteral(node.arguments[0]) &&
			node.arguments[0].text === endpointKey &&
			node.getStart() < firstRequestEndpointPosition
		) {
			firstRequestEndpointPosition = node.getStart();
		}
	});
	const boundaryReturnExpressions = returns
		.filter(({ end, inLoop }) => inLoop || end > firstRequestEndpointPosition)
		.map(({ expression }) => expression);
	const preBoundaryReturnExpressions = returns
		.filter(({ end, inLoop }) => !inLoop && end <= firstRequestEndpointPosition)
		.map(({ expression }) => expression);
	const provenance = collectResponseProvenance(method, endpointKey);

	if (expected.kind === 'return') {
		return expected.members
			.map(normalizeReturnMember)
			.filter(
				(member) =>
					boundaryReturnExpressions.length === 0 ||
					preBoundaryReturnExpressions.some((expression) => !isNeutralFallback(expression)) ||
					boundaryReturnExpressions.some(
						(expression) => !expressionReturnsParsedMember(expression, member, provenance)
					)
			)
			.map(({ member }) => member);
	}

	if (expected.kind === 'void') {
		const identifierOrigin = provenance.get(expected.identifier);
		if (!identifierOrigin || identifierOrigin.path.length !== 0 || !identifierOrigin.awaited) {
			return [`void ${expected.identifier}`];
		}
		let discardsExpectedResponse = false;
		walkWithoutNestedFunctions(method.body ?? method, (node) => {
			if (
				ts.isVoidExpression(node) &&
				ts.isIdentifier(node.expression) &&
				node.expression.text === expected.identifier
			) {
				discardsExpectedResponse = true;
			}
		});
		return discardsExpectedResponse && boundaryReturnExpressions.length === 0
			? []
			: [`void ${expected.identifier}`];
	}

	const returnsAccumulator =
		boundaryReturnExpressions.length > 0 &&
		boundaryReturnExpressions.every(
			(expression) => ts.isIdentifier(expression) && expression.text === expected.identifier
		) &&
		preBoundaryReturnExpressions.every(isNeutralFallback);
	const accumulatorSource = normalizeReturnMember({
		member: expected.sourceMember,
		allowNeutralFallback: true
	});
	const accumulatorInitializers: Array<ts.Expression | undefined> = [];
	walkWithoutNestedFunctions(method.body ?? method, (node) => {
		if (
			ts.isVariableDeclaration(node) &&
			ts.isIdentifier(node.name) &&
			node.name.text === expected.identifier
		) {
			accumulatorInitializers.push(node.initializer);
		}
	});
	const accumulatorInitializer =
		accumulatorInitializers.length === 1 ? accumulatorInitializers[0] : undefined;
	const hasParsedAccumulatorInitializer = Boolean(
		accumulatorInitializer &&
			expressionReturnsWholeParsedMember(accumulatorInitializer, accumulatorSource, provenance)
	);
	let parsedAssignments = hasParsedAccumulatorInitializer ? 1 : 0;
	let hasUnapprovedAccumulatorWrite =
		accumulatorInitializers.length !== 1 ||
		!accumulatorInitializer ||
		(!isNeutralFallback(accumulatorInitializer) && !hasParsedAccumulatorInitializer);
	walkWithoutNestedFunctions(method.body ?? method, (node) => {
		if (ts.isBinaryExpression(node)) {
			const root = assignedRootIdentifier(node.left);
			if (
				root === expected.identifier &&
				node.operatorToken.kind >= ts.SyntaxKind.FirstAssignment &&
				node.operatorToken.kind <= ts.SyntaxKind.LastAssignment
			) {
				hasUnapprovedAccumulatorWrite = true;
			}
		}
		if (
			(ts.isPrefixUnaryExpression(node) || ts.isPostfixUnaryExpression(node)) &&
			assignedRootIdentifier(node.operand) === expected.identifier
		) {
			hasUnapprovedAccumulatorWrite = true;
		}
		if (
			ts.isDeleteExpression(node) &&
			assignedRootIdentifier(node.expression) === expected.identifier
		) {
			hasUnapprovedAccumulatorWrite = true;
		}
		if (!ts.isCallExpression(node) || !ts.isPropertyAccessExpression(node.expression)) {
			return;
		}
		if (
			node.expression.getText() === 'Object.assign' &&
			node.arguments[0] &&
			ts.isIdentifier(node.arguments[0]) &&
			node.arguments[0].text === expected.identifier
		) {
			const sources = node.arguments.slice(1);
			if (
				sources.length === 0 ||
				sources.some(
					(source) => !expressionReturnsWholeParsedMember(source, accumulatorSource, provenance)
				)
			) {
				hasUnapprovedAccumulatorWrite = true;
			} else {
				parsedAssignments += 1;
			}
			return;
		}
		if (assignedRootIdentifier(node.expression.expression) === expected.identifier) {
			hasUnapprovedAccumulatorWrite = true;
		}
	});

	return returnsAccumulator && parsedAssignments > 0 && !hasUnapprovedAccumulatorWrite
		? []
		: [`${expected.identifier}<-${expected.sourceMember}`];
}

function directAuthorityGaps(
	method: ts.MethodDeclaration,
	expectedEndpointKey: string,
	preBoundaryFallbackGuard?: string
): string[] {
	const provenance = collectResponseProvenance(method, expectedEndpointKey);
	const returns: Array<{
		statement: ts.ReturnStatement;
		expression: ts.Expression;
		end: number;
		inLoop: boolean;
	}> = [];
	let firstRequestEndpointPosition = Number.POSITIVE_INFINITY;
	walkWithoutNestedFunctions(method.body ?? method, (node) => {
		if (ts.isReturnStatement(node) && node.expression) {
			returns.push({
				statement: node,
				expression: node.expression,
				end: node.end,
				inLoop: isInsideLoop(node, method)
			});
		}
		if (
			ts.isCallExpression(node) &&
			isTrustedRequestEndpointCall(node) &&
			node.arguments[0] &&
			ts.isStringLiteral(node.arguments[0]) &&
			node.arguments[0].text === expectedEndpointKey &&
			node.getStart() < firstRequestEndpointPosition
		) {
			firstRequestEndpointPosition = node.getStart();
		}
	});
	if (returns.length === 0 || !Number.isFinite(firstRequestEndpointPosition)) {
		return ['return-provenance'];
	}
	const preBoundaryReturns = returns.filter(
		({ end, inLoop }) => !inLoop && end <= firstRequestEndpointPosition
	);
	function isApprovedPreBoundaryFallback(statement: ts.ReturnStatement): boolean {
		if (
			!preBoundaryFallbackGuard ||
			preBoundaryReturns.length !== 1 ||
			hasMethodLocalBinding(method, preBoundaryFallbackGuard)
		) {
			return false;
		}
		let current: ts.Node | undefined = statement.parent;
		while (current && current !== method) {
			if (ts.isIfStatement(current)) {
				const condition = unwrapTransparentExpression(current.expression);
				if (
					ts.isCallExpression(condition) &&
					ts.isIdentifier(condition.expression) &&
					condition.expression.text === preBoundaryFallbackGuard &&
					statement.getStart() >= current.thenStatement.getStart() &&
					statement.end <= current.thenStatement.end
				) {
					return true;
				}
			}
			current = current.parent;
		}
		return false;
	}
	return returns.some(({ statement, expression, end, inLoop }) => {
		if (!inLoop && end <= firstRequestEndpointPosition && isApprovedPreBoundaryFallback(statement)) {
			return false;
		}
		const origin = resolveResponseOrigin(expression, provenance, expectedEndpointKey);
		return !origin || origin.path.length !== 0;
	})
		? ['return-provenance']
		: [];
}

function findMethodDeclaration(sourceFile: ts.SourceFile): ts.MethodDeclaration {
	let result: ts.MethodDeclaration | undefined;
	walk(sourceFile, (node) => {
		if (!result && ts.isMethodDeclaration(node)) result = node;
	});
	if (!result) throw new Error('Expected a method declaration in the test fixture.');
	return result;
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
			const methodFile = ts.createSourceFile(
				`${method}.ts`,
				body,
				ts.ScriptTarget.Latest,
				true,
				ts.ScriptKind.TS
			);
			let usesParser = false;
			walk(methodFile, (node) => {
				if (
					ts.isCallExpression(node) &&
					ts.isIdentifier(node.expression) &&
					node.expression.text === 'parseRegisteredEndpointRequest' &&
					node.arguments[0] &&
					ts.isStringLiteral(node.arguments[0]) &&
					node.arguments[0].text === endpoint &&
					node.arguments.length >= 2
				) {
					usesParser = true;
				}
			});
			return !usesParser;
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
			walkWithoutNestedFunctions(node.body ?? node, (child) => {
				if (ts.isCallExpression(child) && isTrustedRequestEndpointCall(child)) {
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

	it('rejects returned transform members without parsed-response provenance', () => {
		const sourceFile = ts.createSourceFile(
			'mutation.ts',
			"class Example { method() { const response = this.requestEndpoint('example'); const fallback = { config: {} }; return fallback.config; } }",
			ts.ScriptTarget.Latest,
			true,
			ts.ScriptKind.TS
		);
		expect(
			transformAuthorityGaps(
				findMethodDeclaration(sourceFile),
				{
					kind: 'return',
					members: ['config']
				},
				'example'
			)
		).toEqual(['config']);
	});

	it('ignores parsed-response member access inside nested callbacks', () => {
		const sourceFile = ts.createSourceFile(
			'nested-mutation.ts',
			"class Example { method() { const response = this.requestEndpoint('example'); return [1].map(() => response.config); } }",
			ts.ScriptTarget.Latest,
			true,
			ts.ScriptKind.TS
		);
		expect(
			transformAuthorityGaps(findMethodDeclaration(sourceFile), {
				kind: 'return',
				members: ['config']
			})
		).toEqual(['config']);
	});

	it('does not launder response provenance through unrelated object-literal siblings', () => {
		const sourceFile = ts.createSourceFile(
			'object-taint-mutation.ts',
			"class Example { method() { const response = this.requestEndpoint('example'); const fallback = { config: {} }; const projected = { config: fallback.config, response }; return projected.config; } }",
			ts.ScriptTarget.Latest,
			true,
			ts.ScriptKind.TS
		);
		expect(
			transformAuthorityGaps(findMethodDeclaration(sourceFile), {
				kind: 'return',
				members: ['config']
			})
		).toEqual(['config']);
	});

	it('ignores parsed-response member access inside nested accessors', () => {
		const sourceFile = ts.createSourceFile(
			'accessor-mutation.ts',
			"class Example { method() { const response = this.requestEndpoint('example'); const wrapper = { get config() { return response.config; } }; return {}; } }",
			ts.ScriptTarget.Latest,
			true,
			ts.ScriptKind.TS
		);
		expect(
			transformAuthorityGaps(findMethodDeclaration(sourceFile), {
				kind: 'return',
				members: ['config']
			})
		).toEqual(['config']);
	});

	it('requires every value-producing conditional branch to preserve response provenance', () => {
		const sourceFile = ts.createSourceFile(
			'conditional-mutation.ts',
			"class Example { method(useFallback) { const response = this.requestEndpoint('example'); const fallback = { config: {} }; return useFallback ? fallback.config : response.config; } }",
			ts.ScriptTarget.Latest,
			true,
			ts.ScriptKind.TS
		);
		expect(
			transformAuthorityGaps(findMethodDeclaration(sourceFile), {
				kind: 'return',
				members: ['config']
			})
		).toEqual(['config']);
	});

	it('requires logical alternatives and the final comma value to preserve response provenance', () => {
		const mutations = [
			"class Example { method() { const response = this.requestEndpoint('example'); const fallback = { config: {} }; return fallback.config || response.config; } }",
			"class Example { method() { const response = this.requestEndpoint('example'); const fallback = { config: {} }; return (response.config, fallback.config); } }"
		];
		for (const [index, source] of mutations.entries()) {
			const sourceFile = ts.createSourceFile(
				`branch-mutation-${index}.ts`,
				source,
				ts.ScriptTarget.Latest,
				true,
				ts.ScriptKind.TS
			);
			expect(
				transformAuthorityGaps(findMethodDeclaration(sourceFile), {
					kind: 'return',
					members: ['config']
				})
			).toEqual(['config']);
		}
	});

	it('binds object-literal evidence to the expected returned property', () => {
		const sourceFile = ts.createSourceFile(
			'object-property-mutation.ts',
			"class Example { method() { const response = this.requestEndpoint('example'); const fallback = { config: {} }; return { config: fallback.config, evidence: response.config }; } }",
			ts.ScriptTarget.Latest,
			true,
			ts.ScriptKind.TS
		);
		expect(
			transformAuthorityGaps(findMethodDeclaration(sourceFile), {
				kind: 'return',
				members: ['config']
			})
		).toEqual(['config']);
	});

	it('requires every branch to preserve an explicitly accepted member path', () => {
		const sourceFile = ts.createSourceFile(
			'unrelated-branch-mutation.ts',
			"class Example { method(flag) { const response = this.requestEndpoint('example'); return flag ? response.unrelated : response.config; } }",
			ts.ScriptTarget.Latest,
			true,
			ts.ScriptKind.TS
		);
		expect(
			transformAuthorityGaps(findMethodDeclaration(sourceFile), {
				kind: 'return',
				members: ['config']
			})
		).toEqual(['config']);
	});

	it('invalidates response provenance when a tracked binding is reassigned', () => {
		const sourceFile = ts.createSourceFile(
			'reassignment-mutation.ts',
			"class Example { method() { let response = this.requestEndpoint('example'); const fallback = { config: {} }; response = fallback; return response.config; } }",
			ts.ScriptTarget.Latest,
			true,
			ts.ScriptKind.TS
		);
		expect(
			transformAuthorityGaps(findMethodDeclaration(sourceFile), {
				kind: 'return',
				members: ['config']
			})
		).toEqual(['config']);
	});

	it('accepts response origins only from the shared client transport receiver', () => {
		const sourceFile = ts.createSourceFile(
			'untrusted-receiver-mutation.ts',
			"class Example { method() { const fallback = { requestEndpoint: () => ({ config: {} }) }; return fallback.requestEndpoint('example').config; } }",
			ts.ScriptTarget.Latest,
			true,
			ts.ScriptKind.TS
		);
		expect(
			transformAuthorityGaps(
				findMethodDeclaration(sourceFile),
				{ kind: 'return', members: ['config'] },
				'example'
			)
		).toEqual(['config']);
	});

	it('matches complete top-level response member paths', () => {
		const mutations = [
			"class Example { method() { const response = this.requestEndpoint('example'); return response.metadata.config; } }",
			"class Example { method() { const response = this.requestEndpoint('example'); return response.config.value; } }"
		];
		for (const [index, source] of mutations.entries()) {
			const sourceFile = ts.createSourceFile(
				`member-path-mutation-${index}.ts`,
				source,
				ts.ScriptTarget.Latest,
				true,
				ts.ScriptKind.TS
			);
			expect(
				transformAuthorityGaps(findMethodDeclaration(sourceFile), {
					kind: 'return',
					members: ['config']
				})
			).toEqual(['config']);
		}
	});

	it('requires every post-boundary return statement to preserve the expected projection', () => {
		const sourceFile = ts.createSourceFile(
			'post-boundary-return-mutation.ts',
			"class Example { method(flag) { const response = this.requestEndpoint('example'); const fallback = { config: {} }; if (flag) return response.config; return fallback.config; } }",
			ts.ScriptTarget.Latest,
			true,
			ts.ScriptKind.TS
		);
		expect(
			transformAuthorityGaps(findMethodDeclaration(sourceFile), {
				kind: 'return',
				members: ['config']
			})
		).toEqual(['config']);
	});

	it('allows only neutral pre-boundary early returns', () => {
		const sourceFile = ts.createSourceFile(
			'pre-boundary-return-mutation.ts',
			"class Example { method(flag) { const fallback = { config: {} }; if (flag) return fallback.config; const response = this.requestEndpoint('example'); return response.config; } }",
			ts.ScriptTarget.Latest,
			true,
			ts.ScriptKind.TS
		);
		expect(
			transformAuthorityGaps(findMethodDeclaration(sourceFile), {
				kind: 'return',
				members: ['config']
			})
		).toEqual(['config']);
	});

	it('positions transform boundaries from the expected literal endpoint only', () => {
		const sourceFile = ts.createSourceFile(
			'expected-boundary-position.ts',
			"class Example { method(flag, dynamicEndpoint) { void this.requestEndpoint(dynamicEndpoint); if (flag) return {}; const response = this.requestEndpoint('example'); return response.config; } }",
			ts.ScriptTarget.Latest,
			true,
			ts.ScriptKind.TS
		);
		expect(
			transformAuthorityGaps(
				findMethodDeclaration(sourceFile),
				{
					kind: 'return',
					members: [{ member: 'config', allowNeutralFallback: true }]
				},
				'example'
			)
		).toEqual([]);
	});

	it('requires expected-endpoint provenance for void response transforms', () => {
		const sourceFile = ts.createSourceFile(
			'void-provenance-mutation.ts',
			"class Example { method() { void this.requestEndpoint('example'); const response = {}; void response; } }",
			ts.ScriptTarget.Latest,
			true,
			ts.ScriptKind.TS
		);
		expect(
			transformAuthorityGaps(
				findMethodDeclaration(sourceFile),
				{ kind: 'void', identifier: 'response' },
				'example'
			)
		).toEqual(['void response']);
	});

	it('requires void response transforms to await the expected endpoint', () => {
		const sourceFile = ts.createSourceFile(
			'void-await-mutation.ts',
			"class Example { method() { const response = this.requestEndpoint('example'); void response; } }",
			ts.ScriptTarget.Latest,
			true,
			ts.ScriptKind.TS
		);
		expect(
			transformAuthorityGaps(
				findMethodDeclaration(sourceFile),
				{ kind: 'void', identifier: 'response' },
				'example'
			)
		).toEqual(['void response']);
	});

	it('invalidates provenance when a tracked response object is mutated', () => {
		const sourceFile = ts.createSourceFile(
			'property-mutation.ts',
			"class Example { method() { const response = this.requestEndpoint('example'); const fallback = { config: {} }; response.config = fallback.config; return response.config; } }",
			ts.ScriptTarget.Latest,
			true,
			ts.ScriptKind.TS
		);
		expect(
			transformAuthorityGaps(findMethodDeclaration(sourceFile), {
				kind: 'return',
				members: ['config']
			})
		).toEqual(['config']);
	});

	it('propagates response-object mutation invalidation across aliases', () => {
		const sourceFile = ts.createSourceFile(
			'alias-property-mutation.ts',
			"class Example { method() { const response = this.requestEndpoint('example'); const alias = response; const fallback = { config: {} }; alias.config = fallback.config; return response.config; } }",
			ts.ScriptTarget.Latest,
			true,
			ts.ScriptKind.TS
		);
		expect(
			transformAuthorityGaps(findMethodDeclaration(sourceFile), {
				kind: 'return',
				members: ['config']
			})
		).toEqual(['config']);
	});

	it('permits neutral branch fallbacks only when the projection explicitly allows them', () => {
		const sourceFile = ts.createSourceFile(
			'neutral-fallback-mutation.ts',
			"class Example { method() { const response = this.requestEndpoint('example'); return response.config ?? {}; } }",
			ts.ScriptTarget.Latest,
			true,
			ts.ScriptKind.TS
		);
		expect(
			transformAuthorityGaps(findMethodDeclaration(sourceFile), {
				kind: 'return',
				members: ['config']
			})
		).toEqual(['config']);
	});

	it('requires every post-boundary accumulator return to preserve the accumulator', () => {
		const sourceFile = ts.createSourceFile(
			'accumulator-return-mutation.ts',
			"class Example { method(flag) { const defaults = {}; const response = this.requestEndpoint('example'); Object.assign(defaults, response.defaults ?? {}); if (flag) return {}; return defaults; } }",
			ts.ScriptTarget.Latest,
			true,
			ts.ScriptKind.TS
		);
		expect(
			transformAuthorityGaps(findMethodDeclaration(sourceFile), {
				kind: 'accumulator',
				identifier: 'defaults',
				sourceMember: 'defaults'
			})
		).toEqual(['defaults<-defaults']);
	});

	it('accepts unwrap provenance only from the shared client helper', () => {
		const sourceFile = ts.createSourceFile(
			'untrusted-unwrap-mutation.ts',
			"class Example { method() { const response = this.requestEndpoint('example'); const fallback = { unwrap: () => ({ config: {} }) }; return fallback.unwrap(response).config; } }",
			ts.ScriptTarget.Latest,
			true,
			ts.ScriptKind.TS
		);
		expect(
			transformAuthorityGaps(findMethodDeclaration(sourceFile), {
				kind: 'return',
				members: ['config']
			})
		).toEqual(['config']);
	});

	it('fails closed when a response identifier is shadowed by another binding', () => {
		const sourceFile = ts.createSourceFile(
			'shadowed-binding-mutation.ts',
			"class Example { method() { const response = this.requestEndpoint('example'); { const response = { config: {} }; return response.config; } } }",
			ts.ScriptTarget.Latest,
			true,
			ts.ScriptKind.TS
		);
		expect(
			transformAuthorityGaps(findMethodDeclaration(sourceFile), {
				kind: 'return',
				members: ['config']
			})
		).toEqual(['config']);
	});

	it('registers every response-derived license projection', () => {
		const sourceFile = ts.createSourceFile(
			'license-projection-mutation.ts',
			"class Example { method() { const response = this.requestEndpoint('example'); return { status: response.status, tier: 'handwritten', expiryDate: response.expires_at, licenseId: response.license_id ?? undefined, siteId: response.site_id ?? undefined }; } }",
			ts.ScriptTarget.Latest,
			true,
			ts.ScriptKind.TS
		);
		expect(
			transformAuthorityGaps(findMethodDeclaration(sourceFile), {
				kind: 'return',
				members: [
					'status',
					{ member: 'tier', acceptedPaths: [['tier'], ['tier', 'code']] },
					{ member: 'expires_at', targetProperty: 'expiryDate' },
					{
						member: 'license_id',
						targetProperty: 'licenseId',
						allowNeutralFallback: true
					},
					{
						member: 'site_id',
						targetProperty: 'siteId',
						allowNeutralFallback: true
					}
				]
			})
		).toEqual(['tier']);
	});

	it('binds response provenance to the expected literal endpoint key', () => {
		const sourceFile = ts.createSourceFile(
			'endpoint-key-mutation.ts',
			"class Example { method(dynamicEndpoint) { void this.requestEndpoint('example'); const response = this.requestEndpoint(dynamicEndpoint); return response.config; } }",
			ts.ScriptTarget.Latest,
			true,
			ts.ScriptKind.TS
		);
		expect(
			transformAuthorityGaps(
				findMethodDeclaration(sourceFile),
				{ kind: 'return', members: ['config'] },
				'example'
			)
		).toEqual(['config']);
	});

	it('rejects computed or spread overrides after a projected object property', () => {
		const sourceFile = ts.createSourceFile(
			'object-override-mutation.ts',
			"class Example { method() { const response = this.requestEndpoint('example'); const key = 'config'; const fallback = { config: {} }; return { config: response.config, [key]: fallback.config }; } }",
			ts.ScriptTarget.Latest,
			true,
			ts.ScriptKind.TS
		);
		expect(
			transformAuthorityGaps(findMethodDeclaration(sourceFile), {
				kind: 'return',
				members: ['config']
			})
		).toEqual(['config']);
	});

	it('rejects non-response writes to a returned accumulator', () => {
		const sourceFile = ts.createSourceFile(
			'accumulator-write-mutation.ts',
			"class Example { method() { const defaults = {}; const response = this.requestEndpoint('example'); Object.assign(defaults, response.defaults ?? {}); defaults.extra = 'handwritten'; return defaults; } }",
			ts.ScriptTarget.Latest,
			true,
			ts.ScriptKind.TS
		);
		expect(
			transformAuthorityGaps(findMethodDeclaration(sourceFile), {
				kind: 'accumulator',
				identifier: 'defaults',
				sourceMember: 'defaults'
			})
		).toEqual(['defaults<-defaults']);
	});

	it('treats returns inside request-bearing loops as post-boundary paths', () => {
		const sourceFile = ts.createSourceFile(
			'loop-return-mutation.ts',
			"class Example { method(items) { const defaults = {}; for (const item of items) { if (item.skip) return {}; const response = this.requestEndpoint('example'); Object.assign(defaults, response.defaults ?? {}); } return defaults; } }",
			ts.ScriptTarget.Latest,
			true,
			ts.ScriptKind.TS
		);
		expect(
			transformAuthorityGaps(findMethodDeclaration(sourceFile), {
				kind: 'accumulator',
				identifier: 'defaults',
				sourceMember: 'defaults'
			})
		).toEqual(['defaults<-defaults']);
	});

	it('fails closed when response provenance is destructured into aliases', () => {
		const sourceFile = ts.createSourceFile(
			'destructured-alias-mutation.ts',
			"class Example { method() { const response = this.requestEndpoint('example'); const { config: alias } = response; alias.value = 'handwritten'; return response.config; } }",
			ts.ScriptTarget.Latest,
			true,
			ts.ScriptKind.TS
		);
		expect(
			transformAuthorityGaps(findMethodDeclaration(sourceFile), {
				kind: 'return',
				members: ['config']
			})
		).toEqual(['config']);
	});

	it('counts parameters, catch bindings, and uninitialized loop bindings before granting provenance', () => {
		const mutations = [
			"class Example { method(response, flag) { { const response = this.requestEndpoint('example'); if (flag) return response.config; } return response.config; } }",
			"class Example { method(flag) { const response = this.requestEndpoint('example'); try { throw new Error(); } catch (response) { if (flag) return response.config; } return response.config; } }",
			"class Example { method(flag) { const response = this.requestEndpoint('example'); for (let response; flag;) { return response.config; } return response.config; } }"
		];
		for (const [index, source] of mutations.entries()) {
			const sourceFile = ts.createSourceFile(
				`lexical-binding-mutation-${index}.ts`,
				source,
				ts.ScriptTarget.Latest,
				true,
				ts.ScriptKind.TS
			);
			expect(
				transformAuthorityGaps(findMethodDeclaration(sourceFile), {
					kind: 'return',
					members: ['config']
				})
			).toEqual(['config']);
		}
	});

	it('fails closed for response-derived destructuring assignments', () => {
		const sourceFile = ts.createSourceFile(
			'destructuring-assignment-mutation.ts',
			"class Example { method() { let alias; const response = this.requestEndpoint('example'); ({ config: alias } = response); alias.value = 'handwritten'; return response.config; } }",
			ts.ScriptTarget.Latest,
			true,
			ts.ScriptKind.TS
		);
		expect(
			transformAuthorityGaps(findMethodDeclaration(sourceFile), {
				kind: 'return',
				members: ['config']
			})
		).toEqual(['config']);
	});

	it('requires a neutral or response-derived accumulator initializer', () => {
		const sourceFile = ts.createSourceFile(
			'accumulator-initializer-mutation.ts',
			"class Example { method(fallbackConfig) { const defaults = { extra: fallbackConfig }; const response = this.requestEndpoint('example'); Object.assign(defaults, response.defaults); return defaults; } }",
			ts.ScriptTarget.Latest,
			true,
			ts.ScriptKind.TS
		);
		expect(
			transformAuthorityGaps(findMethodDeclaration(sourceFile), {
				kind: 'accumulator',
				identifier: 'defaults',
				sourceMember: 'defaults'
			})
		).toEqual(['defaults<-defaults']);
	});

	it('binds every direct return to the registered endpoint response', () => {
		const mutations = [
			"class Example { method(dynamicEndpoint) { void this.requestEndpoint('example'); return this.requestEndpoint(dynamicEndpoint); } }",
			"class Example { method() { void this.requestEndpoint('example'); return { status: 'handwritten' }; } }"
		];
		for (const [index, source] of mutations.entries()) {
			const sourceFile = ts.createSourceFile(
				`direct-return-mutation-${index}.ts`,
				source,
				ts.ScriptTarget.Latest,
				true,
				ts.ScriptKind.TS
			);
			expect(directAuthorityGaps(findMethodDeclaration(sourceFile), 'example')).toEqual([
				'return-provenance'
			]);
		}
	});

	it('counts block-local class declarations before granting response provenance', () => {
		const sourceFile = ts.createSourceFile(
			'class-binding-mutation.ts',
			"class Example { method(fallbackConfig) { const response = this.requestEndpoint('example'); { class response { static config = fallbackConfig; } return response.config; } } }",
			ts.ScriptTarget.Latest,
			true,
			ts.ScriptKind.TS
		);
		expect(
			transformAuthorityGaps(findMethodDeclaration(sourceFile), {
				kind: 'return',
				members: ['config']
			})
		).toEqual(['config']);
	});

	it('detects response-producing logical and comma destructuring sources', () => {
		const mutations = [
			"class Example { method(fallback) { let alias; const response = this.requestEndpoint('example'); ({ config: alias } = response || fallback); alias.value = 'handwritten'; return response.config; } }",
			"class Example { method(fallback) { let alias; const response = this.requestEndpoint('example'); ({ config: alias } = (fallback, response)); alias.value = 'handwritten'; return response.config; } }"
		];
		for (const [index, source] of mutations.entries()) {
			const sourceFile = ts.createSourceFile(
				`destructuring-source-mutation-${index}.ts`,
				source,
				ts.ScriptTarget.Latest,
				true,
				ts.ScriptKind.TS
			);
			expect(
				transformAuthorityGaps(findMethodDeclaration(sourceFile), {
					kind: 'return',
					members: ['config']
				})
			).toEqual(['config']);
		}
	});

	it('requires whole-value provenance for accumulator initializers and assignment sources', () => {
		const mutations = [
			"class Example { method(fallbackConfig) { const response = this.requestEndpoint('example'); const defaults = { extra: fallbackConfig, defaults: response.defaults }; return defaults; } }",
			"class Example { method(fallbackConfig) { const defaults = {}; const response = this.requestEndpoint('example'); Object.assign(defaults, { extra: fallbackConfig, defaults: response.defaults }); return defaults; } }"
		];
		for (const [index, source] of mutations.entries()) {
			const sourceFile = ts.createSourceFile(
				`accumulator-whole-value-mutation-${index}.ts`,
				source,
				ts.ScriptTarget.Latest,
				true,
				ts.ScriptKind.TS
			);
			expect(
				transformAuthorityGaps(findMethodDeclaration(sourceFile), {
					kind: 'accumulator',
					identifier: 'defaults',
					sourceMember: 'defaults'
				})
			).toEqual(['defaults<-defaults']);
		}
	});

	it('limits pre-boundary fallback authority to one return under the registered guard', () => {
		const sourceFile = ts.createSourceFile(
			'fallback-guard-mutation.ts',
			"class Example { method(flag, slug, id) { if (flag) return importedFallback; if (isInvalidFormSourceContext(slug, id)) return []; const response = this.requestEndpoint('example'); return response; } }",
			ts.ScriptTarget.Latest,
			true,
			ts.ScriptKind.TS
		);
		expect(
			directAuthorityGaps(
				findMethodDeclaration(sourceFile),
				'example',
				'isInvalidFormSourceContext'
			)
		).toEqual(['return-provenance']);
	});

	it('rejects neutral-only logical accumulator results as parsed evidence', () => {
		const sourceFile = ts.createSourceFile(
			'accumulator-logical-result-mutation.ts',
			"class Example { method() { const defaults = {}; const response = this.requestEndpoint('example'); Object.assign(defaults, response.defaults && {}); return defaults; } }",
			ts.ScriptTarget.Latest,
			true,
			ts.ScriptKind.TS
		);
		expect(
			transformAuthorityGaps(findMethodDeclaration(sourceFile), {
				kind: 'accumulator',
				identifier: 'defaults',
				sourceMember: 'defaults'
			})
		).toEqual(['defaults<-defaults']);
	});

	it('requires parsed accumulator provenance on the left of OR and nullish fallbacks', () => {
		const mutations = [
			"class Example { method() { const defaults = {}; const response = this.requestEndpoint('example'); Object.assign(defaults, {} || response.defaults); return defaults; } }",
			"class Example { method() { const defaults = {}; const response = this.requestEndpoint('example'); Object.assign(defaults, {} ?? response.defaults); return defaults; } }"
		];
		for (const [index, source] of mutations.entries()) {
			const sourceFile = ts.createSourceFile(
				`accumulator-logical-order-mutation-${index}.ts`,
				source,
				ts.ScriptTarget.Latest,
				true,
				ts.ScriptKind.TS
			);
			expect(
				transformAuthorityGaps(findMethodDeclaration(sourceFile), {
					kind: 'accumulator',
					identifier: 'defaults',
					sourceMember: 'defaults'
				})
			).toEqual(['defaults<-defaults']);
		}
	});

	it('rejects fallback guards shadowed by method-local bindings', () => {
		const sourceFile = ts.createSourceFile(
			'fallback-guard-shadow-mutation.ts',
			"class Example { method(isInvalidFormSourceContext, slug, id) { if (isInvalidFormSourceContext(slug, id)) return []; const response = this.requestEndpoint('example'); return response; } }",
			ts.ScriptTarget.Latest,
			true,
			ts.ScriptKind.TS
		);
		expect(
			directAuthorityGaps(
				findMethodDeclaration(sourceFile),
				'example',
				'isInvalidFormSourceContext'
			)
		).toEqual(['return-provenance']);
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
			{ endpointKeys: Set<string>; derivedKeys: Set<string>; method: ts.MethodDeclaration }
		>();
		const duplicateMethods: string[] = [];
		walk(clientFile, (node) => {
			if (!ts.isMethodDeclaration(node) || !node.name) return;
			const endpointKeys = new Set<string>();
			walkWithoutNestedFunctions(node.body ?? node, (child) => {
				if (
					ts.isCallExpression(child) &&
					isTrustedRequestEndpointCall(child) &&
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
			walkWithoutNestedFunctions(node.body ?? node, (child) => {
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

			actualMethods.set(methodName, {
				endpointKeys,
				derivedKeys,
				method: node
			});
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
			if (expected.mode === 'direct') {
				const missingEvidence = directAuthorityGaps(
					actual.method,
					expected.key,
					expected.preBoundaryFallbackGuard
				);
				if (missingEvidence.length > 0) {
					gaps.push(`${methodName}:missing-direct=${missingEvidence.join(',')}`);
				}
			}
			if (expected.mode === 'transform') {
				const missingEvidence = transformAuthorityGaps(
					actual.method,
					expected.transform,
					expected.key
				);
				if (missingEvidence.length > 0) {
					gaps.push(`${methodName}:missing-transform=${missingEvidence.join(',')}`);
				}
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
		const uiIndexSource = readFileSync(
			resolve(projectRoot, 'src/lib/components/ui/index.ts'),
			'utf8'
		);
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
