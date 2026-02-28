import * as z from 'zod';

/**
 * Zod schema for custom action validation.
 * Matches PHP sanitize_ constraints from class-custom-actions-controller.php
 */

/**
 * Code format: lowercase letters, numbers, and dashes only
 * Matches PHP: preg_replace('/[^a-z0-9\-]/', '', strtolower($value))
 */
const codeSchema = z
    .string()
    .min(1, 'Code is required')
    .regex(/^[a-z0-9-]+$/, 'Code must contain only lowercase letters, numbers, and dashes')
    .describe('Unique identifier for this action');

/**
 * UUID format for template IDs
 */
const uuidSchema = z
    .string()
    .min(1, 'Template ID is required')
    .uuid('Template ID must be a valid UUID');

/**
 * Prompt overrides: must be a JSON object (not array, not null)
 */
const promptOverridesSchema = z
    .record(z.string(), z.unknown())
    .optional()
    .describe('JSON object of prompt override key-value pairs');

const actionKindSchema = z.enum(['template_override', 'custom_definition']);
const executionModeSchema = z.enum(['validation', 'after_submission', 'real_time']);
const supportedExecutionModesSchema = z
    .array(executionModeSchema)
    .min(1, 'At least one execution mode is required');

const workflowNodeSchema = z
    .object({
        node_id: z.string().min(1, 'Workflow node_id is required'),
        kind: z.enum(['llm_step', 'transform_step', 'decision_step']),
        prompt_template: z.string().optional(),
        input_bindings: z.record(z.string(), z.unknown()).optional(),
        output_key: z.string().min(1, 'Workflow output_key is required'),
        timeout_ms: z.number().int().min(1, 'timeout_ms must be >= 1').optional()
    })
    .passthrough()
    .superRefine((node, ctx) => {
        if (node.kind === 'llm_step' && (!node.prompt_template || node.prompt_template.trim() === '')) {
            ctx.addIssue({
                code: z.ZodIssueCode.custom,
                path: ['prompt_template'],
                message: 'prompt_template is required for llm_step nodes'
            });
        }
    });

const workflowEdgeSchema = z.object({
    from: z.string().min(1, 'Workflow edge.from is required'),
    to: z.string().min(1, 'Workflow edge.to is required')
});

const workflowSchema = z
    .object({
        version: z.number().int().min(1, 'Workflow version must be >= 1').optional(),
        nodes: z.array(workflowNodeSchema).min(1, 'Workflow must include at least one node'),
        edges: z.array(workflowEdgeSchema).optional(),
        max_parallelism: z.number().int().min(1).max(16).optional(),
        retry_policy: z
            .object({
                max_attempts: z.number().int().min(1).optional(),
                backoff_ms: z.array(z.number().int().min(0)).optional()
            })
            .passthrough()
            .optional()
    })
    .passthrough()
    .superRefine((workflow, ctx) => {
        const nodeIds = workflow.nodes.map((node) => node.node_id.trim());
        const uniqueNodeIds = new Set(nodeIds);
        if (uniqueNodeIds.size !== nodeIds.length) {
            ctx.addIssue({
                code: z.ZodIssueCode.custom,
                path: ['nodes'],
                message: 'Workflow node_id values must be unique'
            });
        }

        for (const [index, edge] of (workflow.edges ?? []).entries()) {
            if (edge.from === edge.to) {
                ctx.addIssue({
                    code: z.ZodIssueCode.custom,
                    path: ['edges', index],
                    message: 'Workflow edges cannot be self-referential'
                });
            }
            if (!uniqueNodeIds.has(edge.from)) {
                ctx.addIssue({
                    code: z.ZodIssueCode.custom,
                    path: ['edges', index, 'from'],
                    message: `Unknown workflow node_id '${edge.from}'`
                });
            }
            if (!uniqueNodeIds.has(edge.to)) {
                ctx.addIssue({
                    code: z.ZodIssueCode.custom,
                    path: ['edges', index, 'to'],
                    message: `Unknown workflow node_id '${edge.to}'`
                });
            }
        }
    });

const definitionSchema = z
    .object({
        workflow: workflowSchema.optional()
    })
    .passthrough()
    .nullable()
    .optional();
const outputContractSchema = z.record(z.string(), z.unknown()).nullable().optional();

/**
 * Schema for creating a new custom action
 */
export const customActionCreateSchema = z
    .object({
        template_id: uuidSchema,
        code: codeSchema,
        display_name: z.string().min(1, 'Display name is required').trim(),
        description: z.string().trim().nullable().optional(),
        prompt_overrides: promptOverridesSchema,
        model_hint: z.string().trim().nullable().optional(),
        action_kind: actionKindSchema,
        definition: definitionSchema,
        definition_version: z.number().int().min(1, 'Definition version must be >= 1'),
        output_contract: outputContractSchema,
        supported_execution_modes: supportedExecutionModesSchema
    })
    .superRefine((data, ctx) => {
        if (data.action_kind === 'custom_definition' && !data.definition) {
            ctx.addIssue({
                code: z.ZodIssueCode.custom,
                path: ['definition'],
                message: 'definition is required when action_kind is custom_definition'
            });
        }
    });

/**
 * Schema for updating an existing custom action
 * Code and template_id cannot be changed after creation
 */
export const customActionUpdateSchema = z
    .object({
        display_name: z.string().min(1, 'Display name is required').trim().optional(),
        description: z.string().trim().nullable().optional(),
        prompt_overrides: promptOverridesSchema,
        model_hint: z.string().trim().nullable().optional(),
        action_kind: actionKindSchema,
        definition: definitionSchema,
        definition_version: z.number().int().min(1, 'Definition version must be >= 1'),
        output_contract: outputContractSchema,
        supported_execution_modes: supportedExecutionModesSchema,
        status: z.enum(['active', 'archived']).optional()
    })
    .superRefine((data, ctx) => {
        if (data.action_kind === 'custom_definition' && !data.definition) {
            ctx.addIssue({
                code: z.ZodIssueCode.custom,
                path: ['definition'],
                message: 'definition is required when action_kind is custom_definition'
            });
        }
    });

export type CustomActionCreateInput = z.infer<typeof customActionCreateSchema>;
export type CustomActionUpdateInput = z.infer<typeof customActionUpdateSchema>;

/**
 * Validate and parse custom action create payload
 */
export function validateCreatePayload(data: unknown): {
    success: true;
    data: CustomActionCreateInput;
} | {
    success: false;
    errors: Array<{ path: string; message: string }>;
} {
    const result = customActionCreateSchema.safeParse(data);
    if (result.success) {
        return { success: true, data: result.data };
    }
    return {
        success: false,
        errors: result.error.issues.map((issue) => ({
            path: issue.path.join('.'),
            message: issue.message
        }))
    };
}

/**
 * Validate and parse custom action update payload
 */
export function validateUpdatePayload(data: unknown): {
    success: true;
    data: CustomActionUpdateInput;
} | {
    success: false;
    errors: Array<{ path: string; message: string }>;
} {
    const result = customActionUpdateSchema.safeParse(data);
    if (result.success) {
        return { success: true, data: result.data };
    }
    return {
        success: false,
        errors: result.error.issues.map((issue) => ({
            path: issue.path.join('.'),
            message: issue.message
        }))
    };
}
