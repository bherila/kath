import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join, resolve } from 'node:path';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';

import { assertLockIdentities, inspectActiveGraph } from '../../scripts/check-lint-toolchain.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
let directory;
before(() => { directory = mkdtempSync(join(root, 'tools/oxlint/.toolchain-')); });
after(() => { if (directory) rmSync(directory, { recursive: true, force: true }); });

function manifest(name, data) {
    const path = join(directory, name);
    mkdirSync(path, { recursive: true });
    writeFileSync(join(path, 'package.json'), JSON.stringify(data));
    return path;
}

void test('fresh reachable graph and lock records contain only the intended compiler and no ESLint engine', () => {
    const result = spawnSync(process.execPath, ['scripts/check-lint-toolchain.mjs'], { cwd: root, encoding: 'utf8', timeout: 30_000 });
    assert.ifError(result.error);
    assert.equal(result.status, 0, result.stdout + result.stderr);
    assert.match(result.stdout, /TypeScript 7\.0\.2 only, no ESLint engine/);
});

void test('the ESLint engine cannot resolve from the root or retained rule packages', () => {
    const require = createRequire(import.meta.url);
    for (const context of [import.meta.url, require.resolve('eslint-plugin-react-hooks'), require.resolve('eslint-plugin-simple-import-sort')]) {
        assert.throws(() => createRequire(context).resolve('eslint'), { code: 'MODULE_NOT_FOUND' });
    }
});

void test('renamed dependencies cannot conceal installed engine or classic compiler manifests', () => {
    for (const [name, version] of [['eslint', '10.14.0'], ['typescript', '6.0.2'], ['@typescript/typescript6', '6.0.2'], ['@typescript/old', '6.0.2']]) {
        const path = manifest(name.replaceAll('/', '-'), { name, version });
        const graph = [{ path: manifest('versionless-project', { private: true }), dependencies: { harmlessAlias: { path, version } } }];
        assert.throws(() => inspectActiveGraph(graph));
    }
});

void test('versionless roots admit absent declared optional platforms, refuse missing required packages and ignore unreachable caches', () => {
    const path = manifest('optional-project', { private: true, optionalDependencies: { platform: '1.0.0' } });
    manifest('unreachable-cache', { name: 'eslint', version: '10.14.0' });
    const graph = [{ path, dependencies: {
        typescript: { path: join(root, 'node_modules/typescript'), version: '7.0.2' },
        platform: { path: join(directory, 'missing-platform'), version: '1.0.0' },
    } }];
    assert.equal(inspectActiveGraph(graph), 2);
    writeFileSync(join(path, 'package.json'), JSON.stringify({ private: true }));
    assert.throws(() => inspectActiveGraph(graph), { code: 'ENOENT' });
});

void test('lock package and snapshot keys reject legacy identities but optional peer declarations are harmless', () => {
    const valid = "packages:\n  typescript@7.0.2:\n    peerDependencies:\n      eslint: '>=9'\n  'eslint-plugin-example@1.0.0': {}\nsnapshots:\n  typescript@7.0.2: {}\n";
    assertLockIdentities(valid);
    for (const key of ['eslint@10.14.0', 'typescript@6.0.2', '@typescript/typescript6@6.0.2', '@typescript/old@6.0.2']) {
        assert.throws(() => assertLockIdentities(valid + `  '${key}': {}\n`));
    }
});
