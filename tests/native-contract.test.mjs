import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import test from 'node:test';
import { root, shippedPhpFiles, shippedPhpSource } from './source.mjs';

const entry = resolve(root, 'paibao-ai-operator.php');

test('ships the reviewed 0.2.x atomic WordPress bridge contract', async () => {
  const source = await shippedPhpSource();
  assert.match(source, /Version:\s+0\.2\.\d+/);
  for (const marker of [
    "'paibao-ai-operations/v1'",
    "'/capabilities'",
    "'/content/(?P<type>",
    "'/mutations'",
    "'paibao_manage_ai_operations'",
    "'paibao_ai_operator'",
    'rest_get_authenticated_app_password',
    'wp_authenticate_application_password_errors',
    'START TRANSACTION',
    'FOR UPDATE',
    'Idempotency-Key',
    'If-Match',
    'before_revision_id',
    'after_revision_id',
    'ROLLBACK',
    'data-ai-direct-answer',
    'application/ld+json',
    'pre_get_document_title',
    'validate_json_value',
  ]) assert.match(source, new RegExp(marker.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
  assert.doesNotMatch(source, /get_role\(\s*'administrator'\s*\)[\s\S]{0,200}add_cap/);
  assert.match(source, /array\(\s*self::ROLE\s*\)\s*!==\s*array_values\(\s*\$user->roles\s*\)/);
  assert.match(source, /'https'\s*!==\s*\(\s*\$home\['scheme'\]/);
  assert.match(source, /add_action\(\s*'wp_authenticate_application_password_errors'/);
  assert.match(source, /\$error->add\(\s*'paibao_operator_xmlrpc'/);
  assert.match(source, /is_string\(\s*\$app_uuid\s*\)/);
  assert.doesNotMatch(source, /\$app\[['"]uuid['"]\]/);
});

test('accepts the current planner SEO DTO without weakening managed metadata namespaces', async () => {
  const source = await shippedPhpSource();
  assert.match(source, /array\( 'title', 'description', 'type', 'url', 'image', 'siteName', 'locale', 'localeAlternate' \)/);
  assert.match(source, /array\( 'card', 'title', 'description', 'image', 'site', 'creator' \)/);
  assert.match(source, /'og:'\s*\.\s*\$key/);
  assert.match(source, /'twitter:'\s*\.\s*\$key/);
  assert.match(source, /array_diff\( array_keys\( \$value \), array\( '@context', '@graph' \) \)/);
});

test('runs the real WordPress mutation, publication, public output, and rollback lifecycle', async () => {
  const source = await readFile(resolve(root, 'tests/wordpress-integration.php'), 'utf8');
  for (const marker of ["'update'", "'publish'", "'restore'", 'render_head', 'render_visible_geo', 'WPSEO_VERSION']) {
    assert.ok(source.includes(marker), `integration gate is missing ${marker}`);
  }
});

test('keeps Marketplace site credentials server-only', async () => {
  const source = await shippedPhpSource();
  assert.match(source, /MARKETPLACE_PAGE\s*=\s*'paibao-ai-operations-marketplace'/);
  assert.match(source, /MARKETPLACE_PRODUCT\s*=\s*'ai-operations-officer-wordpress'/);
  assert.match(source, /PAIBAO_MARKETPLACE_SITE_TOKEN/);
  assert.doesNotMatch(source, /name=["'](?:license|api_base|service_url|aio_|site_token)/i);
  assert.doesNotMatch(source, /localStorage|sessionStorage/);
  assert.doesNotMatch(source, /echo[^;]*(?:SITE_TOKEN|application_password)/i);
});

test('removes the 0.1 license-key PoC from every shipped PHP file', async () => {
  const source = await shippedPhpSource();
  assert.doesNotMatch(source, /X-License-Key|license_key|api_base|\/api\/operator\/generate/);
});

test('is a GPL clean-room plugin with a small bootstrap', async () => {
  const files = await shippedPhpFiles();
  const entrySource = await (await import('node:fs/promises')).readFile(entry, 'utf8');
  const source = await shippedPhpSource();
  assert.ok(files.length >= 4, 'runtime must be split into reviewed modules');
  assert.match(entrySource, /License:\s+GPL-2\.0-or-later/);
  assert.match(entrySource, /require_once/);
  assert.ok(entrySource.split('\n').length < 100, 'entrypoint should remain a small bootstrap');
  assert.match(source, /GNU General Public License/);
  assert.doesNotMatch(source, /License:\s+Proprietary|All rights reserved/i);
});
