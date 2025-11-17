export function sanitizeCustomActionCode(value: string): string {
	return value.toLowerCase().replace(/[^a-z0-9-]/g, '');
}

export function parsePromptOverridesInput(raw: string): {
	result?: Record<string, unknown>;
	error?: string;
} {
	if (!raw.trim()) {
		return { result: undefined, error: undefined };
	}

	try {
		const parsed = JSON.parse(raw);
		if (parsed === null || Array.isArray(parsed) || typeof parsed !== 'object') {
			return { error: 'Prompt overrides must be a JSON object.' };
		}
		return { result: parsed as Record<string, unknown> };
	} catch (error) {
		return {
			error:
				error instanceof Error
					? error.message || 'Prompt overrides must be valid JSON.'
					: 'Prompt overrides must be valid JSON.'
		};
	}
}
