<script lang="ts">
	interface Props {
		id?: string;
		type?: 'text' | 'number' | 'email' | 'password' | 'url' | 'search' | 'tel';
		value?: string | number;
		placeholder?: string;
		disabled?: boolean;
		class?: string;
	}

	let {
		id,
		type = 'text',
		value = $bindable(''),
		placeholder,
		disabled = false,
		class: className = ''
	}: Props = $props();
</script>

<input
	{id}
	{type}
	bind:value
	{placeholder}
	{disabled}
	class="sf:w-full sf:px-3 sf:py-2 sf:border sf:border-slate-300 sf:rounded-md sf:text-sm sf:focus-visible:border-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white {className}"
/>
