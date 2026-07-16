import { readdir, readFile } from 'node:fs/promises';
import { resolve } from 'node:path';

export const root = resolve(import.meta.dirname, '..');

export async function shippedPhpFiles(directory = root) {
  const files = [];
  for (const entry of await readdir(directory, { withFileTypes: true })) {
    if (['.git', 'dist', 'node_modules'].includes(entry.name)) continue;
    const path = resolve(directory, entry.name);
    if (entry.isDirectory()) files.push(...await shippedPhpFiles(path));
    else if (entry.name.endsWith('.php')) files.push(path);
  }
  return files.sort();
}

export async function shippedPhpSource() {
  return (await Promise.all((await shippedPhpFiles()).map(file => readFile(file, 'utf8')))).join('\n');
}
