module.exports = {
  preset: 'ts-jest',
  testEnvironment: 'jsdom',
  roots: ['<rootDir>/src'],
  setupFilesAfterEnv: ['<rootDir>/setupTests.ts'],
  moduleFileExtensions: ['ts', 'tsx', 'js', 'jsx'],
  moduleNameMapper: {
    '^@mwp/utils/(.*)$': '<rootDir>/src/utils/$1',
    '^@mwp/api/(.*)$': '<rootDir>/src/api/$1',
    '^@mwp/types$': '<rootDir>/src/types',
    '^@mwp/shared/(.*)$': '<rootDir>/src/common/$1'
  },
  collectCoverage: true,
  coverageDirectory: '<rootDir>/coverage',
  coverageThreshold: {
    global: {
      branches: 0.8,
      functions: 0.8,
      lines: 0.8,
      statements: 0.8
    }
  },
  transform: {
    '^.+\\.(ts|tsx|js|jsx)$': ['ts-jest', { tsconfig: '<rootDir>/tsconfig.json' }]
  }
};
