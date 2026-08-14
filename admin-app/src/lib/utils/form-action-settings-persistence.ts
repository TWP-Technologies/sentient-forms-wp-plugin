import {
	parseRegisteredEndpointRequest,
	type ParsedEndpointRequest
} from '$lib/api/endpoint-schemas';

type PersistableFormActionSettings = NonNullable<
	ParsedEndpointRequest<'forms.actions.update'>['settings']
>;

export function sanitizeFormActionSettingsForPersistence(
	value: Record<string, unknown>
): PersistableFormActionSettings {
	const settings = { ...value };
	delete settings.updated_at;

	return parseRegisteredEndpointRequest('forms.actions.update', { settings }).settings ?? {};
}
