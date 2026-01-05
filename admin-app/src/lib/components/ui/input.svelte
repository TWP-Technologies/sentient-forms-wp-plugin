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
	class="sf:w-full sf:px-3 sf:py-2 sf:border sf:border-slate-300 sf:rounded-md sf:text-sm sf:focus:ring-2 sf:focus:ring-blue-500 sf:focus:border-blue-500 {className}"
/>
