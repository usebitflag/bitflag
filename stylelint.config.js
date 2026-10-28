/** @type {import('stylelint').Config} */
export default {
  extends: ['stylelint-config-standard'],
  ignoreFiles: ['resources/css/theme.generated.css'],
  rules: {
    // Tailwind CSS v4 at-rules.
    'at-rule-no-unknown': [
      true,
      {
        ignoreAtRules: [
          'theme',
          'source',
          'plugin',
          'utility',
          'variant',
          'custom-variant',
          'apply',
          'reference',
          'config',
        ],
      },
    ],
    // Tailwind names a size's line height `--text-sm--line-height`: two dashes before the modifier.
    'custom-property-pattern': [
      '^[a-z][a-z0-9]*(-{1,2}[a-z0-9]+)*$',
      {
        message:
          'Expected custom property name to be kebab-case (Tailwind modifiers use two dashes)',
      },
    ],
    'import-notation': 'string',
    'color-no-hex': true,
    'color-named': 'never',
  },
};
