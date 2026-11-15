import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
let directory;
let ignored;

before(() => {
    directory = mkdtempSync(join(root, 'tools/oxlint/.unused-imports-'));
    ignored = mkdtempSync(join(root, 'public/.unused-imports-'));
});
after(() => {
    for (const path of [directory, ignored]) if (path) rmSync(path, { recursive: true, force: true });
});

function run(args) {
    const result = spawnSync(process.execPath, ['scripts/lint-unused-imports.mjs', ...args], { cwd: root, encoding: 'utf8', timeout: 30_000 });
    assert.ifError(result.error);
    return result;
}

function fixture(name, source) {
    const path = join(directory, name);
    writeFileSync(path, source);
    return path;
}

void test('imports-only diagnostics cover default, type, named and aliased imports; check stays read-only', () => {
    const source = `import DefaultUnused from 'synthetic-default';
import type { UnusedType } from 'synthetic-type';
import { unusedNamed } from 'synthetic-named';
import { original as UnusedAlias } from 'synthetic-alias';
export const answer = 1;
`;
    const path = fixture('unused.tsx', source);
    const result = run([path]);
    assert.equal(result.status, 1, result.stdout + result.stderr);
    assert.equal((result.stdout + result.stderr).match(/lint\/correctness\/noUnusedImports/g).length, 4);
    assert.equal(readFileSync(path, 'utf8'), source);
});

void test('used types, JSX/member names, namespaces and reexports stay valid; unused locals stay permitted', () => {
    const source = `import React from 'react';
import * as UI from 'synthetic-ui';
import type { Shape } from 'synthetic-type';
import { Reexported } from 'synthetic-reexport';
import 'synthetic-side-effect';
import type {} from 'synthetic-augmentation';
const intentionallyUnusedLocal = 1;
export { Reexported };
export { external } from 'synthetic-external';
export function Example({ shape }: { shape: Shape }) { return <React.Fragment><UI.Box>{shape}</UI.Box></React.Fragment>; }
`;
    const path = fixture('used.tsx', source);
    const result = run([path]);
    assert.equal(result.status, 0, result.stdout + result.stderr);
    assert.equal(readFileSync(path, 'utf8'), source);
});

void test('an unused React import without JSX still fails instead of a blanket React-global exemption', () => {
    const path = fixture('react-unused.tsx', "import React from 'react';\nexport const answer = 1;\n");
    const result = run([path]);
    assert.equal(result.status, 1, result.stdout + result.stderr);
    assert.match(result.stdout + result.stderr, /lint\/correctness\/noUnusedImports/);
});

void test('reviewed automatic-JSX cleanup retains React module initialization and side-effect order', () => {
    const original = fixture('react-jsx.tsx', "import 'synthetic-before';\nimport React from 'react';\nexport function Component() { return <div />; }\n");
    assert.equal(run([original]).status, 1, 'Automatic JSX does not consume the legacy React binding.');
    const reviewed = "import 'synthetic-before';\nimport 'react';\nexport function Component() { return <div />; }\n";
    writeFileSync(original, reviewed);
    assert.equal(run([original]).status, 0);
    assert.equal(readFileSync(original, 'utf8'), reviewed);
    assert.ok(reviewed.indexOf('synthetic-before') < reviewed.indexOf("import 'react'"));
});

void test('Biome suppression stays effective and is preserved by explicit fixes', () => {
    const source = "// biome-ignore lint/correctness/noUnusedImports: synthetic compatibility control\nimport Suppressed from 'synthetic-suppressed';\nexport const answer = 1;\n";
    const path = fixture('suppressed.ts', source);
    assert.equal(run([path]).status, 0);
    assert.equal(run(['--fix', '--', path]).status, 0);
    assert.equal(readFileSync(path, 'utf8'), source);
});

void test('explicit import-only unsafe fixing retains side effects and empty augmentation imports and is idempotent', () => {
    const path = fixture('fix.ts', "import Unused from 'synthetic-unused';\nimport 'synthetic-side-effect-z';\nimport 'synthetic-side-effect-a';\nimport type {} from 'synthetic-augmentation';\nconst unusedLocal = 1;\nexport const answer = 1;\n");
    const result = run(['--fix', path]);
    assert.equal(result.status, 0, result.stdout + result.stderr);
    const fixed = readFileSync(path, 'utf8');
    assert.ok(!fixed.includes('import Unused'));
    assert.ok(fixed.includes("import 'synthetic-side-effect-z';"));
    assert.ok(fixed.includes("import 'synthetic-side-effect-a';"));
    assert.ok(fixed.indexOf('synthetic-side-effect-z') < fixed.indexOf('synthetic-side-effect-a'));
    assert.ok(fixed.includes("import type {} from 'synthetic-augmentation';"));
    assert.ok(fixed.includes('const unusedLocal = 1;'));
    assert.equal(run([path]).status, 0);
    assert.equal(run(['--fix', path]).status, 0);
    assert.equal(readFileSync(path, 'utf8'), fixed);
});

void test('fixing requires explicit paths and refuses paths outside the checkout', () => {
    assert.equal(run(['--fix']).status, 2);
    assert.equal(run(['--fix', '..']).status, 2);
});

void test('generated/public paths and unsupported extensions retain their exclusions', () => {
    for (const path of [join(ignored, 'generated.ts'), join(directory, 'unsupported.txt')]) {
        const source = "import Unused from 'synthetic-unused';\n";
        writeFileSync(path, source);
        const result = run([path]);
        assert.equal(result.status, 0, result.stdout + result.stderr);
        assert.doesNotMatch(result.stdout + result.stderr, /lint\/correctness\/noUnusedImports/);
        assert.equal(readFileSync(path, 'utf8'), source);
    }
});
