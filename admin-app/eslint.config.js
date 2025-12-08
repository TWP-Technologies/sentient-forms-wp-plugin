import js from '@eslint/js';
import globals from 'globals';
import tseslint from 'typescript-eslint';
import svelte from 'eslint-plugin-svelte';

const ignores = [
  '.svelte-kit',
  'build',
  'dist',
  'node_modules',
  'playwright-report',
  'test-results',
  'tests/e2e/**/*'
];

const baseLanguageOptions = {
  parser: tseslint.parser,
  parserOptions: {
    ecmaVersion: 'latest',
    sourceType: 'module',
    project: null,
    extraFileExtensions: ['.svelte']
  },
  globals: {
    ...globals.browser,
    ...globals.node
  }
};

const baseRules = {
  ...js.configs.recommended.rules,
  ...tseslint.configs.recommended.rules,
  'no-empty': ['error', { allowEmptyCatch: true }],
  '@typescript-eslint/no-unused-vars': ['warn', { argsIgnorePattern: '^_', varsIgnorePattern: '^_' }],
  '@typescript-eslint/no-explicit-any': 'off',
  'no-undef': 'off',
  'no-unused-vars': 'off'
};

export default [
  { ignores },
  {
    files: ['**/*.{js,ts}'],
    languageOptions: baseLanguageOptions,
    plugins: {
      '@typescript-eslint': tseslint.plugin
    },
    rules: baseRules
  },
  {
    files: ['**/*.svelte'],
    languageOptions: {
      ...baseLanguageOptions,
      parser: svelte.parser,
      parserOptions: {
        ...baseLanguageOptions.parserOptions,
        parser: tseslint.parser
      },
      globals: {
        ...globals.browser
      }
    },
    plugins: { svelte, '@typescript-eslint': tseslint.plugin },
    rules: {
      ...svelte.configs['flat/recommended'].rules,
      ...baseRules,
      // SvelteKit navigation helpers and runes often rely on explicit effects; allow them.
      'svelte/no-at-html-tags': 'off',
      'svelte/no-reactive-literals': 'off',
      'svelte/prefer-style-directive': 'off'
    }
  },
  {
    files: ['tests/**/*.{js,ts}'],
    languageOptions: {
      ...baseLanguageOptions,
      globals: {
        ...globals.node,
        ...globals.browser,
        ...globals.vitest
      }
    },
    plugins: {
      '@typescript-eslint': tseslint.plugin
    },
    rules: {
      ...baseRules,
      '@typescript-eslint/no-explicit-any': 'off'
    }
  },
  {
    files: ['**/*.cjs'],
    languageOptions: {
      parserOptions: { sourceType: 'commonjs' },
      globals: { ...globals.node }
    }
  }
];
