<?php
/**
 * First-party atomic WordPress bridge.
 *
 * Copyright (C) 2026 Paibao contributors
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, either version 2 of the License, or any later version.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Paibao_AI_Operations_Native_Bridge {
	private const REST_NAMESPACE = 'paibao-ai-operations/v1';
	private const ROLE = 'paibao_ai_operator';
	private const CAPABILITY = 'paibao_manage_ai_operations';
	private const SEO_META = '_paibao_ai_operations_seo';
	private const GEO_META = '_paibao_ai_operations_geo';
	private const LOCALE_META = '_paibao_ai_operations_locale';
	private const VERSION_META = '_paibao_ai_operations_version';
	private const MAX_REQUEST = 524288;
	private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

	private static string $scoped_resource = '';

	public static function boot(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'restrict_operator_namespace' ), 10, 3 );
		add_action( 'wp_authenticate_application_password_errors', array( __CLASS__, 'block_operator_xmlrpc' ), 10, 4 );
		add_action( 'wp', array( __CLASS__, 'prepare_public_output' ) );
		add_action( 'wp_head', array( __CLASS__, 'render_head' ), 4 );
		add_filter( 'pre_get_document_title', array( __CLASS__, 'managed_document_title' ), 20 );
		add_filter( 'the_content', array( __CLASS__, 'render_visible_geo' ), 20 );
	}

	public static function activate(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$audit   = self::audit_table();
		$history = self::revision_table();
		dbDelta(
			"CREATE TABLE {$audit} (
				audit_id char(36) NOT NULL,
				tenant_id varchar(128) NOT NULL,
				site_id char(36) NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				application_password_uuid char(36) NOT NULL,
				idempotency_key varchar(128) NOT NULL,
				request_hash char(64) NOT NULL,
				action varchar(16) NOT NULL,
				post_id bigint(20) unsigned NOT NULL,
				resource_type varchar(32) NOT NULL,
				expected_version varchar(67) NOT NULL,
				result_version varchar(67) DEFAULT NULL,
				before_revision_id bigint(20) unsigned DEFAULT NULL,
				after_revision_id bigint(20) unsigned DEFAULT NULL,
				response_json longtext DEFAULT NULL,
				outcome varchar(16) NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (audit_id),
				UNIQUE KEY binding_idempotency (tenant_id,site_id,idempotency_key),
				KEY post_created (post_id,created_at)
			) ENGINE=InnoDB {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$history} (
				revision_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				tenant_id varchar(128) NOT NULL,
				site_id char(36) NOT NULL,
				post_id bigint(20) unsigned NOT NULL,
				resource_type varchar(32) NOT NULL,
				version varchar(67) NOT NULL,
				snapshot_json longtext NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (revision_id),
				KEY binding_post (tenant_id,site_id,post_id,revision_id)
			) ENGINE=InnoDB {$charset};"
		);
		add_role( self::ROLE, 'Paibao AI Operator', array( 'read' => true, self::CAPABILITY => true ) );
		$role = get_role( self::ROLE );
		if ( $role ) {
			foreach ( array_keys( $role->capabilities ) as $capability ) {
				if ( ! in_array( $capability, array( 'read', self::CAPABILITY ), true ) ) {
					$role->remove_cap( $capability );
				}
			}
			$role->add_cap( self::CAPABILITY, true );
		}
	}

	public static function deactivate(): void {
		self::$scoped_resource = '';
	}

	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/capabilities',
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'capabilities' ), 'permission_callback' => array( __CLASS__, 'permission' ) )
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/content/(?P<type>[a-z][a-z0-9_-]{0,31})',
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'list_content' ), 'permission_callback' => array( __CLASS__, 'permission' ) )
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/content/(?P<type>[a-z][a-z0-9_-]{0,31})/(?P<id>[1-9][0-9]*)',
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'get_content' ), 'permission_callback' => array( __CLASS__, 'permission' ) )
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/content/(?P<type>[a-z][a-z0-9_-]{0,31})/(?P<id>[1-9][0-9]*)/revisions',
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'list_revisions' ), 'permission_callback' => array( __CLASS__, 'permission' ) )
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/mutations',
			array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'mutate' ), 'permission_callback' => array( __CLASS__, 'permission' ) )
		);
	}

	public static function permission( WP_REST_Request $request ): bool|WP_Error {
		$user = wp_get_current_user();
		$app_uuid = function_exists( 'rest_get_authenticated_app_password' ) ? rest_get_authenticated_app_password() : null;
		if ( ! $user->exists() || array( self::ROLE ) !== array_values( $user->roles ) || ! current_user_can( self::CAPABILITY ) || ! is_string( $app_uuid ) || 1 !== preg_match( '/^[0-9a-f-]{36}$/i', $app_uuid ) ) {
			return new WP_Error( 'paibao_bridge_forbidden', 'Dedicated Application Password authentication is required.', array( 'status' => 403 ) );
		}
		if ( ! self::valid_binding() ) {
			return new WP_Error( 'paibao_bridge_binding', 'Bridge binding is incomplete.', array( 'status' => 503 ) );
		}
		if ( ! self::storage_ready() ) {
			return new WP_Error( 'paibao_bridge_storage', 'Transactional InnoDB storage is required.', array( 'status' => 503 ) );
		}
		return true;
	}

	public static function restrict_operator_namespace( mixed $result, WP_REST_Server $server, WP_REST_Request $request ): mixed {
		unset( $server );
		$user = wp_get_current_user();
		if ( $user->exists() && in_array( self::ROLE, $user->roles, true ) && ! str_starts_with( $request->get_route(), '/' . self::REST_NAMESPACE . '/' ) ) {
			return new WP_Error( 'paibao_operator_namespace', 'This service identity is limited to the Paibao bridge.', array( 'status' => 403 ) );
		}
		return $result;
	}

	public static function block_operator_xmlrpc( WP_Error $error, WP_User $user, array $item, string $password ): void {
		unset( $item, $password );
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST && in_array( self::ROLE, $user->roles, true ) ) {
			$error->add( 'paibao_operator_xmlrpc', 'The Paibao service identity cannot use XML-RPC.' );
		}
	}

	public static function capabilities(): WP_REST_Response|WP_Error {
		if ( self::seo_conflict() ) {
			return new WP_Error( 'paibao_seo_conflict', 'Disable other managed SEO plugins before enabling Paibao writes.', array( 'status' => 409 ) );
		}
		$binding = self::binding();
		return self::response(
			array(
				'schemaVersion' => 1,
				'bridge'        => 'paibao-ai-operations-bridge',
				'bridgeVersion' => PAIBAO_AI_OPERATOR_VERSION,
				'binding'       => array( 'tenantId' => $binding['tenant_id'], 'siteId' => $binding['site_id'], 'origin' => $binding['origin'], 'locale' => $binding['locale'] ),
				'contract'      => array(
					'atomicCAS'          => true,
					'versionToken'       => 'sha256-v1',
					'seoGeo'             => 'managed-v1',
					'publicVerification' => true,
					'auditLog'           => 'transactional-audit-v1',
					'rollback'           => 'audit-snapshot-v1',
					'arbitraryScripts'   => false,
				),
				'capabilities'  => array(
					'list' => true, 'read' => true, 'createDraft' => false, 'updateDraft' => true,
					'publish' => true, 'unpublish' => true, 'revisions' => true, 'restore' => true,
					'seo' => true, 'geo' => true, 'renderedHead' => true,
				),
			)
		);
	}

	public static function list_content( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( array_diff( array_keys( $request->get_query_params() ), array( 'locale', 'status', 'limit' ) ) ) {
			return new WP_Error( 'paibao_bridge_query', 'Unexpected content query.', array( 'status' => 400 ) );
		}
		$type = self::content_type( (string) $request['type'] );
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		$limit  = self::bounded_int( $request->get_param( 'limit' ), 20, 1, 200 );
		$locale = (string) ( $request->get_param( 'locale' ) ?? self::binding()['locale'] );
		if ( $locale !== self::binding()['locale'] ) {
			return self::response( array( 'items' => array(), 'hasMore' => false ) );
		}
		$status = (string) ( $request->get_param( 'status' ) ?? '' );
		$map    = array( 'draft' => 'draft', 'published' => 'publish', 'scheduled' => 'future', 'archived' => 'trash' );
		if ( '' !== $status && ! isset( $map[ $status ] ) ) {
			return new WP_Error( 'paibao_bridge_status', 'Invalid content status.', array( 'status' => 400 ) );
		}
		$query = new WP_Query(
			array(
				'post_type'      => $type,
				'post_status'    => '' === $status ? array_values( $map ) : $map[ $status ],
				'posts_per_page' => $limit + 1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		$posts = $query->posts;
		$more  = count( $posts ) > $limit;
		$posts = array_slice( $posts, 0, $limit );
		return self::response( array( 'items' => array_map( array( __CLASS__, 'snapshot' ), $posts ), 'hasMore' => $more ) );
	}

	public static function get_content( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post = self::bound_post( (string) $request['type'], (int) $request['id'] );
		return is_wp_error( $post ) ? $post : self::response( self::snapshot( $post ) );
	}

	public static function list_revisions( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;
		$post = self::bound_post( (string) $request['type'], (int) $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$binding = self::binding();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT revision_id, created_at FROM ' . self::revision_table() . ' WHERE tenant_id = %s AND site_id = %s AND post_id = %d AND resource_type = %s ORDER BY revision_id DESC LIMIT 100',
				$binding['tenant_id'], $binding['site_id'], $post->ID, $post->post_type
			),
			ARRAY_A
		);
		$items = array_map(
			static fn( array $row ): array => array( 'id' => (string) $row['revision_id'], 'createdAt' => gmdate( DATE_ATOM, strtotime( $row['created_at'] . ' UTC' ) ) ),
			is_array( $rows ) ? $rows : array()
		);
		return self::response( array( 'items' => $items ) );
	}

	public static function mutate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;
		if ( self::seo_conflict() ) {
			return new WP_Error( 'paibao_seo_conflict', 'Managed SEO conflict.', array( 'status' => 409 ) );
		}
		$declared = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
		if ( $declared < 1 || $declared > self::MAX_REQUEST || strlen( $request->get_body() ) > self::MAX_REQUEST ) {
			return new WP_Error( 'paibao_bridge_size', 'Mutation body is invalid.', array( 'status' => 413 ) );
		}
		if ( ! str_starts_with( strtolower( (string) $request->get_header( 'content-type' ) ), 'application/json' ) ) {
			return new WP_Error( 'paibao_bridge_content_type', 'JSON is required.', array( 'status' => 415 ) );
		}
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || ! self::exact_keys( $body, array( 'schemaVersion', 'action', 'resourceType', 'resourceId', 'revisionId', 'after' ) ) ) {
			return new WP_Error( 'paibao_bridge_payload', 'Mutation schema is invalid.', array( 'status' => 400 ) );
		}
		$action = is_string( $body['action'] ) ? $body['action'] : '';
		$type   = is_string( $body['resourceType'] ) ? $body['resourceType'] : '';
		$id     = is_string( $body['resourceId'] ) && ctype_digit( $body['resourceId'] ) ? (int) $body['resourceId'] : 0;
		if ( 1 !== $body['schemaVersion'] || ! in_array( $action, array( 'update', 'publish', 'unpublish', 'restore' ), true ) ) {
			return new WP_Error( 'paibao_bridge_payload', 'Mutation action is invalid.', array( 'status' => 400 ) );
		}
		if ( ( 'update' === $action ) !== is_array( $body['after'] ) || ( 'restore' === $action ) !== ( is_string( $body['revisionId'] ) && ctype_digit( $body['revisionId'] ) ) ) {
			return new WP_Error( 'paibao_bridge_payload', 'Mutation data is invalid.', array( 'status' => 400 ) );
		}
		if ( 'update' !== $action && null !== $body['after'] ) {
			return new WP_Error( 'paibao_bridge_payload', 'Unexpected after snapshot.', array( 'status' => 400 ) );
		}
		if ( 'restore' !== $action && null !== $body['revisionId'] ) {
			return new WP_Error( 'paibao_bridge_payload', 'Unexpected revision.', array( 'status' => 400 ) );
		}
		$expected = (string) $request->get_header( 'if-match' );
		$idem     = (string) $request->get_header( 'idempotency-key' );
		if ( 1 !== preg_match( '/^v1:[a-f0-9]{64}$/', $expected ) || 1 !== preg_match( '/^wpai_[a-f0-9]{48}$/', $idem ) ) {
			return new WP_Error( 'paibao_bridge_headers', 'If-Match and Idempotency-Key are required.', array( 'status' => 400 ) );
		}
		$post = self::bound_post( $type, $id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$canonical = self::canonical_json( $body );
		if ( ! is_string( $canonical ) ) {
			return new WP_Error( 'paibao_bridge_payload', 'Mutation cannot be canonicalized.', array( 'status' => 400 ) );
		}
		$request_hash = hash( 'sha256', $expected . "\n" . $canonical );
		$binding      = self::binding();
		$app_uuid     = rest_get_authenticated_app_password();
		if ( 1 !== preg_match( '/^[0-9a-f-]{36}$/i', $app_uuid ) ) {
			return new WP_Error( 'paibao_bridge_auth', 'Application Password identity is unavailable.', array( 'status' => 403 ) );
		}

		try {
			$wpdb->query( 'START TRANSACTION' );
			$locked = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID = %d AND post_type = %s FOR UPDATE", $id, $type ) );
			if ( (int) $locked !== $id ) {
				throw new Paibao_AI_Operations_Error( 'Content disappeared during mutation.', 404 );
			}
			$replay = $wpdb->get_row(
				$wpdb->prepare( 'SELECT request_hash, outcome, response_json FROM ' . self::audit_table() . ' WHERE tenant_id = %s AND site_id = %s AND idempotency_key = %s FOR UPDATE', $binding['tenant_id'], $binding['site_id'], $idem ),
				ARRAY_A
			);
			if ( is_array( $replay ) ) {
				if ( hash_equals( (string) $replay['request_hash'], $request_hash ) && 'succeeded' === $replay['outcome'] && is_string( $replay['response_json'] ) ) {
					$data = json_decode( $replay['response_json'], true, 32 );
					if ( is_array( $data ) ) {
						$wpdb->query( 'COMMIT' );
						$data['replayed'] = true;
						return self::response( $data );
					}
				}
				throw new Paibao_AI_Operations_Error( 'Idempotency conflict.', 409 );
			}

			$current = get_post( $id );
			if ( ! $current instanceof WP_Post || ! hash_equals( $expected, self::version( $current ) ) ) {
				throw new Paibao_AI_Operations_Error( 'Content version changed.', 412 );
			}
			if ( ! in_array( $current->post_status, array( 'draft', 'publish' ), true ) ) {
				throw new Paibao_AI_Operations_Error( 'Only draft or published content can be mutated.', 409 );
			}
			$audit_id = wp_generate_uuid4();
			$inserted = $wpdb->insert(
				self::audit_table(),
				array(
					'audit_id' => $audit_id, 'tenant_id' => $binding['tenant_id'], 'site_id' => $binding['site_id'],
					'user_id' => get_current_user_id(), 'application_password_uuid' => $app_uuid,
					'idempotency_key' => $idem, 'request_hash' => $request_hash, 'action' => $action,
					'post_id' => $id, 'resource_type' => $type, 'expected_version' => $expected,
					'outcome' => 'pending', 'created_at' => current_time( 'mysql', true ),
				),
				array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
			);
			if ( 1 !== $inserted ) {
				throw new Paibao_AI_Operations_Error( 'Audit reservation failed.', 409 );
			}

			$before    = self::snapshot( $current );
			$before_id = self::save_revision( $current, $before );
			self::$scoped_resource = $type . ':' . $id;
			self::apply_mutation( $current, $action, $body );
			self::$scoped_resource = '';
			$after_post = get_post( $id );
			if ( ! $after_post instanceof WP_Post ) {
				throw new Paibao_AI_Operations_Error( 'Mutation verification failed.', 500 );
			}
			$after    = self::snapshot( $after_post );
			$after_id = self::save_revision( $after_post, $after );
			$result   = array(
				'schemaVersion' => 1,
				'auditId' => $audit_id,
				'replayed' => false,
				'beforeRevisionId' => (string) $before_id,
				'afterRevisionId' => (string) $after_id,
				'content' => $after,
			);
			$result_json = wp_json_encode( $result, self::JSON_FLAGS );
			if ( ! is_string( $result_json ) ) {
				throw new Paibao_AI_Operations_Error( 'Mutation response encoding failed.', 500 );
			}
			$updated = $wpdb->update(
				self::audit_table(),
				array( 'result_version' => $after['version'], 'before_revision_id' => $before_id, 'after_revision_id' => $after_id, 'response_json' => $result_json, 'outcome' => 'succeeded' ),
				array( 'audit_id' => $audit_id, 'outcome' => 'pending' ),
				array( '%s', '%d', '%d', '%s', '%s' ),
				array( '%s', '%s' )
			);
			if ( 1 !== $updated ) {
				throw new Paibao_AI_Operations_Error( 'Audit finalization failed.', 500 );
			}
			$wpdb->query( 'COMMIT' );
			return self::response( $result );
		} catch ( Throwable $error ) {
			self::$scoped_resource = '';
			$wpdb->query( 'ROLLBACK' );
			$status = $error instanceof Paibao_AI_Operations_Error && $error->status() ? $error->status() : 500;
			return new WP_Error( 'paibao_bridge_mutation', $error->getMessage(), array( 'status' => $status ) );
		}
	}

	private static function apply_mutation( WP_Post $post, string $action, array $body ): void {
		if ( 'restore' === $action ) {
			self::restore_revision( $post, (int) $body['revisionId'] );
			return;
		}
		if ( 'publish' === $action || 'unpublish' === $action ) {
			$status = 'publish' === $action ? 'publish' : 'draft';
			$result = wp_update_post( array( 'ID' => $post->ID, 'post_status' => $status ), true );
			if ( is_wp_error( $result ) ) {
				throw new Paibao_AI_Operations_Error( $result->get_error_message(), 500 );
			}
			self::bump_version( $post->ID );
			return;
		}

		$after = self::validated_after( $body['after'], $post );
		$result = wp_update_post(
			array(
				'ID' => $post->ID, 'post_name' => $after['slug'], 'post_title' => $after['title'],
				'post_excerpt' => $after['description'], 'post_content' => $after['body'],
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			throw new Paibao_AI_Operations_Error( $result->get_error_message(), 500 );
		}
		self::set_json_meta( $post->ID, self::SEO_META, $after['seo'] );
		self::set_json_meta( $post->ID, self::GEO_META, $after['geo'] );
		update_post_meta( $post->ID, self::LOCALE_META, $after['locale'] );
		self::bump_version( $post->ID );
	}

	private static function validated_after( array $after, WP_Post $post ): array {
		$allowed = array( 'type', 'locale', 'slug', 'title', 'description', 'body', 'seo', 'geo' );
		if ( ! self::only_keys( $after, $allowed ) || ! self::has_keys( $after, array( 'type', 'locale', 'slug', 'title', 'body' ) ) ) {
			throw new Paibao_AI_Operations_Error( 'After snapshot is invalid.', 400 );
		}
		if ( $after['type'] !== $post->post_type || $after['locale'] !== self::binding()['locale'] ) {
			throw new Paibao_AI_Operations_Error( 'After snapshot binding changed.', 400 );
		}
		$slug = self::text( $after['slug'], 1, 200, 'slug' );
		if ( sanitize_title( $slug ) !== $slug ) {
			throw new Paibao_AI_Operations_Error( 'Slug is not canonical.', 400 );
		}
		$title = self::text( $after['title'], 1, 300, 'title' );
		$description = array_key_exists( 'description', $after ) ? self::text( $after['description'], 1, 2000, 'description' ) : '';
		$body = self::text( $after['body'], 0, self::MAX_REQUEST, 'body' );
		$body = wp_kses_post( $body );
		$seo  = array_key_exists( 'seo', $after ) ? self::validate_seo( $after['seo'] ) : null;
		$geo  = array_key_exists( 'geo', $after ) ? self::validate_geo( $after['geo'] ) : null;
		return array( 'slug' => $slug, 'title' => $title, 'description' => $description, 'body' => $body, 'locale' => $after['locale'], 'seo' => $seo, 'geo' => $geo );
	}

	private static function validate_seo( mixed $value ): ?array {
		if ( null === $value ) {
			return null;
		}
		if ( ! is_array( $value ) || ! self::only_keys( $value, array( 'title', 'description', 'canonical', 'image', 'noIndex', 'hreflang', 'openGraph', 'twitter', 'jsonLd' ) ) ) {
			throw new Paibao_AI_Operations_Error( 'SEO snapshot is invalid.', 400 );
		}
		$out = array();
		foreach ( array( 'title' => 300, 'description' => 2000 ) as $key => $max ) {
			if ( array_key_exists( $key, $value ) ) {
				$out[ $key ] = self::text( $value[ $key ], 1, $max, 'seo.' . $key );
			}
		}
		foreach ( array( 'canonical', 'image' ) as $key ) {
			if ( array_key_exists( $key, $value ) ) {
				$out[ $key ] = self::https_url( $value[ $key ], 'canonical' === $key );
			}
		}
		if ( array_key_exists( 'noIndex', $value ) ) {
			if ( ! is_bool( $value['noIndex'] ) ) {
				throw new Paibao_AI_Operations_Error( 'SEO noIndex must be boolean.', 400 );
			}
			$out['noIndex'] = $value['noIndex'];
		}
		foreach ( array( 'hreflang', 'openGraph', 'twitter' ) as $key ) {
			if ( array_key_exists( $key, $value ) ) {
				$out[ $key ] = self::string_map( $value[ $key ], $key );
			}
		}
		if ( array_key_exists( 'jsonLd', $value ) ) {
			if ( ! is_array( $value['jsonLd'] ) || count( $value['jsonLd'] ) > 20 ) {
				throw new Paibao_AI_Operations_Error( 'JSON-LD collection is invalid.', 400 );
			}
			$out['jsonLd'] = array_map( array( __CLASS__, 'validate_json_ld' ), $value['jsonLd'] );
		}
		return $out;
	}

	private static function validate_geo( mixed $value ): ?array {
		if ( null === $value ) {
			return null;
		}
		if ( ! is_array( $value ) || ! self::only_keys( $value, array( 'directAnswer', 'facts', 'sources', 'reviewedAt' ) ) ) {
			throw new Paibao_AI_Operations_Error( 'GEO snapshot is invalid.', 400 );
		}
		$out = array();
		if ( array_key_exists( 'directAnswer', $value ) ) {
			$out['directAnswer'] = self::text( $value['directAnswer'], 1, 2000, 'geo.directAnswer' );
		}
		if ( array_key_exists( 'facts', $value ) ) {
			if ( ! is_array( $value['facts'] ) || count( $value['facts'] ) > 100 ) {
				throw new Paibao_AI_Operations_Error( 'GEO facts are invalid.', 400 );
			}
			$out['facts'] = array_map(
				static function ( mixed $fact ): array {
					if ( ! is_array( $fact ) || ! self::only_keys( $fact, array( 'label', 'value', 'sourceUrl', 'asOf' ) ) || ! self::has_keys( $fact, array( 'value', 'sourceUrl', 'asOf' ) ) ) {
						throw new Paibao_AI_Operations_Error( 'GEO fact is invalid.', 400 );
					}
					if ( ! is_string( $fact['value'] ) && ! is_bool( $fact['value'] ) && ! is_int( $fact['value'] ) && ! is_float( $fact['value'] ) ) {
						throw new Paibao_AI_Operations_Error( 'GEO fact value is invalid.', 400 );
					}
					return array(
						'label' => array_key_exists( 'label', $fact ) ? self::text( $fact['label'], 1, 200, 'geo.fact.label' ) : '',
						'value' => $fact['value'],
						'sourceUrl' => self::https_url( $fact['sourceUrl'], false ),
						'asOf' => self::date_text( $fact['asOf'] ),
					);
				},
				$value['facts']
			);
		}
		if ( array_key_exists( 'sources', $value ) ) {
			if ( ! is_array( $value['sources'] ) || count( $value['sources'] ) > 50 ) {
				throw new Paibao_AI_Operations_Error( 'GEO sources are invalid.', 400 );
			}
			$out['sources'] = array_map(
				static function ( mixed $source ): array {
					if ( ! is_array( $source ) || ! self::exact_keys( $source, array( 'title', 'url' ) ) ) {
						throw new Paibao_AI_Operations_Error( 'GEO source is invalid.', 400 );
					}
					return array( 'title' => self::text( $source['title'], 1, 300, 'geo.source.title' ), 'url' => self::https_url( $source['url'], false ) );
				},
				$value['sources']
			);
		}
		if ( array_key_exists( 'reviewedAt', $value ) ) {
			$out['reviewedAt'] = self::date_text( $value['reviewedAt'] );
		}
		return $out;
	}

	private static function validate_json_ld( mixed $value ): array {
		$allowed = array( 'Organization', 'WebSite', 'Product', 'Article', 'FAQPage', 'BreadcrumbList' );
		if ( ! is_array( $value ) || array_is_list( $value ) || ! isset( $value['@type'] ) || ! is_string( $value['@type'] ) || ! in_array( $value['@type'], $allowed, true ) ) {
			throw new Paibao_AI_Operations_Error( 'JSON-LD type is not managed.', 400 );
		}
		$clean = self::validate_json_value( $value );
		$json = wp_json_encode( $clean, self::JSON_FLAGS );
		if ( ! is_string( $json ) || strlen( $json ) > 65536 || str_contains( strtolower( $json ), '</script' ) ) {
			throw new Paibao_AI_Operations_Error( 'JSON-LD document is invalid.', 400 );
		}
		return $clean;
	}

	private static function validate_json_value( mixed $value, int $depth = 0, string $property = '' ): mixed {
		if ( $depth > 10 ) {
			throw new Paibao_AI_Operations_Error( 'JSON-LD nesting is too deep.', 400 );
		}
		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value ) && is_finite( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			if ( '@context' === $property && 'https://schema.org' !== $value ) {
				throw new Paibao_AI_Operations_Error( 'JSON-LD context is invalid.', 400 );
			}
			if ( '@type' === $property ) {
				$types = array( 'Organization', 'WebSite', 'Product', 'Article', 'FAQPage', 'BreadcrumbList', 'Question', 'Answer', 'ListItem', 'Offer', 'AggregateOffer', 'Brand', 'ImageObject', 'Person', 'PostalAddress', 'ContactPoint', 'Rating', 'AggregateRating' );
				if ( ! in_array( $value, $types, true ) ) {
					throw new Paibao_AI_Operations_Error( 'Nested JSON-LD type is not managed.', 400 );
				}
			}
			return self::text( $value, 0, 10000, 'jsonLd.' . $property );
		}
		if ( ! is_array( $value ) || count( $value ) > 100 ) {
			throw new Paibao_AI_Operations_Error( 'JSON-LD value is invalid.', 400 );
		}
		$out = array();
		foreach ( $value as $key => $entry ) {
			if ( ! is_int( $key ) && ( ! is_string( $key ) || strlen( $key ) > 128 ) ) {
				throw new Paibao_AI_Operations_Error( 'JSON-LD property is invalid.', 400 );
			}
			$out[ $key ] = self::validate_json_value( $entry, $depth + 1, is_string( $key ) ? $key : '' );
		}
		return $out;
	}

	private static function restore_revision( WP_Post $post, int $revision_id ): void {
		global $wpdb;
		$binding = self::binding();
		$json = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT snapshot_json FROM ' . self::revision_table() . ' WHERE revision_id = %d AND tenant_id = %s AND site_id = %s AND post_id = %d AND resource_type = %s FOR UPDATE',
				$revision_id, $binding['tenant_id'], $binding['site_id'], $post->ID, $post->post_type
			)
		);
		$snapshot = is_string( $json ) ? json_decode( $json, true, 32 ) : null;
		if ( ! is_array( $snapshot ) ) {
			throw new Paibao_AI_Operations_Error( 'Revision is unavailable.', 404 );
		}
		foreach ( array( 'type', 'locale', 'slug', 'status', 'title', 'body' ) as $required ) {
			if ( ! array_key_exists( $required, $snapshot ) ) {
				throw new Paibao_AI_Operations_Error( 'Revision snapshot is incomplete.', 409 );
			}
		}
		$status = array( 'draft' => 'draft', 'published' => 'publish' )[ $snapshot['status'] ] ?? null;
		if ( ! is_string( $status ) ) {
			throw new Paibao_AI_Operations_Error( 'Revision status is invalid.', 409 );
		}
		$restore_after = array_intersect_key( $snapshot, array_flip( array( 'type', 'locale', 'slug', 'title', 'description', 'body', 'seo', 'geo' ) ) );
		$restored = self::validated_after( $restore_after, $post );
		$result = wp_update_post(
			array(
				'ID' => $post->ID, 'post_name' => $restored['slug'], 'post_status' => $status,
				'post_title' => $restored['title'], 'post_excerpt' => $restored['description'], 'post_content' => $restored['body'],
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			throw new Paibao_AI_Operations_Error( $result->get_error_message(), 500 );
		}
		self::set_json_meta( $post->ID, self::SEO_META, $restored['seo'] );
		self::set_json_meta( $post->ID, self::GEO_META, $restored['geo'] );
		update_post_meta( $post->ID, self::LOCALE_META, $restored['locale'] );
		self::bump_version( $post->ID );
	}

	private static function snapshot( WP_Post $post ): array {
		$status = array( 'publish' => 'published', 'future' => 'scheduled', 'trash' => 'archived' )[ $post->post_status ] ?? 'draft';
		$seo    = self::managed_seo( $post->ID );
		$geo    = self::managed_geo( $post->ID );
		$data   = array(
			'id' => (string) $post->ID,
			'type' => $post->post_type,
			'locale' => (string) ( get_post_meta( $post->ID, self::LOCALE_META, true ) ?: self::binding()['locale'] ),
			'slug' => $post->post_name,
			'status' => $status,
			'title' => $post->post_title,
			'body' => $post->post_content,
			'version' => self::version( $post ),
		);
		if ( '' !== $post->post_excerpt ) {
			$data['description'] = $post->post_excerpt;
		}
		if ( is_array( $seo ) ) {
			$data['seo'] = $seo;
		}
		if ( is_array( $geo ) ) {
			$data['geo'] = $geo;
		}
		if ( 'publish' === $post->post_status ) {
			$url = get_permalink( $post );
			if ( is_string( $url ) ) {
				$data['publicUrl'] = $url;
			}
		}
		return $data;
	}

	private static function version( WP_Post $post ): string {
		$material = array(
			'id' => $post->ID, 'type' => $post->post_type, 'slug' => $post->post_name, 'status' => $post->post_status,
			'title' => $post->post_title, 'description' => $post->post_excerpt, 'body' => $post->post_content,
			'seo' => self::json_meta( $post->ID, self::SEO_META ), 'geo' => self::json_meta( $post->ID, self::GEO_META ),
			'locale' => (string) get_post_meta( $post->ID, self::LOCALE_META, true ),
			'modified' => $post->post_modified_gmt, 'counter' => (int) get_post_meta( $post->ID, self::VERSION_META, true ),
		);
		return 'v1:' . hash( 'sha256', (string) self::canonical_json( $material ) );
	}

	private static function save_revision( WP_Post $post, array $snapshot ): int {
		global $wpdb;
		$binding = self::binding();
		$json    = wp_json_encode( $snapshot, self::JSON_FLAGS );
		if ( ! is_string( $json ) || strlen( $json ) > 1000000 ) {
			throw new Paibao_AI_Operations_Error( 'Revision snapshot is too large.', 413 );
		}
		$ok = $wpdb->insert(
			self::revision_table(),
			array( 'tenant_id' => $binding['tenant_id'], 'site_id' => $binding['site_id'], 'post_id' => $post->ID, 'resource_type' => $post->post_type, 'version' => $snapshot['version'], 'snapshot_json' => $json, 'created_at' => current_time( 'mysql', true ) ),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		if ( 1 !== $ok || $wpdb->insert_id < 1 ) {
			throw new Paibao_AI_Operations_Error( 'Revision snapshot failed.', 500 );
		}
		return (int) $wpdb->insert_id;
	}

	public static function prepare_public_output(): void {
		if ( ! is_singular() ) {
			return;
		}
		$post_id = get_queried_object_id();
		$seo     = self::managed_seo( $post_id );
		if ( is_array( $seo ) && is_string( $seo['canonical'] ?? null ) && '' !== $seo['canonical'] ) {
			remove_action( 'wp_head', 'rel_canonical' );
		}
	}

	public static function render_head(): void {
		if ( ! is_singular() ) {
			return;
		}
		$seo = self::managed_seo( get_queried_object_id() );
		if ( ! is_array( $seo ) ) {
			return;
		}
		if ( isset( $seo['canonical'] ) ) {
			echo '<link rel="canonical" href="' . esc_url( $seo['canonical'] ) . '" />' . "\n";
		}
		foreach ( $seo['hreflang'] ?? array() as $language => $url ) {
			echo '<link rel="alternate" hreflang="' . esc_attr( $language ) . '" href="' . esc_url( $url ) . '" />' . "\n";
		}
		if ( isset( $seo['description'] ) ) {
			echo '<meta name="description" content="' . esc_attr( $seo['description'] ) . '" />' . "\n";
		}
		if ( true === ( $seo['noIndex'] ?? false ) ) {
			echo '<meta name="robots" content="noindex,follow" />' . "\n";
		}
		$open_graph = $seo['openGraph'] ?? array();
		if ( isset( $seo['title'] ) && ! isset( $open_graph['og:title'] ) ) {
			$open_graph['og:title'] = $seo['title'];
		}
		if ( isset( $seo['canonical'] ) && ! isset( $open_graph['og:url'] ) ) {
			$open_graph['og:url'] = $seo['canonical'];
		}
		if ( isset( $seo['image'] ) && ! isset( $open_graph['og:image'] ) ) {
			$open_graph['og:image'] = $seo['image'];
		}
		foreach ( $open_graph as $property => $content ) {
			echo '<meta property="' . esc_attr( $property ) . '" content="' . esc_attr( $content ) . '" />' . "\n";
		}
		$twitter = $seo['twitter'] ?? array();
		if ( isset( $seo['title'] ) && ! isset( $twitter['twitter:title'] ) ) {
			$twitter['twitter:title'] = $seo['title'];
		}
		if ( isset( $seo['image'] ) && ! isset( $twitter['twitter:image'] ) ) {
			$twitter['twitter:image'] = $seo['image'];
			$twitter['twitter:card'] = $twitter['twitter:card'] ?? 'summary_large_image';
		}
		foreach ( $twitter as $name => $content ) {
			echo '<meta name="' . esc_attr( $name ) . '" content="' . esc_attr( $content ) . '" />' . "\n";
		}
		foreach ( $seo['jsonLd'] ?? array() as $document ) {
			$json = wp_json_encode( $document, self::JSON_FLAGS );
			if ( is_string( $json ) ) {
				echo '<script type="application/ld+json">' . $json . '</script>' . "\n";
			}
		}
	}

	public static function managed_document_title( string $title ): string {
		if ( ! is_singular() ) {
			return $title;
		}
		$seo = self::managed_seo( get_queried_object_id() );
		return is_array( $seo ) && is_string( $seo['title'] ?? null ) ? $seo['title'] : $title;
	}

	public static function render_visible_geo( string $content ): string {
		if ( is_admin() || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$geo = self::managed_geo( get_the_ID() );
		if ( ! is_array( $geo ) || empty( $geo ) ) {
			return $content;
		}
		$html = '<section class="paibao-geo-evidence" data-ai-geo-evidence="managed-v1">';
		if ( isset( $geo['directAnswer'] ) ) {
			$html .= '<p data-ai-direct-answer="true">' . esc_html( $geo['directAnswer'] ) . '</p>';
		}
		if ( ! empty( $geo['facts'] ) ) {
			$html .= '<dl>';
			foreach ( $geo['facts'] as $fact ) {
				$html .= '<dt>' . esc_html( $fact['label'] ?? 'Fact' ) . '</dt><dd>' . esc_html( self::scalar_text( $fact['value'] ?? '' ) ) . ' <a rel="nofollow noopener" href="' . esc_url( $fact['sourceUrl'] ) . '">' . esc_html( $fact['asOf'] ) . '</a></dd>';
			}
			$html .= '</dl>';
		}
		if ( ! empty( $geo['sources'] ) ) {
			$html .= '<h2>' . esc_html__( 'Sources', 'paibao-ai-operator' ) . '</h2><ul>';
			foreach ( $geo['sources'] as $source ) {
				$html .= '<li><a rel="nofollow noopener" href="' . esc_url( $source['url'] ) . '">' . esc_html( $source['title'] ) . '</a></li>';
			}
			$html .= '</ul>';
		}
		if ( isset( $geo['reviewedAt'] ) ) {
			$html .= '<p><time datetime="' . esc_attr( $geo['reviewedAt'] ) . '">' . esc_html( $geo['reviewedAt'] ) . '</time></p>';
		}
		return $content . $html . '</section>';
	}

	private static function response( array $data, int $status = 200 ): WP_REST_Response {
		$response = new WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	private static function bound_post( string $type, int $id ): WP_Post|WP_Error {
		$valid_type = self::content_type( $type );
		if ( is_wp_error( $valid_type ) ) {
			return $valid_type;
		}
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || $post->post_type !== $valid_type || 'auto-draft' === $post->post_status ) {
			return new WP_Error( 'paibao_bridge_not_found', 'Content not found.', array( 'status' => 404 ) );
		}
		return $post;
	}

	private static function content_type( string $type ): string|WP_Error {
		$object = get_post_type_object( $type );
		if ( ! $object || ! $object->show_in_rest || ! post_type_supports( $type, 'title' ) || ! post_type_supports( $type, 'editor' ) ) {
			return new WP_Error( 'paibao_bridge_type', 'Content type is not managed.', array( 'status' => 400 ) );
		}
		return $type;
	}

	private static function binding(): array {
		$tenant = defined( 'PAIBAO_AI_OPERATIONS_TENANT_ID' ) ? (string) PAIBAO_AI_OPERATIONS_TENANT_ID : '';
		$site   = defined( 'PAIBAO_AI_OPERATIONS_SITE_ID' ) ? strtolower( (string) PAIBAO_AI_OPERATIONS_SITE_ID ) : '';
		$locale = defined( 'PAIBAO_AI_OPERATIONS_LOCALE' ) ? (string) PAIBAO_AI_OPERATIONS_LOCALE : 'en';
		$home   = wp_parse_url( home_url( '/' ) );
		$origin = '';
		if ( is_array( $home ) && 'https' === ( $home['scheme'] ?? '' ) && ! empty( $home['host'] ) && ! isset( $home['user'], $home['pass'] ) ) {
			$port = isset( $home['port'] ) && 443 !== (int) $home['port'] ? ':' . (int) $home['port'] : '';
			$origin = 'https://' . strtolower( $home['host'] ) . $port;
		}
		return array( 'tenant_id' => $tenant, 'site_id' => $site, 'origin' => $origin, 'locale' => $locale );
	}

	private static function valid_binding(): bool {
		$home = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $home ) || 'https' !== ( $home['scheme'] ?? '' ) ) {
			return false;
		}
		$binding = self::binding();
		return 1 === preg_match( '/^[A-Za-z0-9._-]{3,128}$/', $binding['tenant_id'] )
			&& 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $binding['site_id'] )
			&& 1 === preg_match( '/^[A-Za-z0-9-]{2,20}$/', $binding['locale'] )
			&& '' !== $binding['origin'];
	}

	private static function storage_ready(): bool {
		global $wpdb;
		foreach ( array( $wpdb->posts, $wpdb->postmeta, self::audit_table(), self::revision_table() ) as $table ) {
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
			if ( 'InnoDB' !== $engine ) {
				return false;
			}
		}
		return true;
	}

	private static function seo_conflict(): bool {
		return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' );
	}

	private static function audit_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'paibao_ai_audit';
	}

	private static function revision_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'paibao_ai_revisions';
	}

	private static function json_meta( int $post_id, string $key ): ?array {
		$value = get_post_meta( $post_id, $key, true );
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 1000000 ) {
			return null;
		}
		$decoded = json_decode( $value, true, 32 );
		return is_array( $decoded ) ? $decoded : null;
	}

	private static function managed_seo( int $post_id ): ?array {
		try {
			return self::validate_seo( self::json_meta( $post_id, self::SEO_META ) );
		} catch ( Throwable ) {
			return null;
		}
	}

	private static function managed_geo( int $post_id ): ?array {
		try {
			return self::validate_geo( self::json_meta( $post_id, self::GEO_META ) );
		} catch ( Throwable ) {
			return null;
		}
	}

	private static function set_json_meta( int $post_id, string $key, ?array $value ): void {
		if ( null === $value ) {
			delete_post_meta( $post_id, $key );
			return;
		}
		$json = wp_json_encode( $value, self::JSON_FLAGS );
		if ( ! is_string( $json ) ) {
			throw new Paibao_AI_Operations_Error( 'Managed metadata encoding failed.', 500 );
		}
		update_post_meta( $post_id, $key, $json );
	}

	private static function bump_version( int $post_id ): void {
		update_post_meta( $post_id, self::VERSION_META, (int) get_post_meta( $post_id, self::VERSION_META, true ) + 1 );
		clean_post_cache( $post_id );
	}

	private static function canonical_json( mixed $value ): ?string {
		if ( null === $value || is_bool( $value ) || is_string( $value ) || is_int( $value ) || is_float( $value ) && is_finite( $value ) ) {
			return wp_json_encode( $value, self::JSON_FLAGS );
		}
		if ( ! is_array( $value ) ) {
			return null;
		}
		if ( array_is_list( $value ) ) {
			$parts = array_map( array( __CLASS__, 'canonical_json' ), $value );
			return in_array( null, $parts, true ) ? null : '[' . implode( ',', $parts ) . ']';
		}
		ksort( $value, SORT_STRING );
		$parts = array();
		foreach ( $value as $key => $entry ) {
			$encoded = self::canonical_json( $entry );
			$key_json = wp_json_encode( (string) $key, self::JSON_FLAGS );
			if ( null === $encoded || ! is_string( $key_json ) ) {
				return null;
			}
			$parts[] = $key_json . ':' . $encoded;
		}
		return '{' . implode( ',', $parts ) . '}';
	}

	private static function string_map( mixed $value, string $kind ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) || count( $value ) > 50 ) {
			throw new Paibao_AI_Operations_Error( 'SEO map is invalid.', 400 );
		}
		$out = array();
		foreach ( $value as $key => $entry ) {
			if ( ! is_string( $key ) || 1 !== preg_match( '/^[A-Za-z0-9:_-]{1,64}$/', $key ) ) {
				throw new Paibao_AI_Operations_Error( 'SEO map key is invalid.', 400 );
			}
			if ( 'openGraph' === $kind && ! str_starts_with( $key, 'og:' ) || 'twitter' === $kind && ! str_starts_with( $key, 'twitter:' ) ) {
				throw new Paibao_AI_Operations_Error( 'SEO map namespace is invalid.', 400 );
			}
			if ( 'hreflang' === $kind ) {
				$out[ $key ] = self::https_url( $entry, true );
			} elseif ( in_array( $key, array( 'og:url' ), true ) ) {
				$out[ $key ] = self::https_url( $entry, true );
			} elseif ( in_array( $key, array( 'og:image', 'twitter:image' ), true ) ) {
				$out[ $key ] = self::https_url( $entry, false );
			} else {
				$out[ $key ] = self::text( $entry, 1, 2000, 'seo.map' );
			}
		}
		return $out;
	}

	private static function https_url( mixed $value, bool $same_origin ): string {
		$url = self::text( $value, 1, 2048, 'url' );
		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) || 'https' !== ( $parsed['scheme'] ?? '' ) || empty( $parsed['host'] ) || isset( $parsed['user'], $parsed['pass'] ) ) {
			throw new Paibao_AI_Operations_Error( 'HTTPS URL is invalid.', 400 );
		}
		if ( $same_origin ) {
			$port = isset( $parsed['port'] ) && 443 !== (int) $parsed['port'] ? ':' . (int) $parsed['port'] : '';
			if ( 'https://' . strtolower( $parsed['host'] ) . $port !== self::binding()['origin'] ) {
				throw new Paibao_AI_Operations_Error( 'Managed URL must use the site origin.', 400 );
			}
		}
		return $url;
	}

	private static function date_text( mixed $value ): string {
		$text = self::text( $value, 4, 40, 'date' );
		try {
			$date = new DateTimeImmutable( $text );
		} catch ( Throwable ) {
			throw new Paibao_AI_Operations_Error( 'Date is invalid.', 400 );
		}
		return $date->format( DATE_ATOM );
	}

	private static function text( mixed $value, int $min, int $max, string $field ): string {
		if ( ! is_string( $value ) || wp_check_invalid_utf8( $value, true ) !== $value ) {
			throw new Paibao_AI_Operations_Error( ucfirst( $field ) . ' is invalid.', 400 );
		}
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
		if ( $length < $min || $length > $max || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value ) ) {
			throw new Paibao_AI_Operations_Error( ucfirst( $field ) . ' is out of bounds.', 400 );
		}
		return $value;
	}

	private static function scalar_text( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		return is_scalar( $value ) ? (string) $value : '';
	}

	private static function bounded_int( mixed $value, int $default, int $min, int $max ): int {
		if ( null === $value || '' === $value ) {
			return $default;
		}
		$int = filter_var( $value, FILTER_VALIDATE_INT );
		return false !== $int && $int >= $min && $int <= $max ? $int : $default;
	}

	private static function exact_keys( array $value, array $keys ): bool {
		return count( $value ) === count( $keys ) && self::has_keys( $value, $keys );
	}

	private static function has_keys( array $value, array $keys ): bool {
		return ! array_diff( $keys, array_keys( $value ) );
	}

	private static function only_keys( array $value, array $keys ): bool {
		return ! array_diff( array_keys( $value ), $keys );
	}
}
