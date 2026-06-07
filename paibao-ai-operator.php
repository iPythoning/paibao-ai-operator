<?php
/**
 * Plugin Name:       Paibao AI Operator
 * Plugin URI:        https://paibao.ai/operator
 * Description:       AI content operator — generate GEO-optimized, fact-dense B2B articles with Article/FAQPage JSON-LD and stage them as drafts. Connects to the Paibao AI Operator service via a license key.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Paibao
 * Author URI:        https://paibao.ai
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       paibao-ai-operator
 *
 * Open-core thin client: the generation engine (dynamic knowledge base, expert
 * interview, GEO) runs in the hosted service; this plugin only triggers it with a
 * license key and places the returned content into WordPress (pull mode).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PAIBAO_OP_VERSION', '0.1.0' );
define( 'PAIBAO_OP_DIR', plugin_dir_path( __FILE__ ) );
define( 'PAIBAO_OP_DEFAULT_API', 'https://console.paibao.ai' );
define( 'PAIBAO_OP_JSONLD_META', '_paibao_jsonld' );

require_once PAIBAO_OP_DIR . 'includes/class-paibao-client.php';
require_once PAIBAO_OP_DIR . 'includes/class-paibao-admin.php';

/**
 * Merged plugin settings (with defaults).
 *
 * @return array{api_base:string,license_key:string}
 */
function paibao_op_settings() {
	return wp_parse_args(
		get_option( 'paibao_op_settings', array() ),
		array(
			'api_base'    => PAIBAO_OP_DEFAULT_API,
			'license_key' => '',
		)
	);
}

/**
 * Emit the stored Article/FAQPage JSON-LD into <head> on single posts.
 * `<` is escaped so a stray `</script>` in the JSON cannot break out of the tag.
 */
function paibao_op_head_jsonld() {
	if ( ! is_singular( 'post' ) ) {
		return;
	}
	$jsonld = get_post_meta( get_the_ID(), PAIBAO_OP_JSONLD_META, true );
	if ( empty( $jsonld ) ) {
		return;
	}
	echo '<script type="application/ld+json">' . str_replace( '<', '\\u003c', $jsonld ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
add_action( 'wp_head', 'paibao_op_head_jsonld' );

add_action(
	'plugins_loaded',
	function () {
		if ( is_admin() ) {
			Paibao_Operator_Admin::init();
		}
	}
);
