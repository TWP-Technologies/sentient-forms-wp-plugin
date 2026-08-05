import { endpointRegistry, type RegisteredEndpointResponse } from '$lib/api/endpoint-schemas';
import type { PluginSettingsResponse } from '$lib/api/types';

export const SETTINGS_UPDATED_EVENT = 'sentient-forms:settings-updated';
const updatedFieldsByEvent = new WeakMap<Event, readonly string[]>();

export function parseSettingsUpdatedEvent(event: Event): PluginSettingsResponse | null {
	const result = endpointRegistry['settings.read'].response.safeParse(Reflect.get(event, 'detail'));

	return result.success ? result.data : null;
}

export function settingsUpdatedFields(event: Event): readonly string[] | null {
	return updatedFieldsByEvent.get(event) ?? null;
}

export function announceSettingsUpdated(
	settings: PluginSettingsResponse,
	updatedFields: readonly string[] = []
): boolean {
	if (typeof window === 'undefined') return false;

	const event = new CustomEvent(SETTINGS_UPDATED_EVENT, {
		detail: settings
	});
	updatedFieldsByEvent.set(event, [...updatedFields]);
	window.dispatchEvent(event);
	return true;
}

export function announceSettingsUpdateResponse(
	response: RegisteredEndpointResponse<'settings.update'>,
	updatedFields: readonly string[] = []
): boolean {
	return announceSettingsUpdated(
		'settings' in response ? response.settings : response,
		updatedFields
	);
}
