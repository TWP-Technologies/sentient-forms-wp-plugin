import { describe, expect, it } from 'vitest';
import { findZodGuardViolations } from '../../scripts/zod-guard';

const bypassCases = [
	{
		name: 'contextually typed imported aliases',
		source:
			"import { wpFetch as load } from '$lib/wp';\nconst payload: Secret = await load('secrets');",
		rule: 'raw-network-call'
	},
	{
		name: 'multiline generic network calls',
		source: "import { wpFetch } from '$lib/wp';\nawait wpFetch<\nSecret\n>('secrets');",
		rule: 'generic-network-trust-cast'
	},
	{
		name: 'assertions over parsed boundary calls',
		source: "const payload = (await client.requestParsed('x', schema)) as Secret;",
		rule: 'boundary-type-assertion'
	},
	{
		name: 'local aliases of raw network imports',
		source: "import { wpFetch } from '$lib/wp';\nconst bypass = wpFetch;\nawait bypass('secrets');",
		rule: 'raw-network-call'
	},
	{
		name: 'wrapper functions around fetch',
		source: "function raw() { return fetch('/secrets'); }\nraw();",
		rule: 'raw-network-call'
	},
	{
		name: 'unparsed browser storage reads',
		source: "const raw = localStorage.getItem('secret');\nconst value = JSON.parse(raw ?? 'null');",
		rule: 'unparsed-storage-read'
	},
	{
		name: 'assertion-based alias bypasses',
		source:
			"import { wpFetch } from '$lib/wp';\nconst bypass = wpFetch as unknown as Loader;\nawait bypass('secrets');",
		rule: 'boundary-type-assertion'
	},
	{
		name: 'legacy client request calls without generics',
		source: "await client.request('secrets');",
		rule: 'raw-network-call'
	},
	{
		name: 'namespace-imported raw transports',
		source: "import * as wordpress from '$lib/wp';\nawait wordpress.wpFetch('secrets');",
		rule: 'raw-network-call'
	},
	{
		name: 'destructured aliases from namespace-imported raw transports',
		source:
			"import * as transport from '$lib/api/http';\nconst { apiFetch: bypass } = transport;\nawait bypass('/secrets');",
		rule: 'raw-network-call'
	},
	{
		name: 'globalThis fetch access',
		source: "await globalThis.fetch('/secrets');",
		rule: 'raw-network-call'
	},
	{
		name: 'direct runtime config reads',
		source: 'const nonce = window.sentientFormsConfig?.restNonce;',
		rule: 'unparsed-runtime-config'
	},
	{
		name: 'computed runtime config reads',
		source: "const nonce = window['sentientFormsConfig'];",
		rule: 'unparsed-runtime-config'
	},
	{
		name: 'reflected runtime config reads',
		source: "const nonce = Reflect.get(window, 'sentientFormsConfig');",
		rule: 'unparsed-runtime-config'
	},
	{
		name: 'destructured runtime config reads through aliases',
		source: 'const browser = window; const { sentientFormsConfig: config } = browser;',
		rule: 'unparsed-runtime-config'
	},
	{
		name: 'computed global fetch access',
		source: "await globalThis['fetch']('/secrets');",
		rule: 'raw-network-call'
	},
	{
		name: 'reflected global fetch access',
		source: "await Reflect.get(globalThis, 'fetch')('/secrets');",
		rule: 'raw-network-call'
	},
	{
		name: 'computed namespace transport access',
		source: "import * as transport from '$lib/wp';\nawait transport['wpFetch']('/secrets');",
		rule: 'raw-network-call'
	},
	{
		name: 'reflected namespace transport access',
		source:
			"import * as transport from '$lib/api/http';\nawait Reflect.get(transport, 'apiFetch')('/secrets');",
		rule: 'raw-network-call'
	},
	{
		name: 'string-key destructured namespace transports',
		source:
			"import * as transport from '$lib/wp';\nconst { 'wpFetch': load } = transport;\nawait load('/secrets');",
		rule: 'raw-network-call'
	},
	{
		name: 'computed browser storage reads',
		source: "const raw = localStorage['getItem']('secret');",
		rule: 'unparsed-storage-read'
	},
	{
		name: 'reflected browser storage reads',
		source: "const raw = Reflect.get(localStorage, 'getItem')('secret');",
		rule: 'unparsed-storage-read'
	},
	{
		name: 'aliased computed browser storage reads',
		source: "const read = localStorage['getItem'];\nconst raw = read('secret');",
		rule: 'unparsed-storage-read'
	},
	{
		name: 'aliased global fetch objects',
		source: "const browser = globalThis;\nconst load = browser['fetch'];\nawait load('/secrets');",
		rule: 'raw-network-call'
	},
	{
		name: 'computed JSON parsing',
		source: "const value = JSON['parse'](raw);",
		rule: 'unparsed-json'
	},
	{
		name: 'destructured global fetch access',
		source: "const { fetch: load } = globalThis;\nawait load('/secrets');",
		rule: 'raw-network-call'
	},
	{
		name: 'destructured browser storage reads',
		source: "const { getItem: read } = localStorage;\nconst raw = read('secret');",
		rule: 'unparsed-storage-read'
	},
	{
		name: 'reflected JSON parsing',
		source: "const value = Reflect.get(JSON, 'parse')(raw);",
		rule: 'unparsed-json'
	},
	{
		name: 'destructured JSON parsing',
		source: 'const { parse } = JSON;\nconst value = parse(raw);',
		rule: 'unparsed-json'
	},
	{
		name: 'computed global runtime config reads',
		source: "const config = globalThis['sentientFormsConfig'];",
		rule: 'unparsed-runtime-config'
	},
	{
		name: 'reflected global runtime config reads',
		source: "const config = Reflect.get(globalThis, 'sentientFormsConfig');",
		rule: 'unparsed-runtime-config'
	},
	{
		name: 'destructured global runtime config reads',
		source: 'const { sentientFormsConfig: config } = globalThis;',
		rule: 'unparsed-runtime-config'
	},
	{
		name: 'arrow wrappers around imported transports',
		source:
			"import { wpFetch } from '$lib/wp';\nconst load = () => wpFetch('secrets');\nawait load();",
		rule: 'raw-network-call'
	}
] as const;

describe('Zod trust-boundary guard', () => {
	for (const testCase of bypassCases) {
		it(`rejects ${testCase.name}`, () => {
			const violations = findZodGuardViolations('src/routes/example/+page.ts', testCase.source);
			expect(violations).toEqual(
				expect.arrayContaining([expect.objectContaining({ rule: testCase.rule })])
			);
		});
	}

	it('allows named parsed endpoints and schema-owned JSON parsing', () => {
		const source = [
			"const response = await client.requestEndpoint('settings.read');",
			'const parsed = settingsSchema.parse(JSON.parse(raw));'
		].join('\n');

		expect(findZodGuardViolations('src/lib/settings.ts', source)).toEqual([]);
	});

	it('rejects generic public request methods even inside the transport module', () => {
		const source = [
			'class Client {',
			'  async request<T>(path: string): Promise<T> {',
			'    return (await this.requestUnknown(path)) as T;',
			'  }',
			'  private requestUnknown(path: string): Promise<unknown> { throw new Error(path); }',
			'}'
		].join('\n');

		expect(findZodGuardViolations('src/lib/api/client.ts', source)).toEqual(
			expect.arrayContaining([expect.objectContaining({ rule: 'generic-network-trust-cast' })])
		);
	});

	it('rejects generic raw transport functions inside the transport module', () => {
		const source = [
			'export async function apiFetch<T>(path: string): Promise<T> {',
			'  return (await fetch(path).then((response) => response.json())) as T;',
			'}'
		].join('\n');

		expect(findZodGuardViolations('src/lib/api/http.ts', source)).toEqual(
			expect.arrayContaining([expect.objectContaining({ rule: 'generic-network-trust-cast' })])
		);
	});
});
