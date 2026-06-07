<?php
/**
 * Admin UI + generate/publish handler.
 *
 * @package Paibao_AI_Operator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Paibao_Operator_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'wp_ajax_paibao_generate', array( __CLASS__, 'ajax_generate' ) );
	}

	public static function menu() {
		add_menu_page(
			__( 'AI Operator', 'paibao-ai-operator' ),
			__( 'AI Operator', 'paibao-ai-operator' ),
			'publish_posts',
			'paibao-ai-operator',
			array( __CLASS__, 'render_page' ),
			'dashicons-edit-large',
			26
		);
	}

	public static function register_settings() {
		register_setting(
			'paibao_op',
			'paibao_op_settings',
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) )
		);
	}

	public static function sanitize( $in ) {
		return array(
			'api_base'    => esc_url_raw( isset( $in['api_base'] ) && '' !== $in['api_base'] ? $in['api_base'] : PAIBAO_OP_DEFAULT_API ),
			'license_key' => sanitize_text_field( isset( $in['license_key'] ) ? $in['license_key'] : '' ),
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'publish_posts' ) ) {
			return;
		}
		$s    = paibao_op_settings();
		$ent  = '' !== $s['license_key'] ? Paibao_Operator_Client::entitlements() : null;
		$langs = ( is_array( $ent ) && ! empty( $ent['languages'] ) ) ? $ent['languages'] : array( 'en' );
		$nonce = wp_create_nonce( 'paibao_generate' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AI Operator', 'paibao-ai-operator' ); ?></h1>

			<h2><?php esc_html_e( 'Connection', 'paibao-ai-operator' ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields( 'paibao_op' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="paibao_key"><?php esc_html_e( 'License key', 'paibao-ai-operator' ); ?></label></th>
						<td><input name="paibao_op_settings[license_key]" id="paibao_key" type="text" class="regular-text"
							value="<?php echo esc_attr( $s['license_key'] ); ?>" autocomplete="off" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="paibao_api"><?php esc_html_e( 'Service URL', 'paibao-ai-operator' ); ?></label></th>
						<td><input name="paibao_op_settings[api_base]" id="paibao_api" type="url" class="regular-text"
							value="<?php echo esc_attr( $s['api_base'] ); ?>" /></td>
					</tr>
				</table>
				<?php submit_button( __( 'Save', 'paibao-ai-operator' ) ); ?>
			</form>

			<?php if ( is_wp_error( $ent ) ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html( $ent->get_error_message() ); ?></p></div>
			<?php elseif ( is_array( $ent ) ) : ?>
				<p><strong><?php esc_html_e( 'Plan:', 'paibao-ai-operator' ); ?></strong>
					<?php echo esc_html( $ent['tier'] ?? '' ); ?> ·
					<?php echo esc_html( implode( ', ', $langs ) ); ?></p>
			<?php endif; ?>

			<hr />
			<h2><?php esc_html_e( 'Generate a draft', 'paibao-ai-operator' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="paibao_topic"><?php esc_html_e( 'Topic', 'paibao-ai-operator' ); ?></label></th>
					<td><input id="paibao_topic" type="text" class="regular-text"
						placeholder="<?php esc_attr_e( 'Leave empty to auto-suggest from your knowledge base', 'paibao-ai-operator' ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="paibao_market"><?php esc_html_e( 'Target market', 'paibao-ai-operator' ); ?></label></th>
					<td><input id="paibao_market" type="text" class="regular-text" placeholder="West Africa, CIS, MENA…" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Languages', 'paibao-ai-operator' ); ?></th>
					<td>
						<?php foreach ( $langs as $l ) : ?>
							<label style="margin-right:12px;">
								<input type="checkbox" class="paibao-lang" value="<?php echo esc_attr( $l ); ?>"
									<?php checked( 'en' === $l ); ?> /> <?php echo esc_html( $l ); ?>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
			</table>
			<p>
				<button class="button button-primary" id="paibao-go"><?php esc_html_e( 'Generate draft', 'paibao-ai-operator' ); ?></button>
				<span id="paibao-status" style="margin-left:10px;"></span>
			</p>
			<ul id="paibao-result"></ul>
		</div>

		<script>
		( function () {
			var btn = document.getElementById( 'paibao-go' );
			if ( ! btn ) { return; }
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var status = document.getElementById( 'paibao-status' );
				var result = document.getElementById( 'paibao-result' );
				var langs = Array.prototype.slice.call( document.querySelectorAll( '.paibao-lang:checked' ) ).map( function ( c ) { return c.value; } );
				if ( ! langs.length ) { langs = [ 'en' ]; }
				btn.disabled = true;
				status.textContent = <?php echo wp_json_encode( __( 'Generating… this can take up to a minute.', 'paibao-ai-operator' ) ); ?>;
				var body = new URLSearchParams();
				body.append( 'action', 'paibao_generate' );
				body.append( '_ajax_nonce', <?php echo wp_json_encode( $nonce ); ?> );
				body.append( 'topic', document.getElementById( 'paibao_topic' ).value );
				body.append( 'market', document.getElementById( 'paibao_market' ).value );
				langs.forEach( function ( l ) { body.append( 'languages[]', l ); } );
				fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( j ) {
						btn.disabled = false;
						if ( ! j.success ) { status.textContent = '⚠ ' + ( j.data || 'error' ); return; }
						status.textContent = '✓ ' + ( j.data.topic || '' );
						result.innerHTML = '';
						( j.data.created || [] ).forEach( function ( p ) {
							var li = document.createElement( 'li' );
							var a = document.createElement( 'a' );
							a.href = p.edit; a.textContent = '[' + p.lang + '] ' + p.title;
							li.appendChild( a );
							result.appendChild( li );
						} );
					} )
					.catch( function () { btn.disabled = false; status.textContent = '⚠ network error'; } );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * AJAX: call the service, then place each returned draft into WordPress.
	 */
	public static function ajax_generate() {
		check_ajax_referer( 'paibao_generate' );
		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'paibao-ai-operator' ), 403 );
		}

		$topic     = isset( $_POST['topic'] ) ? sanitize_text_field( wp_unslash( $_POST['topic'] ) ) : '';
		$market    = isset( $_POST['market'] ) ? sanitize_text_field( wp_unslash( $_POST['market'] ) ) : '';
		$languages = isset( $_POST['languages'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['languages'] ) ) : array( 'en' );

		$res = Paibao_Operator_Client::generate( $topic, $languages, $market );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( $res->get_error_message() );
		}

		$created = array();
		foreach ( (array) ( isset( $res['drafts'] ) ? $res['drafts'] : array() ) as $d ) {
			if ( empty( $d['title'] ) || empty( $d['html'] ) ) {
				continue;
			}
			$post_id = wp_insert_post(
				array(
					'post_title'   => $d['title'],
					'post_name'    => isset( $d['slug'] ) ? sanitize_title( $d['slug'] ) : '',
					'post_content' => wp_kses_post( $d['html'] ), // strips the embedded <script>; JSON-LD goes to head via meta
					'post_excerpt' => isset( $d['excerpt'] ) ? sanitize_text_field( $d['excerpt'] ) : '',
					'post_status'  => 'draft',
					'post_type'    => 'post',
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				continue;
			}
			if ( ! empty( $d['jsonld'] ) ) {
				update_post_meta( $post_id, PAIBAO_OP_JSONLD_META, wp_slash( $d['jsonld'] ) );
			}
			$created[] = array(
				'id'    => $post_id,
				'title' => $d['title'],
				'lang'  => isset( $d['locale'] ) ? $d['locale'] : 'en',
				'edit'  => get_edit_post_link( $post_id, 'raw' ),
			);
		}

		wp_send_json_success(
			array(
				'topic'   => isset( $res['topic'] ) ? $res['topic'] : $topic,
				'created' => $created,
			)
		);
	}
}
