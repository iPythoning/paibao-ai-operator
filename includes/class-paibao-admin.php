<?php
/**
 * WordPress administrator console for Paibao AI Operations.
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

final class Paibao_AI_Operations_Admin {
	private const MARKETPLACE_PAGE = 'paibao-ai-operations-marketplace';
	private const MARKETPLACE_PRODUCT = 'ai-operations-officer-wordpress';
	private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
	private static ?Paibao_AI_Operations_Control_Client $client = null;

	public static function boot( Paibao_AI_Operations_Control_Client $client ): void {
		self::$client = $client;
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_paibao_ai_operations_proposal', array( __CLASS__, 'handle_proposal' ) );
		add_action( 'admin_post_paibao_ai_operations_approve', array( __CLASS__, 'handle_approve' ) );
		add_action( 'admin_post_paibao_ai_operations_rollback', array( __CLASS__, 'handle_rollback' ) );
	}

	public static function menu(): void {
		add_menu_page(
			'Paibao AI 运营官',
			'AI 运营官',
			'manage_options',
			self::MARKETPLACE_PAGE,
			array( __CLASS__, 'render_ai_operations' ),
			'dashicons-chart-area',
			58
		);
	}

	public static function handle_proposal(): void {
		$request_id = '';
		try {
			self::assert_admin_post( 'paibao_ai_operations_proposal', array( 'action', '_wpnonce', '_wp_http_referer', 'paibao_goal', 'paibao_scope', 'paibao_request_id' ) );
			$request_id = self::uuid( self::post_string( 'paibao_request_id' ) );
			$goal  = trim( self::control_text( self::post_string( 'paibao_goal' ), 2000 ) );
			$scope = self::post_string( 'paibao_scope' );
			if ( '' === $goal || ! in_array( $scope, array( 'audit', 'content' ), true ) ) {
				throw new Paibao_AI_Operations_Error( '提案内容无效。' );
			}
			$paibao_idempotency_key = self::stable_idempotency_key( 'proposal', $request_id );
			$result = self::client()->propose( $goal, $scope, $paibao_idempotency_key );
			$job = is_array( $result['job'] ?? null ) ? self::sanitize_job_detail( $result['job'] ) : null;
			self::redirect( 'requested', is_array( $job ) ? $job['id'] : '' );
		} catch ( Throwable $error ) {
			self::redirect( self::notice_for_error( $error ), '', $request_id );
		}
	}

	public static function handle_approve(): void {
		self::handle_job_action( 'approve', 'paibao_ai_operations_approve' );
	}

	public static function handle_rollback(): void {
		self::handle_job_action( 'rollback', 'paibao_ai_operations_rollback' );
	}

	private static function handle_job_action( string $operation, string $nonce_action ): void {
		$job_id = '';
		try {
			self::assert_admin_post( $nonce_action, array( 'action', '_wpnonce', '_wp_http_referer', 'paibao_job_id', 'paibao_confirm' ) );
			$job_id = strtolower( self::post_string( 'paibao_job_id' ) );
			if ( 1 !== preg_match( self::UUID, $job_id ) || 'yes' !== self::post_string( 'paibao_confirm' ) ) {
				throw new Paibao_AI_Operations_Error( '请确认任务操作。' );
			}
			$paibao_idempotency_key = self::stable_idempotency_key( $operation, $job_id );
			$result = 'approve' === $operation
				? self::client()->approve( $job_id, $paibao_idempotency_key )
				: self::client()->rollback( $job_id, $paibao_idempotency_key );
			self::sanitize_job_detail( $result );
			self::redirect( 'requested', $job_id );
		} catch ( Throwable $error ) {
			self::redirect( self::notice_for_error( $error ), $job_id );
		}
	}

	private static function assert_admin_post( string $nonce_action, array $allowed_fields ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage AI Operations.', 'paibao-ai-operator' ), '', array( 'response' => 403 ) );
		}
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'POST is required.', 'paibao-ai-operator' ), '', array( 'response' => 405 ) );
		}
		$length = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
		if ( $length < 1 || $length > 16384 ) {
			wp_die( esc_html__( 'Request body is invalid.', 'paibao-ai-operator' ), '', array( 'response' => 413 ) );
		}
		$content_type = strtolower( trim( explode( ';', (string) ( $_SERVER['CONTENT_TYPE'] ?? '' ) )[0] ) );
		if ( 'application/x-www-form-urlencoded' !== $content_type ) {
			wp_die( esc_html__( 'Form encoding is required.', 'paibao-ai-operator' ), '', array( 'response' => 415 ) );
		}
		self::assert_same_origin_post();
		self::assert_post_fields( $allowed_fields );
		check_admin_referer( $nonce_action );
	}

	private static function assert_same_origin_post(): void {
		$candidate = (string) ( $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '' );
		$actual    = wp_parse_url( $candidate );
		$expected  = wp_parse_url( admin_url( '/' ) );
		if ( ! is_array( $actual ) || ! is_array( $expected ) || ! isset( $actual['scheme'], $actual['host'], $expected['scheme'], $expected['host'] ) ) {
			wp_die( esc_html__( 'Same-origin request is required.', 'paibao-ai-operator' ), '', array( 'response' => 403 ) );
		}
		$actual_port   = (int) ( $actual['port'] ?? ( 'https' === $actual['scheme'] ? 443 : 80 ) );
		$expected_port = (int) ( $expected['port'] ?? ( 'https' === $expected['scheme'] ? 443 : 80 ) );
		if ( strtolower( $actual['scheme'] ) !== strtolower( $expected['scheme'] ) || strtolower( $actual['host'] ) !== strtolower( $expected['host'] ) || $actual_port !== $expected_port ) {
			wp_die( esc_html__( 'Cross-origin request was rejected.', 'paibao-ai-operator' ), '', array( 'response' => 403 ) );
		}
	}

	private static function assert_post_fields( array $allowed ): void {
		$required = array_values( array_diff( $allowed, array( '_wp_http_referer' ) ) );
		$keys     = array_keys( $_POST );
		if ( array_diff( $keys, $allowed ) || array_diff( $required, $keys ) ) {
			wp_die( esc_html__( 'Unexpected form fields.', 'paibao-ai-operator' ), '', array( 'response' => 400 ) );
		}
		foreach ( $_POST as $value ) {
			if ( ! is_string( $value ) ) {
				wp_die( esc_html__( 'Scalar form values are required.', 'paibao-ai-operator' ), '', array( 'response' => 400 ) );
			}
		}
	}

	private static function stable_idempotency_key( string $operation, string $material ): string {
		$site_id = defined( 'PAIBAO_AI_OPERATIONS_SITE_ID' ) ? strtolower( (string) PAIBAO_AI_OPERATIONS_SITE_ID ) : '';
		return 'paibao_' . substr( hash( 'sha256', $site_id . "\n" . $operation . "\n" . $material ), 0, 48 );
	}

	public static function render_ai_operations(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$notice = isset( $_GET['paibao_notice'] ) && is_string( $_GET['paibao_notice'] ) ? sanitize_key( wp_unslash( $_GET['paibao_notice'] ) ) : '';
		$job_id = isset( $_GET['job'] ) && is_string( $_GET['job'] ) ? strtolower( sanitize_text_field( wp_unslash( $_GET['job'] ) ) ) : '';
		$request_id = isset( $_GET['paibao_request_id'] ) && is_string( $_GET['paibao_request_id'] ) && 1 === preg_match( self::UUID, $_GET['paibao_request_id'] )
			? strtolower( $_GET['paibao_request_id'] )
			: '';
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Paibao AI 运营官', 'paibao-ai-operator' ); ?></h1>
			<?php self::render_notice( $notice ); ?>
			<p><?php echo esc_html__( '服务授权由 Paibao 控制面实时判断；GPL 插件本身不包含也不授予 SaaS 服务权限。', 'paibao-ai-operator' ); ?></p>
			<p><?php echo esc_html__( '新建建议与批准执行时会实时校验服务授权；历史记录与回滚不以当前订阅状态为前提。', 'paibao-ai-operator' ); ?></p>
			<?php
			if ( ! self::client()->is_configured() ) {
				self::render_marketplace_entry();
				?>
			</div>
			<?php
				return;
			}
			try {
				$overview = self::sanitize_overview( self::client()->overview() );
				self::render_connection( $overview['site'] );
				self::render_proposal_form( $request_id );
				if ( 1 === preg_match( self::UUID, $job_id ) ) {
					self::render_job_detail( self::sanitize_job_detail( self::client()->job( $job_id ) ) );
				}
				self::render_job_list( $overview['jobs'] );
			} catch ( Throwable ) {
				self::render_connection_error();
			}
			?>
		</div>
		<?php
	}

	private static function render_marketplace_entry(): void {
		$url = 'https://marketplace.paibao.ai/products/' . self::MARKETPLACE_PRODUCT;
		?>
		<div class="notice notice-info inline">
			<p><strong><?php echo esc_html__( '尚未连接 WordPress AI 运营官', 'paibao-ai-operator' ); ?></strong></p>
			<p><?php echo esc_html__( '请在 Paibao Marketplace 完成购买和站点绑定。安装方会在服务器端下发站点凭据，无需客户复制密钥。', 'paibao-ai-operator' ); ?></p>
			<p><a class="button button-primary" href="<?php echo esc_url( $url ); ?>" rel="noopener noreferrer"><?php echo esc_html__( '前往 Marketplace', 'paibao-ai-operator' ); ?></a></p>
		</div>
		<?php
	}

	private static function render_connection_error(): void {
		?>
		<div class="notice notice-warning inline"><p><?php echo esc_html__( '站点已配置安全绑定，但暂时无法确认连接状态。请稍后刷新；不要重复购买。', 'paibao-ai-operator' ); ?></p></div>
		<?php
	}

	private static function render_connection( array $site ): void {
		?>
		<h2><?php echo esc_html__( '订阅与站点连接', 'paibao-ai-operator' ); ?></h2>
		<table class="widefat striped" style="max-width:900px"><tbody>
		<tr><th><?php echo esc_html__( '站点', 'paibao-ai-operator' ); ?></th><td><?php echo esc_html( $site['id'] ); ?></td></tr>
		<tr><th><?php echo esc_html__( '平台', 'paibao-ai-operator' ); ?></th><td>WordPress</td></tr>
		<tr><th><?php echo esc_html__( '连接状态', 'paibao-ai-operator' ); ?></th><td><?php echo esc_html( $site['connectionStatus'] ); ?></td></tr>
		<tr><th><?php echo esc_html__( '审批方式', 'paibao-ai-operator' ); ?></th><td><?php echo esc_html( $site['approvalMode'] ); ?></td></tr>
		</tbody></table>
		<?php
	}

	private static function render_proposal_form( string $request_id = '' ): void {
		if ( 1 !== preg_match( self::UUID, $request_id ) ) {
			$request_id = strtolower( wp_generate_uuid4() );
		}
		?>
		<h2><?php echo esc_html__( '创建运营任务', 'paibao-ai-operator' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:900px">
			<input type="hidden" name="action" value="paibao_ai_operations_proposal" />
			<input type="hidden" name="paibao_request_id" value="<?php echo esc_attr( $request_id ); ?>" />
			<?php wp_nonce_field( 'paibao_ai_operations_proposal' ); ?>
			<p><label for="paibao-goal"><strong><?php echo esc_html__( '目标', 'paibao-ai-operator' ); ?></strong></label></p>
			<textarea id="paibao-goal" name="paibao_goal" rows="5" maxlength="2000" class="large-text" required></textarea>
			<p><label><input type="radio" name="paibao_scope" value="audit" checked /> <?php echo esc_html__( '只读审计', 'paibao-ai-operator' ); ?></label>&nbsp;&nbsp;<label><input type="radio" name="paibao_scope" value="content" /> <?php echo esc_html__( '内容与 SEO/GEO', 'paibao-ai-operator' ); ?></label></p>
			<p><button class="button button-primary" type="submit"><?php echo esc_html__( '生成变更提案', 'paibao-ai-operator' ); ?></button></p>
		</form>
		<?php
	}

	private static function render_job_list( array $jobs ): void {
		?>
		<h2><?php echo esc_html__( '任务记录', 'paibao-ai-operator' ); ?></h2>
		<table class="widefat striped"><thead><tr><th><?php echo esc_html__( '时间', 'paibao-ai-operator' ); ?></th><th><?php echo esc_html__( '目标', 'paibao-ai-operator' ); ?></th><th><?php echo esc_html__( '状态', 'paibao-ai-operator' ); ?></th><th><?php echo esc_html__( '风险', 'paibao-ai-operator' ); ?></th><th><?php echo esc_html__( '变更', 'paibao-ai-operator' ); ?></th><th></th></tr></thead><tbody>
		<?php foreach ( $jobs as $job ) : ?>
			<tr><td><?php echo esc_html( self::display_time( $job['createdAt'] ) ); ?></td><td><?php echo esc_html( $job['goal'] ); ?></td><td><?php echo esc_html( $job['state'] ); ?></td><td><?php echo esc_html( $job['risk'] ); ?></td><td><?php echo esc_html( (string) $job['changeCount'] ); ?></td><td><a href="<?php echo esc_url( self::page_url( $job['id'] ) ); ?>"><?php echo esc_html__( '查看', 'paibao-ai-operator' ); ?></a></td></tr>
		<?php endforeach; ?>
		<?php if ( empty( $jobs ) ) : ?><tr><td colspan="6"><?php echo esc_html__( '暂无任务。', 'paibao-ai-operator' ); ?></td></tr><?php endif; ?>
		</tbody></table>
		<?php
	}

	private static function render_job_detail( array $job ): void {
		?>
		<hr><h2><?php echo esc_html__( '任务详情', 'paibao-ai-operator' ); ?></h2>
		<p><strong><?php echo esc_html( $job['goal'] ); ?></strong></p>
		<p><?php echo esc_html( $job['summary'] ); ?></p>
		<p><?php echo esc_html( sprintf( '状态：%s；风险：%s；更新时间：%s', $job['state'], $job['risk'], self::display_time( $job['updatedAt'] ) ) ); ?></p>
		<h3><?php echo esc_html__( '变更摘要', 'paibao-ai-operator' ); ?></h3>
		<table class="widefat striped"><thead><tr><th><?php echo esc_html__( '对象', 'paibao-ai-operator' ); ?></th><th><?php echo esc_html__( '动作', 'paibao-ai-operator' ); ?></th><th><?php echo esc_html__( '摘要', 'paibao-ai-operator' ); ?></th></tr></thead><tbody>
		<?php foreach ( $job['changes'] as $change ) : ?><tr><td><?php echo esc_html( $change['target'] ); ?></td><td><?php echo esc_html( $change['action'] ); ?></td><td><?php echo esc_html( $change['summary'] ); ?></td></tr><?php endforeach; ?>
		<?php if ( empty( $job['changes'] ) ) : ?><tr><td colspan="3"><?php echo esc_html__( '提案仍在生成，尚无变更摘要。', 'paibao-ai-operator' ); ?></td></tr><?php endif; ?>
		</tbody></table>
		<?php if ( ! empty( $job['seoGeoChecks'] ) ) : ?><h3><?php echo esc_html__( '公开页面验证', 'paibao-ai-operator' ); ?></h3><ul><?php foreach ( $job['seoGeoChecks'] as $check ) : ?><li><?php echo esc_html( $check ); ?></li><?php endforeach; ?></ul><?php endif; ?>
		<?php self::render_geo_evidence( $job['geoEvidence'] ); ?>
		<div style="display:flex;gap:20px;align-items:flex-start;margin:20px 0">
		<?php if ( 'awaiting_approval' === $job['state'] ) : self::render_confirm_form( 'approve', $job['id'], '确认以上变更摘要并批准执行', '批准执行' ); endif; ?>
		<?php if ( $job['rollbackAvailable'] ) : self::render_confirm_form( 'rollback', $job['id'], '确认回滚到该任务执行前的审计快照', '请求回滚' ); endif; ?>
		</div>
		<?php
	}

	private static function render_confirm_form( string $operation, string $job_id, string $label, string $button ): void {
		$action = 'paibao_ai_operations_' . $operation;
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
			<input type="hidden" name="paibao_job_id" value="<?php echo esc_attr( $job_id ); ?>" />
			<?php wp_nonce_field( $action ); ?>
			<label><input type="checkbox" name="paibao_confirm" value="yes" required /> <?php echo esc_html( $label ); ?></label><br><br>
			<button type="submit" class="button <?php echo 'rollback' === $operation ? '' : 'button-primary'; ?>"><?php echo esc_html( $button ); ?></button>
		</form>
		<?php
	}

	private static function render_geo_evidence( ?array $geo ): void {
		if ( ! is_array( $geo ) ) {
			return;
		}
		?>
		<h3><?php echo esc_html__( 'GEO 事实依据', 'paibao-ai-operator' ); ?></h3>
		<?php if ( '' !== $geo['directAnswer'] ) : ?><p><strong><?php echo esc_html( $geo['directAnswer'] ); ?></strong></p><?php endif; ?>
		<ul><?php foreach ( $geo['facts'] as $fact ) : ?><li><?php echo esc_html( ( '' !== $fact['label'] ? $fact['label'] . '：' : '' ) . self::scalar_text( $fact['value'] ) . '（' . $fact['asOf'] . '）' ); ?> <a href="<?php echo esc_url( $fact['sourceUrl'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( '来源', 'paibao-ai-operator' ); ?></a></li><?php endforeach; ?></ul>
		<?php if ( ! empty( $geo['sources'] ) ) : ?><h4><?php echo esc_html__( '来源清单', 'paibao-ai-operator' ); ?></h4><ul><?php foreach ( $geo['sources'] as $source ) : ?><li><a href="<?php echo esc_url( $source['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $source['title'] ); ?></a></li><?php endforeach; ?></ul><?php endif; ?>
		<?php if ( '' !== $geo['reviewedAt'] ) : ?><p><?php echo esc_html( '更新时间：' . $geo['reviewedAt'] ); ?></p><?php endif; ?>
		<?php
	}

	private static function render_notice( string $notice ): void {
		$messages = array(
			'requested' => array( 'success', '请求已提交；请刷新任务详情确认控制面最终状态。' ),
			'pending'   => array( 'warning', '请求结果待确认，请刷新任务状态；系统不会自动重复批准或回滚。' ),
		);
		if ( ! isset( $messages[ $notice ] ) ) {
			if ( 'entitlement' === $notice ) {
				$url = 'https://marketplace.paibao.ai/products/' . self::MARKETPLACE_PRODUCT;
				printf( '<div class="notice notice-warning"><p>%s <a href="%s" rel="noopener noreferrer">%s</a></p></div>', esc_html__( '当前服务授权不足，请在 Marketplace 购买或续费。', 'paibao-ai-operator' ), esc_url( $url ), esc_html__( '前往 Marketplace', 'paibao-ai-operator' ) );
			}
			return;
		}
		printf( '<div class="notice notice-%s"><p>%s</p></div>', esc_attr( $messages[ $notice ][0] ), esc_html( $messages[ $notice ][1] ) );
	}

	private static function sanitize_overview( mixed $value ): array {
		if ( ! is_array( $value ) || ! self::exact_keys( $value, array( 'site', 'jobs', 'page' ) ) || ! is_array( $value['site'] ) || ! is_array( $value['jobs'] ) || count( $value['jobs'] ) > 20 || ! is_array( $value['page'] ) ) {
			throw new Paibao_AI_Operations_Error( '概览响应无效。' );
		}
		$site = $value['site'];
		if ( ! self::exact_keys( $site, array( 'id', 'platform', 'connectionStatus', 'approvalMode' ) ) ) {
			throw new Paibao_AI_Operations_Error( '站点响应无效。' );
		}
		$id = self::uuid( $site['id'] );
		$expected_id = defined( 'PAIBAO_AI_OPERATIONS_SITE_ID' ) ? strtolower( (string) PAIBAO_AI_OPERATIONS_SITE_ID ) : '';
		if ( $id !== $expected_id || 'wordpress' !== $site['platform'] || ! in_array( $site['connectionStatus'], array( 'ready', 'degraded', 'disconnected' ), true ) || ! in_array( $site['approvalMode'], array( 'always', 'low_risk' ), true ) ) {
			throw new Paibao_AI_Operations_Error( '站点绑定不匹配。' );
		}
		$page = $value['page'];
		if ( ! self::exact_keys( $page, array( 'limit', 'offset', 'hasMore' ) ) || 20 !== $page['limit'] || 0 !== $page['offset'] || ! is_bool( $page['hasMore'] ) ) {
			throw new Paibao_AI_Operations_Error( '分页响应无效。' );
		}
		$jobs = array_map( array( __CLASS__, 'sanitize_job_summary' ), $value['jobs'] );
		return array( 'site' => array( 'id' => $id, 'platform' => 'wordpress', 'connectionStatus' => $site['connectionStatus'], 'approvalMode' => $site['approvalMode'] ), 'jobs' => $jobs );
	}

	private static function sanitize_job_summary( mixed $value ): array {
		$keys = array( 'id', 'siteId', 'platform', 'goal', 'scope', 'state', 'stateVersion', 'risk', 'summary', 'changeCount', 'rollbackAvailable', 'errorCode', 'createdAt', 'updatedAt' );
		if ( ! is_array( $value ) || ! self::exact_keys( $value, $keys ) ) {
			throw new Paibao_AI_Operations_Error( '任务摘要无效。' );
		}
		$id = self::uuid( $value['id'] );
		$site_id = self::uuid( $value['siteId'] );
		$expected = defined( 'PAIBAO_AI_OPERATIONS_SITE_ID' ) ? strtolower( (string) PAIBAO_AI_OPERATIONS_SITE_ID ) : '';
		$states = array( 'planning', 'awaiting_approval', 'approved', 'executing', 'verifying', 'completed', 'failed', 'rollback_queued', 'rolled_back', 'rollback_failed' );
		$errors = array( null, 'adapter_unavailable', 'version_conflict', 'validation_failed', 'publish_failed', 'verification_failed', 'rollback_failed', 'approval_revoked' );
		if ( $site_id !== $expected || 'wordpress' !== $value['platform'] || ! in_array( $value['scope'], array( 'audit', 'content' ), true ) || ! in_array( $value['state'], $states, true ) || ! in_array( $value['risk'], array( 'none', 'low', 'medium', 'high' ), true ) || ! in_array( $value['errorCode'], $errors, true ) ) {
			throw new Paibao_AI_Operations_Error( '任务绑定无效。' );
		}
		foreach ( array( 'stateVersion', 'changeCount', 'createdAt', 'updatedAt' ) as $field ) {
			if ( ! is_int( $value[ $field ] ) || $value[ $field ] < 0 || in_array( $field, array( 'createdAt', 'updatedAt' ), true ) && $value[ $field ] > 4102444800000 ) {
				throw new Paibao_AI_Operations_Error( '任务数值无效。' );
			}
		}
		if ( $value['changeCount'] > 200 || ! is_bool( $value['rollbackAvailable'] ) ) {
			throw new Paibao_AI_Operations_Error( '任务状态无效。' );
		}
		return array(
			'id' => $id, 'siteId' => $site_id, 'platform' => 'wordpress',
			'goal' => self::control_text( $value['goal'], 2000 ), 'scope' => $value['scope'], 'state' => $value['state'],
			'stateVersion' => $value['stateVersion'], 'risk' => $value['risk'], 'summary' => self::control_text( $value['summary'], 4000 ),
			'changeCount' => $value['changeCount'], 'rollbackAvailable' => $value['rollbackAvailable'], 'errorCode' => $value['errorCode'],
			'createdAt' => $value['createdAt'], 'updatedAt' => $value['updatedAt'],
		);
	}

	private static function sanitize_job_detail( mixed $value ): array {
		if ( ! is_array( $value ) || ! self::has_keys( $value, array( 'changes', 'seoGeoChecks', 'geoEvidence' ) ) ) {
			throw new Paibao_AI_Operations_Error( '任务详情无效。' );
		}
		$summary_keys = array( 'id', 'siteId', 'platform', 'goal', 'scope', 'state', 'stateVersion', 'risk', 'summary', 'changeCount', 'rollbackAvailable', 'errorCode', 'createdAt', 'updatedAt' );
		if ( ! self::exact_keys( $value, array_merge( $summary_keys, array( 'changes', 'seoGeoChecks', 'geoEvidence' ) ) ) ) {
			throw new Paibao_AI_Operations_Error( '任务详情字段无效。' );
		}
		$summary = self::sanitize_job_summary( array_intersect_key( $value, array_flip( $summary_keys ) ) );
		if ( ! is_array( $value['changes'] ) || count( $value['changes'] ) > 200 || ! is_array( $value['seoGeoChecks'] ) || count( $value['seoGeoChecks'] ) > 100 ) {
			throw new Paibao_AI_Operations_Error( '任务详情超出限制。' );
		}
		$changes = array_map(
			static function ( mixed $change ): array {
				if ( ! is_array( $change ) || ! self::exact_keys( $change, array( 'target', 'action', 'summary' ) ) || ! in_array( $change['action'], array( 'create', 'update', 'publish', 'unpublish', 'archive', 'restore' ), true ) ) {
					throw new Paibao_AI_Operations_Error( '变更摘要无效。' );
				}
				return array( 'target' => self::control_text( $change['target'], 500 ), 'action' => $change['action'], 'summary' => self::control_text( $change['summary'], 2000 ) );
			},
			$value['changes']
		);
		$checks = array_map( static fn( mixed $check ): string => self::control_text( $check, 1000 ), $value['seoGeoChecks'] );
		$summary['changes'] = $changes;
		$summary['seoGeoChecks'] = $checks;
		$summary['geoEvidence'] = self::sanitize_geo_evidence( $value['geoEvidence'] );
		return $summary;
	}

	private static function sanitize_geo_evidence( mixed $value ): ?array {
		if ( null === $value ) {
			return null;
		}
		if ( ! is_array( $value ) || ! self::exact_keys( $value, array( 'directAnswer', 'facts', 'sources', 'reviewedAt' ) ) || ! is_array( $value['facts'] ) || count( $value['facts'] ) > 20 || ! is_array( $value['sources'] ) || count( $value['sources'] ) > 20 ) {
			throw new Paibao_AI_Operations_Error( 'GEO 依据无效。' );
		}
		$facts = array_map(
			static function ( mixed $fact ): array {
				if ( ! is_array( $fact ) || ! self::has_keys( $fact, array( 'value', 'sourceUrl', 'asOf' ) ) || ! self::only_keys( $fact, array( 'label', 'value', 'sourceUrl', 'asOf' ) ) ) {
					throw new Paibao_AI_Operations_Error( 'GEO 事实无效。' );
				}
				return array(
					'label' => array_key_exists( 'label', $fact ) ? self::control_text( $fact['label'], 200 ) : '',
					'value' => self::sanitize_fact_value( $fact['value'] ),
					'sourceUrl' => self::control_url( $fact['sourceUrl'] ),
					'asOf' => self::control_text( $fact['asOf'], 40 ),
				);
			},
			$value['facts']
		);
		$sources = array_map(
			static function ( mixed $source ): array {
				if ( ! is_array( $source ) || ! self::exact_keys( $source, array( 'title', 'url' ) ) ) {
					throw new Paibao_AI_Operations_Error( 'GEO 来源无效。' );
				}
				return array( 'title' => self::control_text( $source['title'], 300 ), 'url' => self::control_url( $source['url'] ) );
			},
			$value['sources']
		);
		return array( 'directAnswer' => self::control_text( $value['directAnswer'], 2000 ), 'facts' => $facts, 'sources' => $sources, 'reviewedAt' => self::control_text( $value['reviewedAt'], 40 ) );
	}

	private static function sanitize_fact_value( mixed $value ): string|int|float|bool {
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) && is_finite( $value ) ) {
			return $value;
		}
		return self::control_text( $value, 2000 );
	}

	private static function control_text( mixed $value, int $max ): string {
		if ( ! is_string( $value ) || wp_check_invalid_utf8( $value, true ) !== $value ) {
			throw new Paibao_AI_Operations_Error( '控制面文本无效。' );
		}
		if ( self::text_length( $value ) > $max || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value ) ) {
			throw new Paibao_AI_Operations_Error( '控制面文本超出限制。' );
		}
		return $value;
	}

	private static function text_length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}

	private static function control_url( mixed $value ): string {
		$url = self::control_text( $value, 2048 );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) || isset( $parts['user'], $parts['pass'] ) ) {
			throw new Paibao_AI_Operations_Error( '来源 URL 无效。' );
		}
		return $url;
	}

	private static function uuid( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( self::UUID, $value ) ) {
			throw new Paibao_AI_Operations_Error( 'UUID 无效。' );
		}
		return strtolower( $value );
	}

	private static function post_string( string $key ): string {
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
	}

	private static function notice_for_error( Throwable $error ): string {
		return $error instanceof Paibao_AI_Operations_Error && 'ai-operations-entitlement-required' === $error->control_code() ? 'entitlement' : 'pending';
	}

	private static function client(): Paibao_AI_Operations_Control_Client {
		if ( ! self::$client ) {
			throw new Paibao_AI_Operations_Error( '控制面客户端未初始化。' );
		}
		return self::$client;
	}

	private static function redirect( string $notice, string $job_id = '', string $request_id = '' ): never {
		$url = self::page_url( 1 === preg_match( self::UUID, $job_id ) ? $job_id : '' );
		$args = array( 'paibao_notice' => $notice );
		if ( 1 === preg_match( self::UUID, $request_id ) ) {
			$args['paibao_request_id'] = strtolower( $request_id );
		}
		wp_safe_redirect( add_query_arg( $args, $url ), 303 );
		exit;
	}

	private static function page_url( string $job_id = '' ): string {
		$url = admin_url( 'admin.php?page=' . self::MARKETPLACE_PAGE );
		return '' === $job_id ? $url : add_query_arg( 'job', $job_id, $url );
	}

	private static function display_time( int $milliseconds ): string {
		return wp_date( 'Y-m-d H:i:s', (int) floor( $milliseconds / 1000 ) );
	}

	private static function scalar_text( string|int|float|bool $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		return (string) $value;
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
