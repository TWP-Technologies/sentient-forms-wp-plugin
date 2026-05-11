export const load = ({ params }: { params: { formSourceSlug: string; formId: string } }) => ({
	formSourceSlug: params.formSourceSlug,
	formId: Number(params.formId)
});
