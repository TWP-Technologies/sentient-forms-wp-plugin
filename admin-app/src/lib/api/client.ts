export interface ClientConfig {
	baseUrl: string;
	getNonce?: () => string | undefined;
	fetchImpl?: typeof fetch;
}

export interface RequestOptions extends Omit<RequestInit, 'body'> {
	body?: unknown;
}

export class SentientFormsApiClient {
	private baseUrl: URL;
	private getNonce?: () => string | undefined;
	private fetchImpl: typeof fetch;

	constructor(config: ClientConfig) {
		this.baseUrl = new URL(config.baseUrl, 'http://localhost');
		this.getNonce = config.getNonce;
		this.fetchImpl = config.fetchImpl ?? fetch;
	}

	async request<T>(path: string, options: RequestOptions = {}): Promise<T> {
		const url = new URL(path, this.baseUrl);
		const { body, headers, ...rest } = options;
		const nonce = this.getNonce?.();

		const response = await this.fetchImpl(url.toString(), {
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				...(nonce ? { 'X-WP-Nonce': nonce } : {}),
				...(headers as Record<string, string>)
			},
			body: body ? JSON.stringify(body) : undefined,
			...rest
		});

		if (!response.ok) {
			const errorPayload = await response.text();
			throw new Error(`API error ${response.status}: ${errorPayload}`);
		}

		if (response.status === 204) {
			return undefined as T;
		}

		const text = await response.text();
		return text.length ? (JSON.parse(text) as T) : (undefined as T);
	}
}

export const mockClient = new SentientFormsApiClient({
	baseUrl: 'https://example.test/wp-json/sentient-forms/v1/'
});
