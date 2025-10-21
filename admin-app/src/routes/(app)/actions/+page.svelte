<script lang="ts">
	import { Section, Button, Card, Badge } from '$lib/components/ui';

	const mockActions = [
		{
			id: 'spam-detection',
			label: 'Spam detection',
			status: 'Enabled',
			lastUpdated: '2025-10-18T15:32:00Z'
		},
		{
			id: 'entry-summarizer',
			label: 'Entry summarizer',
			status: 'Draft',
			lastUpdated: '2025-10-12T12:00:00Z'
		}
	];

	const statusVariant = (status: string) => {
		return status === 'Enabled' ? 'success' : status === 'Draft' ? 'warning' : 'info';
	};
</script>

<Section heading="Actions" description="Configure CPS-backed workflows for your Gravity Forms submissions.">
	<Button slot="actions">New action</Button>

	<Card>
		<div class="sf-overflow-x-auto">
			<table class="sf-min-w-full sf-divide-y sf-divide-slate-200">
				<thead class="sf-bg-slate-50">
					<tr class="sf-text-left sf-text-xs sf-font-semibold sf-uppercase sf-tracking-wide sf-text-slate-600">
						<th class="sf-px-4 sf-py-3">Action</th>
						<th class="sf-px-4 sf-py-3">Status</th>
						<th class="sf-px-4 sf-py-3">Last updated</th>
					</tr>
				</thead>
				<tbody class="sf-divide-y sf-divide-slate-200">
					{#each mockActions as action}
						<tr class="sf-text-sm sf-text-slate-700">
							<td class="sf-px-4 sf-py-3 sf-font-medium">{action.label}</td>
							<td class="sf-px-4 sf-py-3">
								<Badge variant={statusVariant(action.status)}>{action.status}</Badge>
							</td>
							<td class="sf-px-4 sf-py-3">
								{new Date(action.lastUpdated).toLocaleString()}
							</td>
						</tr>
					{/each}
				</tbody>
			</table>
		</div>
	</Card>
</Section>
