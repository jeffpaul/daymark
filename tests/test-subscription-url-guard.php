<?php
/**
 * Daymark_Subscription_Url_Guard tests (issue #81 — "Malformed and
 * malicious feed hardening", SSRF defense-in-depth).
 *
 * Every case that involves an IP *literal* in the URL (loopback, private,
 * link-local, ULA, CGNAT, IPv4-mapped) exercises the real check with no
 * mocking at all — a literal address never goes through DNS resolution.
 * The one "this normal URL is safe" case uses a hostname, so it mocks
 * `daymark_subscription_url_guard_resolved_addresses` to a canned public IP
 * rather than depending on a real (and possibly sandboxed-unavailable) DNS
 * lookup — the class docblock documents this filter for exactly this
 * purpose.
 *
 * @package Daymark
 */

/**
 * Tests Daymark_Subscription_Url_Guard::check().
 */
class Test_Subscription_Url_Guard extends WP_UnitTestCase {

	/** A normal, resolvable public-site URL is accepted. */
	public function test_accepts_normal_public_url() {
		add_filter(
			'daymark_subscription_url_guard_resolved_addresses',
			static function () {
				return array( '93.184.216.34' );
			}
		);

		$this->assertTrue( Daymark_Subscription_Url_Guard::check( 'https://example.com/feed/' ) );
	}

	/** A normal public URL on each of the three allowed ports is accepted. */
	public function test_accepts_allowed_ports() {
		foreach ( array( 80, 443, 8080 ) as $port ) {
			$this->assertTrue(
				Daymark_Subscription_Url_Guard::check( "http://93.184.216.34:{$port}/feed/" ),
				"Port {$port} should be allowed"
			);
		}
	}

	/** A non-standard port is rejected. */
	public function test_rejects_non_standard_port() {
		$result = Daymark_Subscription_Url_Guard::check( 'https://93.184.216.34:8443/feed/' );

		$this->assertWPError( $result );
		$this->assertSame( 'daymark_subscription_unsafe_url', $result->get_error_code() );
	}

	/** A URL carrying embedded userinfo (user:pass@host) is rejected. */
	public function test_rejects_embedded_userinfo() {
		$result = Daymark_Subscription_Url_Guard::check( 'https://user:pass@93.184.216.34/feed/' );

		$this->assertWPError( $result );
		$this->assertSame( 'daymark_subscription_unsafe_url', $result->get_error_code() );
	}

	/** IPv4 loopback is rejected. */
	public function test_rejects_ipv4_loopback() {
		$this->assertWPError( Daymark_Subscription_Url_Guard::check( 'http://127.0.0.1/feed/' ) );
	}

	/**
	 * Standard IPv4 private ranges are rejected — defense-in-depth on top of
	 * core's own wp_http_validate_url(), which already blocks these.
	 */
	public function test_rejects_ipv4_private_ranges() {
		foreach ( array( '10.1.2.3', '172.16.5.6', '192.168.1.1' ) as $address ) {
			$this->assertWPError(
				Daymark_Subscription_Url_Guard::check( "http://{$address}/feed/" ),
				"{$address} should be rejected"
			);
		}
	}

	/** IPv4 link-local is rejected. */
	public function test_rejects_ipv4_link_local() {
		$this->assertWPError( Daymark_Subscription_Url_Guard::check( 'http://169.254.1.1/feed/' ) );
	}

	/** The IPv4 CGNAT range (100.64.0.0/10) is rejected. */
	public function test_rejects_cgnat_range() {
		foreach ( array( '100.64.0.1', '100.100.0.1', '100.127.255.254' ) as $address ) {
			$this->assertWPError(
				Daymark_Subscription_Url_Guard::check( "http://{$address}/feed/" ),
				"{$address} should be rejected as CGNAT"
			);
		}

		// One address just outside the CGNAT range on each side is safe.
		$this->assertTrue( Daymark_Subscription_Url_Guard::check( 'http://100.63.255.255/feed/' ) );
		$this->assertTrue( Daymark_Subscription_Url_Guard::check( 'http://100.128.0.0/feed/' ) );
	}

	/** IPv6 loopback (::1) is rejected. */
	public function test_rejects_ipv6_loopback() {
		$this->assertWPError( Daymark_Subscription_Url_Guard::check( 'http://[::1]/feed/' ) );
	}

	/** IPv6 unique local addresses (fc00::/7) are rejected. */
	public function test_rejects_ipv6_unique_local() {
		$this->assertWPError( Daymark_Subscription_Url_Guard::check( 'http://[fc00::1]/feed/' ) );
		$this->assertWPError( Daymark_Subscription_Url_Guard::check( 'http://[fd12:3456:789a::1]/feed/' ) );
	}

	/** IPv6 link-local (fe80::/10) is rejected. */
	public function test_rejects_ipv6_link_local() {
		$this->assertWPError( Daymark_Subscription_Url_Guard::check( 'http://[fe80::1]/feed/' ) );
	}

	/**
	 * An IPv4-mapped IPv6 literal (e.g. ::ffff:127.0.0.1) is rejected —
	 * the "belt and suspenders" case explicitly named in issue #81.
	 */
	public function test_rejects_ipv4_mapped_ipv6_loopback() {
		$this->assertWPError( Daymark_Subscription_Url_Guard::check( 'http://[::ffff:127.0.0.1]/feed/' ) );
	}

	/** A malformed/unparsable URL is rejected rather than allowed through. */
	public function test_rejects_url_with_no_host() {
		$this->assertWPError( Daymark_Subscription_Url_Guard::check( 'not-a-url' ) );
	}

	/**
	 * A host that cannot be resolved at all is treated as safe to proceed —
	 * that is a plain connectivity failure the fetch itself will surface a
	 * moment later, not a private-network address.
	 */
	public function test_unresolvable_host_is_not_treated_as_unsafe() {
		add_filter(
			'daymark_subscription_url_guard_resolved_addresses',
			static function () {
				return array(); // Simulates "could not resolve".
			}
		);

		$this->assertTrue( Daymark_Subscription_Url_Guard::check( 'https://this-does-not-resolve.example.invalid/feed/' ) );
	}
}
