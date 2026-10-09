// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.misc.jest-config

module.exports = {
    testEnvironment: 'jest-environment-jsdom',
    testMatch: ['<rootDir>/tests/js/**/*.test.js'],
    collectCoverageFrom: ['assets/admin-post-editor.js'],
    coverageReporters: ['text', 'lcov'],
    coverageDirectory: 'coverage',
};
