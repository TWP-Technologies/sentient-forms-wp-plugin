import { FlatCompat } from '@eslint/eslintrc';
import js from '@eslint/js';
import globals from 'globals';

const compat = new FlatCompat({
	baseDirectory: import.meta.dirname,
	resolvePluginsRelativeTo: import.meta.dirname
});

export default [
	{
		ignores: ['.svelte-kit', 'build', 'node_modules', 'playwright-report', 'test-results', 'dist']
	},
	...compat.extends('plugin:svelte/recommended', 'plugin:@typescript-eslint/recommended', 'prettier'),
	js.configs.recommended,
	{
		files: ['**/*.{ts,tsx,js,jsx}'],
		languageOptions: {
			globals: {
				...globals.browser,
				...globals.node
			}
		},
		rules: {
			'@typescript-eslint/only-throw-error': 'off',
			'no-undef': 'off'
		}
	},
	{
		files: ['**/*.svelte'],
		languageOptions: {
			globals: {
				...globals.browser
			}
		},
		rules: {
			'@typescript-eslint/only-throw-error': 'off'
		}
	},
	{
		files: ['**/*.cjs'],
		languageOptions: {
			sourceType: 'commonjs'
		}
	}
];
