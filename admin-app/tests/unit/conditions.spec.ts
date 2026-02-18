import { describe, expect, it } from 'vitest';
import type { ConditionGroup, ConditionNode, MappingConditionsConfig } from '$lib/api/types';
import {
	createDefaultConditionConfig,
	MAX_CONDITION_DEPTH,
	MAX_CONDITION_NODES,
	validateConditionConfig
} from '$lib/utils/conditions';

function createNestedGroup(depth: number, leaf: ConditionNode): ConditionGroup {
	if (depth <= 1) {
		return {
			type: 'group',
			logic: 'all',
			rules: [leaf]
		};
	}

	return {
		type: 'group',
		logic: 'all',
		rules: [createNestedGroup(depth - 1, leaf)]
	};
}

describe('validateConditionConfig (CB-FORMS-006)', () => {
	it('returns no errors when conditional run is disabled', () => {
		const config = createDefaultConditionConfig();
		config.enabled = false;
		expect(validateConditionConfig(config)).toEqual([]);
	});

	it('returns an error when root group is missing', () => {
		const config = {
			enabled: true
		} as MappingConditionsConfig;

		expect(validateConditionConfig(config)).toContain(
			'Conditional run requires a root condition group.'
		);
	});

	it('requires a field id for each rule', () => {
		const config: MappingConditionsConfig = {
			enabled: true,
			root: {
				type: 'group',
				logic: 'all',
				rules: [
					{
						type: 'rule',
						field_id: '',
						operator: 'eq',
						value: 'x'
					}
				]
			}
		};

		expect(validateConditionConfig(config)).toContain('Each condition rule must target a form field.');
	});

	it('rejects unsupported operators', () => {
		const config: MappingConditionsConfig = {
			enabled: true,
			root: {
				type: 'group',
				logic: 'all',
				rules: [
					{
						type: 'rule',
						field_id: '1',
						operator: 'made_up' as any,
						value: 'x'
					}
				]
			}
		};

		const errors = validateConditionConfig(config);
		expect(errors.some((error) => error.includes('Unsupported operator'))).toBe(true);
	});

	it('requires at least one list value for in/not_in operators', () => {
		const config: MappingConditionsConfig = {
			enabled: true,
			root: {
				type: 'group',
				logic: 'all',
				rules: [
					{
						type: 'rule',
						field_id: '2',
						operator: 'in',
						value: []
					}
				]
			}
		};

		expect(validateConditionConfig(config)).toContain(
			'The in/not_in operators require at least one comparison value.'
		);
	});

	it('requires numeric values for numeric operators', () => {
		const config: MappingConditionsConfig = {
			enabled: true,
			root: {
				type: 'group',
				logic: 'all',
				rules: [
					{
						type: 'rule',
						field_id: '3',
						operator: 'gt',
						value: 'not-a-number'
					}
				]
			}
		};

		expect(validateConditionConfig(config)).toContain('Numeric operators require a valid number.');
	});

	it('allows a rule nested within MAX_CONDITION_DEPTH groups', () => {
		const config: MappingConditionsConfig = {
			enabled: true,
			root: createNestedGroup(MAX_CONDITION_DEPTH, {
				type: 'rule',
				field_id: '4',
				operator: 'eq',
				value: 'run'
			})
		};

		expect(validateConditionConfig(config)).toEqual([]);
	});

	it('rejects groups nested deeper than MAX_CONDITION_DEPTH', () => {
		const config: MappingConditionsConfig = {
			enabled: true,
			root: createNestedGroup(MAX_CONDITION_DEPTH + 1, {
				type: 'rule',
				field_id: '4',
				operator: 'eq',
				value: 'run'
			})
		};

		const errors = validateConditionConfig(config);
		expect(
			errors.includes(`Condition groups can only be nested ${MAX_CONDITION_DEPTH} levels deep.`)
		).toBe(true);
	});

	it('rejects condition trees that exceed MAX_CONDITION_NODES', () => {
		const manyRules: ConditionNode[] = [];
		for (let index = 0; index < MAX_CONDITION_NODES; index += 1) {
			manyRules.push({
				type: 'rule',
				field_id: String(index + 1),
				operator: 'is_empty'
			});
		}

		const config: MappingConditionsConfig = {
			enabled: true,
			root: {
				type: 'group',
				logic: 'all',
				rules: manyRules
			}
		};

		expect(validateConditionConfig(config)).toContain(
			`Condition tree exceeds the maximum of ${MAX_CONDITION_NODES} nodes.`
		);
	});
});
