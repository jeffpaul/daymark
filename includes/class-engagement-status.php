<?php
/**
 * Why Like or Comment can't reach a followed post's site, and what would
 * fix it (issue #561).
 *
 * @package Daymark
 * @since   0.22.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Like and Comment availability for one followed post, with a short note
 * for the full post view when either can't reach the post's site.
 *
 * The routes themselves are unchanged: Daymark_Like_Delivery decides
 * whether a Like can be delivered and Daymark_Comment_Delivery where a
 * comment goes. This class looks the post up once through the same cached
 * discovery, then works out which missing piece is responsible. explain()
 * is a pure function of those facts, so every case can be tested without
 * the plugins it talks about.
 *
 * A note links to the fix only for someone allowed to make it (the
 * Connectors tab for a plugin or Bridgy Fed, Jetpack's own screen to link
 * a WordPress.com account, ActivityPub's settings or the Users screen);
 * anyone else gets the same explanation without the link.
 */
class Daymark_Engagement_Status {

	/**
	 * Whether for_post() would have to look something up (the post's page,
	 * or its ActivityPub object), which is when the REST route spends a
	 * rate-limit allowance. A cached answer costs nothing, and so does an
	 * address Daymark won't fetch, since nothing is looked up for it.
	 *
	 * @param int $subscription_post_id A `daymark_sub_post` post ID.
	 * @return bool
	 */
	public static function needs_lookup( int $subscription_post_id ): bool {
		$permalink = self::permalink( $subscription_post_id );

		if ( '' === $permalink ) {
			return false;
		}

		if ( null === Daymark_Comment_Delivery::cached_origin_signals( $permalink ) ) {
			return Daymark_Comment_Delivery::can_look_up( $permalink );
		}

		return Daymark_ActivityPub_Engagement::available_for_user()
			&& null === Daymark_ActivityPub_Engagement::cached_target( $permalink );
	}

	/**
	 * Like and Comment availability for a followed post, and a note when
	 * either can't reach its site. Looks the post up (one cached page
	 * fetch, plus an ActivityPub lookup when that route is set up) when it
	 * isn't cached yet.
	 *
	 * @param int $subscription_post_id A `daymark_sub_post` post ID.
	 * @return array{like: array{available: bool, method: string}, comment: array{method: string, url: string}, note: array{reason: string, sentences: string[], link: array{url: string, label: string}|null}|null}
	 */
	public static function for_post( int $subscription_post_id ): array {
		$permalink = self::permalink( $subscription_post_id );
		$signals   = Daymark_Comment_Delivery::origin_signals_for_post( $subscription_post_id );
		$usable    = ! is_wp_error( $signals );
		$signals   = $usable ? $signals : array();

		$activitypub = $usable
			&& Daymark_ActivityPub_Engagement::available_for_user()
			&& null !== Daymark_ActivityPub_Engagement::resolve_target( $permalink );

		$like    = Daymark_Like_Delivery::evaluate( $signals, $activitypub );
		$comment = $usable
			? Daymark_Comment_Delivery::target_for_signals( $permalink, $signals )
			: array(
				'method' => 'redirect',
				'url'    => '',
			);

		$facts = array(
			'like_available'           => (bool) $like['available'],
			'liked'                    => self::liked( $subscription_post_id, $permalink ),
			'comment_method'           => (string) $comment['method'],
			'comment_url'              => (string) ( $comment['url'] ?? '' ),
			// Entries cached before `reached` existed don't carry it.
			'reached'                  => $usable && false !== ( $signals['reached'] ?? true ),
			'webmention_endpoint'      => '' !== (string) ( $signals['webmention_endpoint'] ?? '' ),
			'jetpack_origin'           => (int) ( $signals['jetpack_site_id'] ?? 0 ) > 0 && (int) ( $signals['jetpack_post_id'] ?? 0 ) > 0,
			'fediverse'                => ! empty( $signals['activitypub_object'] ),
			'bluesky'                  => ! empty( $signals['bluesky'] ),
			'can_send'                 => Daymark_Webmention::can_send(),
			'jetpack_user'             => Daymark_Jetpack_Engagement::current_user_connected(),
			'activitypub'              => self::activitypub_state(),
			'activitypub_profiles_off' => self::activitypub_profiles_off(),
			'activitypub_user'         => Daymark_ActivityPub_Engagement::available_for_user(),
			'bridged'                  => Daymark_Bridgy_Fed::is_bridged(),
			'can'                      => array(
				'connectors'     => current_user_can( Daymark_Admin_Subscriptions::CAPABILITY ),
				'plugins'        => current_user_can( 'activate_plugins' ),
				'update_plugins' => current_user_can( 'update_plugins' ),
				'settings'       => current_user_can( 'manage_options' ),
				'users'          => current_user_can( 'edit_users' ),
				'jetpack'        => current_user_can( 'jetpack_connect_user' ), // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Jetpack's own capability for linking a WordPress.com account.
			),
		);

		return array(
			'like'    => array(
				'available' => (bool) $like['available'],
				'method'    => (string) $like['method'],
			),
			'comment' => array(
				'method' => (string) $comment['method'],
				'url'    => (string) ( $comment['url'] ?? '' ),
			),
			'note'    => self::explain( $facts ),
		);
	}

	/**
	 * The note for a set of facts about a followed post, or null when Like
	 * and Comment both reach its site (or there's nothing to explain).
	 *
	 * Facts (all optional; a missing one counts as false or empty):
	 * like_available, liked, comment_method, comment_url, reached,
	 * webmention_endpoint, jetpack_origin, fediverse, bluesky, can_send,
	 * jetpack_user, activitypub ('active', 'missing', 'outdated', or
	 * 'incompatible'), activitypub_profiles_off, activitypub_user, bridged,
	 * and can (connectors, plugins, update_plugins, settings, users,
	 * jetpack: whether the viewer may open each fix).
	 *
	 * An already-liked post gets no Like explanation: its Like stays so it
	 * can be undone.
	 *
	 * @param array<string, mixed> $facts Facts about the post, its site, and this site.
	 * @return array{reason: string, sentences: string[], link: array{url: string, label: string}|null}|null
	 */
	public static function explain( array $facts ): ?array {
		$can               = is_array( $facts['can'] ?? null ) ? $facts['can'] : array();
		$comment_redirects = 'redirect' === ( $facts['comment_method'] ?? '' ) && '' !== (string) ( $facts['comment_url'] ?? '' );
		$jetpack_account   = ! empty( $facts['jetpack_origin'] ) && empty( $facts['jetpack_user'] );

		if ( empty( $facts['like_available'] ) && empty( $facts['liked'] ) ) {
			if ( $jetpack_account ) {
				// Linking the account fixes Comment too, so no second sentence.
				return self::note(
					'jetpack_account',
					__( 'Link your WordPress.com account to like and comment on this post from Daymark.', 'daymark' ),
					self::jetpack_link( $can )
				);
			}

			$note = self::like_reason( $facts, $can );

			if ( $comment_redirects ) {
				$note['sentences'][] = __( 'To comment, Daymark opens the post on its own site.', 'daymark' );
			}

			return $note;
		}

		if ( ! $comment_redirects ) {
			return null;
		}

		if ( $jetpack_account ) {
			return self::note(
				'jetpack_account',
				__( 'Link your WordPress.com account to comment on this post from Daymark.', 'daymark' ),
				self::jetpack_link( $can )
			);
		}

		return self::note(
			'comment_only',
			__( 'Daymark can’t send comments to this post’s site, so Comment opens the post there instead.', 'daymark' )
		);
	}

	/**
	 * Why a Like can't reach the post's site.
	 *
	 * @param array<string, mixed> $facts See explain().
	 * @param array<string, bool>  $can   What the viewer may open.
	 * @return array{reason: string, sentences: string[], link: array{url: string, label: string}|null}
	 */
	private static function like_reason( array $facts, array $can ): array {
		if ( ! empty( $facts['webmention_endpoint'] ) && empty( $facts['can_send'] ) ) {
			return self::note(
				'webmention',
				__( 'This post’s site accepts Likes by Webmention, but your site isn’t set up to send Webmentions.', 'daymark' ),
				self::plugin_link( $can, 'webmention', __( 'Set up Webmention', 'daymark' ) )
			);
		}

		$note = null;

		if ( ! empty( $facts['bluesky'] ) ) {
			$note = self::bluesky_reason( $facts, $can );
		} elseif ( ! empty( $facts['fediverse'] ) ) {
			$note = self::fediverse_reason( $facts, $can );
		}

		if ( null !== $note ) {
			return $note;
		}

		if ( empty( $facts['reached'] ) ) {
			return self::note(
				'unreachable',
				__( 'Daymark couldn’t load this post from its site to check whether it accepts Likes.', 'daymark' )
			);
		}

		return self::note(
			'origin',
			__( 'This post’s site doesn’t accept Likes from other sites.', 'daymark' )
		);
	}

	/**
	 * Why a Like can't reach a Bluesky post: only Bridgy Fed can deliver
	 * one, so it's either not set up or can't send.
	 *
	 * @param array<string, mixed> $facts See explain().
	 * @param array<string, bool>  $can   What the viewer may open.
	 * @return array{reason: string, sentences: string[], link: array{url: string, label: string}|null}|null
	 */
	private static function bluesky_reason( array $facts, array $can ): ?array {
		if ( empty( $facts['bridged'] ) ) {
			return self::note(
				'bridgy_fed',
				__( 'This is a Bluesky post. Daymark can like it once your site is connected to Bridgy Fed.', 'daymark' ),
				self::connectors_link( $can, 'bridgy_fed', __( 'Set up Bridgy Fed', 'daymark' ) )
			);
		}

		return empty( $facts['can_send'] ) ? self::bridgy_fed_sending_note( $can ) : null;
	}

	/**
	 * Why a Like can't reach a fediverse post (Mastodon, or a site running
	 * the ActivityPub plugin): the ActivityPub plugin's route isn't usable,
	 * or Bridgy Fed can't send.
	 *
	 * @param array<string, mixed> $facts See explain().
	 * @param array<string, bool>  $can   What the viewer may open.
	 * @return array{reason: string, sentences: string[], link: array{url: string, label: string}|null}|null
	 */
	private static function fediverse_reason( array $facts, array $can ): ?array {
		// A bridged site reaches the fediverse through Bridgy Fed, so the
		// ActivityPub plugin isn't the fix (the Connectors tab says to use
		// one or the other).
		if ( ! empty( $facts['bridged'] ) ) {
			return empty( $facts['can_send'] ) ? self::bridgy_fed_sending_note( $can ) : null;
		}

		switch ( (string) ( $facts['activitypub'] ?? 'missing' ) ) {
			case 'active':
				break;

			case 'outdated':
				return self::note(
					'activitypub_outdated',
					sprintf(
						/* translators: %s: lowest version of the ActivityPub plugin Daymark can like with, such as 8.1.0. */
						__( 'This is a fediverse post. Daymark can like it once the ActivityPub plugin on your site is updated to version %s or later.', 'daymark' ),
						Daymark_ActivityPub_Engagement::MIN_VERSION
					),
					! empty( $can['update_plugins'] ) ? self::link( admin_url( 'plugins.php' ), __( 'Update plugins', 'daymark' ) ) : null
				);

			case 'incompatible':
				return self::note(
					'activitypub_incompatible',
					__( 'This is a fediverse post, but Daymark can’t like it with the version of the ActivityPub plugin on your site.', 'daymark' )
				);

			default:
				return self::note(
					'activitypub_missing',
					__( 'This is a fediverse post. Daymark can like it once the ActivityPub plugin is active on your site.', 'daymark' ),
					self::plugin_link( $can, 'activitypub', __( 'Set up ActivityPub', 'daymark' ) )
				);
		}

		if ( ! empty( $facts['activitypub_profiles_off'] ) ) {
			return self::note(
				'activitypub_profiles',
				__( 'This is a fediverse post. Daymark can like it once author profiles are turned on in your site’s ActivityPub settings.', 'daymark' ),
				! empty( $can['settings'] ) ? self::link( admin_url( 'options-general.php?page=activitypub&tab=settings' ), __( 'ActivityPub settings', 'daymark' ) ) : null
			);
		}

		if ( empty( $facts['activitypub_user'] ) ) {
			return self::note(
				'activitypub_user',
				__( 'This is a fediverse post. Daymark can like it once your account is turned on for ActivityPub.', 'daymark' ),
				! empty( $can['users'] ) ? self::link( admin_url( 'users.php' ), __( 'Turn it on in Users', 'daymark' ) ) : null
			);
		}

		return self::note(
			'activitypub_unreachable',
			__( 'Daymark couldn’t look this post up through ActivityPub, so it can’t like it right now.', 'daymark' )
		);
	}

	/**
	 * Bridgy Fed is set up but nothing on this site can send it a
	 * Webmention.
	 *
	 * @param array<string, bool> $can What the viewer may open.
	 * @return array{reason: string, sentences: string[], link: array{url: string, label: string}|null}
	 */
	private static function bridgy_fed_sending_note( array $can ): array {
		return self::note(
			'bridgy_fed_webmention',
			__( 'Your site uses Bridgy Fed, but it isn’t set up to send Webmentions, so it can’t like this post.', 'daymark' ),
			self::plugin_link( $can, 'webmention', __( 'Set up Webmention', 'daymark' ) )
		);
	}

	/**
	 * Link to Jetpack's screen for linking a WordPress.com account, for
	 * someone allowed to link one.
	 *
	 * @param array<string, bool> $can What the viewer may open.
	 * @return array{url: string, label: string}|null
	 */
	private static function jetpack_link( array $can ): ?array {
		return ! empty( $can['jetpack'] )
			? self::link( Daymark_Jetpack_Engagement::connect_account_url(), __( 'Link your account', 'daymark' ) )
			: null;
	}

	/**
	 * Link to a plugin's card on the Connectors tab, for someone allowed to
	 * open the tab and turn plugins on.
	 *
	 * @param array<string, bool> $can   What the viewer may open.
	 * @param string              $key   Connector key.
	 * @param string              $label Link text.
	 * @return array{url: string, label: string}|null
	 */
	private static function plugin_link( array $can, string $key, string $label ): ?array {
		return ! empty( $can['plugins'] ) ? self::connectors_link( $can, $key, $label ) : null;
	}

	/**
	 * Link to a connector's card on the Connectors tab, for someone allowed
	 * to open it.
	 *
	 * @param array<string, bool> $can   What the viewer may open.
	 * @param string              $key   Connector key.
	 * @param string              $label Link text.
	 * @return array{url: string, label: string}|null
	 */
	private static function connectors_link( array $can, string $key, string $label ): ?array {
		return ! empty( $can['connectors'] )
			? self::link( Daymark_Admin_Subscriptions::connector_url( $key ), $label )
			: null;
	}

	/**
	 * A note's link.
	 *
	 * @param string $url   Address.
	 * @param string $label Link text.
	 * @return array{url: string, label: string}
	 */
	private static function link( string $url, string $label ): array {
		return array(
			'url'   => esc_url_raw( $url ),
			'label' => $label,
		);
	}

	/**
	 * A note.
	 *
	 * @param string                                 $reason   Machine-readable reason.
	 * @param string                                 $sentence The explanation.
	 * @param array{url: string, label: string}|null $link     The fix, when the viewer can make it.
	 * @return array{reason: string, sentences: string[], link: array{url: string, label: string}|null}
	 */
	private static function note( string $reason, string $sentence, ?array $link = null ): array {
		return array(
			'reason'    => $reason,
			'sentences' => array( $sentence ),
			'link'      => $link,
		);
	}

	/**
	 * Whether the current user already likes the post, by any route Daymark
	 * knows about. Its Like stays (so it can be undone), so there's nothing
	 * to explain.
	 *
	 * @param int    $subscription_post_id A `daymark_sub_post` post ID.
	 * @param string $permalink            Its permalink.
	 * @return bool
	 */
	private static function liked( int $subscription_post_id, string $permalink ): bool {
		if ( '' === $permalink ) {
			return false;
		}

		$user_id = get_current_user_id();

		return Daymark_Like_Delivery::own_mark_id( '_daymark_like_of', $permalink ) > 0
			|| Daymark_Jetpack_Engagement::is_liked( $user_id, $subscription_post_id )
			|| Daymark_IndieBlocks_Likes::find_like_id( $user_id, $permalink ) > 0;
	}

	/**
	 * Where the ActivityPub plugin stands: 'active' (Daymark can use it),
	 * 'missing' (not active), 'outdated' (older than MIN_VERSION), or
	 * 'incompatible' (new enough, but without a function Daymark needs).
	 *
	 * @return string
	 */
	private static function activitypub_state(): string {
		if ( Daymark_ActivityPub_Engagement::plugin_available() ) {
			return 'active';
		}

		if ( ! defined( 'ACTIVITYPUB_PLUGIN_VERSION' ) ) {
			return 'missing';
		}

		return version_compare( (string) constant( 'ACTIVITYPUB_PLUGIN_VERSION' ), Daymark_ActivityPub_Engagement::MIN_VERSION, '>=' )
			? 'incompatible'
			: 'outdated';
	}

	/**
	 * Whether the ActivityPub plugin is set to the blog profile only, which
	 * keeps every user from acting as themselves.
	 *
	 * @return bool
	 */
	private static function activitypub_profiles_off(): bool {
		if ( ! function_exists( 'Activitypub\\is_user_type_disabled' ) ) {
			return false;
		}

		try {
			return (bool) \Activitypub\is_user_type_disabled( 'user' );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * A subscription post's permalink, as every lookup keys it.
	 *
	 * @param int $subscription_post_id A `daymark_sub_post` post ID.
	 * @return string
	 */
	private static function permalink( int $subscription_post_id ): string {
		return esc_url_raw( (string) get_post_meta( $subscription_post_id, 'permalink', true ) );
	}
}
