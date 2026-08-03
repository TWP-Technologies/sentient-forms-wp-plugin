import { createClientFromConfig, type RequestOptions } from '$lib/api/client';
import type { EndpointName, RegisteredEndpointResponse } from '$lib/api/endpoint-schemas';

export function wpRequestEndpoint<TName extends EndpointName>(
	name: TName,
	options: RequestOptions = {},
	pathOverride?: string
): Promise<RegisteredEndpointResponse<TName>> {
	return createClientFromConfig().requestEndpoint(name, options, pathOverride);
}
