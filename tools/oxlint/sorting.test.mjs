import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { after, before, test } from 'node:test';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
let directory;
before(() => { directory = mkdtempSync(join(root, 'tools/oxlint/.sorting-')); });
after(() => { if (directory) rmSync(directory, { recursive: true, force: true }); });

function lint(path, fix = false) {
    const result = spawnSync(join(root, 'node_modules/.bin/oxlint'), ['--format', 'json', '--threads', '1', ...(fix ? ['--fix'] : []), path], { cwd: root, encoding: 'utf8', timeout: 30_000 });
    assert.ifError(result.error);
    return { status: result.status, diagnostics: JSON.parse(result.stdout).diagnostics };
}

for (const fixture of [
    { rule: 'imports', invalid: "import zulu from 'synthetic-zulu';\nimport alpha from 'synthetic-alpha';\nexport { alpha, zulu };", valid: "import alpha from 'synthetic-alpha';\nimport zulu from 'synthetic-zulu';\nexport { alpha, zulu };" },
    { rule: 'exports', invalid: "export { Zulu } from 'synthetic-zulu';\nexport { Alpha } from 'synthetic-alpha';", valid: "export { Alpha } from 'synthetic-alpha';\nexport { Zulu } from 'synthetic-zulu';" },
]) {
    void test(`simple-import-sort ${fixture.rule} rejects invalid order and accepts its control`, () => {
        const path = join(directory, `${fixture.rule}.tsx`);
        writeFileSync(path, fixture.invalid);
        const invalid = lint(path);
        assert.equal(invalid.status, 1);
        assert.ok(invalid.diagnostics.some(({ code, severity }) => code === `simple-import-sort(${fixture.rule})` && severity === 'error'));
        writeFileSync(path, fixture.valid);
        const valid = lint(path);
        assert.equal(valid.status, 0, JSON.stringify(valid.diagnostics));
        assert.deepEqual(valid.diagnostics, []);
    });
}

void test('sorting fixes preserve side-effect import order and are idempotent', () => {
    const path = join(directory, 'side-effects.tsx');
    writeFileSync(path, "import 'synthetic-z-effect';\nimport zulu from 'synthetic-zulu';\nimport 'synthetic-a-effect';\nimport alpha from 'synthetic-alpha';\nexport { alpha, zulu };\n");
    const fixed = lint(path, true);
    assert.equal(fixed.status, 0, JSON.stringify(fixed.diagnostics));
    const source = readFileSync(path, 'utf8');
    assert.ok(source.indexOf('synthetic-z-effect') < source.indexOf('synthetic-a-effect'));
    assert.equal(lint(path, true).status, 0);
    assert.equal(readFileSync(path, 'utf8'), source);
});
