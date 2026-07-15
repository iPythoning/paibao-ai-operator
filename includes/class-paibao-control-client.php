<?php
/**
 * Paibao control-plane client.
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

final class Paibao_AI_Operations_Error extends RuntimeException {
	private int $status;
	private string $control_code;

	public function __construct( string $message, int $status = 0, string $control_code = '' ) {
		parent::__construct( $message );
		$this->status = $status;
		$this->control_code = $control_code;
	}

	public function status(): int {
		return $this->status;
	}

	public function control_code(): string {
		return $this->control_code;
	}
}

final class Paibao_AI_Operations_Credential_Provider {
	private static string $session_token = '';
	private static int $expires_at = 0;

	public function site_token(): string {
		$token = defined( 'PAIBAO_MARKETPLACE_SITE_TOKEN' ) ? (string) PAIBAO_MARKETPLACE_SITE_TOKEN : '';
		if ( 1 !== preg_match( '/^st_[A-Za-z0-9_-]{43}$/', $token ) ) {
			throw new Paibao_AI_Operations_Error( '站点尚未完成 Marketplace 安全绑定。' );
		}
		return $token;
	}

	public function session( callable $exchange, bool $force_refresh = false ): string {
		$now_ms = (int) floor( microtime( true ) * 1000 );
		if ( ! $force_refresh && self::$session_token && self::$expires_at - $now_ms > 60000 ) {
			return self::$session_token;
		}

		$data = $exchange( $this->site_token() );
		if (
			! is_array( $data ) ||
			! isset( $data['token'], $data['tokenType'], $data['scopes'], $data['expiresAt'] ) ||
			! is_string( $data['token'] ) ||
			1 !== preg_match( '/^aio_[A-Za-z0-9_-]{43}$/', $data['token'] ) ||
			'Bearer' !== $data['tokenType'] ||
			! is_array( $data['scopes'] ) ||
			array( 'ai-operations:read', 'ai-operations:request' ) !== array_values( $data['scopes'] ) ||
			! is_int( $data['expiresAt'] ) ||
			$data['expiresAt'] <= $now_ms + 60000 ||
			$data['expiresAt'] > $now_ms + 360000
		) {
			throw new Paibao_AI_Operations_Error( '控制面返回了无效的短时会话。' );
		}

		self::$session_token = $data['token'];
		self::$expires_at    = $data['expiresAt'];
		return self::$session_token;
	}

	public function forget_session(): void {
		self::$session_token = '';
		self::$expires_at    = 0;
	}
}

final class Paibao_AI_Operations_Control_Client {
	private const DEFAULT_ORIGIN = 'https://marketplace.paibao.ai';
	private const MAX_RESPONSE = 1000000;
	private Paibao_AI_Operations_Credential_Provider $credentials;

	public function __construct( ?Paibao_AI_Operations_Credential_Provider $credentials = null ) {
		$this->credentials = $credentials ?? new Paibao_AI_Operations_Credential_Provider();
	}

	public function overview(): array {
		return $this->ai_request( 'GET', '/api/site/ai-operations?limit=20&offset=0' );
	}

	public function is_configured(): bool {
		try {
			$this->credentials->site_token();
			$this->binding();
			return true;
		} catch ( Throwable ) {
			return false;
		}
	}

	public function job( string $job_id ): array {
		$this->assert_uuid( $job_id );
		return $this->ai_request( 'GET', '/api/site/ai-operations/jobs/' . strtolower( $job_id ) );
	}

	public function propose( string $goal, string $scope, string $idempotency_key ): array {
		return $this->ai_request(
			'POST',
			'/api/site/ai-operations/proposals',
			array( 'goal' => $goal, 'scope' => $scope ),
			$idempotency_key
		);
	}

	public function approve( string $job_id, string $idempotency_key ): array {
		$this->assert_uuid( $job_id );
		return $this->ai_request( 'POST', '/api/site/ai-operations/jobs/' . strtolower( $job_id ) . '/approve', array(), $idempotency_key );
	}

	public function rollback( string $job_id, string $idempotency_key ): array {
		$this->assert_uuid( $job_id );
		return $this->ai_request( 'POST', '/api/site/ai-operations/jobs/' . strtolower( $job_id ) . '/rollback', array(), $idempotency_key );
	}

	private function ai_request( string $method, string $path, ?array $body = null, string $idempotency_key = '' ): array {
		$token = $this->credentials->session(
			fn( string $site_token ): array => $this->transport( 'POST', '/api/site/ai-operations/session', array(), $site_token )
		);

		try {
			return $this->transport( $method, $path, $body, $token, $idempotency_key );
		} catch ( Paibao_AI_Operations_Error $error ) {
			$response_code = $error->status();
			if ( 401 !== $response_code ) {
				throw $error;
			}
			$this->credentials->forget_session();
			$force_refresh = true;
			$token = $this->credentials->session(
				fn( string $site_token ): array => $this->transport( 'POST', '/api/site/ai-operations/session', array(), $site_token ),
				$force_refresh
			);
			return $this->transport( $method, $path, $body, $token, $idempotency_key );
		}
	}

	private function transport( string $method, string $path, ?array $body, string $token, string $idempotency_key = '' ): array {
		$this->assert_route( $method, $path );
		$site = $this->binding();
		if ( 1 !== preg_match( '/^(?:st|aio)_[A-Za-z0-9_-]{43}$/', $token ) ) {
			throw new Paibao_AI_Operations_Error( '无效的服务端凭据。' );
		}

		$headers = array(
			'Accept'                => 'application/json',
			'Authorization'         => 'Bearer ' . $token,
			'x-paibao-site-id'      => $site['site_id'],
			'x-paibao-site-origin'  => $site['site_origin'],
			'x-paibao-platform'     => 'wordpress',
		);
		$args = array(
			'method'              => $method,
			'headers'             => $headers,
			'timeout'             => 15,
			'redirection'         => 0,
			'sslverify'           => true,
			'limit_response_size' => self::MAX_RESPONSE + 1,
		);
		if ( 'POST' === $method ) {
			$encoded = wp_json_encode( empty( $body ) ? new stdClass() : $body, JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $encoded ) || strlen( $encoded ) > 16384 ) {
				throw new Paibao_AI_Operations_Error( '控制面请求超出限制。' );
			}
			$args['body'] = $encoded;
			$args['headers']['Content-Type'] = 'application/json';
			if ( '/api/site/ai-operations/session' !== $path ) {
				$this->assert_idempotency( $idempotency_key );
				$args['headers']['Idempotency-Key'] = $idempotency_key;
			}
		}

		$response = wp_safe_remote_request( $this->origin() . $path, $args );
		if ( is_wp_error( $response ) ) {
			throw new Paibao_AI_Operations_Error( '控制面暂时无法连接，结果待确认。' );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		$content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		if ( strlen( $raw ) > self::MAX_RESPONSE ) {
			throw new Paibao_AI_Operations_Error( '控制面响应超出限制。', $status );
		}
		if ( ! str_starts_with( $content_type, 'application/json' ) ) {
			throw new Paibao_AI_Operations_Error( '控制面响应类型无效，结果待确认。', $status );
		}
		$payload = json_decode( $raw, true, 32 );
		if ( $status < 200 || $status >= 300 ) {
			$code = is_array( $payload ) && is_string( $payload['code'] ?? null ) && 1 === preg_match( '/^[a-z0-9-]{1,80}$/', $payload['code'] ) ? $payload['code'] : '';
			$message = 'ai-operations-entitlement-required' === $code ? '需要购买或续费 AI 运营官。' : '控制面拒绝了请求，结果待确认。';
			throw new Paibao_AI_Operations_Error( $message, $status, $code );
		}
		if ( ! is_array( $payload ) || true !== ( $payload['ok'] ?? false ) || ! isset( $payload['data'] ) || ! is_array( $payload['data'] ) ) {
			throw new Paibao_AI_Operations_Error( '控制面响应格式无效，结果待确认。', $status );
		}
		return $payload['data'];
	}

	private function binding(): array {
		$site_id = defined( 'PAIBAO_AI_OPERATIONS_SITE_ID' ) ? strtolower( (string) PAIBAO_AI_OPERATIONS_SITE_ID ) : '';
		$this->assert_uuid( $site_id );
		$origin = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $origin ) || 'https' !== ( $origin['scheme'] ?? '' ) || empty( $origin['host'] ) || isset( $origin['user'], $origin['pass'], $origin['query'], $origin['fragment'] ) ) {
			throw new Paibao_AI_Operations_Error( '站点必须使用规范 HTTPS 域名。' );
		}
		$port = isset( $origin['port'] ) ? ':' . (int) $origin['port'] : '';
		return array( 'site_id' => $site_id, 'site_origin' => 'https://' . strtolower( $origin['host'] ) . $port );
	}

	private function origin(): string {
		return self::DEFAULT_ORIGIN;
	}

	private function assert_route( string $method, string $path ): void {
		$allowed =
			( 'POST' === $method && '/api/site/ai-operations/session' === $path ) ||
			( 'GET' === $method && '/api/site/ai-operations?limit=20&offset=0' === $path ) ||
			( 'POST' === $method && '/api/site/ai-operations/proposals' === $path ) ||
			( 'GET' === $method && 1 === preg_match( '#^/api/site/ai-operations/jobs/[0-9a-f-]{36}$#', $path ) ) ||
			( 'POST' === $method && 1 === preg_match( '#^/api/site/ai-operations/jobs/[0-9a-f-]{36}/(?:approve|rollback)$#', $path ) );
		if ( ! $allowed ) {
			throw new Paibao_AI_Operations_Error( '请求路由未获准。' );
		}
	}

	private function assert_idempotency( string $key ): void {
		if ( 1 !== preg_match( '/^[A-Za-z0-9._:-]{16,128}$/', $key ) ) {
			throw new Paibao_AI_Operations_Error( '幂等键无效。' );
		}
	}

	private function assert_uuid( string $value ): void {
		if ( 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value ) ) {
			throw new Paibao_AI_Operations_Error( '站点或任务标识无效。' );
		}
	}
}
