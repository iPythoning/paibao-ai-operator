import assert from 'node:assert/strict';
import test from 'node:test';
import { shippedPhpSource } from './source.mjs';

test('keeps AI operations credentials server-only behind a replaceable provider', async () => {
  const source = await shippedPhpSource();
  assert.match(source, /class Paibao_AI_Operations_Credential_Provider/);
  assert.match(source, /PAIBAO_MARKETPLACE_SITE_TOKEN/);
  assert.match(source, /\/api\/site\/ai-operations\/session/);
  assert.match(source, /60000/);
  assert.match(source, /\/\^aio_\[A-Za-z0-9_-\]\{43\}\$\//);
  assert.match(source, /\/\^st_\[A-Za-z0-9_-\]\{43\}\$\//);
  assert.doesNotMatch(source, /PAIBAO_AI_OPERATIONS_CONTROL_TOKEN/);
  assert.doesNotMatch(source, /update_option\([^\n]*(?:CONTROL_TOKEN|SITE_TOKEN|aio_)/i);
  assert.doesNotMatch(source, /name=["'](?:license|api_base|service_url|aio_|site_token)/i);
  assert.doesNotMatch(source, /localStorage|sessionStorage/);
  assert.doesNotMatch(source, /echo[^;]*(?:CONTROL_TOKEN|SITE_TOKEN|application_password)/i);
});

test('server proxy is fixed to the real site-bound control-plane routes', async () => {
  const source = await shippedPhpSource();
  for (const route of [
    '/api/site/ai-operations/session',
    '/api/site/ai-operations?limit=20&offset=0',
    '/api/site/ai-operations/proposals',
    '/api/site/ai-operations/jobs/',
    '/approve',
    '/rollback',
  ]) assert.ok(source.includes(route), `missing ${route}`);
  for (const header of ['x-paibao-site-id', 'x-paibao-site-origin', 'x-paibao-platform']) {
    assert.ok(source.includes(header), `missing ${header}`);
  }
  assert.match(source, /'x-paibao-platform'\s*=>\s*'wordpress'/);
  assert.match(source, /'redirection'\s*=>\s*0/);
  assert.match(source, /'sslverify'\s*=>\s*true/);
  assert.match(source, /'limit_response_size'/);
  assert.equal(source.match(/\bwp_safe_remote_request\s*\(/g)?.length, 1);
  assert.doesNotMatch(source, /\bwp_remote_request\s*\(/);
  assert.doesNotMatch(source, /marketplace-staging|PAIBAO_MARKETPLACE_CONTROL_PLANE_URL/);
  assert.match(source, /(?:response_code\s*===\s*401|401\s*!==\s*\$response_code)/);
  assert.match(source, /force_refresh/);
  assert.match(source, /ai-operations-entitlement-required/);
});

test('admin writes require capability, nonce, same origin, strict fields, confirmation, and idempotency', async () => {
  const source = await shippedPhpSource();
  assert.match(source, /current_user_can\(\s*'manage_options'\s*\)/);
  assert.match(source, /check_admin_referer/);
  assert.match(source, /assert_same_origin_post/);
  assert.match(source, /assert_post_fields/);
  assert.match(source, /paibao_confirm/);
  assert.match(source, /paibao_idempotency_key/);
  assert.match(source, /stable_idempotency_key/);
  assert.match(source, /16384/);
  assert.match(source, /2000/);
  assert.doesNotMatch(source, /wp_ajax_/);
});

test('admin page renders only sanitized summaries and explicit proposal, approval, and rollback controls', async () => {
  const source = await shippedPhpSource();
  for (const marker of [
    'render_ai_operations',
    'sanitize_job_summary',
    'sanitize_job_detail',
    'paibao_ai_operations_proposal',
    'paibao_ai_operations_approve',
    'paibao_ai_operations_rollback',
    'rollbackAvailable',
    'seoGeoChecks',
    'geoEvidence',
  ]) assert.ok(source.includes(marker), `missing ${marker}`);
  assert.doesNotMatch(source, /\[['"](?:beforeSnapshot|afterSnapshot|proposal|idempotencyKey|approvalIdempotencyKey|rollbackIdempotencyKey)['"]\]/);
  assert.match(source, /render_connection_error/);
  assert.match(source, /\$geo\['sources'\]/);
});

test('accepts bounded Unicode text and scalar GEO fact values from the real control DTO', async () => {
  const source = await shippedPhpSource();
  assert.match(source, /function text_length/);
  assert.match(source, /mb_strlen/);
  assert.match(source, /wp_check_invalid_utf8/);
  assert.match(source, /function sanitize_fact_value/);
  assert.match(source, /is_bool/);
  assert.match(source, /is_int/);
  assert.match(source, /is_float/);
  assert.doesNotMatch(source, /preg_match\(\s*'\/\[\\x00-\\x1F\\x7F\]\/'/);
});
