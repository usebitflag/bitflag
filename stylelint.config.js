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
    'import-notation': 'string',
    'color-no-hex': true,
    'color-named': 'never',
  },
};
