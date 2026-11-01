/** @type {import('@jest/types').Config.InitialOptions} */
const config = {
  testEnvironment: 'jsdom',
  testMatch: ['<rootDir>/resources/js/**/*.test.ts?(x)', '<rootDir>/tests-ts/**/*.test.ts?(x)'],
  setupFilesAfterEnv: ['<rootDir>/tests-ts/jest.setup.ts'],
  moduleNameMapper: {
    '^@/(.*)$': '<rootDir>/resources/js/$1',
    // The app lazy-loads exifr's ESM lite build; Jest gets the CommonJS one.
    '^exifr/dist/lite\\.esm\\.mjs$': '<rootDir>/node_modules/exifr/dist/lite.umd.cjs',
  },
  transformIgnorePatterns: [
    // pnpm resolves packages to node_modules/.pnpm/<pkg>@<ver>/node_modules/<pkg>.
    '/node_modules/(?!(\\.pnpm/[^/]+/node_modules/)?(dayjs|@noble)/).+\\.js$',
  ],
  moduleFileExtensions: ['ts', 'tsx', 'js', 'jsx', 'json', 'node'],
  transform: {
    '^.+\\.(ts|tsx)$': ['@swc/jest', {
      jsc: {
        parser: { syntax: 'typescript', tsx: true },
        transform: { react: { runtime: 'automatic' } },
        target: 'es2022',
      },
    }],
    // ESM-only dependencies (e.g. @noble/hashes), compiled to CommonJS for Jest.
    '^.+/node_modules/.+\\.js$': ['@swc/jest', {
      jsc: { parser: { syntax: 'ecmascript' }, target: 'es2022' },
    }],
  },
};

module.exports = config;