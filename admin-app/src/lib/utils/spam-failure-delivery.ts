import type { FormActionConfig, FormActionLinkage } from '$lib/api/types';

type SuppressionSettings = Pick<
	FormActionConfig,
	'suppress_notifications_on_spam' | 'suppress_webhooks_on_spam'
>;
export type SpamFailureDeliveryPolicy = NonNullable<
	NonNullable<FormActionLinkage['settings']>['spam_failure_delivery_policy']
>;
export type SpamDeliveryHolds = {
	state: 'active' | 'inactive' | 'unavailable';
	channels: string[];
};

export function resolveSpamDeliveryHolds(input: {
	notificationCapability: boolean | undefined;
	webhookCapability: boolean | undefined;
	applies: boolean | undefined;
	mapping: SuppressionSettings;
	form: SuppressionSettings | undefined;
	action: SuppressionSettings | undefined;
}): SpamDeliveryHolds {
	const channels: string[] = [];
	let unknown = false;
	for (const [key, capable, label] of [
		['suppress_notifications_on_spam', input.notificationCapability, 'notifications'],
		['suppress_webhooks_on_spam', input.webhookCapability, 'Webhooks']
	] as const) {
		// A missing response differs from a loaded config with no override.
		let suppress = input.mapping[key];
		if (suppress === undefined && input.form !== undefined) {
			suppress = input.form[key];
			if (suppress === undefined && input.action !== undefined)
				suppress = input.action[key];
		}
		if (suppress === false || capable === false || input.applies === false) continue;
		if (suppress === undefined || capable === undefined || input.applies === undefined)
			unknown = true;
		else channels.push(label);
	}
	return { state: unknown ? 'unavailable' : channels.length ? 'active' : 'inactive', channels };
}
