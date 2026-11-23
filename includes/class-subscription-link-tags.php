<?php
/**
 * Reads the `<link>` tags out of a followed site's page.
 *
 * Every built-in subscription source looks for a different `<link>` when a
 * site is followed: Daymark_Subscription_Source_Feed for a feed
 * (`rel="alternate"`), an icon and the site's other languages,
 * Daymark_Subscription_Source_WordPress for the REST API root, and
 * Daymark_Subscription_Source_Microformats for a JSON feed. They all read
 * the tags here, through WordPress core's WP_HTML_Tag_Processor, so the
 * page is read by HTML's own rules: attribute values with no quotes around
 * them count (`<link rel=alternate href=/feed>`, common on minified
 * pages), and a tag inside a comment or a script does not.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds `<link>` tags and their attributes in an HTML fragment.
 */
class Daymark_Subscription_Link_Tags {

	/**
	 * Every `<link>` tag in the HTML, as its attributes.
	 *
	 * @param string $html HTML to read: a whole page, or its `<head>` alone.
	 * @return array<int, array<string, string>> One entry per tag, in
	 *                                           document order: attribute
	 *                                           name (lowercase) => decoded
	 *                                           value. An attribute written
	 *                                           with no value is left out.
	 */
	public static function read( string $html ): array {
		$links = array();

		if ( '' === $html ) {
			return $links;
		}

		$processor = new WP_HTML_Tag_Processor( $html );

		while ( $processor->next_tag( 'link' ) ) {
			$attrs = array();

			foreach ( (array) $processor->get_attribute_names_with_prefix( '' ) as $name ) {
				$value = $processor->get_attribute( $name );

				if ( is_string( $value ) ) {
					$attrs[ $name ] = $value;
				}
			}

			$links[] = $attrs;
		}

		return $links;
	}
}
