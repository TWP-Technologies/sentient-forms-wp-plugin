<script lang="ts">
	import Alert from '$lib/components/ui/alert.svelte';
	import type { ValidationIssue } from './types';

	export let heading = 'Please correct the following:';
	export let issues: ValidationIssue[] = [];
</script>

{#if issues.length}
	<Alert variant="danger" role="alert" class="sf-space-y-2" data-testid="validation-summary">
		<p class="sf-font-semibold sf-text-sm">{heading}</p>
		<ul class="sf-list-disc sf-pl-5 sf-text-sm sf-space-y-1">
			{#each issues as issue}
				<li>
					{#if issue.id}
						<a class="sf-text-primary-600 sf-underline" href={`#${issue.id}`}>
							{issue.message}
						</a>
					{:else}
						{issue.message}
					{/if}
				</li>
			{/each}
		</ul>
	</Alert>
{/if}
