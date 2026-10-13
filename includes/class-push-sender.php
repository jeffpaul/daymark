<?php
/**
 * Sends web push messages with the bundled web-push-php library.
 *
 * The library encrypts each message for one device and signs the request
 * with the site's VAPID key. Daymark hands it Daymark_Push_Http_Client, so
 * every request goes through WordPress's HTTP API and Daymark's outbound
 * guard, and only to an allowed push service host. The library is loaded
 * only when a push is actually sent or keys are made, never on an ordinary
 * page load.
 *
 * @package Daymark
 */

defined( 'ABSPATH' ) || exit;

/**
 * Push delivery.
 */
class Daymark_Push_Sender {

	/**
	 * Load the bundled library and Daymark's PSR-18 client for it.
	 *
	 * @return bool Whether the library is available.
	 */
	public static function load_library(): bool {
		static $loaded = null;

		if ( null !== $loaded ) {
			return $loaded;
		}

		$autoload = DAYMARK_PLUGIN_DIR . 'lib/vendor-prefixed/autoload.php';

		if ( ! is_readable( $autoload ) ) {
			$loaded = false;
			return false;
		}

		try {
			require_once $autoload;
			require_once DAYMARK_PLUGIN_DIR . 'includes/class-push-http-exception.php';
			require_once DAYMARK_PLUGIN_DIR . 'includes/class-push-http-client.php';
			$loaded = class_exists( '\Daymark\Vendor\Minishlink\WebPush\WebPush' );
		} catch ( \Throwable $e ) {
			$loaded = false;
		}

		return $loaded;
	}

	/**
	 * The VAPID subject: the site's address, as the push services require a
	 * URL or mailto: address to identify the sender.
	 *
	 * @return string
	 */
	public static function subject(): string {
		/**
		 * Filter the VAPID subject sent to push services. Must be an https
		 * URL or a mailto: address.
		 *
		 * @param string $subject Default: the site's home URL.
		 */
		return (string) apply_filters( 'daymark_push_vapid_subject', home_url( '/' ) );
	}

	/**
	 * A short Topic header value for a message tag, so a push service
	 * replaces an undelivered message with a newer one for the same thing.
	 * Push services allow at most 32 URL-safe Base64 characters.
	 *
	 * @param string $tag Message tag.
	 * @return string
	 */
	public static function topic( string $tag ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Building a Topic header value, not obfuscating code.
		return substr( rtrim( strtr( base64_encode( hash( 'sha256', $tag, true ) ), '+/', '-_' ), '=' ), 0, 32 );
	}

	/**
	 * Send one message to every device a user registered.
	 *
	 * A device whose push service answers 404 or 410 has unsubscribed or
	 * been reset, so it's removed.
	 *
	 * @param int                  $user_id User ID.
	 * @param array<string, mixed> $message title, body, url, tag.
	 * @return array{sent: int, failed: int, removed: int}
	 */
	public static function send_to_user( int $user_id, array $message ): array {
		$result = array(
			'sent'    => 0,
			'failed'  => 0,
			'removed' => 0,
		);

		$subscriptions = Daymark_Push::get_subscriptions( $user_id );

		if ( empty( $subscriptions ) || ! self::load_library() ) {
			return $result;
		}

		$keys = Daymark_Push::keys();

		if ( ! $keys ) {
			return $result;
		}

		$message = Daymark_Push::sanitize_message( $message );
		$payload = (string) wp_json_encode(
			array(
				'title' => '' !== $message['title'] ? $message['title'] : 'Daymark',
				'body'  => $message['body'],
				'url'   => $message['url'],
				'tag'   => $message['tag'],
				'icon'  => Daymark_Routes::daymark_icon_url( 192 ),
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		try {
			$factory = new \Daymark\Vendor\Nyholm\Psr7\Factory\Psr17Factory();
			$webpush = new \Daymark\Vendor\Minishlink\WebPush\WebPush(
				array(
					'VAPID' => array(
						'subject'    => self::subject(),
						'publicKey'  => $keys['publicKey'],
						'privateKey' => $keys['privateKey'],
					),
				),
				array(
					/**
					 * Filter how long a push service keeps an undelivered
					 * message, in seconds. Apple keeps one at most 30 days.
					 *
					 * @param int $ttl Default one day.
					 */
					'TTL'     => max( 0, (int) apply_filters( 'daymark_push_ttl', DAY_IN_SECONDS ) ),
					'urgency' => 'normal',
				),
				new Daymark_Push_Http_Client( $factory ),
				$factory,
				$factory
			);
			$webpush->setReuseVAPIDHeaders( true );

			$options = '' !== $message['tag'] ? array( 'topic' => self::topic( $message['tag'] ) ) : array();

			foreach ( $subscriptions as $sub ) {
				$webpush->queueNotification(
					\Daymark\Vendor\Minishlink\WebPush\Subscription::create(
						array(
							'endpoint'        => (string) $sub['endpoint'],
							'publicKey'       => (string) $sub['p256dh'],
							'authToken'       => (string) $sub['auth'],
							'contentEncoding' => 'aes128gcm',
						)
					),
					$payload,
					$options
				);
			}

			foreach ( $webpush->flush() as $report ) {
				if ( $report->isSuccess() ) {
					++$result['sent'];
					continue;
				}

				++$result['failed'];

				if ( $report->isSubscriptionExpired() ) {
					Daymark_Push::delete_subscription( $user_id, (string) $report->getEndpoint() );
					++$result['removed'];
				}
			}
		} catch ( \Throwable $e ) {
			// A push never breaks the request or cron run that triggered it.
			$result['failed'] = max( $result['failed'], count( $subscriptions ) - $result['sent'] );
		}

		/**
		 * Fires after a push was sent to a user's devices.
		 *
		 * @param int                   $user_id User ID.
		 * @param array<string, string> $message The message.
		 * @param array<string, int>    $result  sent, failed, removed.
		 */
		do_action( 'daymark_push_sent', $user_id, $message, $result );

		return $result;
	}
}
