import { redirect } from '@sveltejs/kit';
import { appPath } from '$lib/navigation';

export const load = () => {
	throw redirect(302, appPath('/dashboard'));
};
