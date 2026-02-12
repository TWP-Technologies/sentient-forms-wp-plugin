import { z } from 'zod';

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

/**
 * Schema for creating a new custom action
 */
export const customActionCreateSchema = z.object({
    template_id: uuidSchema,
    code: codeSchema,
    display_name: z.string().min(1, 'Display name is required').trim(),
    description: z.string().trim().nullable().optional(),
    prompt_overrides: promptOverridesSchema,
    model_hint: z.string().trim().nullable().optional()
});

/**
 * Schema for updating an existing custom action
 * Code and template_id cannot be changed after creation
 */
export const customActionUpdateSchema = z.object({
    display_name: z.string().min(1, 'Display name is required').trim().optional(),
    description: z.string().trim().nullable().optional(),
    prompt_overrides: promptOverridesSchema,
    model_hint: z.string().trim().nullable().optional(),
    status: z.enum(['active', 'archived']).optional()
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
