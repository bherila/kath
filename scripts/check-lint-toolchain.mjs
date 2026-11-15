import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync, realpathSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');

export function assertPackageIdentity(manifest) {
    assert.notEqual(manifest.name, 'eslint', 'The installed ESLint engine must not return.');
    assert.ok(!/^@typescript\/(?:typescript[0-6]|old)$/.test(manifest.name), `Classic compiler installed: ${manifest.name}`);
    if (manifest.name === 'typescript') {
        assert.equal(manifest.version, '7.0.2', 'Only the stable native TypeScript 7.0.2 compiler is supported.');
        return manifest.version;
    }
    return null;
}

export function inspectActiveGraph(graph) {
    const seen = new Map();
    const compilers = new Set();
    function walk(node, optional = false) {
        let manifest = {};
        if (node.path) {
            let directory;
            try { directory = realpathSync(node.path); } catch (error) {
                if (optional && error.code === 'ENOENT') return;
                throw error;
            }
            if (!seen.has(directory)) {
                // Read the real package identity: a renamed dependency can still be a classic compiler.
                manifest = JSON.parse(readFileSync(join(directory, 'package.json'), 'utf8'));
                seen.set(directory, manifest);
                const version = assertPackageIdentity(manifest);
                if (version) compilers.add(version);
            } else manifest = seen.get(directory);
        }
        for (const field of ['dependencies', 'devDependencies', 'optionalDependencies']) {
            for (const [name, dependency] of Object.entries(node[field] ?? {})) {
                // pnpm list flattens optional edges into dependencies. Consult the
                // actual parent manifest before skipping an absent platform binary.
                walk(dependency, field === 'optionalDependencies' || Object.hasOwn(manifest.optionalDependencies ?? {}, name));
            }
        }
    }
    for (const project of graph) walk(project);
    assert.deepEqual([...compilers], ['7.0.2'], 'A native compiler must actually be installed.');
    return seen.size;
}

export function assertLockIdentities(lock) {
    // Frozen pnpm installation validates YAML. Match package/snapshot identity keys,
    // not peer range text: optional mentions of eslint or TS6 are not installations.
    const identities = [...lock.matchAll(/^ {2}['"]?([^\s'"]+?)['"]?:/gm)].map((match) => match[1]);
    assert.ok(identities.some((identity) => identity.startsWith('typescript@7.0.2')), 'Native compiler missing from the resolved lockfile.');
    for (const identity of identities) {
        assert.ok(!/^eslint@/.test(identity), 'ESLint engine returned to the resolved lockfile.');
        assert.ok(!/^typescript@[0-6](?:\.|$)/.test(identity), 'Classic TypeScript returned to the resolved lockfile.');
        assert.ok(!/^@typescript\/(?:typescript[0-6]|old)@/.test(identity), 'An aliased classic compiler returned to the resolved lockfile.');
    }
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    const expected = JSON.parse(readFileSync(join(root, 'package.json'), 'utf8')).packageManager;
    assert.equal(`pnpm@${execFileSync('pnpm', ['--version'], { cwd: root, encoding: 'utf8' }).trim()}`, expected);
    const graph = JSON.parse(execFileSync('pnpm', ['list', '--depth', 'Infinity', '--json'], {
        cwd: root, encoding: 'utf8', maxBuffer: 16 * 1024 * 1024,
    }));
    const count = inspectActiveGraph(graph);
    assertLockIdentities(readFileSync(join(root, 'pnpm-lock.yaml'), 'utf8'));
    console.log(`Toolchain contract: ${count} reachable package manifests inspected; TypeScript 7.0.2 only, no ESLint engine.`);
}
