#!/usr/bin/env bash
set -euo pipefail

: "${WP_ROOT:?WP_ROOT is required}"
: "${PLUGIN_ROOT:?PLUGIN_ROOT is required}"

WP_CLI="${WP_CLI:-wp}"
DB_HOST="${WP_DB_HOST:-127.0.0.1:3306}"
DB_NAME="${WP_DB_NAME:-wordpress}"
DB_USER="${WP_DB_USER:-wordpress}"
DB_PASSWORD="${WP_DB_PASSWORD:-wordpress}"

mkdir -p "$WP_ROOT"
"$WP_CLI" core download --path="$WP_ROOT" --quiet
"$WP_CLI" config create --path="$WP_ROOT" --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASSWORD" --dbhost="$DB_HOST" --skip-check --quiet
"$WP_CLI" core install --path="$WP_ROOT" --url="https://customer.test" --title="Paibao Integration" --admin_user="admin" --admin_password="integration-only-password" --admin_email="integration@example.test" --skip-email --quiet
"$WP_CLI" config set --path="$WP_ROOT" PAIBAO_AI_OPERATIONS_TENANT_ID tenant-integration --type=constant --quiet
"$WP_CLI" config set --path="$WP_ROOT" PAIBAO_AI_OPERATIONS_SITE_ID 11111111-1111-4111-8111-111111111111 --type=constant --quiet
"$WP_CLI" config set --path="$WP_ROOT" PAIBAO_AI_OPERATIONS_LOCALE en --type=constant --quiet
ln -s "$PLUGIN_ROOT" "$WP_ROOT/wp-content/plugins/paibao-ai-operator"
"$WP_CLI" plugin activate paibao-ai-operator --path="$WP_ROOT" --quiet
"$WP_CLI" eval --path="$WP_ROOT" '
  $role = get_role("paibao_ai_operator");
  if (!$role || !$role->has_cap("paibao_manage_ai_operations")) { throw new RuntimeException("operator role missing"); }
  global $wpdb;
  foreach ([$wpdb->prefix . "paibao_ai_audit", $wpdb->prefix . "paibao_ai_revisions"] as $table) {
    if ($wpdb->get_var($wpdb->prepare("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s", $table)) !== "InnoDB") {
      throw new RuntimeException("transactional table missing");
    }
  }
'
