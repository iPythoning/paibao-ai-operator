import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { cp, mkdtemp, mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { basename, dirname, join, resolve } from 'node:path';
import { promisify } from 'node:util';
import test from 'node:test';

const run = promisify(execFile);
const root = resolve(import.meta.dirname, '..');
const builder = resolve(root, 'scripts/build-release.mjs');

test('builds a deterministic audited WordPress ZIP', async () => {
  const module = await import(builder).catch(() => null);
  assert.ok(module?.buildRelease, 'release builder is missing');
  const output = await mkdtemp(join(tmpdir(), 'paibao-wp-release-'));
  try {
    const first = await module.buildRelease({ root, output, publishedAt: '2026-07-15T00:00:00.000Z' });
    const firstBytes = await readFile(first.bundlePath);
    const release = JSON.parse(await readFile(first.releasePath, 'utf8'));
    const audit = JSON.parse(await readFile(first.auditPath, 'utf8'));
    assert.equal(release.pluginId, 'paibao-ai-operator');
    assert.equal(release.version, '0.2.3');
    assert.equal(release.runtime, 'wordpress-native');
    assert.equal(release.capability, 'ai_operations_wordpress_native_bridge');
    assert.equal(release.auditVerdict, 'pass');
    assert.match(release.checksum, /^[a-f0-9]{64}$/);
    assert.equal(await readFile(first.checksumPath, 'utf8'), `${release.checksum}  ${basename(first.bundlePath)}\n`);
    assert.deepEqual(audit.checks, [
      'php-entrypoint', 'no-dynamic-code', 'no-shell', 'single-control-plane-egress',
      'authenticated-rest', 'opaque-cas', 'idempotency', 'transactional-audit',
      'admin-nonce-origin-confirmation', 'managed-head',
    ]);
    const entries = (await run('unzip', ['-Z1', first.bundlePath])).stdout.trim().split('\n');
    assert.deepEqual(entries, [
      'paibao-ai-operator/',
      'paibao-ai-operator/LICENSE',
      'paibao-ai-operator/includes/',
      'paibao-ai-operator/includes/class-paibao-admin.php',
      'paibao-ai-operator/includes/class-paibao-control-client.php',
      'paibao-ai-operator/includes/class-paibao-native-bridge.php',
      'paibao-ai-operator/paibao-ai-operator.php',
      'paibao-ai-operator/readme.txt',
    ]);

    const second = await module.buildRelease({ root, output, publishedAt: '2026-07-16T00:00:00.000Z' });
    assert.deepEqual(await readFile(second.bundlePath), firstBytes);
  } finally {
    await rm(output, { recursive: true, force: true });
  }
});

test('release audit rejects unreviewed network egress', async () => {
  const module = await import(builder).catch(() => null);
  assert.ok(module?.buildRelease, 'release builder is missing');
  const fixture = await mkdtemp(join(tmpdir(), 'paibao-wp-audit-'));
  const entry = resolve(fixture, 'paibao-ai-operator.php');
  try {
    await mkdir(dirname(entry), { recursive: true });
    await cp(resolve(root, 'includes'), resolve(fixture, 'includes'), { recursive: true });
    await cp(resolve(root, 'LICENSE'), resolve(fixture, 'LICENSE'));
    await cp(resolve(root, 'readme.txt'), resolve(fixture, 'readme.txt'));
    await writeFile(entry, `${await readFile(resolve(root, 'paibao-ai-operator.php'), 'utf8')}\nwp_safe_remote_get( 'https://example.invalid' );\n`);
    await assert.rejects(module.buildRelease({ root: fixture, output: resolve(fixture, 'dist') }), /network|egress/i);
  } finally {
    await rm(fixture, { recursive: true, force: true });
  }
});

test('release audit rejects raw PHP socket and curl egress', async () => {
  const module = await import(builder);
  for (const call of ["curl_exec( $handle );", "fsockopen( 'example.invalid', 443 );"]) {
    const fixture = await mkdtemp(join(tmpdir(), 'paibao-wp-raw-egress-'));
    try {
      await cp(resolve(root, 'includes'), resolve(fixture, 'includes'), { recursive: true });
      await cp(resolve(root, 'LICENSE'), resolve(fixture, 'LICENSE'));
      await cp(resolve(root, 'readme.txt'), resolve(fixture, 'readme.txt'));
      await writeFile(resolve(fixture, 'paibao-ai-operator.php'), `${await readFile(resolve(root, 'paibao-ai-operator.php'), 'utf8')}\n${call}\n`);
      await assert.rejects(module.buildRelease({ root: fixture, output: resolve(fixture, 'dist') }), /network|egress/i);
    } finally {
      await rm(fixture, { recursive: true, force: true });
    }
  }
});

test('package and CI expose one reproducible release gate', async () => {
  const pkg = JSON.parse(await readFile(resolve(root, 'package.json'), 'utf8'));
  const workflow = await readFile(resolve(root, '.github/workflows/quality.yml'), 'utf8').catch(() => '');
  assert.equal(pkg.version, '0.2.3');
  assert.equal(pkg.scripts.build, 'node scripts/build-release.mjs');
  assert.equal(pkg.scripts.check, 'npm run lint:php && npm test');
  for (const marker of [
    'npm ci', 'npm run check', 'php:8.1-cli', 'php -l paibao-ai-operator.php',
    'wordpress-integration.sh', 'npm run build', 'actions/upload-artifact@',
    'compression-level: 0', 'retention-days: 90',
  ]) assert.ok(workflow.includes(marker), `CI is missing ${marker}`);
});
