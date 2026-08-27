module.exports = {
    testEnvironment: 'jest-environment-jsdom',
    testMatch: ['<rootDir>/tests/js/**/*.test.js'],
    collectCoverageFrom: ['assets/admin-post-editor.js'],
    coverageReporters: ['text', 'lcov'],
    coverageDirectory: 'coverage',
};
