type ManagedZdrSettingsLoader = () => Promise<{ managed_zdr_required?: boolean | null }>;

let managedZdrRequirementRequest: Promise<boolean> | null = null;

export async function loadSharedManagedZdrRequirement(
	loadSettings: ManagedZdrSettingsLoader
): Promise<boolean> {
	if (managedZdrRequirementRequest === null) {
		managedZdrRequirementRequest = loadSettings()
			.then((settings) => Boolean(settings.managed_zdr_required))
			.finally(() => {
				managedZdrRequirementRequest = null;
			});
	}

	return managedZdrRequirementRequest;
}

export function resetSharedManagedZdrRequirementForTests(): void {
	managedZdrRequirementRequest = null;
}
