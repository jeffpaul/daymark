<?php
/**
 * Made-up friends for the wordpress.org Live Preview.
 *
 * A preview with nobody to follow shows half of Daymark, and a preview that
 * follows real sites depends on those sites answering and puts real people's
 * names in a public demo. So the Live Preview follows four people who do not
 * exist, on `.example` addresses that can never belong to anyone.
 *
 * Nothing here writes followed posts into the database by hand. This file
 * answers the requests WordPress would send to those four addresses (the
 * home page, the feed, and each post's own page) from the list below, and
 * Daymark then follows and reads them exactly as it does a real site. So
 * following, refreshing, and opening a post all work in the preview, with no
 * network and the same result every time.
 *
 * The wordpress.org blueprint (`.wordpress-org/blueprints/blueprint.json`)
 * copies this folder into `wp-content/daymark-demo/`, loads this file on
 * every request from a one-line must-use plugin, and calls
 * daymark_sample_friends_follow() once. Requests to any other address are
 * left alone.
 *
 * @package Daymark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The made-up friends, keyed by host, each with a few posts.
 *
 * A post's `age` is how long before the demo started it was posted, in
 * seconds, so the Timeline always reads as the last few days. The sample
 * Marks (`seed-sample-content.php`) fall half an hour apart over the four
 * hours before the demo started, so the newest ages here are set to land a
 * friend's photo first and the rest in among them. `images` are
 * names in `sample-images/`; one image makes a photo post, more make a
 * gallery, none makes a written post.
 *
 * @return array<string, array{name: string, about: string, icon: string, posts: array<int, array{slug: string, title: string, age: int, images: string[], html: string}>}>
 */
function daymark_sample_friends(): array {
	return array(
		'sarahchen.example'     => array(
			'name'  => 'Sarah Chen',
			'about' => 'Photos and notes from the lake country.',
			'icon'  => 'friend-sarah-chen.png',
			'posts' => array(
				array(
					'slug'   => 'fog-on-the-lake',
					'title'  => 'Fog on the lake',
					'age'    => 12 * MINUTE_IN_SECONDS,
					'images' => array( 'alpine-lake' ),
					'html'   => '<p>Got to the lake before the fog burned off. Worth the five o\'clock alarm.</p>',
				),
				array(
					'slug'   => 'a-year-on-my-own-site',
					'title'  => 'A year of posting to my own site',
					'age'    => 27 * HOUR_IN_SECONDS,
					'images' => array(),
					'html'   => '<p>A year ago I stopped posting my photos straight to social apps and started putting them on my own site first. I expected it to feel like homework. It turned out to be the opposite.</p>'
						. '<p>Everything I made this year is in one place, in order, with my name on the address. Copies still go out to the networks my family uses, and their replies come back here. Nothing got lost when one of those apps changed its mind about what it wanted to be.</p>'
						. '<p>If you have been thinking about doing the same, start small. One photo a day is plenty.</p>',
				),
			),
		),
		'anaruiz.example'       => array(
			'name'  => 'Ana Ruiz',
			'about' => 'Road trips, mostly.',
			'icon'  => 'friend-ana-ruiz.png',
			'posts' => array(
				array(
					'slug'   => 'weekend-in-the-desert',
					'title'  => 'Weekend in the desert',
					'age'    => 75 * MINUTE_IN_SECONDS,
					'images' => array( 'desert-dunes', 'sunrise-harbor', 'city-dusk' ),
					'html'   => '<p>Three days, two sunrises, and one very long drive home.</p>',
				),
				array(
					'slug'   => 'the-gate',
					'title'  => '',
					'age'    => 2 * DAY_IN_SECONDS + 3 * HOUR_IN_SECONDS,
					'images' => array(),
					'html'   => '<p>Finally fixed the squeaky gate. The whole street can sleep again.</p>',
				),
			),
		),
		'marcuswrites.example'  => array(
			'name'  => 'Marcus Webb',
			'about' => 'Writing about cooking, reading, and slow hobbies.',
			'icon'  => 'friend-marcus-webb.png',
			'posts' => array(
				array(
					'slug'   => 'a-year-of-sunday-bread',
					'title'  => 'What a year of Sunday bread taught me',
					'age'    => 160 * MINUTE_IN_SECONDS,
					'images' => array(),
					'html'   => '<p>I baked a loaf every Sunday for a year. Fifty-two loaves, of which maybe ten were good and three were great.</p>'
						. '<p>The lesson was not about flour. It was that doing a small thing on the same day every week takes the deciding out of it. By March I had stopped asking whether I felt like baking. It was Sunday, so there was dough.</p>'
						. '<p>Next year I am doing the same with soup.</p>',
				),
				array(
					'slug'   => 'autumn-reading-list',
					'title'  => 'My autumn reading list',
					'age'    => 3 * DAY_IN_SECONDS + 6 * HOUR_IN_SECONDS,
					'images' => array(),
					'html'   => '<p>Short days are for long books. Three I am taking into the cold months:</p>'
						. '<ul><li>A history of lighthouses and the people who kept them.</li><li>A novel my sister has been telling me to read for two years.</li><li>A cookbook, read front to back like a story.</li></ul>'
						. '<p>Tell me what is on yours.</p>',
				),
			),
		),
		'priyaoutdoors.example' => array(
			'name'  => 'Priya Nair',
			'about' => 'Trail notes and packing lists.',
			'icon'  => 'friend-priya-nair.png',
			'posts' => array(
				array(
					'slug'   => 'ridge-trail-at-dusk',
					'title'  => 'Ridge trail at dusk',
					'age'    => 5 * HOUR_IN_SECONDS,
					'images' => array( 'pine-forest' ),
					'html'   => '<p>Last light through the pines. I had the whole ridge to myself.</p>',
				),
				array(
					'slug'   => 'two-day-packing-list',
					'title'  => 'What goes in my bag for a two-day hike',
					'age'    => 4 * DAY_IN_SECONDS + 2 * HOUR_IN_SECONDS,
					'images' => array(),
					'html'   => '<p>People ask what I carry for one night out. Less every year, is the honest answer.</p>'
						. '<ul><li>A tent that weighs less than my water.</li><li>One warm layer more than the forecast says.</li><li>A paper map, because batteries are optimists.</li><li>Good coffee. This is not negotiable.</li></ul>'
						. '<p>Everything else is a want, not a need.</p>',
				),
			),
		),
	);
}

/**
 * When the demo started, so every post keeps the date it was first given.
 *
 * @return int Unix time.
 */
function daymark_sample_friends_start(): int {
	$start = (int) get_option( 'daymark_sample_friends_start', 0 );

	if ( $start <= 0 ) {
		$start = time();
		update_option( 'daymark_sample_friends_start', $start, false );
	}

	return $start;
}

/**
 * The address of a file in `sample-images/` on this site. The made-up
 * addresses serve nothing a browser can load, so pictures and avatars come
 * from this site instead.
 *
 * @param string $file File name, with its extension.
 * @return string URL.
 */
function daymark_sample_friends_image_url( string $file ): string {
	$dir     = wp_normalize_path( __DIR__ );
	$content = wp_normalize_path( WP_CONTENT_DIR );

	if ( ! str_starts_with( $dir, $content ) ) {
		return '';
	}

	return content_url( substr( $dir, strlen( $content ) ) . '/sample-images/' . $file );
}

/**
 * One post's content as its site would publish it: the pictures, then the
 * words.
 *
 * @param array{title: string, images: string[], html: string} $post A post from daymark_sample_friends().
 * @return string HTML.
 */
function daymark_sample_friends_post_html( array $post ): string {
	$html = '';

	foreach ( $post['images'] as $image ) {
		$html .= sprintf(
			'<figure><img src="%s" alt="%s" width="1200" height="800" /></figure>',
			esc_url( daymark_sample_friends_image_url( $image . '.jpg' ) ),
			esc_attr( ucfirst( str_replace( '-', ' ', $image ) ) )
		);
	}

	return $html . $post['html'];
}

/**
 * One post's words with the markup taken out, for the feed's summary. A
 * space goes in where a paragraph or list item ended, so sentences don't run
 * together.
 *
 * @param array{html: string} $post A post from daymark_sample_friends().
 * @return string Plain text.
 */
function daymark_sample_friends_post_text( array $post ): string {
	return trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( str_replace( '</', ' </', $post['html'] ) ) ) );
}

/**
 * The `<head>` every page of a made-up site shares: its name, its feed, and
 * its icon.
 *
 * @param string                            $host   The site's host.
 * @param array{name: string, icon: string} $friend The site's owner.
 * @param string                            $title  The page title.
 * @return string HTML.
 */
function daymark_sample_friends_head( string $host, array $friend, string $title ): string {
	return sprintf(
		'<meta charset="utf-8" /><title>%1$s</title>'
		. '<link rel="alternate" type="application/rss+xml" title="%2$s &raquo; Feed" href="https://%3$s/feed/" />'
		. '<link rel="icon" href="%4$s" />',
		esc_html( $title ),
		esc_attr( $friend['name'] ),
		esc_attr( $host ),
		esc_url( daymark_sample_friends_image_url( $friend['icon'] ) )
	);
}

/**
 * A made-up site's feed, as RSS.
 *
 * @param string                                                                      $host   The site's host.
 * @param array{name: string, about: string, posts: array<int, array<string, mixed>>} $friend The site's owner and posts.
 * @return string XML.
 */
function daymark_sample_friends_feed( string $host, array $friend ): string {
	$start = daymark_sample_friends_start();
	$items = '';

	foreach ( $friend['posts'] as $post ) {
		$media = '';

		foreach ( $post['images'] as $image ) {
			$media .= sprintf(
				'<media:content url="%s" medium="image" type="image/jpeg" width="1200" height="800" />',
				esc_url( daymark_sample_friends_image_url( $image . '.jpg' ) )
			);
		}

		$link   = 'https://' . $host . '/' . $post['slug'] . '/';
		$items .= sprintf(
			'<item>%1$s<link>%2$s</link><guid isPermaLink="true">%2$s</guid><pubDate>%3$s</pubDate>'
			. '<dc:creator><![CDATA[%4$s]]></dc:creator><description><![CDATA[%5$s]]></description>'
			. '<content:encoded><![CDATA[%6$s]]></content:encoded>%7$s</item>',
			'' !== $post['title'] ? '<title>' . esc_html( $post['title'] ) . '</title>' : '',
			esc_url( $link ),
			gmdate( 'D, d M Y H:i:s', $start - (int) $post['age'] ) . ' +0000',
			$friend['name'],
			daymark_sample_friends_post_text( $post ),
			daymark_sample_friends_post_html( $post ),
			$media
		);
	}

	return '<?xml version="1.0" encoding="UTF-8"?>'
		. '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:media="http://search.yahoo.com/mrss/">'
		. '<channel><title>' . esc_html( $friend['name'] ) . '</title><link>https://' . esc_html( $host ) . '/</link>'
		. '<description>' . esc_html( $friend['about'] ) . '</description><language>en</language>'
		. $items . '</channel></rss>';
}

/**
 * Answer a request to one of the made-up addresses.
 *
 * Hooked to `pre_http_request`, which lets a site answer one of its own
 * outgoing requests before anything is sent. Every other address gets the
 * value it came in with, so real requests go out as usual.
 *
 * @param false|array|WP_Error $preempt The answer so far (false for none).
 * @param array                $args    Request arguments (unused).
 * @param string               $url     Request URL.
 * @return false|array|WP_Error An answer for a made-up address, else `$preempt`.
 */
function daymark_sample_friends_answer( $preempt, $args, $url ) {
	unset( $args );

	$friends = daymark_sample_friends();
	$host    = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );

	if ( ! isset( $friends[ $host ] ) ) {
		return $preempt;
	}

	$friend = $friends[ $host ];
	$path   = trim( (string) wp_parse_url( (string) $url, PHP_URL_PATH ), '/' );
	$status = 200;
	$type   = 'text/html; charset=UTF-8';

	if ( '' === $path ) {
		$list = '';

		foreach ( $friend['posts'] as $post ) {
			$list .= sprintf(
				'<li><a href="https://%s/%s/">%s</a></li>',
				esc_attr( $host ),
				esc_attr( $post['slug'] ),
				esc_html( '' !== $post['title'] ? $post['title'] : daymark_sample_friends_post_text( $post ) )
			);
		}

		$body = '<!doctype html><html lang="en"><head>' . daymark_sample_friends_head( $host, $friend, $friend['name'] ) . '</head>'
			. '<body><main><h1>' . esc_html( $friend['name'] ) . '</h1><p>' . esc_html( $friend['about'] ) . '</p><ul>' . $list . '</ul></main></body></html>';
	} elseif ( 'feed' === $path ) {
		$type = 'application/rss+xml; charset=UTF-8';
		$body = daymark_sample_friends_feed( $host, $friend );
	} else {
		$body = '';

		foreach ( $friend['posts'] as $post ) {
			if ( $post['slug'] !== $path ) {
				continue;
			}

			$title = '' !== $post['title'] ? $post['title'] : $friend['name'];
			$body  = '<!doctype html><html lang="en"><head>' . daymark_sample_friends_head( $host, $friend, $title ) . '</head>'
				. '<body><main><article>'
				. ( '' !== $post['title'] ? '<h1>' . esc_html( $post['title'] ) . '</h1>' : '' )
				. '<div class="entry-content">' . daymark_sample_friends_post_html( $post ) . '</div>'
				. '</article></main></body></html>';
		}

		if ( '' === $body ) {
			$status = 404;
			$body   = '<!doctype html><html lang="en"><head><meta charset="utf-8" /><title>Not found</title></head><body><p>Not found.</p></body></html>';
		}
	}

	$headers = array( 'content-type' => $type );

	return array(
		'headers'  => class_exists( 'WpOrg\Requests\Utility\CaseInsensitiveDictionary' ) ? new WpOrg\Requests\Utility\CaseInsensitiveDictionary( $headers ) : $headers,
		'body'     => $body,
		'response' => array(
			'code'    => $status,
			'message' => 200 === $status ? 'OK' : 'Not Found',
		),
		'cookies'  => array(),
		'filename' => null,
	);
}
add_filter( 'pre_http_request', 'daymark_sample_friends_answer', 1, 3 );

/**
 * Follow each made-up friend and read their posts, the same two calls the
 * Follow a site sheet and the background check make. Safe to run again: a
 * friend already followed is skipped.
 *
 * @return int How many friends were followed this time.
 */
function daymark_sample_friends_follow(): int {
	if ( ! class_exists( 'Daymark_Plugin' ) ) {
		return 0;
	}

	$plugin   = Daymark_Plugin::instance();
	$followed = 0;

	// Set the demo's start time before the first feed is built.
	daymark_sample_friends_start();

	foreach ( array_keys( daymark_sample_friends() ) as $host ) {
		$subscription_id = $plugin->subscriptions->subscribe_to_site( 'https://' . $host . '/' );

		if ( is_wp_error( $subscription_id ) ) {
			continue;
		}

		$plugin->subscription_poller->poll_subscription( (int) $subscription_id );
		++$followed;
	}

	return $followed;
}
