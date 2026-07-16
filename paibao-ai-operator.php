<?php
/**
 * Plugin Name: Paibao AI Operations Officer
 * Description: Site-bound WordPress bridge and approval console for Paibao AI Operations.
 * Version: 0.2.3
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Paibao
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: paibao-ai-operator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PAIBAO_AI_OPERATOR_VERSION', '0.2.3' );
define( 'PAIBAO_AI_OPERATOR_FILE', __FILE__ );
define( 'PAIBAO_AI_OPERATOR_DIR', plugin_dir_path( __FILE__ ) );

require_once PAIBAO_AI_OPERATOR_DIR . 'includes/class-paibao-control-client.php';
require_once PAIBAO_AI_OPERATOR_DIR . 'includes/class-paibao-native-bridge.php';
require_once PAIBAO_AI_OPERATOR_DIR . 'includes/class-paibao-admin.php';

register_activation_hook( __FILE__, array( 'Paibao_AI_Operations_Native_Bridge', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Paibao_AI_Operations_Native_Bridge', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		Paibao_AI_Operations_Native_Bridge::boot();
		Paibao_AI_Operations_Admin::boot( new Paibao_AI_Operations_Control_Client() );
	}
);
