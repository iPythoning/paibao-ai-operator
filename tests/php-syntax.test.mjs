import assert from 'node:assert/strict';
import { readdir, readFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import test from 'node:test';
import PhpParser from 'php-parser';

const root = resolve(import.meta.dirname, '..');
const parser = new PhpParser({ parser: { php7: true, suppressErrors: false } });

async function phpFiles(directory) {
  const files = [];
  for (const entry of await readdir(directory, { withFileTypes: true })) {
    if (['.git', 'dist', 'node_modules'].includes(entry.name)) continue;
    const path = resolve(directory, entry.name);
    if (entry.isDirectory()) files.push(...await phpFiles(path));
    else if (entry.name.endsWith('.php')) files.push(path);
  }
  return files;
}

test('all shipped PHP parses as PHP 8 compatible syntax', async () => {
  const files = await phpFiles(root);
  assert.ok(files.length > 0, 'no PHP plugin files found');
  for (const file of files) parser.parseCode(await readFile(file, 'utf8'), file);
});
