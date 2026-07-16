<?php

namespace DSA\Secure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SecureTrack_Ip_Service {
	public static function get_ip(): string {
		$details = self::resolution_details();
		return (string) $details['resolved'];
	}

	public static function resolution_details(): array {
		$remote = self::normalize_ip( $_SERVER['REMOTE_ADDR'] ?? '' );
		$forwarded_present = '' !== trim( (string) ( $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '' ) )
			|| '' !== trim( (string) ( $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '' ) )
			|| '' !== trim( (string) ( $_SERVER['HTTP_X_REAL_IP'] ?? '' ) );
		$details = [
			'remote'            => $remote ?: '127.0.0.1',
			'resolved'          => $remote ?: '127.0.0.1',
			'source'            => 'remote_addr',
			'remote_trusted'    => false,
			'forwarded_present' => $forwarded_present,
			'forwarded_ignored' => false,
		];
		if ( $remote && self::ip_in_cidrs( $remote, self::cloudflare_cidrs() ) ) {
			$details['remote_trusted'] = true;
			$cf = self::normalize_ip( $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '' );
			if ( $cf ) {
				$details['resolved'] = $cf;
				$details['source'] = 'cf_connecting_ip';
				return $details;
			}
		}

		$trusted_proxies = self::trusted_proxy_cidrs();
		if ( $remote && self::ip_in_cidrs( $remote, $trusted_proxies ) ) {
			$details['remote_trusted'] = true;
			$xff = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '' ) );
			if ( $xff !== '' ) {
				$parts = array_reverse( array_map( 'trim', explode( ',', $xff ) ) );
				foreach ( $parts as $part ) {
					$ip = self::normalize_ip( $part );
					if ( $ip && ! self::ip_in_cidrs( $ip, $trusted_proxies ) && ! self::ip_in_cidrs( $ip, self::cloudflare_cidrs() ) ) {
						$details['resolved'] = $ip;
						$details['source'] = 'x_forwarded_for';
						return $details;
					}
				}
			}

			$real = self::normalize_ip( $_SERVER['HTTP_X_REAL_IP'] ?? '' );
			if ( $real ) {
				$details['resolved'] = $real;
				$details['source'] = 'x_real_ip';
				return $details;
			}
		}

		$details['forwarded_ignored'] = $forwarded_present && ! $details['remote_trusted'];
		return $details;
	}

	public static function normalize_ip( $ip ): string {
		$ip = trim( sanitize_text_field( wp_unslash( (string) $ip ) ) );
		if ( strpos( $ip, ':' ) !== false && preg_match( '/^\[?([0-9a-fA-F:.]+)\]?(?::\d+)?$/', $ip, $m ) ) {
			$ip = $m[1];
		}
		if ( strpos( $ip, ':' ) === false && preg_match( '/^(\d{1,3}(?:\.\d{1,3}){3})(?::\d+)?$/', $ip, $m ) ) {
			$ip = $m[1];
		}
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}
		$packed = @inet_pton( $ip );
		if ( $packed === false ) {
			return $ip;
		}
		$normalized = @inet_ntop( $packed );

		return $normalized ?: $ip;
	}

	public static function ip_in_cidrs( $ip, $cidrs ): bool {
		$ip = self::normalize_ip( $ip );
		if ( ! $ip ) {
			return false;
		}
		$ip_bin = @inet_pton( $ip );
		if ( $ip_bin === false ) {
			return false;
		}

		foreach ( (array) $cidrs as $cidr ) {
			$cidr = trim( (string) $cidr );
			if ( $cidr === '' ) {
				continue;
			}
			if ( strpos( $cidr, '/' ) === false ) {
				$cidr .= filter_var( $cidr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? '/128' : '/32';
			}

			list( $net, $bits ) = explode( '/', $cidr, 2 );
			$net_bin = @inet_pton( self::normalize_ip( $net ) );
			if ( $net_bin === false || strlen( $net_bin ) !== strlen( $ip_bin ) ) {
				continue;
			}

			$bits = max( 0, min( strlen( $ip_bin ) * 8, (int) $bits ) );
			$bytes = intdiv( $bits, 8 );
			$rem = $bits % 8;

			if ( $bytes && substr( $ip_bin, 0, $bytes ) !== substr( $net_bin, 0, $bytes ) ) {
				continue;
			}
			if ( $rem ) {
				$mask = ( 0xff << ( 8 - $rem ) ) & 0xff;
				if ( ( ord( $ip_bin[ $bytes ] ) & $mask ) !== ( ord( $net_bin[ $bytes ] ) & $mask ) ) {
					continue;
				}
			}

			return true;
		}

		return false;
	}

	public static function cloudflare_cidrs(): array {
		return [
			'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
			'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
		];
	}

	public static function trusted_proxy_cidrs(): array {
		$cidrs = self::cloudflare_cidrs();
		if ( defined( 'DSA_OPTION_SETTINGS' ) ) {
			$settings = get_option( DSA_OPTION_SETTINGS, [] );
			$raw = is_array( $settings ) ? (string) ( $settings['secure']['trusted_proxy_cidrs'] ?? '' ) : '';
			foreach ( preg_split( '/[\r\n,]+/', $raw ) ?: [] as $cidr ) {
				$cidr = trim( (string) $cidr );
				if ( '' !== $cidr ) $cidrs[] = $cidr;
			}
		}
		return array_values( array_unique( array_filter( (array) apply_filters( 'stp_trusted_proxy_cidrs', $cidrs ) ) ) );
	}

	public static function blank_ip_row( $ip_str ): object {
		return (object) [
			'id'            => 0,
			'ip_address'    => self::normalize_ip( $ip_str ),
			'status'        => 'unknown',
			'risk_score'    => 0,
			'is_proxy'      => 0,
			'is_hosting'    => 0,
			'failed_logins' => 0,
			'country'       => null,
			'city'          => null,
			'country_code'  => null,
			'geo_fetched'   => 0,
			'total_hits'    => 0,
			'blocked_at'    => null,
		];
	}

	public static function get_ip_row( $ip_str ): object {
		global $wpdb;

		$ip_str = self::normalize_ip( $ip_str );
		if ( ! $ip_str || ! stp_table_exists( 'ips' ) ) {
			return self::blank_ip_row( $ip_str );
		}

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . stp_t( 'ips' ) . ' WHERE ip_address=%s', $ip_str ) );

		return $row ?: self::blank_ip_row( $ip_str );
	}

	public static function upsert_ip( $ip_str, bool $increment_hits = true ): object {
		global $wpdb;

		$ip_str = substr( self::normalize_ip( $ip_str ), 0, 45 );
		if ( ! $ip_str ) {
			return self::blank_ip_row( $ip_str );
		}

		$now = current_time( 'mysql' );
		if ( $increment_hits ) {
			$wpdb->query(
				$wpdb->prepare(
					'INSERT INTO ' . stp_t( 'ips' ) . ' (ip_address,first_seen,last_seen,total_hits)
					 VALUES (%s,%s,%s,1)
					 ON DUPLICATE KEY UPDATE last_seen=%s, total_hits=total_hits+1',
					$ip_str,
					$now,
					$now,
					$now
				)
			);
		} else {
			$wpdb->query(
				$wpdb->prepare(
					'INSERT INTO ' . stp_t( 'ips' ) . ' (ip_address,first_seen,last_seen,total_hits)
					 VALUES (%s,%s,%s,0)
					 ON DUPLICATE KEY UPDATE last_seen=last_seen',
					$ip_str,
					$now,
					$now
				)
			);
		}

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . stp_t( 'ips' ) . ' WHERE ip_address=%s', $ip_str ) );

		return $row ?: self::blank_ip_row( $ip_str );
	}

	public static function status_is_trusted( $ip_or_row ): bool {
		global $wpdb;

		if ( is_object( $ip_or_row ) ) {
			return ( $ip_or_row->status ?? '' ) === 'trusted';
		}

		$ip = self::normalize_ip( $ip_or_row );
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) || ! stp_table_exists( 'ips' ) ) {
			return false;
		}

		return 'trusted' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . stp_t( 'ips' ) . ' WHERE ip_address=%s', $ip ) );
	}

	public static function block_decision( $ip = null ): array {
		global $wpdb;

		if ( ! stp_tables_ready() ) {
			return [ 'blocked' => false ];
		}

		static $cache = [];
		$ip = self::normalize_ip( $ip ?: self::get_ip() );
		if ( isset( $cache[ $ip ] ) ) {
			return $cache[ $ip ];
		}

		$ip_row = $wpdb->get_row( $wpdb->prepare( 'SELECT status,country_code FROM ' . stp_t( 'ips' ) . ' WHERE ip_address=%s', $ip ) );
		$subnet = stp_subnet24_cidr( $ip );
		$out = [
			'blocked'      => false,
			'source'       => '',
			'ip'           => $ip,
			'subnet'       => $subnet,
			'country_code' => $ip_row->country_code ?? '',
			'ip_status'    => $ip_row->status ?? '',
		];

		if ( $ip_row && $ip_row->status === 'blocked' ) {
			$out['blocked'] = true;
			$out['source'] = 'ip_block';
			return $cache[ $ip ] = $out;
		}

		if ( $ip_row && ! empty( $ip_row->country_code ) ) {
			$blocked_countries = (array) ( stp_cfg()['country_blocklist_codes'] ?? [] );
			if ( in_array( strtoupper( $ip_row->country_code ), $blocked_countries, true ) ) {
				$out['blocked'] = true;
				$out['source'] = 'country_blocklist';
				return $cache[ $ip ] = $out;
			}
		}

		if ( $subnet ) {
			$is_banned = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . stp_t( 'subnets' ) . ' WHERE subnet=%s AND is_banned=1', $subnet ) );
			if ( $is_banned ) {
				$out['blocked'] = true;
				$out['source'] = 'subnet_ban';
				return $cache[ $ip ] = $out;
			}
		}

		return $cache[ $ip ] = $out;
	}

	public static function deny_blocked_request( $decision, string $stage = 'block_gate' ): void {
		$ctx = stp_blocked_request_context();
		$response = '403 Access Denied: Your IP has been blocked by this site security policy.';

		if ( stp_enforcement_paused() ) {
			stp_log(
				'protection_block',
				[
					'sub'   => 'monitor_only_' . sanitize_key( $decision['source'] ?? 'ip_block' ),
					'url'   => $ctx['url'],
					'extra' => [
						'block_source'   => $decision['source'] ?? 'ip_block',
						'stage'          => $stage,
						'monitor_only'   => true,
						'response_code'  => 200,
						'response_shown' => 'Emergency safe mode allowed a request that SecureTrack would have denied.',
					],
				]
			);
			return;
		}

		stp_log(
			'protection_block',
			[
				'sub'   => sanitize_key( $decision['source'] ?? 'ip_block' ),
				'url'   => $ctx['url'],
				'extra' => [
					'block_source'    => $decision['source'] ?? 'ip_block',
					'stage'           => $stage,
					'subnet'          => $decision['subnet'] ?? '',
					'country_code'    => $decision['country_code'] ?? '',
					'method'          => sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ),
					'user_agent'      => substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 300 ),
					'url_flags'       => $ctx['url_flags'],
					'payload_score'   => $ctx['payload_score'],
					'payload_flags'   => $ctx['payload_flags'],
					'payload_sample'  => $ctx['payload_sample'],
					'would_waf_block' => $ctx['would_waf_block'],
					'response_code'   => 403,
					'response_shown'  => $response,
				],
			]
		);

		status_header( 403 );
		wp_die( '<h1>Access Denied</h1><p>Your IP has been blocked by this site security policy.</p>', 'Access Denied', [ 'response' => 403 ] );
	}
}
