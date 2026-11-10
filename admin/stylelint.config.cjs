module.exports = {
  extends: ['stylelint-config-standard'],
  ignoreFiles: ['build/**/*'],
  rules: {
    'at-rule-no-unknown': [
      true,
      {
        ignoreAtRules: ['tailwind', 'apply', 'layer']
      }
    ],
    'selector-class-pattern': [
      '^[a-z][a-z0-9]*(?:[-_]{1,2}[a-z0-9]+)*$'
    ]
  }
};
