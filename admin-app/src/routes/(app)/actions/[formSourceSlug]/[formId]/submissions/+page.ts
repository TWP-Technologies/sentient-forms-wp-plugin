import { error } from '@sveltejs/kit';
import { z } from 'zod';
import type { PageLoad } from './$types';

const paramsSchema = z.object({
	formSourceSlug: z.string().trim().min(1),
	formId: z.string().trim().min(1)
});

export const load: PageLoad = ({ params }) => {
	const parsed = paramsSchema.safeParse(params);

	if (!parsed.success) {
		throw error(404, 'Invalid form identifier');
	}

	return {
		formSourceSlug: parsed.data.formSourceSlug,
		formId: parsed.data.formId
	};
};
