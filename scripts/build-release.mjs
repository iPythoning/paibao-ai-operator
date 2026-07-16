import { execFile } from 'node:child_process';
import { cp, mkdir, mkdtemp, readFile, rm, stat, utimes, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { basename, dirname, join, resolve } from 'node:path';
import { promisify } from 'node:util';
import { createHash } from 'node:crypto';

const run = promisify(execFile);
const pluginId = 'paibao-ai-operator';
const shippedFiles = [
  'LICENSE',
  'includes/class-paibao-admin.php',
  'includes/class-paibao-control-client.php',
  'includes/class-paibao-native-bridge.php',
  'paibao-ai-operator.php',
  'readme.txt',
];
const checks = [
  'php-entrypoint', 'no-dynamic-code', 'no-shell', 'single-control-plane-egress',
  'authenticated-rest', 'opaque-cas', 'idempotency', 'transactional-audit',
  'admin-nonce-origin-confirmation', 'managed-head',
];

function requireCheck(condition, message) {
  if (!condition) throw new Error(`Release audit failed: ${message}`);
}

async function audit(root) {
  const entry = await readFile(resolve(root, 'paibao-ai-operator.php'), 'utf8');
  const sources = await Promise.all(shippedFiles.filter(file => file.endsWith('.php'))
    .map(file => readFile(resolve(root, file), 'utf8')));
  const source = sources.join('\n');

  requireCheck(/Plugin Name:\s*Paibao AI Operations Officer/.test(entry), 'PHP entrypoint is missing');
  requireCheck(/License:\s*GPL-2\.0-or-later/.test(entry) && /GNU General Public License/.test(source), 'GPL declaration is missing');
  requireCheck(!/License:\s*Proprietary|All rights reserved|X-License-Key|license_key|api_base/i.test(source), 'non-GPL or legacy licensing code found');
  requireCheck(!/\b(?:eval|assert|create_function)\s*\(|preg_replace\s*\([^,]*\/e["']/i.test(source), 'dynamic code execution found');
  requireCheck(!/\b(?:exec|shell_exec|passthru|system|proc_open|popen)\s*\(/i.test(source), 'shell execution found');
  const network = source.match(/\bwp_(?:safe_)?remote_(?:request|get|post|head)\s*\(/g) ?? [];
  requireCheck(network.length === 1 && network[0].startsWith('wp_safe_remote_request'), 'network egress must use one reviewed transport');
  requireCheck(!/\b(?:curl_exec|curl_multi_exec|fsockopen|pfsockopen|stream_socket_client)\s*\(/i.test(source), 'raw network egress found');
  requireCheck(source.includes("private const DEFAULT_ORIGIN = 'https://marketplace.paibao.ai'"), 'control-plane origin is not fixed');
  requireCheck(source.includes('rest_get_authenticated_app_password') && source.includes("'paibao_ai_operator'"), 'authenticated REST identity is missing');
  requireCheck(source.includes('If-Match') && source.includes('/^v1:[a-f0-9]{64}$/'), 'opaque CAS contract is missing');
  requireCheck(source.includes('Idempotency-Key') && source.includes('idempotency_key'), 'idempotency contract is missing');
  requireCheck(source.includes('START TRANSACTION') && source.includes('FOR UPDATE') && source.includes("'ROLLBACK'"), 'transactional audit is missing');
  requireCheck(source.includes('check_admin_referer') && source.includes('assert_same_origin_post') && source.includes('paibao_confirm'), 'administrator write guard is missing');
  requireCheck(source.includes('data-ai-direct-answer') && source.includes('application/ld+json') && source.includes('rel="canonical"'), 'managed public head is missing');

  const version = /Version:\s*(\d+\.\d+\.\d+)/.exec(entry)?.[1];
  requireCheck(Boolean(version), 'plugin version is missing');
  return { version, checks };
}

async function normalizeTree(path) {
  const fixed = new Date('1980-01-01T00:00:00.000Z');
  await utimes(path, fixed, fixed);
  for (const child of ['LICENSE', 'includes', 'paibao-ai-operator.php', 'readme.txt']) {
    const target = resolve(path, child);
    if ((await stat(target)).isDirectory()) {
      for (const file of shippedFiles.filter(name => name.startsWith(`${child}/`))) {
        await utimes(resolve(path, file), fixed, fixed);
      }
      await utimes(target, fixed, fixed);
    } else {
      await utimes(target, fixed, fixed);
    }
  }
}

export async function buildRelease({
  root = resolve(import.meta.dirname, '..'),
  output = resolve(root, 'dist'),
  publishedAt = new Date().toISOString(),
} = {}) {
  const audited = await audit(root);
  await mkdir(output, { recursive: true });
  const staging = await mkdtemp(join(tmpdir(), 'paibao-wordpress-build-'));
  const pluginRoot = resolve(staging, pluginId);
  const bundlePath = resolve(output, `${pluginId}-${audited.version}.zip`);
  try {
    await mkdir(resolve(pluginRoot, 'includes'), { recursive: true });
    for (const file of shippedFiles) {
      await mkdir(dirname(resolve(pluginRoot, file)), { recursive: true });
      await cp(resolve(root, file), resolve(pluginRoot, file));
    }
    await normalizeTree(pluginRoot);
    await rm(bundlePath, { force: true });
    const entries = [
      `${pluginId}/`,
      `${pluginId}/LICENSE`,
      `${pluginId}/includes/`,
      ...shippedFiles.filter(file => file.startsWith('includes/')).map(file => `${pluginId}/${file}`),
      `${pluginId}/paibao-ai-operator.php`,
      `${pluginId}/readme.txt`,
    ];
    await run('zip', ['-X', '-0', '-q', bundlePath, ...entries], { cwd: staging });
  } finally {
    await rm(staging, { recursive: true, force: true });
  }

  const checksum = createHash('sha256').update(await readFile(bundlePath)).digest('hex');
  const releasePath = resolve(output, `${pluginId}-${audited.version}.release.json`);
  const auditPath = resolve(output, `${pluginId}-${audited.version}.audit.json`);
  const checksumPath = resolve(output, `${pluginId}-${audited.version}.sha256`);
  const release = {
    pluginId,
    version: audited.version,
    runtime: 'wordpress-native',
    capability: 'ai_operations_wordpress_native_bridge',
    publishedAt,
    bundle: basename(bundlePath),
    checksum,
    auditVerdict: 'pass',
  };
  const auditReport = { pluginId, version: audited.version, verdict: 'pass', checks: audited.checks };
  await writeFile(releasePath, `${JSON.stringify(release, null, 2)}\n`);
  await writeFile(auditPath, `${JSON.stringify(auditReport, null, 2)}\n`);
  await writeFile(checksumPath, `${checksum}  ${basename(bundlePath)}\n`);
  return { bundlePath, releasePath, auditPath, checksumPath };
}

if (process.argv[1] && resolve(process.argv[1]) === resolve(import.meta.filename)) {
  const result = await buildRelease();
  process.stdout.write(`${result.bundlePath}\n`);
}
