<script lang="ts">
	import SearchIcon from '@lucide/svelte/icons/search';
	import type { HTMLInputAttributes } from 'svelte/elements';

	type Props = Omit<HTMLInputAttributes, 'type' | 'value'> & {
		value?: string;
		wrapperClass?: string;
	};

	let {
		value = $bindable(),
		placeholder = 'Search...',
		disabled = false,
		class: className = '',
		wrapperClass = '',
		...rest
	}: Props = $props();
</script>

<div class={['sf:relative', wrapperClass].filter(Boolean).join(' ')}>
	<span
		class="sf:pointer-events-none sf:absolute sf:inset-y-0 sf:left-0 sf:flex sf:w-10 sf:items-center sf:justify-center sf:text-slate-400"
		aria-hidden="true"
	>
		<SearchIcon class="sf:h-4 sf:w-4" aria-hidden="true" />
	</span>
	<input
		type="search"
		bind:value
		{placeholder}
		{disabled}
		class={[
			'sf:w-full sf:rounded-md sf:border sf:border-slate-300 sf:bg-white sf:py-2 sf:pl-10 sf:pr-3 sf:text-sm sf:leading-5 sf:text-slate-950 sf:placeholder:text-slate-400 sf:focus-visible:border-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white disabled:sf:cursor-not-allowed disabled:sf:bg-slate-50 disabled:sf:text-slate-500',
			className
		]
			.filter(Boolean)
			.join(' ')}
		{...rest}
	/>
</div>
