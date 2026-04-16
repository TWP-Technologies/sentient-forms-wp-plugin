import type {
	ActionDefinitionPayload,
	ActionKind,
	CustomActionCreatePayload,
	CustomActionStatus,
	CustomActionUpdatePayload,
	ExecutionMode,
	OutputContract,
	WorkflowDefinitionPayload,
	WorkflowEdgePayload,
	WorkflowNodePayload
} from '$lib/api/types';

type IssuePath = Array<string | number>;

type ValidationIssue = {
	path: IssuePath;
	message: string;
};

type SafeParseSuccess<T> = {
	success: true;
	data: T;
};

type SafeParseFailure = {
	success: false;
	error: {
		issues: ValidationIssue[];
	};
};

type SafeParseResult<T> = SafeParseSuccess<T> | SafeParseFailure;

type PayloadValidationError = {
	path: string;
	message: string;
};

type PayloadValidationResult<T> =
	| {
			success: true;
			data: T;
	  }
	| {
			success: false;
			errors: PayloadValidationError[];
	  };

export type CustomActionCreateInput = CustomActionCreatePayload;
export type CustomActionUpdateInput = CustomActionUpdatePayload;

const ACTION_KINDS = new Set<ActionKind>(['template_override', 'custom_definition']);
const EXECUTION_MODES = new Set<ExecutionMode>(['validation', 'after_submission', 'real_time']);
const STATUSES = new Set<CustomActionStatus>(['active', 'archived']);
const WORKFLOW_NODE_KINDS = new Set(['llm_step', 'transform_step', 'decision_step']);
const UUID_RE =
	/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const CODE_RE = /^[a-z0-9-]+$/;

function isRecord(value: unknown): value is Record<string, unknown> {
	return Boolean(value && typeof value === 'object' && !Array.isArray(value));
}

function isPositiveInteger(value: unknown): value is number {
	return typeof value === 'number' && Number.isInteger(value) && value >= 1;
}

function pushIssue(issues: ValidationIssue[], path: IssuePath, message: string): void {
	issues.push({ path, message });
}

function normalizeOptionalTrimmedString(value: unknown): string | null | undefined {
	if (value === undefined) return undefined;
	if (value === null) return null;
	return typeof value === 'string' ? value.trim() : undefined;
}

function validateCode(value: unknown, issues: ValidationIssue[]): string {
	if (typeof value !== 'string' || value.length === 0) {
		pushIssue(issues, ['code'], 'Code is required');
		return '';
	}

	if (!CODE_RE.test(value)) {
		pushIssue(issues, ['code'], 'Code must contain only lowercase letters, numbers, and dashes');
	}

	return value;
}

function validateTemplateId(value: unknown, issues: ValidationIssue[]): string {
	if (typeof value !== 'string' || value.length === 0) {
		pushIssue(issues, ['template_id'], 'Template ID is required');
		return '';
	}

	if (!UUID_RE.test(value)) {
		pushIssue(issues, ['template_id'], 'Template ID must be a valid UUID');
	}

	return value;
}

function validateDisplayName(value: unknown, issues: ValidationIssue[]): string {
	if (typeof value !== 'string' || value.trim() === '') {
		pushIssue(issues, ['display_name'], 'Display name is required');
		return '';
	}

	return value.trim();
}

function validatePromptOverrides(
	value: unknown,
	issues: ValidationIssue[]
): Record<string, unknown> | undefined {
	if (value === undefined) return undefined;
	if (!isRecord(value)) {
		pushIssue(issues, ['prompt_overrides'], 'Prompt overrides must be a JSON object');
		return undefined;
	}

	return value;
}

function validateActionKind(value: unknown, issues: ValidationIssue[]): ActionKind {
	if (typeof value === 'string' && ACTION_KINDS.has(value as ActionKind)) {
		return value as ActionKind;
	}

	pushIssue(issues, ['action_kind'], 'Action kind is required');
	return 'template_override';
}

function validateDefinitionVersion(value: unknown, issues: ValidationIssue[]): number {
	if (isPositiveInteger(value)) return value;
	pushIssue(issues, ['definition_version'], 'Definition version must be >= 1');
	return 1;
}

function validateOutputContract(value: unknown, issues: ValidationIssue[]): OutputContract | null | undefined {
	if (value === undefined) return undefined;
	if (value === null) return null;
	if (isRecord(value)) return value as OutputContract;

	pushIssue(issues, ['output_contract'], 'Output contract must be a JSON object');
	return undefined;
}

function validateSupportedExecutionModes(value: unknown, issues: ValidationIssue[]): ExecutionMode[] {
	if (!Array.isArray(value) || value.length === 0) {
		pushIssue(issues, ['supported_execution_modes'], 'At least one execution mode is required');
		return [];
	}

	const modes: ExecutionMode[] = [];
	value.forEach((mode, index) => {
		if (typeof mode === 'string' && EXECUTION_MODES.has(mode as ExecutionMode)) {
			modes.push(mode as ExecutionMode);
			return;
		}

		pushIssue(issues, ['supported_execution_modes', index], 'Unsupported execution mode');
	});

	return modes;
}

function validateWorkflowNode(
	value: unknown,
	index: number,
	issues: ValidationIssue[]
): WorkflowNodePayload | null {
	if (!isRecord(value)) {
		pushIssue(issues, ['definition', 'workflow', 'nodes', index], 'Workflow node must be an object');
		return null;
	}

	const nodeId = typeof value.node_id === 'string' ? value.node_id.trim() : '';
	const kind = typeof value.kind === 'string' ? value.kind : '';
	const outputKey = typeof value.output_key === 'string' ? value.output_key.trim() : '';

	if (!nodeId) {
		pushIssue(issues, ['definition', 'workflow', 'nodes', index, 'node_id'], 'Workflow node_id is required');
	}

	if (!WORKFLOW_NODE_KINDS.has(kind)) {
		pushIssue(issues, ['definition', 'workflow', 'nodes', index, 'kind'], 'Unsupported workflow node kind');
	}

	if (!outputKey) {
		pushIssue(
			issues,
			['definition', 'workflow', 'nodes', index, 'output_key'],
			'Workflow output_key is required'
		);
	}

	const promptTemplate =
		typeof value.prompt_template === 'string' ? value.prompt_template.trim() : undefined;
	if (kind === 'llm_step' && !promptTemplate) {
		pushIssue(
			issues,
			['definition', 'workflow', 'nodes', index, 'prompt_template'],
			'prompt_template is required for llm_step nodes'
		);
	}

	if (
		value.timeout_ms !== undefined &&
		(typeof value.timeout_ms !== 'number' ||
			!Number.isInteger(value.timeout_ms) ||
			value.timeout_ms < 1)
	) {
		pushIssue(
			issues,
			['definition', 'workflow', 'nodes', index, 'timeout_ms'],
			'timeout_ms must be >= 1'
		);
	}

	return {
		...value,
		node_id: nodeId,
		kind: kind as WorkflowNodePayload['kind'],
		output_key: outputKey,
		...(promptTemplate !== undefined ? { prompt_template: promptTemplate } : {})
	} as WorkflowNodePayload;
}

function validateWorkflowEdge(
	value: unknown,
	index: number,
	nodeIds: Set<string>,
	issues: ValidationIssue[]
): WorkflowEdgePayload | null {
	if (!isRecord(value)) {
		pushIssue(issues, ['definition', 'workflow', 'edges', index], 'Workflow edge must be an object');
		return null;
	}

	const from = typeof value.from === 'string' ? value.from.trim() : '';
	const to = typeof value.to === 'string' ? value.to.trim() : '';

	if (!from) {
		pushIssue(issues, ['definition', 'workflow', 'edges', index, 'from'], 'Workflow edge.from is required');
	}
	if (!to) {
		pushIssue(issues, ['definition', 'workflow', 'edges', index, 'to'], 'Workflow edge.to is required');
	}
	if (from && to && from === to) {
		pushIssue(issues, ['definition', 'workflow', 'edges', index], 'Workflow edges cannot be self-referential');
	}
	if (from && !nodeIds.has(from)) {
		pushIssue(issues, ['definition', 'workflow', 'edges', index, 'from'], `Unknown workflow node_id '${from}'`);
	}
	if (to && !nodeIds.has(to)) {
		pushIssue(issues, ['definition', 'workflow', 'edges', index, 'to'], `Unknown workflow node_id '${to}'`);
	}

	return { from, to };
}

function validateWorkflow(value: unknown, issues: ValidationIssue[]): WorkflowDefinitionPayload | undefined {
	if (value === undefined) return undefined;
	if (!isRecord(value)) {
		pushIssue(issues, ['definition', 'workflow'], 'Workflow must be a JSON object');
		return undefined;
	}

	if (!Array.isArray(value.nodes) || value.nodes.length === 0) {
		pushIssue(issues, ['definition', 'workflow', 'nodes'], 'Workflow must include at least one node');
		return { ...value, nodes: [] } as WorkflowDefinitionPayload;
	}

	const nodes = value.nodes
		.map((node, index) => validateWorkflowNode(node, index, issues))
		.filter((node): node is WorkflowNodePayload => node !== null);
	const nodeIds = nodes.map((node) => node.node_id);
	const uniqueNodeIds = new Set(nodeIds);
	if (uniqueNodeIds.size !== nodeIds.length) {
		pushIssue(issues, ['definition', 'workflow', 'nodes'], 'Workflow node_id values must be unique');
	}

	const edges = Array.isArray(value.edges)
		? value.edges
				.map((edge, index) => validateWorkflowEdge(edge, index, uniqueNodeIds, issues))
				.filter((edge): edge is WorkflowEdgePayload => edge !== null)
		: undefined;

	if (
		value.max_parallelism !== undefined &&
		(typeof value.max_parallelism !== 'number' ||
			!Number.isInteger(value.max_parallelism) ||
			value.max_parallelism < 1 ||
			value.max_parallelism > 16)
	) {
		pushIssue(
			issues,
			['definition', 'workflow', 'max_parallelism'],
			'max_parallelism must be between 1 and 16'
		);
	}

	return {
		...value,
		nodes,
		...(edges !== undefined ? { edges } : {})
	} as WorkflowDefinitionPayload;
}

function validateDefinition(
	value: unknown,
	actionKind: ActionKind,
	issues: ValidationIssue[]
): ActionDefinitionPayload | null | undefined {
	if (value === undefined) return undefined;
	if (value === null) {
		return null;
	}

	if (!isRecord(value)) {
		pushIssue(issues, ['definition'], 'Definition must be a JSON object');
		return undefined;
	}

	return {
		...value,
		workflow: validateWorkflow(value.workflow, issues)
	} as ActionDefinitionPayload;
}

function validateSharedPayload(
	data: unknown,
	issues: ValidationIssue[],
	options: { requireDisplayName: boolean }
): Omit<CustomActionCreatePayload, 'template_id' | 'code'> {
	const source = isRecord(data) ? data : {};
	if (!isRecord(data)) {
		pushIssue(issues, [], 'Payload must be a JSON object');
	}

	const displayName =
		options.requireDisplayName || source.display_name !== undefined
			? validateDisplayName(source.display_name, issues)
			: undefined;
	const actionKind = validateActionKind(source.action_kind, issues);
	const definition = validateDefinition(source.definition, actionKind, issues);

	if (actionKind === 'custom_definition' && !definition) {
		pushIssue(issues, ['definition'], 'definition is required when action_kind is custom_definition');
	}

	const payload: Partial<Omit<CustomActionCreatePayload, 'template_id' | 'code'>> = {
		description: normalizeOptionalTrimmedString(source.description),
		prompt_overrides: validatePromptOverrides(source.prompt_overrides, issues),
		model_hint: normalizeOptionalTrimmedString(source.model_hint),
		action_kind: actionKind,
		definition,
		definition_version: validateDefinitionVersion(source.definition_version, issues),
		output_contract: validateOutputContract(source.output_contract, issues),
		supported_execution_modes: validateSupportedExecutionModes(
			source.supported_execution_modes,
			issues
		)
	};

	if (displayName !== undefined) {
		payload.display_name = displayName;
	}

	return Object.fromEntries(
		Object.entries(payload).filter((entry) => entry[1] !== undefined)
	) as Omit<CustomActionCreatePayload, 'template_id' | 'code'>;
}

function result<T>(issues: ValidationIssue[], data: T): SafeParseResult<T> {
	if (issues.length > 0) {
		return { success: false, error: { issues } };
	}

	return { success: true, data };
}

function safeParseCreate(data: unknown): SafeParseResult<CustomActionCreateInput> {
	const issues: ValidationIssue[] = [];
	const source = isRecord(data) ? data : {};
	const payload = {
		...validateSharedPayload(data, issues, { requireDisplayName: true }),
		template_id: validateTemplateId(source.template_id, issues),
		code: validateCode(source.code, issues)
	};

	return result(issues, payload);
}

function safeParseUpdate(data: unknown): SafeParseResult<CustomActionUpdateInput> {
	const issues: ValidationIssue[] = [];
	const source = isRecord(data) ? data : {};
	const payload: CustomActionUpdateInput = validateSharedPayload(data, issues, {
		requireDisplayName: false
	});

	if (source.status !== undefined) {
		if (typeof source.status === 'string' && STATUSES.has(source.status as CustomActionStatus)) {
			payload.status = source.status as CustomActionStatus;
		} else {
			pushIssue(issues, ['status'], 'Unsupported custom action status');
		}
	}

	return result(issues, payload);
}

function parseResult<T>(safeParseResult: SafeParseResult<T>): T {
	if (safeParseResult.success === true) {
		return safeParseResult.data;
	}

	const message = safeParseResult.error.issues[0]?.message ?? 'Invalid custom action payload';
	throw new Error(message);
}

function flattenIssues(issues: ValidationIssue[]): PayloadValidationError[] {
	return issues.map((issue) => ({
		path: issue.path.join('.'),
		message: issue.message
	}));
}

function validatePayload<T>(safeParseResult: SafeParseResult<T>): PayloadValidationResult<T> {
	if (safeParseResult.success === true) {
		return safeParseResult;
	}

	return {
		success: false,
		errors: flattenIssues(safeParseResult.error.issues)
	};
}

export const customActionCreateSchema = {
	safeParse: safeParseCreate,
	parse(data: unknown): CustomActionCreateInput {
		return parseResult(safeParseCreate(data));
	}
};

export const customActionUpdateSchema = {
	safeParse: safeParseUpdate,
	parse(data: unknown): CustomActionUpdateInput {
		return parseResult(safeParseUpdate(data));
	}
};

export function validateCreatePayload(data: unknown): PayloadValidationResult<CustomActionCreateInput> {
	return validatePayload(safeParseCreate(data));
}

export function validateUpdatePayload(data: unknown): PayloadValidationResult<CustomActionUpdateInput> {
	return validatePayload(safeParseUpdate(data));
}
