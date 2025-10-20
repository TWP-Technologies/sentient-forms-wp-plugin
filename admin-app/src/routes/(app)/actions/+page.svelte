<script lang="ts">
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
</script>

<section class="sf-space-y-4">
	<header class="sf-flex sf-items-center sf-justify-between">
		<div>
			<h2 class="sf-text-2xl sf-font-semibold">Actions</h2>
			<p class="sf-text-sm sf-text-slate-500">
				Configure CPS-backed workflows for your Gravity Forms submissions.
			</p>
		</div>
		<button
			class="sf-inline-flex sf-items-center sf-rounded sf-bg-slate-900 sf-px-3 sf-py-2 sf-text-sm sf-font-medium sf-text-white hover:sf-bg-slate-800"
			type="button"
		>
			New action
		</button>
	</header>

	<div class="sf-overflow-hidden sf-rounded-lg sf-border sf-border-slate-200 sf-bg-white sf-shadow-sm">
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
						<td class="sf-px-4 sf-py-3">{action.status}</td>
						<td class="sf-px-4 sf-py-3">
							{new Date(action.lastUpdated).toLocaleString()}
						</td>
					</tr>
				{/each}
			</tbody>
		</table>
	</div>
</section>
