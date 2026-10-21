import js from '@eslint/js';
import prettier from 'eslint-config-prettier';
import vue from 'eslint-plugin-vue';
import globals from 'globals';
import tseslint from 'typescript-eslint';

export default tseslint.config(
  {
    ignores: [
      'vendor/',
      'node_modules/',
      'public/',
      'storage/',
      'bootstrap/cache/',
      'coverage/',
      'resources/js/actions/',
      'resources/js/routes/',
      'resources/js/wayfinder/',
    ],
  },
  js.configs.recommended,
  ...tseslint.configs.recommended,
  ...vue.configs['flat/recommended'],
  {
    files: ['**/*.vue'],
    languageOptions: { parserOptions: { parser: tseslint.parser } },
  },
  {
    files: ['resources/**/*.{js,ts,vue}'],
    languageOptions: { globals: globals.browser },
  },
  {
    files: ['resources/**/*.{js,ts,vue}'],
    rules: {
      // Colours come from the generated theme, never from literals in components.
      'no-restricted-syntax': [
        'error',
        {
          selector: 'Literal[value=/^#[0-9a-fA-F]{3,8}$/]',
          message: 'Use a theme colour class or variable instead of a hex literal.',
        },
        {
          selector: 'TemplateElement[value.raw=/#[0-9a-fA-F]{6}/]',
          message: 'Use a theme colour class or variable instead of a hex literal.',
        },
      ],
    },
  },
  {
    files: ['*.{js,ts}', 'cypress/**/*.ts', 'scripts/**/*.mjs'],
    languageOptions: { globals: globals.node },
  },
  {
    // Inertia page components are named by their path, for example errors/Error.
    files: ['resources/js/pages/**/*.vue'],
    rules: { 'vue/multi-word-component-names': 'off' },
  },
  prettier,
);
