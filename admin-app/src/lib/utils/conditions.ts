import type {
	ConditionGroup,
	ConditionNode,
	ConditionOperator,
	ConditionRule,
	MappingConditionsConfig
} from '$lib/api/types';

export const CONDITION_OPERATORS: ConditionOperator[] = [
	'eq',
	'neq',
	'contains',
	'not_contains',
	'starts_with',
	'ends_with',
	'in',
	'not_in',
	'is_empty',
	'is_not_empty',
	'gt',
	'gte',
	'lt',
	'lte'
];

export const MAX_CONDITION_DEPTH = 3;
export const MAX_CONDITION_NODES = 50;

export function createDefaultConditionRule(fieldId = ''): ConditionRule {
	return {
		type: 'rule',
		field_id: fieldId,
		operator: 'eq',
		value: ''
	};
}

export function createDefaultConditionGroup(): ConditionGroup {
	return {
		type: 'group',
		logic: 'all',
		rules: []
	};
}

export function createDefaultConditionConfig(): MappingConditionsConfig {
	return {
		enabled: false,
		root: createDefaultConditionGroup()
	};
}

function operatorRequiresValue(operator: ConditionOperator): boolean {
	return operator !== 'is_empty' && operator !== 'is_not_empty';
}

function operatorRequiresList(operator: ConditionOperator): boolean {
	return operator === 'in' || operator === 'not_in';
}

function operatorRequiresNumeric(operator: ConditionOperator): boolean {
	return operator === 'gt' || operator === 'gte' || operator === 'lt' || operator === 'lte';
}

function isRule(node: ConditionNode): node is ConditionRule {
	return node.type === 'rule';
}

function isGroup(node: ConditionNode): node is ConditionGroup {
	return node.type === 'group';
}

function normalizeListValue(value: ConditionRule['value']): string[] {
	if (Array.isArray(value)) {
		return value.map((item) => String(item).trim()).filter(Boolean);
	}

	if (typeof value === 'string') {
		return value
			.split(',')
			.map((item) => item.trim())
			.filter(Boolean);
	}

	return [];
}

function validateNode(
	node: ConditionNode,
	errors: string[],
	depth: number,
	nodeCounter: { count: number }
) {
	nodeCounter.count += 1;
	if (nodeCounter.count > MAX_CONDITION_NODES) {
		errors.push(`Condition tree exceeds the maximum of ${MAX_CONDITION_NODES} nodes.`);
		return;
	}

	if (depth > MAX_CONDITION_DEPTH) {
		errors.push(`Condition groups can only be nested ${MAX_CONDITION_DEPTH} levels deep.`);
		return;
	}

	if (isGroup(node)) {
		if (!Array.isArray(node.rules)) {
			errors.push('Condition group rules must be an array.');
			return;
		}

		for (const child of node.rules) {
			const nextDepth = child.type === 'group' ? depth + 1 : depth;
			validateNode(child, errors, nextDepth, nodeCounter);
		}
		return;
	}

	if (!node.field_id || !node.field_id.trim()) {
		errors.push('Each condition rule must target a form field.');
	}

	if (!CONDITION_OPERATORS.includes(node.operator)) {
		errors.push(`Unsupported operator "${node.operator}" in a condition rule.`);
		return;
	}

	if (!operatorRequiresValue(node.operator)) {
		return;
	}

	if (operatorRequiresList(node.operator)) {
		const values = normalizeListValue(node.value);
		if (values.length === 0) {
			errors.push('The in/not_in operators require at least one comparison value.');
		}
		return;
	}

	if (operatorRequiresNumeric(node.operator)) {
		if (node.value === undefined || node.value === null || node.value === '') {
			errors.push('Numeric operators require a numeric comparison value.');
			return;
		}

		if (Number.isNaN(Number(node.value))) {
			errors.push('Numeric operators require a valid number.');
		}
		return;
	}

	if (node.value === undefined || node.value === null || String(node.value).trim() === '') {
		errors.push('This operator requires a comparison value.');
	}
}

export function validateConditionConfig(config: MappingConditionsConfig): string[] {
	const errors: string[] = [];
	if (!config.enabled) {
		return errors;
	}

	if (!config.root || config.root.type !== 'group') {
		errors.push('Conditional run requires a root condition group.');
		return errors;
	}

	validateNode(config.root, errors, 1, { count: 0 });
	return errors;
}
