<?php

function paibao_integration_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function paibao_integration_data( mixed $response ): array {
	if ( is_wp_error( $response ) ) {
		throw new RuntimeException( $response->get_error_code() . ': ' . $response->get_error_message() );
	}
	paibao_integration_assert( $response instanceof WP_REST_Response, 'REST response missing' );
	$data = $response->get_data();
	paibao_integration_assert( is_array( $data ), 'REST response data missing' );
	return $data;
}

function paibao_integration_request( array $payload, string $version, string $label ): array {
	$body = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	paibao_integration_assert( is_string( $body ), 'Mutation encoding failed' );
	$request = new WP_REST_Request( 'POST', '/paibao-ai-operations/v1/mutations' );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_header( 'if-match', $version );
	$request->set_header( 'idempotency-key', 'wpai_' . substr( hash( 'sha256', $label ), 0, 48 ) );
	$request->set_body( $body );
	$_SERVER['CONTENT_LENGTH'] = (string) strlen( $body );
	paibao_integration_assert( true === Paibao_AI_Operations_Native_Bridge::permission( $request ), 'Mutation permission failed' );
	return paibao_integration_data( Paibao_AI_Operations_Native_Bridge::mutate( $request ) );
}

$user_id = wp_insert_user(
	array(
		'user_login' => 'paibao-integration-operator',
		'user_pass' => wp_generate_password( 32, true, true ),
		'user_email' => 'operator-integration@example.test',
		'role' => 'paibao_ai_operator',
	)
);
paibao_integration_assert( is_int( $user_id ), 'Operator user creation failed' );
$application = WP_Application_Passwords::create_new_application_password( $user_id, array( 'name' => 'Paibao integration' ) );
paibao_integration_assert( is_array( $application ) && is_array( $application[1] ?? null ), 'Application Password creation failed' );
wp_set_current_user( $user_id );
$GLOBALS['wp_rest_application_password_uuid'] = $application[1]['uuid'];

$post_id = wp_insert_post(
	array(
		'post_type' => 'post',
		'post_status' => 'draft',
		'post_name' => 'pump-guide',
		'post_title' => 'Original Pump Guide',
		'post_excerpt' => 'Original description',
		'post_content' => '<p>Original pump guide.</p>',
	),
	true
);
paibao_integration_assert( is_int( $post_id ), 'Post creation failed' );

$read = new WP_REST_Request( 'GET', '/paibao-ai-operations/v1/content/post/' . $post_id );
$read->set_param( 'type', 'post' );
$read->set_param( 'id', (string) $post_id );
paibao_integration_assert( true === Paibao_AI_Operations_Native_Bridge::permission( $read ), 'Read permission failed' );
$original = paibao_integration_data( Paibao_AI_Operations_Native_Bridge::get_content( $read ) );
$origin = home_url();
$canonical = home_url( '/pump-guide/' );
$image = home_url( '/wp-includes/images/w-logo-blue-white-bg.png' );

$update_payload = array(
		'schemaVersion' => 1,
		'action' => 'update',
		'resourceType' => 'post',
		'resourceId' => (string) $post_id,
		'revisionId' => null,
		'after' => array(
			'type' => 'post',
			'locale' => 'en',
			'slug' => 'pump-guide',
			'title' => 'Updated Pump Guide',
			'description' => 'Updated description',
			'body' => '<p>Updated pump guide.</p>',
			'seo' => array(
				'title' => 'Updated Pump Guide',
				'description' => 'Updated description',
				'canonical' => $canonical,
				'image' => $image,
				'noIndex' => false,
				'hreflang' => array( 'en' => $canonical, 'x-default' => $canonical ),
				'openGraph' => array(
					'title' => 'Updated Pump Guide',
					'description' => 'Updated description',
					'type' => 'article',
					'url' => $canonical,
					'image' => $image,
					'siteName' => 'Paibao Integration',
					'locale' => 'en_US',
					'localeAlternate' => 'zh_CN',
				),
				'twitter' => array(
					'card' => 'summary_large_image',
					'title' => 'Updated Pump Guide',
					'description' => 'Updated description',
					'image' => $image,
					'site' => '@paibao',
					'creator' => '@paibao',
				),
				'jsonLd' => array(
					array(
						'@context' => 'https://schema.org',
						'@graph' => array(
							array( '@type' => 'Organization', 'name' => 'Paibao Integration', 'url' => $origin ),
							array( '@type' => 'WebSite', 'name' => 'Paibao Integration', 'url' => $origin ),
							array(
								'@type' => 'Article',
								'headline' => 'Updated Pump Guide',
								'additionalProperty' => array( array( '@type' => 'PropertyValue', 'name' => 'Flow', 'value' => '20 m3/h' ) ),
							),
							array(
								'@type' => 'FAQPage',
								'mainEntity' => array( array( '@type' => 'Question', 'name' => 'What is a pump?', 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => 'It moves fluid.' ) ) ),
							),
							array( '@type' => 'BreadcrumbList', 'itemListElement' => array( array( '@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $origin ) ) ),
						),
					),
				),
			),
			'geo' => array(
				'directAnswer' => 'Choose the pump by its verified duty point.',
				'facts' => array( array( 'label' => 'Flow', 'value' => '20 m3/h', 'sourceUrl' => $canonical, 'asOf' => '2026-07-14' ) ),
				'sources' => array( array( 'title' => 'Pump guide', 'url' => $canonical ) ),
				'reviewedAt' => '2026-07-14',
			),
		),
	);
$invalid_payload = $update_payload;
$invalid_payload['after']['seo']['twitter']['card'] = 'player';
try {
	paibao_integration_request( $invalid_payload, $original['version'], 'invalid-twitter-card' );
	paibao_integration_assert( false, 'Unsupported Twitter card was accepted' );
} catch ( RuntimeException $error ) {
	paibao_integration_assert( str_contains( $error->getMessage(), 'Twitter card is not managed' ), 'Unexpected Twitter card failure' );
}

$updated = paibao_integration_request(
	$update_payload,
	$original['version'],
	'update'
);
paibao_integration_assert( 'draft' === $updated['content']['status'], 'Update changed publication status' );
paibao_integration_assert( 'Updated Pump Guide' === $updated['content']['seo']['openGraph']['title'], 'Open Graph DTO changed' );
paibao_integration_assert( 'summary_large_image' === $updated['content']['seo']['twitter']['card'], 'Twitter DTO changed' );
paibao_integration_assert( isset( $updated['content']['seo']['jsonLd'][0]['@graph'] ), 'JSON-LD graph missing' );

$published = paibao_integration_request(
	array(
		'schemaVersion' => 1,
		'action' => 'publish',
		'resourceType' => 'post',
		'resourceId' => (string) $post_id,
		'revisionId' => null,
		'after' => null,
	),
	$updated['content']['version'],
	'publish'
);
paibao_integration_assert( 'published' === $published['content']['status'], 'Publish failed' );

$GLOBALS['wp_query'] = new WP_Query( array( 'p' => $post_id, 'post_type' => 'post' ) );
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
ob_start();
Paibao_AI_Operations_Native_Bridge::render_head();
$head = (string) ob_get_clean();
paibao_integration_assert( str_contains( $head, 'property="og:title" content="Updated Pump Guide"' ), 'Public Open Graph missing' );
paibao_integration_assert( str_contains( $head, 'property="og:site_name" content="Paibao Integration"' ), 'Public Open Graph site name missing' );
paibao_integration_assert( str_contains( $head, 'name="twitter:card" content="summary_large_image"' ), 'Public Twitter card missing' );
paibao_integration_assert( str_contains( $head, '"@context":"https://schema.org","@graph"' ), 'Public JSON-LD graph missing' );
$GLOBALS['wp_query']->the_post();
$content = Paibao_AI_Operations_Native_Bridge::render_visible_geo( get_the_content() );
paibao_integration_assert( str_contains( $content, 'data-ai-direct-answer="true"' ), 'Public GEO direct answer missing' );
paibao_integration_assert( str_contains( $content, '20 m3/h' ), 'Public GEO fact missing' );
wp_reset_postdata();

$restored = paibao_integration_request(
	array(
		'schemaVersion' => 1,
		'action' => 'restore',
		'resourceType' => 'post',
		'resourceId' => (string) $post_id,
		'revisionId' => $updated['beforeRevisionId'],
		'after' => null,
	),
	$published['content']['version'],
	'restore'
);
paibao_integration_assert( 'draft' === $restored['content']['status'], 'Rollback did not restore draft status' );
paibao_integration_assert( 'Original Pump Guide' === $restored['content']['title'], 'Rollback did not restore title' );
paibao_integration_assert( '<p>Original pump guide.</p>' === $restored['content']['body'], 'Rollback did not restore body' );
paibao_integration_assert( ! isset( $restored['content']['seo'] ) && ! isset( $restored['content']['geo'] ), 'Rollback did not remove managed metadata' );

define( 'WPSEO_VERSION', 'integration-conflict' );
$conflict = Paibao_AI_Operations_Native_Bridge::capabilities();
paibao_integration_assert( is_wp_error( $conflict ) && 'paibao_seo_conflict' === $conflict->get_error_code(), 'SEO plugin conflict did not fail closed' );
