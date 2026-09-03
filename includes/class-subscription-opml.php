<?php
/**
 * OPML import/export for subscriptions (issue #80).
 *
 * OPML is the de facto interchange format feed readers use to migrate
 * reading lists; this is also, per the issue's own motivation, Daymark's
 * only subscription-list backup mechanism (export, then re-import into
 * Daymark or elsewhere). Both directions share nothing with — and change
 * nothing about — the `daymark_subscription` table itself
 * (Daymark_Subscriptions): export() only ever reads via get_all(), and
 * import() only ever writes via the same create()/subscribe_to_site() paths
 * manual subscribe-by-URL already uses, so a bulk-imported row is
 * indistinguishable from one added by hand.
 *
 * Import is intentionally lenient at the entry level: one bad `<outline>`
 * reports its own 'failed' result rather than aborting the whole file (see
 * import()'s per-entry result contract) — this is what makes a large,
 * imperfect real-world export from another reader still mostly succeed.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and parses OPML documents for the Settings -> Daymark screen's
 * Export/Import actions and their REST equivalents.
 */
class Daymark_Subscription_OPML {

	/**
	 * Reserved XML namespace for Daymark-specific outline attributes (e.g. a
	 * future per-subscription interval override, per issue #80's AC #4) —
	 * declared on the root `<opml>` element so a Daymark-authored export
	 * stays valid, standard OPML for any other reader while still carrying
	 * its own extra data under a namespaced attribute a standard reader will
	 * simply ignore.
	 *
	 * @var string
	 */
	private const NAMESPACE_URI = 'https://github.com/jeffpaul/daymark';

	/**
	 * Default cap, in bytes, on an OPML upload accepted for import — applied
	 * by the caller (the REST route and the wp-admin import handler) BEFORE
	 * the file is read into memory or handed to import(), not by import()
	 * itself. A single shared constant (rather than the same literal
	 * duplicated in two files) so both surfaces enforce the exact same cap.
	 * Filterable via `daymark_subscription_opml_max_upload_bytes`.
	 *
	 * @var int
	 */
	public const MAX_UPLOAD_BYTES = 2 * MB_IN_BYTES;

	/**
	 * Build a standard OPML 2.0 document listing every subscription
	 * (Daymark_Subscriptions::get_all(), which — deliberately, per the
	 * issue's locked-in scoping decision #2 — includes a dead-flagged
	 * (`status` => 'error') subscription: an already-failing feed is still a
	 * follow worth backing up, and the dead-feed check re-runs naturally on
	 * the normal poll cycle for whatever re-imports it).
	 *
	 * Built via DOMDocument/DOMElement rather than string concatenation, so
	 * every attribute value (a site's own title, which may contain quotes,
	 * ampersands, or other characters an OPML reader must see correctly
	 * escaped) is escaped automatically by the DOM serializer instead of by
	 * hand.
	 *
	 * @return string A complete OPML XML document.
	 */
	public function export(): string {
		$dom               = new DOMDocument( '1.0', 'UTF-8' );
		$dom->formatOutput = true; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument's own native property name, not ours to rename.
		$opml              = $dom->createElement( 'opml' );
		$opml->setAttribute( 'version', '2.0' );
		// Declared on the root element (not per-outline) — the standard way
		// to reserve a namespace for attributes that may appear anywhere
		// below it. See NAMESPACE_URI's own docblock for why this exists.
		$opml->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:daymark', self::NAMESPACE_URI );
		$dom->appendChild( $opml );

		$head = $dom->createElement( 'head' );
		$opml->appendChild( $head );
		$head->appendChild( $dom->createElement( 'title', 'Daymark Subscriptions' ) );
		// RFC 822 date, the format OPML's own spec expects for dateCreated.
		$head->appendChild( $dom->createElement( 'dateCreated', gmdate( 'D, d M Y H:i:s \G\M\T' ) ) );

		$body = $dom->createElement( 'body' );
		$opml->appendChild( $body );

		foreach ( Daymark_Plugin::instance()->subscriptions->get_all() as $subscription ) {
			$body->appendChild( $this->build_outline( $dom, $subscription ) );
		}

		$xml = $dom->saveXML();

		return false !== $xml ? $xml : '';
	}

	/**
	 * Build one subscription's `<outline>` element.
	 *
	 * @param DOMDocument          $dom          Owning document (element
	 *                                           factory only — not
	 *                                           appended here).
	 * @param array<string, mixed> $subscription A `daymark_subscription` row.
	 * @return DOMElement
	 */
	private function build_outline( DOMDocument $dom, array $subscription ): DOMElement {
		$site_title = (string) ( $subscription['site_title'] ?? '' );
		$site_url   = (string) ( $subscription['site_url'] ?? '' );
		$feed_url   = (string) ( $subscription['feed_url'] ?? '' );
		$icon_url   = (string) ( $subscription['site_icon_url'] ?? '' );

		// text/title both carry the same value — a plain site name when
		// known, else the site URL itself so the row still has a usable
		// label in another reader.
		$label = '' !== $site_title ? $site_title : $site_url;

		$outline = $dom->createElement( 'outline' );
		$outline->setAttribute( 'type', 'rss' );
		$outline->setAttribute( 'text', $label );
		$outline->setAttribute( 'title', $label );
		$outline->setAttribute( 'xmlUrl', $feed_url );
		$outline->setAttribute( 'htmlUrl', $site_url );

		if ( '' !== $icon_url ) {
			$outline->setAttributeNS( self::NAMESPACE_URI, 'daymark:iconUrl', $icon_url );
		}

		return $outline;
	}

	/**
	 * Parse an OPML document and import each `<outline>` entry it contains,
	 * reporting a per-entry result rather than failing (or succeeding) the
	 * whole batch on one bad entry.
	 *
	 * XXE safety: `DOMDocument::loadXML()` is called with `LIBXML_NONET`
	 * (never resolve anything over the network while parsing) and
	 * `LIBXML_NOBLANKS`, and deliberately WITHOUT `LIBXML_NOENT` or any
	 * `LIBXML_DTDLOAD`/`LIBXML_DTDATTR` flag. On this plugin's PHP 8.2+/
	 * libxml 2.9+ baseline, libxml has not resolved external entities by
	 * default since 2.9 (2014) unless a caller explicitly opts back in via
	 * those flags — the same reasoning already documented for SimplePie's
	 * own XMLReader-based feed parsing (see
	 * Daymark_Subscription_Url_Guard's and
	 * Daymark_Subscription_Source_Feed's docblocks, and CLAUDE.md's
	 * "Feed/subscription fetch hardening" decision) — so a crafted
	 * `<!ENTITY xxe SYSTEM "file:///etc/passwd">` in an imported file is
	 * left un-substituted (or fails the parse outright) rather than leaking
	 * local file contents into an imported field.
	 *
	 * Outlines are matched at any depth (`//outline`, namespace-agnostic)
	 * since OPML supports nested folder/grouping outlines; an outline with
	 * neither `xmlUrl` nor `htmlUrl` is a pure folder and is skipped
	 * entirely (not counted in the returned results, and not counted
	 * against the entry cap below).
	 *
	 * @param string $xml Raw OPML file contents.
	 * @return array<int, array{label: string, status: string, message: string}>|WP_Error
	 *         Per-entry results (`status` one of 'subscribed', 'duplicate',
	 *         'failed'), or a WP_Error for a request-level failure (the file
	 *         itself could not be parsed as OPML, or carries more entries
	 *         than the configured cap — in which case nothing is imported).
	 */
	public function import( string $xml ) {
		if ( '' === trim( $xml ) ) {
			return $this->invalid_opml_error();
		}

		$previous_setting = libxml_use_internal_errors( true );
		libxml_clear_errors();

		$dom = new DOMDocument();

		// See this method's own docblock for why LIBXML_NOENT/DTD flags are
		// deliberately never passed here.
		$loaded = $dom->loadXML( $xml, LIBXML_NONET | LIBXML_NOBLANKS );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous_setting );

		// DOMDocument's own native property — not ours to rename to snake_case.
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$root_element = $dom->documentElement;

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMElement's own native property, not ours to rename.
		$root_node_name = $root_element instanceof DOMElement ? $root_element->nodeName : '';

		if (
			false === $loaded
			|| ! $root_element instanceof DOMElement
			|| 'opml' !== strtolower( $root_node_name )
		) {
			return $this->invalid_opml_error();
		}

		$xpath         = new DOMXPath( $dom );
		$outline_nodes = $xpath->query( '//outline' );

		if ( false === $outline_nodes ) {
			return $this->invalid_opml_error();
		}

		// Filter down to real entries (an xmlUrl or htmlUrl present) BEFORE
		// anything is imported, so the entry cap below can reject the whole
		// file up front rather than partially importing then rejecting.
		$entries = array();

		foreach ( $outline_nodes as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}

			$xml_url  = trim( $node->getAttribute( 'xmlUrl' ) );
			$html_url = trim( $node->getAttribute( 'htmlUrl' ) );

			if ( '' === $xml_url && '' === $html_url ) {
				continue; // A pure folder/grouping outline, not a subscription entry.
			}

			$entries[] = $node;
		}

		/**
		 * Filters the maximum number of `<outline>` entries an OPML import
		 * will accept in one request. A file over this cap is rejected
		 * outright (nothing imported) rather than partially processed.
		 *
		 * @since 0.10.0
		 *
		 * @param int $max_entries Defaults to 1000.
		 */
		$max_entries = max( 0, (int) apply_filters( 'daymark_subscription_opml_max_entries', 1000 ) );

		if ( count( $entries ) > $max_entries ) {
			return new WP_Error(
				'daymark_subscription_opml_too_many_entries',
				sprintf(
					/* translators: %d: maximum number of OPML entries allowed in one import. */
					__( 'This file has more subscriptions than can be imported at once (max %d). Split it into smaller files and try again.', 'daymark' ),
					$max_entries
				),
				array( 'status' => 400 )
			);
		}

		$results = array();

		foreach ( $entries as $node ) {
			$results[] = $this->import_entry( $node );
		}

		return $results;
	}

	/**
	 * The request-level "this isn't a valid OPML file" error, shared by
	 * every rejection path in import() that isn't the entry-count cap.
	 *
	 * @return WP_Error
	 */
	private function invalid_opml_error(): WP_Error {
		return new WP_Error(
			'daymark_subscription_opml_invalid',
			__( 'This file is not a valid OPML file.', 'daymark' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Import one `<outline>` entry: the `xmlUrl` fast path (create() only,
	 * no live fetch — the scoping decision behind this whole feature) when a
	 * feed URL is present, else full live discovery via
	 * Daymark_Subscriptions::subscribe_to_site() when only an `htmlUrl` is
	 * present.
	 *
	 * @param DOMElement $node One `<outline>` element.
	 * @return array{label: string, status: string, message: string}
	 */
	private function import_entry( DOMElement $node ): array {
		$xml_url  = trim( $node->getAttribute( 'xmlUrl' ) );
		$html_url = trim( $node->getAttribute( 'htmlUrl' ) );
		$text     = trim( $node->getAttribute( 'text' ) );
		$title    = trim( $node->getAttribute( 'title' ) );

		$label = '' !== $text ? $text : ( '' !== $title ? $title : ( '' !== $xml_url ? $xml_url : $html_url ) );
		$label = sanitize_text_field( $label );

		if ( '' !== $xml_url ) {
			return $this->import_via_xml_url( $node, $xml_url, $html_url, $label );
		}

		return $this->import_via_html_url( $html_url, $label );
	}

	/**
	 * The `xmlUrl` fast path: validate the feed URL, resolve/validate a
	 * best-effort `site_url` and `daymark:iconUrl`, then create the row
	 * directly via Daymark_Subscriptions::create() — no autodiscovery
	 * request is made for this entry, per the issue's own "OPML is a
	 * trusted interchange format" scoping decision. The feed URL itself is
	 * still run through Daymark_Subscription_Url_Guard::check() first: OPML
	 * being trusted as an interchange *format* does not make a URL inside
	 * an arbitrary uploaded file a trusted *target* to store and later
	 * fetch on a schedule.
	 *
	 * @param DOMElement $node     The `<outline>` element (for its
	 *                             `daymark:iconUrl` attribute).
	 * @param string     $xml_url  Raw `xmlUrl` attribute value.
	 * @param string     $html_url Raw `htmlUrl` attribute value, if any.
	 * @param string     $label    Display label for the result row.
	 * @return array{label: string, status: string, message: string}
	 */
	private function import_via_xml_url( DOMElement $node, string $xml_url, string $html_url, string $label ): array {
		$xml_url = esc_url_raw( $xml_url );
		$scheme  = strtolower( (string) wp_parse_url( $xml_url, PHP_URL_SCHEME ) );
		$host    = (string) wp_parse_url( $xml_url, PHP_URL_HOST );

		if ( '' === $xml_url || '' === $host || false !== strpos( $host, ' ' ) || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return array(
				'label'   => $label,
				'status'  => 'failed',
				'message' => __( 'This entry\'s feed URL is not valid.', 'daymark' ),
			);
		}

		if ( is_wp_error( Daymark_Subscription_Url_Guard::check( $xml_url ) ) ) {
			return array(
				'label'   => $label,
				'status'  => 'failed',
				'message' => __( 'This entry\'s feed URL could not be validated.', 'daymark' ),
			);
		}

		$site_url = $this->validate_site_url( $html_url );

		if ( '' === $site_url ) {
			// No usable htmlUrl: derive scheme://host from the feed URL
			// itself, same fallback shape subscribe_to_site() would produce
			// for a bare-domain entry.
			$site_url = $scheme . '://' . $host;
		}

		$created = Daymark_Plugin::instance()->subscriptions->create(
			array(
				'site_url'      => $site_url,
				'feed_url'      => $xml_url,
				'source_type'   => 'feed',
				'site_title'    => $label,
				'feed_title'    => $label,
				'site_icon_url' => $this->extract_icon_url( $node ),
			)
		);

		return $this->map_create_result( $created, $label );
	}

	/**
	 * The `htmlUrl`-only path: no `xmlUrl` was present, so fall back to the
	 * same live feed-autodiscovery flow manual subscribe-by-URL already
	 * uses (which performs its own Url_Guard check, discovery, and
	 * favicon/title lookup — none of that is duplicated here).
	 *
	 * @param string $html_url Raw `htmlUrl` attribute value.
	 * @param string $label    Display label for the result row.
	 * @return array{label: string, status: string, message: string}
	 */
	private function import_via_html_url( string $html_url, string $label ): array {
		if ( '' === $html_url ) {
			return array(
				'label'   => $label,
				'status'  => 'failed',
				'message' => __( 'This entry has no feed or site URL to import.', 'daymark' ),
			);
		}

		$result = Daymark_Plugin::instance()->subscriptions->subscribe_to_site( $html_url );

		return $this->map_create_result( $result, $label );
	}

	/**
	 * Validate a candidate `site_url` value (an outline's `htmlUrl`): must
	 * be a well-formed http(s) URL. Deliberately does not run it through
	 * Daymark_Subscription_Url_Guard::check() — unlike `xmlUrl` and
	 * `daymark:iconUrl`, this value is never itself fetched by the
	 * xmlUrl fast path (it is only stored for display and later manual
	 * refresh/unsubscribe use), so the SSRF guard's purpose does not apply
	 * to it the same way; a malformed value simply isn't used.
	 *
	 * @param string $html_url Raw `htmlUrl` attribute value.
	 * @return string The sanitized URL, or '' if not usable.
	 */
	private function validate_site_url( string $html_url ): string {
		if ( '' === $html_url ) {
			return '';
		}

		$html_url = esc_url_raw( $html_url );
		$scheme   = strtolower( (string) wp_parse_url( $html_url, PHP_URL_SCHEME ) );
		$host     = (string) wp_parse_url( $html_url, PHP_URL_HOST );

		if ( '' === $html_url || '' === $host || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}

		return $html_url;
	}

	/**
	 * Read and validate an outline's Daymark-specific icon URL: the
	 * namespaced `daymark:iconUrl` attribute this class's own export()
	 * writes, falling back to a plain, non-namespaced `iconUrl` attribute
	 * for interop with a hand-edited or non-namespace-aware file.
	 *
	 * Run through Daymark_Subscription_Url_Guard::check() before being
	 * accepted: unlike `site_url`, this value is a real fetch target — it
	 * is displayed as an `<img>` src on every Timeline/Settings render — so
	 * it gets the same SSRF hardening every other stored, fetchable
	 * subscription URL already gets.
	 *
	 * @param DOMElement $node The `<outline>` element.
	 * @return string The validated icon URL, or '' when absent or unsafe.
	 */
	private function extract_icon_url( DOMElement $node ): string {
		$icon_url = $node->getAttributeNS( self::NAMESPACE_URI, 'iconUrl' );

		if ( '' === $icon_url ) {
			$icon_url = $node->getAttribute( 'iconUrl' );
		}

		$icon_url = trim( $icon_url );

		if ( '' === $icon_url ) {
			return '';
		}

		$icon_url = esc_url_raw( $icon_url );
		$scheme   = strtolower( (string) wp_parse_url( $icon_url, PHP_URL_SCHEME ) );

		if ( '' === $icon_url || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}

		if ( is_wp_error( Daymark_Subscription_Url_Guard::check( $icon_url ) ) ) {
			return '';
		}

		return $icon_url;
	}

	/**
	 * Map a Daymark_Subscriptions::create()/subscribe_to_site() return value
	 * to this class's per-entry result shape.
	 *
	 * @param int|WP_Error $created The create()/subscribe_to_site() return value.
	 * @param string       $label   Display label for the result row.
	 * @return array{label: string, status: string, message: string}
	 */
	private function map_create_result( $created, string $label ): array {
		if ( is_wp_error( $created ) ) {
			return array(
				'label'   => $label,
				'status'  => 'daymark_subscription_duplicate' === $created->get_error_code() ? 'duplicate' : 'failed',
				'message' => $created->get_error_message(),
			);
		}

		return array(
			'label'   => $label,
			'status'  => 'subscribed',
			'message' => '',
		);
	}
}
