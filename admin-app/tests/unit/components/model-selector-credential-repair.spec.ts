import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render } from '@testing-library/svelte';
import { tick } from 'svelte';
import ModelSelector from '$lib/components/ui/model-selector.svelte';
import type { LocalProviderCredential, ModelSelection } from '$lib/api/types';
import { wpRequestEndpoint } from '$lib/wp';

vi.mock('@lucide/svelte/icons/refresh-cw', () => ({
	default: () => undefined
}));

vi.mock('$lib/api/client', () => ({
	createClientFromConfig: () => ({
		getOpenRouterModels: vi.fn().mockResolvedValue({ refresh_consent: null }),
		getSettings: vi.fn().mockResolvedValue({})
	})
}));

vi.mock('$lib/wp', () => ({
	wpRequestEndpoint: vi.fn(async (name: string) => {
		if (name === 'models.catalog') {
			return {
				models: [],
				presets: [
					{
						code: 'sf_default',
						display_name: 'Recommended',
						description: 'Test preset',
						category: 'local',
						resolved_model_id: 'openrouter/auto',
						auto_upgrade: true
					}
				]
			};
		}
		if (name === 'models.resolve') {
			return {
				model_id: 'openrouter/auto',
				display_name: 'OpenRouter Auto',
				resolution_source: 'preset',
				override_chain: []
			};
		}
		return [];
	})
}));

vi.mock('$lib/utils/managed-zdr-requirement-cache', () => ({
	loadSharedManagedZdrRequirement: vi.fn().mockResolvedValue(false)
}));

function credential(id: number): LocalProviderCredential {
	return {
		id,
		provider: 'openrouter',
		label: `OpenRouter ${id}`,
		auth_mode: 'api_key',
		constant_name: null,
		status: 'valid',
		status_json: null,
		last_validated_at: null,
		created_at: null,
		updated_at: null,
		secret_configured: true
	};
}

const staleSelection: ModelSelection = {
	primary: 'sf_default',
	backup: '',
	is_preset: true,
	provider: 'openrouter',
	credential_id: 61
};

async function settle() {
	await tick();
	await Promise.resolve();
	await tick();
}

afterEach(() => {
	cleanup();
});

beforeEach(() => {
	vi.clearAllMocks();
});

describe('ModelSelector credential repair', () => {
	it('does not clear an initially valid supplied credential in a controlled parent', async () => {
		const changes: ModelSelection[] = [];
		let controlledValue = staleSelection;
		const view = render(ModelSelector, {
			value: controlledValue,
			providerCredentials: [credential(61)],
			managedZdrRequired: false,
			onchange: (selection: ModelSelection) => {
				changes.push(selection);
				controlledValue = selection;
				void view.rerender({ value: controlledValue });
			}
		});
		await settle();

		expect(changes.some((selection) => selection.credential_id === null)).toBe(false);
		expect(controlledValue.credential_id).toBe(61);
	});

	it('emits a repaired ready credential for an existing stale selection', async () => {
		const changes: ModelSelection[] = [];
		render(ModelSelector, {
			value: staleSelection,
			providerCredentials: [credential(64)],
			managedZdrRequired: false,
			onchange: (selection: ModelSelection) => changes.push(selection)
		});

		await settle();
		expect(changes.some((selection) => selection.credential_id === 64)).toBe(true);
	});

	it('repairs credentials changed while the picker is open when it closes', async () => {
		const changes: ModelSelection[] = [];
		const view = render(ModelSelector, {
			value: staleSelection,
			providerCredentials: [credential(61)],
			managedZdrRequired: false,
			onchange: (selection: ModelSelection) => changes.push(selection)
		});
		await settle();

		await fireEvent.click(view.getByTestId('model-selector-open'));
		await settle();
		await view.rerender({ providerCredentials: [credential(64)] });
		await settle();
		await fireEvent.click(view.getByTestId('model-selector-close'));
		await settle();

		expect(changes.at(-1)?.credential_id).toBe(64);
	});

	it('repairs internally fetched credentials that resolve while the picker is open', async () => {
		let resolveCredentials: (credentials: LocalProviderCredential[]) => void = () => undefined;
		const credentialsResponse = new Promise<LocalProviderCredential[]>((resolve) => {
			resolveCredentials = resolve;
		});
		vi.mocked(wpRequestEndpoint).mockImplementation(async (name: string) => {
			if (name === 'providers.credentials.list') return credentialsResponse as never;
			if (name === 'models.catalog') {
				return {
					models: [],
					presets: [
						{
							code: 'sf_default',
							display_name: 'Recommended',
							description: 'Test preset',
							category: 'local',
							resolved_model_id: 'openrouter/auto',
							auto_upgrade: true
						}
					]
				} as never;
			}
			if (name === 'models.resolve') {
				return {
					model_id: 'openrouter/auto',
					display_name: 'OpenRouter Auto',
					resolution_source: 'preset',
					override_chain: []
				} as never;
			}
			return [] as never;
		});

		const changes: ModelSelection[] = [];
		const view = render(ModelSelector, {
			value: staleSelection,
			managedZdrRequired: false,
			onchange: (selection: ModelSelection) => changes.push(selection)
		});
		await settle();

		await fireEvent.click(view.getByTestId('model-selector-open'));
		resolveCredentials([credential(64)]);
		await settle();
		await fireEvent.click(view.getByTestId('model-selector-close'));
		await settle();

		expect(changes.at(-1)?.credential_id).toBe(64);
	});

	it('does not clear a valid saved credential before internal credentials finish loading', async () => {
		let resolveCredentials: (credentials: LocalProviderCredential[]) => void = () => undefined;
		const credentialsResponse = new Promise<LocalProviderCredential[]>((resolve) => {
			resolveCredentials = resolve;
		});
		vi.mocked(wpRequestEndpoint).mockImplementation(async (name: string) => {
			if (name === 'providers.credentials.list') return credentialsResponse as never;
			if (name === 'models.catalog') return { models: [], presets: [] } as never;
			if (name === 'models.resolve') return {} as never;
			return [] as never;
		});

		const changes: ModelSelection[] = [];
		let controlledValue = staleSelection;
		const view = render(ModelSelector, {
			value: controlledValue,
			managedZdrRequired: false,
			onchange: (selection: ModelSelection) => {
				changes.push(selection);
				controlledValue = selection;
				void view.rerender({ value: controlledValue });
			}
		});
		await settle();

		expect(changes.some((selection) => selection.credential_id === null)).toBe(false);
		resolveCredentials([credential(61)]);
		await settle();

		expect(changes.some((selection) => selection.credential_id === null)).toBe(false);
		expect(controlledValue.credential_id).toBe(61);
	});

	it('fails closed after a known-empty credential response resolves while open', async () => {
		let resolveCredentials: (credentials: LocalProviderCredential[]) => void = () => undefined;
		const credentialsResponse = new Promise<LocalProviderCredential[]>((resolve) => {
			resolveCredentials = resolve;
		});
		vi.mocked(wpRequestEndpoint).mockImplementation(async (name: string) => {
			if (name === 'providers.credentials.list') return credentialsResponse as never;
			if (name === 'models.catalog') return { models: [], presets: [] } as never;
			if (name === 'models.resolve') return {} as never;
			return [] as never;
		});

		const changes: ModelSelection[] = [];
		const view = render(ModelSelector, {
			value: staleSelection,
			managedZdrRequired: false,
			onchange: (selection: ModelSelection) => changes.push(selection)
		});
		await settle();
		await fireEvent.click(view.getByTestId('model-selector-open'));
		resolveCredentials([]);
		await settle();
		await fireEvent.click(view.getByTestId('model-selector-close'));
		await settle();

		expect(changes.at(-1)?.credential_id).toBeNull();
	});

	it('preserves the saved credential when internal credential loading fails', async () => {
		vi.mocked(wpRequestEndpoint).mockImplementation(async (name: string) => {
			if (name === 'providers.credentials.list') throw new Error('temporary failure');
			if (name === 'models.catalog') return { models: [], presets: [] } as never;
			if (name === 'models.resolve') return {} as never;
			return [] as never;
		});

		const changes: ModelSelection[] = [];
		render(ModelSelector, {
			value: staleSelection,
			managedZdrRequired: false,
			onchange: (selection: ModelSelection) => changes.push(selection)
		});
		await settle();

		expect(changes.some((selection) => selection.credential_id === null)).toBe(false);
	});

	it('repairs credentials before a picker selection emits and closes the dialog', async () => {
		const changes: ModelSelection[] = [];
		const view = render(ModelSelector, {
			value: staleSelection,
			providerCredentials: [credential(61)],
			managedZdrRequired: false,
			onchange: (selection: ModelSelection) => changes.push(selection)
		});
		await settle();

		await fireEvent.click(view.getByTestId('model-selector-open'));
		await view.rerender({ providerCredentials: [credential(64)] });
		await settle();
		changes.length = 0;
		await fireEvent.click(view.getByTestId('model-preset-sf_default'));
		await settle();

		expect(changes.length).toBeGreaterThan(0);
		expect(changes.some((selection) => selection.credential_id === 61)).toBe(false);
		expect(changes.at(-1)?.credential_id).toBe(64);
		expect(view.queryByTestId('model-selector-dialog')).toBeNull();
	});

	it('clears supplied credentials and reloads when control returns to internal loading', async () => {
		let resolveCredentials: (credentials: LocalProviderCredential[]) => void = () => undefined;
		const credentialsResponse = new Promise<LocalProviderCredential[]>((resolve) => {
			resolveCredentials = resolve;
		});
		vi.mocked(wpRequestEndpoint).mockImplementation(async (name: string) => {
			if (name === 'providers.credentials.list') return credentialsResponse as never;
			if (name === 'models.catalog') return { models: [], presets: [] } as never;
			if (name === 'models.resolve') return {} as never;
			return [] as never;
		});

		const changes: ModelSelection[] = [];
		const view = render(ModelSelector, {
			value: staleSelection,
			providerCredentials: [credential(61)],
			managedZdrRequired: false,
			onchange: (selection: ModelSelection) => changes.push(selection)
		});
		await settle();
		await view.rerender({ providerCredentials: null });
		await settle();

		expect(changes.some((selection) => selection.credential_id === null)).toBe(false);
		resolveCredentials([credential(64)]);
		await settle();
		expect(changes.at(-1)?.credential_id).toBe(64);
	});

	it('does not auto-select an ambiguous credential when the execution route changes', async () => {
		const managedCredential = {
			...credential(62),
			provider: 'sentient_managed' as const,
			label: 'Managed 62'
		};
		const changes: ModelSelection[] = [];
		const view = render(ModelSelector, {
			value: { ...staleSelection, credential_id: 62, provider: 'sentient_managed' },
			providerCredentials: [managedCredential, credential(64), credential(65)],
			managedZdrRequired: false,
			onchange: (selection: ModelSelection) => changes.push(selection)
		});
		await settle();
		await fireEvent.click(view.getByTestId('model-selector-open'));
		const routeSelect = view.getByLabelText('Execution route') as HTMLSelectElement;
		await fireEvent.change(routeSelect, { target: { value: 'openrouter' } });
		await settle();

		expect(changes.at(-1)?.provider).toBe('openrouter');
		expect(changes.at(-1)?.credential_id).toBeNull();
	});
});
