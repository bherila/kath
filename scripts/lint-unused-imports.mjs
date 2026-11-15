import { spawnSync } from 'node:child_process';
import { dirname, isAbsolute, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const args = process.argv.slice(2).filter((argument) => argument !== '--');
const fix = args.includes('--fix');
const paths = args.filter((argument) => argument !== '--fix');

if (paths.some((path) => path.startsWith('-')) || (fix && paths.length === 0)) {
    console.error('Use source paths only; --fix requires explicit paths and removes unused imports unsafely.');
    process.exit(2);
}

const targets = (paths.length ? paths : ['.']).map((path) => resolve(process.cwd(), path));

if (targets.some((target) => relative(root, target).startsWith('..') || isAbsolute(relative(root, target)))) {
    console.error('Unused-import checks and fixes must stay inside this checkout.');
    process.exit(2);
}

const result = spawnSync(resolve(root, 'node_modules/.bin/biome'), [
    'lint',
    '--config-path', resolve(root, 'biome.unused-imports.json'),
    '--only=correctness/noUnusedImports',
    '--no-errors-on-unmatched',
    ...(fix ? ['--write', '--unsafe'] : []),
    ...targets,
], { cwd: root, stdio: 'inherit' });

if (result.error) {
    console.error(`Unable to run the unused-import checker: ${result.error.message}`);
}

process.exit(result.status ?? 1);
