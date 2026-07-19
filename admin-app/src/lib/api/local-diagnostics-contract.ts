import { z } from 'zod';

export const localDiagnosticsSettingsResponseSchema = z.object({
	local_diagnostics_enabled: z.boolean(),
	updated_at: z.string().nullable()
});

export type LocalDiagnosticsSettingsResponse = z.infer<
	typeof localDiagnosticsSettingsResponseSchema
>;

export const localDiagnosticsBootstrapSchema = z.strictObject({
	enabled: z.boolean(),
	updatedAt: z.string().nullable().optional()
});

export type LocalDiagnosticsBootstrap = z.infer<typeof localDiagnosticsBootstrapSchema>;

export function parseLocalDiagnosticsBootstrap(payload: unknown): LocalDiagnosticsBootstrap | null {
	const result = localDiagnosticsBootstrapSchema.safeParse(payload);

	return result.success ? result.data : null;
}
