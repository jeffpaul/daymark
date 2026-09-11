<?php
/**
 * Daymark_Admin_Subscriptions tests (issue #78): the Settings -> Daymark
 * screen's rendering, scoped to what's safe to exercise directly.
 *
 * The three admin_post handlers (handle_subscribe/handle_refresh/
 * handle_unsubscribe) all end in redirect() -> exit, so they are not
 * called here; this file covers render_page()'s output instead, which is
 * where the Refresh-availability and Last-fetched-column behavior live.
 *
 * @package Daymark
 */

/**
 * Render-output coverage for the Refresh action and the Last fetched
 * column added to the subscriptions table.
 *
 * The admin_post handlers themselves (handle_subscribe/handle_refresh/
 * handle_unsubscribe, and — new here — handle_refresh_icon/handle_export/
 * handle_import) all end in wp_die()/wp_safe_redirect()+exit, which PHPUnit
 * has no safe way to intercept without special scaffolding this codebase
 * doesn't otherwise rely on — see this class's own pre-existing docblock
 * note above test_refresh_button_shown_for_active_subscription() and
 * class-share-target.php's matching note for Daymark_Share_Target::handle().
 * Daymark_Subscriptions::refresh_icon() and Daymark_Subscription_OPML's own
 * export()/import() logic (the substantive, testable behavior each new
 * handler here only thinly wraps) are covered directly in
 * tests/test-subscriptions.php and tests/test-subscription-opml.php
 * instead; this file covers what each new form actually renders.
 *
 * The Connectors tab's own class-signal-fallback test below (issue #342)
 * exercises connector_status() via Reflection with a synthetic connector
 * array pointing at the generic fixture class tests/class-plugin-detector-stub.php
 * declares (required from tests/bootstrap.php) — never the real ATmosphere
 * plugin's own `Atmosphere\Publisher` name, which an earlier version of
 * this coverage aliased into place globally and broke
 * Test_Publish_Helpers::test_detects_nothing_by_default in CI as a result;
 * see that stub file's own docblock for the full account.
 */
class Test_Admin_Subscriptions extends WP_UnitTestCase {

	/** @var Daymark_Subscriptions */
	private $subscriptions;

	/** @var Daymark_Admin_Subscriptions */
	private $admin_subscriptions;

	public function set_up(): void {
		parent::set_up();

		Daymark_Subscriptions::install();

		$this->subscriptions       = new Daymark_Subscriptions();
		$this->admin_subscriptions = new Daymark_Admin_Subscriptions();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		// wp_scripts() is a persistent global PHPUnit does not reset between
		// tests, so an earlier test's enqueue_assets() call would otherwise
		// leave this script enqueued for every test that follows it in the
		// same process — the same reason tests/test-subscription-opml.php's
		// set_up() resets Daymark_Subscription_Html_Cache (issue #137).
		wp_dequeue_script( 'daymark-admin-subscriptions' );
		wp_deregister_script( 'daymark-admin-subscriptions' );

		// reconcile_selected_candidates() (issue #363) genuinely calls
		// subscribe_to_candidate()/manual_refresh(), which both make real
		// outbound requests — block them the same way
		// tests/test-subscriptions.php does, rather than hitting the network
		// or an unmocked-request fatal. Their enrichment/poll steps are
		// documented best-effort (failure never blocks the subscribe/reconcile
		// outcome), so a blocked request here just means those tests exercise
		// the "poll failed" (pending) path, which is itself worth covering.
		add_filter( 'pre_http_request', array( $this, 'block_http_request' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'block_http_request' ), 10 );

		parent::tear_down();
	}

	/**
	 * Blocks every HTTP request made in this file with a WP_Error, matching
	 * tests/test-subscriptions.php's own approach — this file never needs a
	 * real or canned response, only for the request to fail predictably and
	 * fast rather than reach the network.
	 *
	 * @param mixed  $preempt     Existing short-circuit value.
	 * @param array  $parsed_args Request args (unused).
	 * @param string $url         Requested URL (unused).
	 * @return WP_Error
	 */
	public function block_http_request( $preempt, $parsed_args, $url ) {
		return new WP_Error( 'daymark_test_http_blocked', 'Unmocked HTTP request blocked in test: ' . $url );
	}

	/**
	 * Renders the page and returns its output as a string.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		$this->admin_subscriptions->render_page();

		return (string) ob_get_clean();
	}

	public function test_refresh_button_shown_for_active_subscription(): void {
		$this->subscriptions->create(
			array(
				'site_url' => 'https://example.com',
				'feed_url' => 'https://example.com/feed',
				'status'   => 'active',
			)
		);

		$output = $this->render();

		$this->assertStringContainsString( 'daymark_subscription_refresh', $output );
		$this->assertStringContainsString( 'Refresh', $output );
	}

	/**
	 * Scenario (Unreleased — moved the content Refresh action out of the
	 * Actions column into a circular-arrows icon next to "Last fetched"):
	 * the icon button carries dashicons-update, not the old labeled
	 * secondary-button markup, and renders inside the same cell as the
	 * last-fetched text rather than alongside Unsubscribe.
	 */
	public function test_refresh_trigger_renders_as_icon_next_to_last_fetched(): void {
		$this->subscriptions->create(
			array(
				'site_url' => 'https://icon-refresh.example',
				'feed_url' => 'https://icon-refresh.example/feed',
				'status'   => 'active',
			)
		);

		$output = $this->render();

		$this->assertStringContainsString( 'daymark-subscription-refresh-trigger', $output );
		$this->assertStringContainsString( 'dashicons-update', $output );

		// The refresh trigger's form appears after the last-fetched span,
		// within the same table cell — not down in the Actions column.
		$last_fetched_pos = strpos( $output, 'daymark-subscription-last-fetched' );
		$refresh_pos      = strpos( $output, 'daymark-subscription-refresh-form' );

		$this->assertIsInt( $last_fetched_pos );
		$this->assertIsInt( $refresh_pos );
		$this->assertGreaterThan( $last_fetched_pos, $refresh_pos );
	}

	public function test_refresh_button_shown_for_error_subscription(): void {
		$this->subscriptions->create(
			array(
				'site_url' => 'https://example.org',
				'feed_url' => 'https://example.org/feed',
				'status'   => 'error',
			)
		);

		$output = $this->render();

		$this->assertStringContainsString( 'daymark_subscription_refresh', $output );
	}

	/** The site icon renders inline with the Site column's title, not in its own column. */
	public function test_site_icon_renders_inline_with_title_when_site_icon_url_set(): void {
		$this->subscriptions->create(
			array(
				'site_url'      => 'https://example.com',
				'feed_url'      => 'https://example.com/feed',
				'site_icon_url' => 'https://example.com/favicon.ico',
			)
		);

		$output = $this->render();

		$this->assertStringNotContainsString( '<th scope="col">Icon</th>', $output );
		$this->assertStringContainsString( '<img src="https://example.com/favicon.ico"', $output );
	}

	public function test_site_icon_renders_nothing_when_site_icon_url_empty(): void {
		$this->subscriptions->create(
			array(
				'site_url' => 'https://example.org',
				'feed_url' => 'https://example.org/feed',
			)
		);

		$output = $this->render();

		$this->assertStringNotContainsString( '<img', $output );
	}

	public function test_last_fetched_column_shows_never_when_unchecked(): void {
		$this->subscriptions->create(
			array(
				'site_url' => 'https://example.net',
				'feed_url' => 'https://example.net/feed',
			)
		);

		$output = $this->render();

		$this->assertStringContainsString( 'Last fetched', $output );
		$this->assertStringContainsString( 'Never', $output );
	}

	/**
	 * Scenario (issue #81): the Status column shows an error-flagged
	 * subscription's `last_error` text — a small, natural addition alongside
	 * the existing "Error" label, not a new UI element.
	 */
	public function test_status_column_shows_last_error_for_error_subscription(): void {
		$id = $this->subscriptions->create(
			array(
				'site_url' => 'https://example.biz',
				'feed_url' => 'https://example.biz/feed',
				'status'   => 'error',
			)
		);

		$this->subscriptions->update( $id, array( 'last_error' => 'The response exceeded the maximum allowed size.' ) );

		$output = $this->render();

		$this->assertStringContainsString( 'The response exceeded the maximum allowed size.', $output );
	}

	/** An active subscription's row never shows a `last_error`, even if one is on file from a past failure. */
	public function test_status_column_hides_last_error_for_active_subscription(): void {
		$id = $this->subscriptions->create(
			array(
				'site_url' => 'https://example.cc',
				'feed_url' => 'https://example.cc/feed',
				'status'   => 'active',
			)
		);

		$this->subscriptions->update( $id, array( 'last_error' => 'A stale error from before this recovered.' ) );

		$output = $this->render();

		$this->assertStringNotContainsString( 'A stale error from before this recovered.', $output );
	}

	public function test_last_fetched_column_shows_relative_time_once_checked(): void {
		$id = $this->subscriptions->create(
			array(
				'site_url' => 'https://example.test',
				'feed_url' => 'https://example.test/feed',
			)
		);

		$this->subscriptions->update( $id, array( 'last_checked_at' => gmdate( 'Y-m-d H:i:s', time() - 5 * MINUTE_IN_SECONDS ) ) );

		$output = $this->render();

		$this->assertStringContainsString( 'ago', $output );
		$this->assertStringNotContainsString( '>Never<', $output );
	}

	/** Scenario (issue #94): every subscription row shows a "Refresh icon" action. */
	public function test_refresh_icon_button_shown_for_subscription(): void {
		$this->subscriptions->create(
			array(
				'site_url' => 'https://icon-example.com',
				'feed_url' => 'https://icon-example.com/feed',
				'status'   => 'active',
			)
		);

		$output = $this->render();

		$this->assertStringContainsString( 'daymark_subscription_refresh_icon', $output );
		$this->assertStringContainsString( 'Refresh icon', $output );
	}

	// -----------------------------------------------------------------
	// "Choose from available feeds" / candidate picker (issue #307,
	// renamed from "Check for other feeds" and reworked from radios to
	// checkboxes in issue #334; reworked again in issue #363 so an
	// already-subscribed candidate can be unchecked to unsubscribe it).
	// -----------------------------------------------------------------

	/** Scenario: with nothing stashed yet, a subscription's row shows the plain "Choose from available feeds" trigger, not a picker. */
	public function test_source_switch_trigger_shown_by_default(): void {
		$this->subscriptions->create(
			array(
				'site_url' => 'https://sources-example.com',
				'feed_url' => 'https://sources-example.com/feed',
				'status'   => 'active',
			)
		);

		$output = $this->render();

		$this->assertStringContainsString( 'daymark_subscription_discover_sources', $output );
		$this->assertStringContainsString( 'Choose from available feeds', $output );
		$this->assertStringNotContainsString( 'daymark_candidate_index', $output );
	}

	/**
	 * Scenario: once a discovery result is stashed for this subscription (what
	 * handle_discover_sources() would have written), the row shows the
	 * candidate picker instead of the plain trigger — each option labeled with
	 * its source, as a checkbox rather than a radio (issue #334), the
	 * currently active feed_url checked and marked "(current)", and an
	 * "Update feeds" action (issue #363; "Add selected feeds" before that).
	 */
	public function test_source_picker_shown_once_candidates_are_stashed(): void {
		$id = $this->subscriptions->create(
			array(
				'site_url'    => 'https://picker-example.com',
				'feed_url'    => 'https://picker-example.com/wp-json/wp/v2/posts',
				'source_type' => 'wordpress',
				'status'      => 'active',
			)
		);

		set_transient(
			'daymark_subscription_sources_' . $id . '_' . get_current_user_id(),
			array(
				'site_url'   => 'https://picker-example.com',
				'candidates' => array(
					array(
						'url'          => 'https://picker-example.com/wp-json/wp/v2/posts',
						'title'        => '',
						'source_type'  => 'wordpress',
						'source_label' => 'WordPress REST API',
					),
					array(
						'url'          => 'https://picker-example.com/feed/',
						'title'        => 'Picker Example » Feed',
						'source_type'  => 'feed',
						'source_label' => 'RSS/Atom Feed',
					),
				),
			),
			5 * MINUTE_IN_SECONDS
		);

		$output = $this->render();

		$this->assertStringContainsString( 'daymark_subscription_update_feeds', $output );
		$this->assertStringContainsString( 'daymark_candidate_index[]', $output );
		$this->assertStringContainsString( 'type="checkbox"', $output );
		$this->assertStringNotContainsString( 'type="radio"', $output );
		$this->assertStringContainsString( 'WordPress REST API', $output );
		$this->assertStringContainsString( 'RSS/Atom Feed', $output );
		$this->assertStringContainsString( 'https://picker-example.com/feed/', $output );
		$this->assertStringContainsString( '(current)', $output );
		$this->assertStringContainsString( 'Update feeds', $output );
		$this->assertStringContainsString( 'daymark_subscription_discover_sources_dismiss', $output );
		// The plain trigger is replaced by the picker, not shown alongside it.
		$this->assertStringNotContainsString( 'Choose from available feeds', $output );
	}

	/**
	 * Scenario (issue #363; previously `disabled`, issue #334): the row's own
	 * current feed_url renders checked but no longer disabled — it can be
	 * unchecked through this picker, which is what lets a submit unsubscribe
	 * it via reconcile_selected_candidates(). The new, not-yet-subscribed
	 * candidate renders unchecked and, like every candidate now, not disabled
	 * either.
	 */
	public function test_source_picker_current_feed_checkbox_is_checked_but_not_disabled(): void {
		$id = $this->subscriptions->create(
			array(
				'site_url'    => 'https://checkbox-example.com',
				'feed_url'    => 'https://checkbox-example.com/feed/',
				'source_type' => 'feed',
				'status'      => 'active',
			)
		);

		set_transient(
			'daymark_subscription_sources_' . $id . '_' . get_current_user_id(),
			array(
				'site_url'   => 'https://checkbox-example.com',
				'candidates' => array(
					array(
						'url'          => 'https://checkbox-example.com/feed/',
						'title'        => '',
						'source_type'  => 'feed',
						'source_label' => 'RSS/Atom Feed',
					),
					array(
						'url'          => 'https://checkbox-example.com/wp-json/wp/v2/posts',
						'title'        => '',
						'source_type'  => 'wordpress',
						'source_label' => 'WordPress REST API',
					),
				),
			),
			5 * MINUTE_IN_SECONDS
		);

		$output = $this->render();

		// Matched precisely by each candidate's own <input> tag rather than
		// searching the whole page for the bare word "checked"/"disabled",
		// either of which can appear elsewhere on the page too.
		preg_match( '/<input\s+type="checkbox"\s+name="daymark_candidate_index\[\]"\s+value="0"[^>]*\/>/s', $output, $current_input );
		preg_match( '/<input\s+type="checkbox"\s+name="daymark_candidate_index\[\]"\s+value="1"[^>]*\/>/s', $output, $new_input );

		$this->assertNotEmpty( $current_input, 'Expected to find the current candidate\'s checkbox markup.' );
		$this->assertNotEmpty( $new_input, 'Expected to find the new candidate\'s checkbox markup.' );
		$this->assertStringContainsString( 'checked', $current_input[0] );
		$this->assertStringNotContainsString( 'disabled', $current_input[0] );
		$this->assertStringNotContainsString( 'checked', $new_input[0] );
		$this->assertStringNotContainsString( 'disabled', $new_input[0] );
	}

	/**
	 * Scenario: a stashed transient for a *different* site_url than the
	 * subscription's own current one (a stale result — e.g. site_url changed,
	 * or this is somehow another subscription's leftover key) is not trusted;
	 * the row falls back to the plain trigger instead of showing a mismatched
	 * picker.
	 */
	public function test_source_picker_ignores_a_stashed_result_for_a_different_site_url(): void {
		$id = $this->subscriptions->create(
			array(
				'site_url' => 'https://mismatch-example.com',
				'feed_url' => 'https://mismatch-example.com/feed',
				'status'   => 'active',
			)
		);

		set_transient(
			'daymark_subscription_sources_' . $id . '_' . get_current_user_id(),
			array(
				'site_url'   => 'https://some-other-site.example',
				'candidates' => array(
					array(
						'url'          => 'https://some-other-site.example/feed/',
						'title'        => '',
						'source_type'  => 'feed',
						'source_label' => 'RSS/Atom Feed',
					),
				),
			),
			5 * MINUTE_IN_SECONDS
		);

		$output = $this->render();

		$this->assertStringContainsString( 'Choose from available feeds', $output );
		$this->assertStringNotContainsString( 'daymark_candidate_index', $output );
	}

	/** Scenario (issue #334): a successful "Add selected feeds" submit shows a plain success notice, singular wording for exactly one feed. */
	public function test_feeds_added_notice_rendered_singular(): void {
		$_GET['daymark_notice'] = 'feeds_added';
		$output                 = $this->render();
		unset( $_GET['daymark_notice'] );

		$this->assertStringContainsString( 'Added a feed. New posts from it will start appearing in the Timeline.', $output );
	}

	/** Scenario (issue #334): the same notice pluralizes once daymark_count is greater than one. */
	public function test_feeds_added_notice_rendered_plural(): void {
		$_GET['daymark_notice'] = 'feeds_added';
		$_GET['daymark_count']  = '3';
		$output                 = $this->render();
		unset( $_GET['daymark_notice'], $_GET['daymark_count'] );

		$this->assertStringContainsString( 'Added 3 feeds. New posts from them will start appearing in the Timeline.', $output );
	}

	/** Scenario (issue #363): a pure-removal "Update feeds" submit — nothing new checked, one already-subscribed candidate unchecked. */
	public function test_feeds_removed_notice_rendered_singular(): void {
		$_GET['daymark_notice']  = 'feeds_removed';
		$_GET['daymark_removed'] = '1';
		$output                  = $this->render();
		unset( $_GET['daymark_notice'], $_GET['daymark_removed'] );

		$this->assertStringContainsString( 'Removed 1 feed — its previously loaded posts have been removed too.', $output );
	}

	/** Scenario (issue #363): the removal notice pluralizes once daymark_removed is greater than one. */
	public function test_feeds_removed_notice_rendered_plural(): void {
		$_GET['daymark_notice']  = 'feeds_removed';
		$_GET['daymark_removed'] = '2';
		$output                  = $this->render();
		unset( $_GET['daymark_notice'], $_GET['daymark_removed'] );

		$this->assertStringContainsString( 'Removed 2 feeds — their previously loaded posts have been removed too.', $output );
	}

	/** Scenario (issue #363): a mixed add-and-remove "Update feeds" submit — a genuine feed switch. */
	public function test_feeds_updated_notice_rendered(): void {
		$_GET['daymark_notice']  = 'feeds_updated';
		$_GET['daymark_count']   = '1';
		$_GET['daymark_removed'] = '1';
		$output                  = $this->render();
		unset( $_GET['daymark_notice'], $_GET['daymark_count'], $_GET['daymark_removed'] );

		$this->assertStringContainsString( 'Updated this site\'s feeds: added 1, removed 1 — the removed feeds\' previously loaded posts have been removed too.', $output );
	}

	/** Scenario (issue #363): the mixed notice's own pending variant, when the newly-added feed's first poll didn't complete. */
	public function test_feeds_updated_pending_notice_rendered(): void {
		$_GET['daymark_notice']  = 'feeds_updated_pending';
		$_GET['daymark_count']   = '1';
		$_GET['daymark_removed'] = '1';
		$output                  = $this->render();
		unset( $_GET['daymark_notice'], $_GET['daymark_count'], $_GET['daymark_removed'] );

		$this->assertStringContainsString( 'their first fetch didn\'t complete', $output );
	}

	/** Scenario (issue #363): submitting the picker with no actual change (nothing newly checked or unchecked) is a harmless, clearly-labeled no-op. */
	public function test_feeds_unchanged_notice_rendered(): void {
		$_GET['daymark_notice'] = 'feeds_unchanged';
		$output                 = $this->render();
		unset( $_GET['daymark_notice'] );

		$this->assertStringContainsString( 'No changes made to this site\'s feeds.', $output );
	}

	// -----------------------------------------------------------------
	// reconcile_selected_candidates() / resolve_update_feeds_notice()
	// (issue #363) — exercised via Reflection, matching this file's own
	// established pattern (test_connector_status_falls_back_to_class_signal_when_folder_slug_does_not_match()
	// below) for substantive private logic worth unit testing directly
	// rather than only through render() output.
	// -----------------------------------------------------------------

	/** Scenario: a checked candidate not yet subscribed to gets subscribed. */
	public function test_reconcile_subscribes_a_newly_checked_candidate(): void {
		$method = new ReflectionMethod( $this->admin_subscriptions, 'reconcile_selected_candidates' );

		$outcome = $method->invoke(
			$this->admin_subscriptions,
			'https://reconcile-add.example/',
			array(
				array(
					'url'          => 'https://reconcile-add.example/feed/',
					'source_type'  => 'feed',
					'source_label' => 'RSS/Atom Feed',
				),
			),
			array( 0 )
		);

		$this->assertSame( 1, $outcome['added'] );
		$this->assertSame( 0, $outcome['removed'] );
		$this->assertNotNull( $this->subscriptions->get_by_feed_url( 'https://reconcile-add.example/feed/' ) );
	}

	/** Scenario: an unchecked candidate that is currently subscribed gets unsubscribed — and its cached content is trashed. */
	public function test_reconcile_unsubscribes_a_newly_unchecked_candidate(): void {
		$this->subscriptions->create(
			array(
				'site_url'    => 'https://reconcile-remove.example/',
				'feed_url'    => 'https://reconcile-remove.example/wp-json/wp/v2/posts',
				'source_type' => 'wordpress',
				'status'      => 'active',
			)
		);

		$method = new ReflectionMethod( $this->admin_subscriptions, 'reconcile_selected_candidates' );

		$outcome = $method->invoke(
			$this->admin_subscriptions,
			'https://reconcile-remove.example/',
			array(
				array(
					'url'          => 'https://reconcile-remove.example/wp-json/wp/v2/posts',
					'source_type'  => 'wordpress',
					'source_label' => 'WordPress REST API',
				),
			),
			array() // Nothing checked.
		);

		$this->assertSame( 0, $outcome['added'] );
		$this->assertSame( 1, $outcome['removed'] );
		$this->assertNull( $this->subscriptions->get_by_feed_url( 'https://reconcile-remove.example/wp-json/wp/v2/posts' ) );
	}

	/** Scenario: a genuine feed switch — the current feed unchecked, a different one checked, in the same submission. */
	public function test_reconcile_switches_from_one_feed_to_another_in_one_submission(): void {
		$this->subscriptions->create(
			array(
				'site_url'    => 'https://reconcile-switch.example/',
				'feed_url'    => 'https://reconcile-switch.example/wp-json/wp/v2/posts',
				'source_type' => 'wordpress',
				'status'      => 'active',
			)
		);

		$method = new ReflectionMethod( $this->admin_subscriptions, 'reconcile_selected_candidates' );

		$outcome = $method->invoke(
			$this->admin_subscriptions,
			'https://reconcile-switch.example/',
			array(
				array(
					'url'          => 'https://reconcile-switch.example/wp-json/wp/v2/posts',
					'source_type'  => 'wordpress',
					'source_label' => 'WordPress REST API',
				),
				array(
					'url'          => 'https://reconcile-switch.example/en/feed/',
					'source_type'  => 'feed',
					'source_label' => 'RSS/Atom Feed',
					'language'     => 'en',
				),
			),
			array( 1 ) // Only the English-scoped feed stays checked.
		);

		$this->assertSame( 1, $outcome['added'] );
		$this->assertSame( 1, $outcome['removed'] );
		$this->assertNull( $this->subscriptions->get_by_feed_url( 'https://reconcile-switch.example/wp-json/wp/v2/posts' ) );
		$this->assertNotNull( $this->subscriptions->get_by_feed_url( 'https://reconcile-switch.example/en/feed/' ) );
	}

	/** Scenario: a checked-and-already-subscribed, or unchecked-and-never-subscribed, candidate is a no-op either way. */
	public function test_reconcile_is_a_no_op_when_nothing_actually_changed(): void {
		$this->subscriptions->create(
			array(
				'site_url'    => 'https://reconcile-noop.example/',
				'feed_url'    => 'https://reconcile-noop.example/feed/',
				'source_type' => 'feed',
				'status'      => 'active',
			)
		);

		$method = new ReflectionMethod( $this->admin_subscriptions, 'reconcile_selected_candidates' );

		$outcome = $method->invoke(
			$this->admin_subscriptions,
			'https://reconcile-noop.example/',
			array(
				array(
					'url'          => 'https://reconcile-noop.example/feed/',
					'source_type'  => 'feed',
					'source_label' => 'RSS/Atom Feed',
				),
				array(
					'url'          => 'https://reconcile-noop.example/wp-json/wp/v2/posts',
					'source_type'  => 'wordpress',
					'source_label' => 'WordPress REST API',
				),
			),
			array( 0 ) // The already-subscribed feed stays checked; the other stays unchecked.
		);

		$this->assertSame( 0, $outcome['added'] );
		$this->assertSame( 0, $outcome['removed'] );
		$this->assertNotNull( $this->subscriptions->get_by_feed_url( 'https://reconcile-noop.example/feed/' ) );
	}

	/**
	 * @dataProvider provide_update_feeds_notice_outcomes
	 * @param array{added: int, pending: int, removed: int} $outcome Reconcile outcome.
	 * @param string                                          $expected Expected notice key.
	 */
	public function test_resolve_update_feeds_notice( array $outcome, string $expected ): void {
		$method = new ReflectionMethod( $this->admin_subscriptions, 'resolve_update_feeds_notice' );

		$this->assertSame( $expected, $method->invoke( $this->admin_subscriptions, $outcome ) );
	}

	/**
	 * @return array<string, array{0: array{added: int, pending: int, removed: int}, 1: string}>
	 */
	public function provide_update_feeds_notice_outcomes(): array {
		return array(
			'nothing changed'        => array(
				array(
					'added'   => 0,
					'pending' => 0,
					'removed' => 0,
				),
				'feeds_unchanged',
			),
			'pure add'               => array(
				array(
					'added'   => 1,
					'pending' => 0,
					'removed' => 0,
				),
				'feeds_added',
			),
			'pure add, poll pending' => array(
				array(
					'added'   => 1,
					'pending' => 1,
					'removed' => 0,
				),
				'feeds_added_pending',
			),
			'pure remove'            => array(
				array(
					'added'   => 0,
					'pending' => 0,
					'removed' => 1,
				),
				'feeds_removed',
			),
			'mixed add and remove'   => array(
				array(
					'added'   => 1,
					'pending' => 0,
					'removed' => 1,
				),
				'feeds_updated',
			),
			'mixed, poll pending'    => array(
				array(
					'added'   => 1,
					'pending' => 1,
					'removed' => 1,
				),
				'feeds_updated_pending',
			),
		);
	}

	/** Scenario (issue #334): the new-subscribe flow's own "subscribed" notice stays exactly the pre-#334 singular wording when daymark_count is absent (the default, count = 1). */
	public function test_subscribed_notice_rendered_singular_by_default(): void {
		$_GET['daymark_notice'] = 'subscribed';
		$output                 = $this->render();
		unset( $_GET['daymark_notice'] );

		$this->assertStringContainsString( 'Subscribed. New posts from this site will start appearing in the Timeline.', $output );
	}

	/** Scenario (issue #334): subscribing to more than one feed at once pluralizes the same notice. */
	public function test_subscribed_notice_rendered_plural(): void {
		$_GET['daymark_notice'] = 'subscribed';
		$_GET['daymark_count']  = '2';
		$output                 = $this->render();
		unset( $_GET['daymark_notice'], $_GET['daymark_count'] );

		$this->assertStringContainsString( 'Subscribed to 2 feeds. New posts will start appearing in the Timeline.', $output );
	}

	// -----------------------------------------------------------------
	// New-subscribe "pick which feed(s) to follow" picker (issue #334).
	// -----------------------------------------------------------------

	/** Scenario: with no discovery stashed yet, the Subscribe screen shows the plain URL field, not a picker. */
	public function test_subscribe_form_shows_plain_url_field_by_default(): void {
		$output = $this->render();

		$this->assertStringContainsString( 'daymark_site_url', $output );
		$this->assertStringContainsString( 'daymark_subscribe', $output );
		$this->assertStringNotContainsString( 'daymark_candidate_index', $output );
	}

	/**
	 * Scenario: once handle_subscribe() has stashed a discovery result for
	 * this user (a brand-new site, no subscription row exists yet), the
	 * Subscribe screen shows the checkbox picker instead of the URL field —
	 * the richest candidate (WordPress REST API, per
	 * Daymark_Subscriptions::most_optimal_candidate_index()) checked by
	 * default, a weaker one left unchecked.
	 */
	public function test_new_subscribe_picker_shown_once_stashed_with_best_candidate_checked(): void {
		set_transient(
			'daymark_new_subscription_candidates_' . get_current_user_id(),
			array(
				'site_url'   => 'https://new-subscribe-example.com',
				'candidates' => array(
					array(
						'url'          => 'https://new-subscribe-example.com/feed/',
						'title'        => '',
						'source_type'  => 'feed',
						'source_label' => 'RSS/Atom Feed',
					),
					array(
						'url'          => 'https://new-subscribe-example.com/wp-json/wp/v2/posts',
						'title'        => '',
						'source_type'  => 'wordpress',
						'source_label' => 'WordPress REST API',
					),
				),
			),
			5 * MINUTE_IN_SECONDS
		);

		$output = $this->render();

		$this->assertStringContainsString( 'daymark_subscribe_confirm', $output );
		$this->assertStringContainsString( 'daymark_subscribe_cancel', $output );
		$this->assertStringContainsString( 'daymark_candidate_index[]', $output );
		$this->assertStringContainsString( 'new-subscribe-example.com', $output );
		$this->assertStringNotContainsString( 'name="daymark_site_url"', $output );

		// The WordPress REST API candidate (index 1, the richest) is checked;
		// the feed candidate (index 0) is not — matched precisely by each
		// candidate's own <input> tag rather than searching the whole page
		// for the bare word "checked", which can appear elsewhere too.
		preg_match( '/<input\s+type="checkbox"\s+name="daymark_candidate_index\[\]"\s+value="0"[^>]*\/>/s', $output, $feed_input );
		preg_match( '/<input\s+type="checkbox"\s+name="daymark_candidate_index\[\]"\s+value="1"[^>]*\/>/s', $output, $wordpress_input );

		$this->assertNotEmpty( $feed_input, 'Expected to find the feed candidate\'s checkbox markup.' );
		$this->assertNotEmpty( $wordpress_input, 'Expected to find the WordPress REST API candidate\'s checkbox markup.' );
		$this->assertStringNotContainsString( 'checked', $feed_input[0] );
		$this->assertStringContainsString( 'checked', $wordpress_input[0] );
	}

	/**
	 * Scenario (issue #336): a candidate carrying a `language` tag (hreflang-
	 * based per-language feed detection) shows its human-readable language
	 * name in the picker label; a candidate with no `language` tag at all
	 * (the overwhelming majority of single-language sites) shows nothing
	 * extra.
	 */
	public function test_new_subscribe_picker_shows_language_name_when_tagged(): void {
		set_transient(
			'daymark_new_subscription_candidates_' . get_current_user_id(),
			array(
				'site_url'   => 'https://multilingual-picker-example.com',
				'candidates' => array(
					array(
						'url'          => 'https://multilingual-picker-example.com/feed/',
						'title'        => '',
						'source_type'  => 'feed',
						'source_label' => 'RSS/Atom Feed',
						'language'     => 'en',
					),
					array(
						'url'          => 'https://multilingual-picker-example.com/br/feed/',
						'title'        => '',
						'source_type'  => 'feed',
						'source_label' => 'RSS/Atom Feed',
						'language'     => 'pt-br',
					),
					array(
						'url'          => 'https://multilingual-picker-example.com/wp-json/wp/v2/posts',
						'title'        => '',
						'source_type'  => 'wordpress',
						'source_label' => 'WordPress REST API',
					),
				),
			),
			5 * MINUTE_IN_SECONDS
		);

		$output = $this->render();

		$this->assertStringContainsString( 'RSS/Atom Feed', $output );
		$this->assertStringContainsString( '(English)', $output );
		$this->assertStringContainsString( '(Portuguese)', $output );

		// The untagged WordPress REST API candidate's own label carries no
		// parenthesized language at all.
		$wordpress_pos = strpos( $output, 'WordPress REST API' );
		$this->assertIsInt( $wordpress_pos );
		$this->assertStringNotContainsString( '(', substr( $output, $wordpress_pos, 40 ) );
	}

	/** Scenario: a candidate that's already subscribed (a previous partial attempt, or simply already followed) renders checked and disabled with "(already subscribed)", never "(current)" — there is no existing row yet in this context. */
	public function test_new_subscribe_picker_marks_already_subscribed_candidate(): void {
		$this->subscriptions->create(
			array(
				'site_url'    => 'https://already-subscribed-example.com',
				'feed_url'    => 'https://already-subscribed-example.com/feed/',
				'source_type' => 'feed',
			)
		);

		set_transient(
			'daymark_new_subscription_candidates_' . get_current_user_id(),
			array(
				'site_url'   => 'https://already-subscribed-example.com',
				'candidates' => array(
					array(
						'url'          => 'https://already-subscribed-example.com/feed/',
						'title'        => '',
						'source_type'  => 'feed',
						'source_label' => 'RSS/Atom Feed',
					),
				),
			),
			5 * MINUTE_IN_SECONDS
		);

		$output = $this->render();

		$this->assertStringContainsString( 'already subscribed', $output );
		$this->assertStringNotContainsString( '(current)', $output );
	}

	/** Scenario (issue #80): the settings page renders an OPML Export link. */
	public function test_export_link_rendered(): void {
		$_GET['tab'] = 'import-export';
		$output      = $this->render();
		unset( $_GET['tab'] );

		$this->assertStringContainsString( 'daymark_subscriptions_export', $output );
		$this->assertStringContainsString( 'Export subscriptions (OPML)', $output );
	}

	/**
	 * Scenario (issue #80, issue #86's tab restructuring): the Import/Export
	 * tab renders an OPML Import form.
	 */
	public function test_import_form_rendered(): void {
		$_GET['tab'] = 'import-export';
		$output      = $this->render();
		unset( $_GET['tab'] );

		$this->assertStringContainsString( 'daymark_subscriptions_import', $output );
		$this->assertStringContainsString( 'enctype="multipart/form-data"', $output );
		$this->assertStringContainsString( 'daymark_opml_file', $output );
	}

	/**
	 * Scenario (issue #80): after a successful import, render_page() reads
	 * and displays the per-entry results transient handle_import() would
	 * have written, then clears it so a page refresh doesn't repeat it.
	 */
	public function test_opml_import_results_rendered_and_consumed(): void {
		$user_id = get_current_user_id();

		set_transient(
			'daymark_opml_import_result_' . $user_id,
			array(
				array(
					'label'   => 'A Subscribed Site',
					'status'  => 'subscribed',
					'message' => '',
				),
				array(
					'label'   => 'A Duplicate Site',
					'status'  => 'duplicate',
					'message' => 'A subscription for this feed already exists.',
				),
				array(
					'label'   => 'A Bad Entry <script>',
					'status'  => 'failed',
					'message' => 'This entry\'s feed URL is not valid.',
				),
			),
			MINUTE_IN_SECONDS
		);

		$_GET['daymark_notice'] = 'opml_imported';
		$output                 = $this->render();
		unset( $_GET['daymark_notice'] );

		$this->assertStringContainsString( 'Import complete: 1 subscribed, 1 already subscribed, 1 failed.', $output );
		$this->assertStringContainsString( 'A Subscribed Site', $output );
		$this->assertStringContainsString( 'A Duplicate Site', $output );
		$this->assertStringContainsString( 'A subscription for this feed already exists.', $output );
		// Untrusted OPML-sourced labels are escaped, not rendered raw.
		$this->assertStringContainsString( 'A Bad Entry &lt;script&gt;', $output );
		$this->assertStringNotContainsString( '<script>', $output );

		// Consumed: rendering again with no notice query var shows nothing left over.
		$this->assertFalse( get_transient( 'daymark_opml_import_result_' . $user_id ) );
	}

	/**
	 * Scenario (issue #175): the Refresh form and its row carry the data
	 * attributes assets/admin-subscriptions.js needs to submit inline via
	 * the REST refresh endpoint and update that row in place.
	 */
	public function test_refresh_form_and_row_carry_js_enhancement_hooks(): void {
		$id = $this->subscriptions->create(
			array(
				'site_url' => 'https://inline-refresh.example',
				'feed_url' => 'https://inline-refresh.example/feed',
				'status'   => 'active',
			)
		);

		$output = $this->render();

		$this->assertStringContainsString( 'data-daymark-subscription-row="' . $id . '"', $output );
		$this->assertStringContainsString( 'daymark-subscription-refresh-form', $output );
		$this->assertStringContainsString( 'data-daymark-subscription-id="' . $id . '"', $output );
		$this->assertStringContainsString( 'daymark-subscription-status-text', $output );
		$this->assertStringContainsString( 'daymark-subscription-last-fetched', $output );
	}

	/**
	 * Scenario (issue #175): enqueue_assets() localizes the REST refresh
	 * endpoint + a wp_rest nonce for assets/admin-subscriptions.js, but only
	 * on this screen.
	 */
	public function test_enqueue_assets_localizes_rest_config_on_settings_screen(): void {
		$this->admin_subscriptions->enqueue_assets( 'settings_page_' . Daymark_Admin_Subscriptions::PAGE_SLUG );

		$this->assertTrue( wp_script_is( 'daymark-admin-subscriptions', 'enqueued' ) );

		$localized = wp_scripts()->get_data( 'daymark-admin-subscriptions', 'data' );
		$this->assertIsString( $localized );

		$this->assertSame( 1, preg_match( '/daymarkAdminSubscriptions\s*=\s*(\{.*\});/s', $localized, $matches ) );
		$config = json_decode( $matches[1], true );

		$this->assertIsArray( $config );
		$this->assertSame( rest_url( 'daymark/v1/subscriptions/' ), $config['restUrl'] );
		$this->assertNotEmpty( $config['restNonce'] );
		$this->assertSame( 'Refresh', $config['i18n']['refreshLabel'] );
	}

	/** enqueue_assets() does nothing on any other admin screen. */
	public function test_enqueue_assets_does_nothing_off_screen(): void {
		$this->admin_subscriptions->enqueue_assets( 'edit.php' );

		$this->assertFalse( wp_script_is( 'daymark-admin-subscriptions', 'enqueued' ) );
	}

	// -----------------------------------------------------------------
	// Sortable columns (issue #178).
	// -----------------------------------------------------------------

	/**
	 * Create three subscriptions whose site_title values are deliberately
	 * out of alphabetical order, so a test can assert render()'s output
	 * puts them back in (or out of) order via relative strpos(). Each gets
	 * an explicit, distinct created_at (oldest: Charlie, then Alpha, then
	 * Bravo newest) set directly via $wpdb — create() always stamps
	 * created_at with "now", and three creations in one test method can
	 * easily land in the same second, which would make the *default*
	 * (created_at DESC) order's tie-break behavior undefined rather than
	 * reliably reverse-insertion; the tests here need it deterministic.
	 *
	 * @return void
	 */
	private function create_three_out_of_order_subscriptions(): void {
		global $wpdb;

		$charlie_id = $this->subscriptions->create(
			array(
				'site_url'   => 'https://charlie.example',
				'feed_url'   => 'https://charlie.example/feed',
				'site_title' => 'Charlie Site',
			)
		);
		$alpha_id   = $this->subscriptions->create(
			array(
				'site_url'   => 'https://alpha.example',
				'feed_url'   => 'https://alpha.example/feed',
				'site_title' => 'Alpha Site',
			)
		);
		$bravo_id   = $this->subscriptions->create(
			array(
				'site_url'   => 'https://bravo.example',
				'feed_url'   => 'https://bravo.example/feed',
				'site_title' => 'Bravo Site',
			)
		);

		$table = Daymark_Subscriptions::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test-only: forcing a deterministic created_at that Daymark_Subscriptions::update() doesn't expose (matches tests/test-subscriptions.php's own precedent for direct $wpdb use in test setup).
		$wpdb->update( $table, array( 'created_at' => '2026-01-01 00:00:01' ), array( 'id' => $charlie_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See above.
		$wpdb->update( $table, array( 'created_at' => '2026-01-01 00:00:02' ), array( 'id' => $alpha_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See above.
		$wpdb->update( $table, array( 'created_at' => '2026-01-01 00:00:03' ), array( 'id' => $bravo_id ) );
	}

	/** Sorting the Site column ascending orders rows by site_title A-Z. */
	public function test_site_column_sorts_ascending(): void {
		$this->create_three_out_of_order_subscriptions();

		$_GET['orderby'] = 'site';
		$_GET['order']   = 'asc';
		$output          = $this->render();
		unset( $_GET['orderby'], $_GET['order'] );

		$alpha   = strpos( $output, 'Alpha Site' );
		$bravo   = strpos( $output, 'Bravo Site' );
		$charlie = strpos( $output, 'Charlie Site' );

		$this->assertLessThan( $bravo, $alpha );
		$this->assertLessThan( $charlie, $bravo );
	}

	/** Sorting the Site column descending orders rows by site_title Z-A. */
	public function test_site_column_sorts_descending(): void {
		$this->create_three_out_of_order_subscriptions();

		$_GET['orderby'] = 'site';
		$_GET['order']   = 'desc';
		$output          = $this->render();
		unset( $_GET['orderby'], $_GET['order'] );

		$alpha   = strpos( $output, 'Alpha Site' );
		$bravo   = strpos( $output, 'Bravo Site' );
		$charlie = strpos( $output, 'Charlie Site' );

		$this->assertLessThan( $bravo, $charlie );
		$this->assertLessThan( $alpha, $bravo );
	}

	/** Sorting the Status column ascending puts 'active' rows before 'error' rows. */
	public function test_status_column_sorts_ascending(): void {
		$this->subscriptions->create(
			array(
				'site_url'   => 'https://error-site.example',
				'feed_url'   => 'https://error-site.example/feed',
				'site_title' => 'Error Site',
				'status'     => 'error',
			)
		);
		$this->subscriptions->create(
			array(
				'site_url'   => 'https://active-site.example',
				'feed_url'   => 'https://active-site.example/feed',
				'site_title' => 'Active Site',
				'status'     => 'active',
			)
		);

		$_GET['orderby'] = 'status';
		$_GET['order']   = 'asc';
		$output          = $this->render();
		unset( $_GET['orderby'], $_GET['order'] );

		$this->assertLessThan( strpos( $output, 'Error Site' ), strpos( $output, 'Active Site' ) );
	}

	/** Sorting the Last fetched column ascending puts the least-recently-checked row first. */
	public function test_last_checked_column_sorts_ascending(): void {
		$older_id = $this->subscriptions->create(
			array(
				'site_url'   => 'https://older.example',
				'feed_url'   => 'https://older.example/feed',
				'site_title' => 'Older Check',
			)
		);
		$this->subscriptions->update( $older_id, array( 'last_checked_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );

		$newer_id = $this->subscriptions->create(
			array(
				'site_url'   => 'https://newer.example',
				'feed_url'   => 'https://newer.example/feed',
				'site_title' => 'Newer Check',
			)
		);
		$this->subscriptions->update( $newer_id, array( 'last_checked_at' => gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ) ) );

		$_GET['orderby'] = 'last_checked';
		$_GET['order']   = 'asc';
		$output          = $this->render();
		unset( $_GET['orderby'], $_GET['order'] );

		$this->assertLessThan( strpos( $output, 'Newer Check' ), strpos( $output, 'Older Check' ) );
	}

	/** With no orderby requested, the table defaults to A-to-Z by Site (issue #295) rather than raw subscribe order. */
	public function test_no_orderby_defaults_to_site_ascending(): void {
		$this->create_three_out_of_order_subscriptions();

		$output = $this->render();

		$alpha   = strpos( $output, 'Alpha Site' );
		$bravo   = strpos( $output, 'Bravo Site' );
		$charlie = strpos( $output, 'Charlie Site' );

		$this->assertLessThan( $bravo, $alpha );
		$this->assertLessThan( $charlie, $bravo );
	}

	/** An unrecognized orderby value is ignored, falling back to the same A-to-Z-by-Site default rather than erroring. */
	public function test_invalid_orderby_falls_back_to_default_order(): void {
		$this->create_three_out_of_order_subscriptions();

		$_GET['orderby'] = 'not-a-real-column';
		$_GET['order']   = 'asc';
		$output          = $this->render();
		unset( $_GET['orderby'], $_GET['order'] );

		$this->assertLessThan( strpos( $output, 'Bravo Site' ), strpos( $output, 'Alpha Site' ) );
	}

	/** Column header links carry the query args that flip the active column's direction, and mark it via aria-sort. */
	public function test_sortable_header_reflects_active_column_and_toggles_direction(): void {
		$this->subscriptions->create(
			array(
				'site_url' => 'https://example.test',
				'feed_url' => 'https://example.test/feed',
			)
		);

		$_GET['orderby'] = 'site';
		$_GET['order']   = 'asc';
		$output          = $this->render();
		unset( $_GET['orderby'], $_GET['order'] );

		// The active (Site) header points at the opposite direction and is marked ascending.
		// The exact entity esc_url() uses to separate query args (&#038;, &amp;, or a
		// bare &) isn't the point of this assertion, so the regex accepts any of them.
		$this->assertStringContainsString( 'aria-sort="ascending"', $output );
		$this->assertMatchesRegularExpression( '/orderby=site(&amp;|&#038;|&)order=desc/', $output );

		// An inactive sortable header (Status) is marked unsorted and defaults to ascending.
		$this->assertStringContainsString( 'aria-sort="none"', $output );
		$this->assertMatchesRegularExpression( '/orderby=status(&amp;|&#038;|&)order=asc/', $output );
	}

	// -----------------------------------------------------------------
	// Editable site name (issue #180).
	// -----------------------------------------------------------------

	/** The inline name editor's form carries the subscription's current site_title, ready to submit back unchanged or edited, behind a pencil-icon trigger. */
	public function test_edit_title_form_renders_with_current_site_title(): void {
		$this->subscriptions->create(
			array(
				'site_url'   => 'https://friend-site.example',
				'feed_url'   => 'https://friend-site.example/feed',
				'site_title' => 'cryptic-friend-handle',
			)
		);

		$output = $this->render();

		$this->assertStringContainsString( 'daymark_subscription_edit_title', $output );
		$this->assertStringContainsString( 'name="daymark_site_title" value="cryptic-friend-handle"', $output );
		$this->assertStringContainsString( 'dashicons-edit', $output );
		$this->assertStringContainsString( 'Edit site name', $output );
	}

	/** With no site_title set, the edit form's input is empty but hints at the site URL via its placeholder. */
	public function test_edit_title_form_placeholder_falls_back_to_site_url_when_title_empty(): void {
		$this->subscriptions->create(
			array(
				'site_url' => 'https://untitled.example',
				'feed_url' => 'https://untitled.example/feed',
			)
		);

		$output = $this->render();

		$this->assertStringContainsString( 'placeholder="https://untitled.example"', $output );
	}

	// -----------------------------------------------------------------
	// Surfacing subscription fetch issues (issue #182).
	// -----------------------------------------------------------------

	/**
	 * An active subscription that has started failing (but hasn't yet
	 * reached the dead threshold) now shows a prefixed "Recent fetch
	 * issue: ..." message — distinct wording from a fully dead feed's
	 * plain error text, so the two don't read as identical.
	 */
	public function test_status_column_shows_recent_fetch_issue_for_failing_active_subscription(): void {
		$id = $this->subscriptions->create(
			array(
				'site_url' => 'https://flaky.example',
				'feed_url' => 'https://flaky.example/feed',
				'status'   => 'active',
			)
		);

		$this->subscriptions->update(
			$id,
			array(
				'consecutive_failure_count' => 2,
				'last_error'                => 'Connection timed out.',
			)
		);

		$output = $this->render();

		$this->assertStringContainsString( 'Recent fetch issue: Connection timed out.', $output );
	}

	/**
	 * A dead (`status` = 'error') subscription still shows the plain
	 * `last_error` text, unchanged — the new prefixed wording is only for
	 * the not-yet-dead case.
	 */
	public function test_status_column_shows_plain_error_for_dead_subscription(): void {
		$id = $this->subscriptions->create(
			array(
				'site_url' => 'https://dead.example',
				'feed_url' => 'https://dead.example/feed',
				'status'   => 'error',
			)
		);

		$this->subscriptions->update(
			$id,
			array(
				'consecutive_failure_count' => 7,
				'last_error'                => 'This subscription\'s feed could not be reached or parsed.',
			)
		);

		$output = $this->render();

		$this->assertStringContainsString( 'This subscription&#039;s feed could not be reached or parsed.', $output );
		$this->assertStringNotContainsString( 'Recent fetch issue:', $output );
	}

	/**
	 * An active subscription with no failures at all (consecutive_failure_count
	 * stays 0) shows no error text, matching the pre-existing
	 * test_status_column_hides_last_error_for_active_subscription() case.
	 */
	public function test_status_column_hides_error_for_healthy_active_subscription(): void {
		$this->subscriptions->create(
			array(
				'site_url' => 'https://healthy.example',
				'feed_url' => 'https://healthy.example/feed',
				'status'   => 'active',
			)
		);

		$output = $this->render();

		$this->assertStringNotContainsString( 'Recent fetch issue:', $output );
	}

	// -----------------------------------------------------------------
	// Search (issue #281).
	// -----------------------------------------------------------------

	/** The search box isn't rendered at all when there are no subscriptions to search. */
	public function test_search_form_not_rendered_when_no_subscriptions(): void {
		$output = $this->render();

		$this->assertStringNotContainsString( 'daymark-subscription-search-input', $output );
	}

	/** The search box renders once there is at least one subscription, even with no search active. */
	public function test_search_form_rendered_with_subscriptions(): void {
		$this->subscriptions->create(
			array(
				'site_url' => 'https://example.test',
				'feed_url' => 'https://example.test/feed',
			)
		);

		$output = $this->render();

		$this->assertStringContainsString( 'daymark-subscription-search-input', $output );
		$this->assertStringContainsString( 'name="s"', $output );
	}

	/** A search term matching a site's title shows only that row. */
	public function test_search_filters_by_site_title(): void {
		$this->subscriptions->create(
			array(
				'site_url'   => 'https://alpha.example',
				'feed_url'   => 'https://alpha.example/feed',
				'site_title' => 'Alpha Site',
			)
		);
		$this->subscriptions->create(
			array(
				'site_url'   => 'https://bravo.example',
				'feed_url'   => 'https://bravo.example/feed',
				'site_title' => 'Bravo Site',
			)
		);

		$_GET['s'] = 'Alpha';
		$output    = $this->render();
		unset( $_GET['s'] );

		$this->assertStringContainsString( 'Alpha Site', $output );
		$this->assertStringNotContainsString( 'Bravo Site', $output );
	}

	/** A search term matching only a site's URL (not its title) still finds that row. */
	public function test_search_filters_by_site_url(): void {
		$this->subscriptions->create(
			array(
				'site_url'   => 'https://jakespurlock.example',
				'feed_url'   => 'https://jakespurlock.example/feed',
				'site_title' => 'Jake',
			)
		);
		$this->subscriptions->create(
			array(
				'site_url'   => 'https://someone-else.example',
				'feed_url'   => 'https://someone-else.example/feed',
				'site_title' => 'Someone Else',
			)
		);

		$_GET['s'] = 'jakespurlock';
		$output    = $this->render();
		unset( $_GET['s'] );

		$this->assertStringContainsString( 'Jake', $output );
		$this->assertStringNotContainsString( 'Someone Else', $output );
	}

	/**
	 * A search term matching only the underlying feed URL finds the right
	 * row among two subscriptions to the same site with different feeds
	 * (issue #183's own scenario) — searching the visible Site column text
	 * alone couldn't distinguish these.
	 */
	public function test_search_filters_by_feed_url(): void {
		$this->subscriptions->create(
			array(
				'site_url'   => 'https://example.test/notes/',
				'feed_url'   => 'https://example.test/notes/feed/',
				'site_title' => 'Example Site',
			)
		);
		$this->subscriptions->create(
			array(
				'site_url'   => 'https://example.test/',
				'feed_url'   => 'https://example.test/feed/',
				'site_title' => 'Example Site',
			)
		);

		$_GET['s'] = 'notes/feed';
		$output    = $this->render();
		unset( $_GET['s'] );

		$this->assertSame( 1, substr_count( $output, 'data-daymark-subscription-row' ) );
		$this->assertStringContainsString( 'notes/feed', $output );
	}

	/** Matching is case-insensitive. */
	public function test_search_is_case_insensitive(): void {
		$this->subscriptions->create(
			array(
				'site_url'   => 'https://example.test',
				'feed_url'   => 'https://example.test/feed',
				'site_title' => 'Alpha Site',
			)
		);

		$_GET['s'] = 'ALPHA';
		$output    = $this->render();
		unset( $_GET['s'] );

		$this->assertStringContainsString( 'Alpha Site', $output );
	}

	/** An empty search term (the box submitted with nothing typed) shows every subscription, same as no search at all. */
	public function test_empty_search_shows_all(): void {
		$this->subscriptions->create(
			array(
				'site_url'   => 'https://alpha.example',
				'feed_url'   => 'https://alpha.example/feed',
				'site_title' => 'Alpha Site',
			)
		);

		$_GET['s'] = '';
		$output    = $this->render();
		unset( $_GET['s'] );

		$this->assertStringContainsString( 'Alpha Site', $output );
	}

	/** A search matching nothing shows a distinct message, not the generic "No subscriptions yet." empty state. */
	public function test_no_match_shows_search_specific_empty_state(): void {
		$this->subscriptions->create(
			array(
				'site_url'   => 'https://alpha.example',
				'feed_url'   => 'https://alpha.example/feed',
				'site_title' => 'Alpha Site',
			)
		);

		$_GET['s'] = 'nothing-matches-this';
		$output    = $this->render();
		unset( $_GET['s'] );

		$this->assertStringContainsString( 'No subscriptions match', $output );
		$this->assertStringNotContainsString( 'No subscriptions yet.', $output );
		$this->assertStringNotContainsString( 'Alpha Site', $output );
	}

	/** The search input's value reflects the active search term, so re-rendering after a submit doesn't clear the box. */
	public function test_search_input_preserves_submitted_value(): void {
		$this->subscriptions->create(
			array(
				'site_url' => 'https://example.test',
				'feed_url' => 'https://example.test/feed',
			)
		);

		$_GET['s'] = 'a "quoted" term';
		$output    = $this->render();
		unset( $_GET['s'] );

		$this->assertStringContainsString( 'value="a &quot;quoted&quot; term"', $output );
	}

	/** A sortable column header's link carries the active search term forward, so re-sorting doesn't drop the filter. */
	public function test_sort_link_preserves_active_search_term(): void {
		$this->subscriptions->create(
			array(
				'site_url'   => 'https://alpha.example',
				'feed_url'   => 'https://alpha.example/feed',
				'site_title' => 'Alpha Site',
			)
		);

		$_GET['s'] = 'Alpha';
		$output    = $this->render();
		unset( $_GET['s'] );

		// Site is the default active sort (issue #295) even with no explicit
		// orderby, so its own header link flips to the opposite direction.
		$this->assertMatchesRegularExpression( '/orderby=site(&amp;|&#038;|&)order=desc(&amp;|&#038;|&)s=Alpha/', $output );
	}

	/** The search form carries the active sort forward as hidden fields, so submitting a new search doesn't reset it. */
	public function test_search_form_preserves_active_sort(): void {
		$this->subscriptions->create(
			array(
				'site_url' => 'https://example.test',
				'feed_url' => 'https://example.test/feed',
			)
		);

		$_GET['orderby'] = 'status';
		$_GET['order']   = 'desc';
		$output          = $this->render();
		unset( $_GET['orderby'], $_GET['order'] );

		$this->assertStringContainsString( 'name="orderby" value="status"', $output );
		$this->assertStringContainsString( 'name="order" value="desc"', $output );
	}

	/**
	 * Privacy section (issue #289): all four checkboxes render checked by
	 * default (matching each option's own pre-existing filter default),
	 * except "Publish location publicly," which defaults unchecked.
	 */
	public function test_privacy_section_renders_default_checked_states(): void {
		$_GET['tab'] = 'privacy';
		$output      = $this->render();
		unset( $_GET['tab'] );

		$this->assertMatchesRegularExpression( '/class="nav-tab nav-tab-active"[^>]*>Privacy<\/a>/', $output );

		foreach ( array( 'daymark_capture_location', 'daymark_capture_weather', 'daymark_capture_camera_metadata' ) as $option ) {
			$this->assertMatchesRegularExpression(
				'/name="' . preg_quote( $option, '/' ) . '"[^>]*checked/',
				$output,
				$option . ' should be checked by default'
			);
		}

		$this->assertDoesNotMatchRegularExpression(
			'/name="daymark_publish_location_publicly"[^>]*checked/',
			$output,
			'daymark_publish_location_publicly should be unchecked by default'
		);
	}

	/** The Privacy section's checkboxes reflect each option's own stored value, not just its default. */
	public function test_privacy_section_reflects_stored_option_values(): void {
		update_option( 'daymark_capture_location', '' );
		update_option( 'daymark_publish_location_publicly', '1' );

		$_GET['tab'] = 'privacy';
		$output      = $this->render();
		unset( $_GET['tab'] );

		delete_option( 'daymark_capture_location' );
		delete_option( 'daymark_publish_location_publicly' );

		$this->assertDoesNotMatchRegularExpression(
			'/name="daymark_capture_location"[^>]*checked/',
			$output,
			'daymark_capture_location should reflect its stored, unchecked value'
		);
		$this->assertMatchesRegularExpression(
			'/name="daymark_publish_location_publicly"[^>]*checked/',
			$output,
			'daymark_publish_location_publicly should reflect its stored, checked value'
		);
	}

	/**
	 * The "Check for new posts" dropdown (issue #291) defaults to Daily
	 * selected, matching the pre-existing filter's own DAY_IN_SECONDS
	 * default.
	 */
	public function test_poll_interval_form_defaults_to_daily(): void {
		$output = $this->render();

		$this->assertStringContainsString( 'Check for new posts:', $output );
		$this->assertMatchesRegularExpression(
			'/<option value="' . DAY_IN_SECONDS . '"[^>]*selected[^>]*>Daily<\/option>/',
			$output
		);
	}

	/** The dropdown reflects a stored daymark_subscription_poll_interval option value, not just the default. */
	public function test_poll_interval_form_reflects_stored_option_value(): void {
		update_option( 'daymark_subscription_poll_interval', HOUR_IN_SECONDS );

		$output = $this->render();

		delete_option( 'daymark_subscription_poll_interval' );

		$this->assertMatchesRegularExpression(
			'/<option value="' . HOUR_IN_SECONDS . '"[^>]*selected[^>]*>Hourly<\/option>/',
			$output
		);
	}

	// -----------------------------------------------------------------
	// Tab nav + legacy URL (issue #86 restructuring)
	// -----------------------------------------------------------------

	/** With no ?tab=, the Subscriptions tab renders and is marked active. */
	public function test_tab_nav_renders_all_tabs_with_subscriptions_default_active(): void {
		$output = $this->render();

		$this->assertStringContainsString( 'nav-tab-wrapper', $output );
		foreach ( array( 'Subscriptions', 'Connectors', 'Import / Export', 'Privacy' ) as $label ) {
			$this->assertStringContainsString( $label, $output );
		}
		$this->assertMatchesRegularExpression( '/class="nav-tab nav-tab-active"[^>]*>Subscriptions<\/a>/', $output );
		// The Subscriptions tab's own content (the subscribe form) rendered too.
		$this->assertStringContainsString( 'daymark_site_url', $output );
	}

	/** ?tab= switches both which nav item is marked active and which content renders. */
	public function test_tab_nav_switches_active_tab_via_query_var(): void {
		$_GET['tab'] = 'connectors';
		$output      = $this->render();
		unset( $_GET['tab'] );

		$this->assertMatchesRegularExpression( '/class="nav-tab nav-tab-active"[^>]*>Connectors<\/a>/', $output );
		$this->assertStringContainsString( 'Webmention', $output );
		// The Subscriptions tab's own content did not also render.
		$this->assertStringNotContainsString( 'daymark_site_url', $output );
	}

	/** An unrecognized ?tab= value falls back to the default (Subscriptions) tab. */
	public function test_invalid_tab_falls_back_to_subscriptions(): void {
		$_GET['tab'] = 'not-a-real-tab';
		$output      = $this->render();
		unset( $_GET['tab'] );

		$this->assertMatchesRegularExpression( '/class="nav-tab nav-tab-active"[^>]*>Subscriptions<\/a>/', $output );
	}

	/** page_url() now points at the short options-general.php?page=daymark URL (issue #86). */
	public function test_page_url_points_at_short_settings_url(): void {
		$this->assertSame( admin_url( 'options-general.php?page=daymark' ), Daymark_Admin_Subscriptions::page_url() );
	}

	/** tab_url() appends the requested tab as a query arg onto page_url(). */
	public function test_tab_url_appends_tab_query_arg(): void {
		$this->assertSame( admin_url( 'options-general.php?page=daymark&tab=connectors' ), Daymark_Admin_Subscriptions::tab_url( 'connectors' ) );
	}

	/**
	 * Scenario (issue #86): a visit to the pre-0.14.0 Settings submenu page
	 * (any other than the legacy Daymark slug) is left alone —
	 * maybe_redirect_legacy_url() only ever acts on its own specific old
	 * URL. Its true (redirecting) branch ends in exit(), which PHPUnit
	 * cannot safely intercept — the same reason this file's own admin_post
	 * handlers aren't exercised directly (see the class docblock) — so only
	 * this guard-clause-miss path is safe to call directly.
	 */
	public function test_maybe_redirect_legacy_url_does_nothing_for_an_unrelated_page(): void {
		global $pagenow;
		$original_pagenow = $pagenow;
		$pagenow          = 'options-general.php';
		$_GET['page']     = 'some-other-plugin-settings';

		$this->admin_subscriptions->maybe_redirect_legacy_url();

		unset( $_GET['page'] );
		$pagenow = $original_pagenow;

		// Reaching this assertion at all means the method returned instead of exit()-ing.
		$this->assertTrue( true );
	}

	/** Likewise, visiting options-general.php for the legacy slug is a no-op when $pagenow isn't options-general.php. */
	public function test_maybe_redirect_legacy_url_does_nothing_off_options_general(): void {
		global $pagenow;
		$original_pagenow = $pagenow;
		$pagenow          = 'edit.php';
		$_GET['page']     = 'daymark-subscriptions';

		$this->admin_subscriptions->maybe_redirect_legacy_url();

		unset( $_GET['page'] );
		$pagenow = $original_pagenow;

		$this->assertTrue( true );
	}

	// -----------------------------------------------------------------
	// Connectors tab (issue #86)
	// -----------------------------------------------------------------

	/**
	 * Writes a minimal, real plugin file under WP_PLUGIN_DIR so
	 * get_plugins() (a filesystem scan, not filterable) actually finds it —
	 * the same technique WordPress core's own plugin-detection tests use,
	 * since Daymark_Admin_Subscriptions::connector_plugin_file() has to
	 * match a real installed folder, not merely an option value.
	 *
	 * @param string $folder    Plugin folder name (e.g. 'webmention').
	 * @param string $main_file Main plugin file's basename (e.g. 'webmention.php').
	 * @return void
	 */
	private function install_fake_plugin( string $folder, string $main_file ): void {
		$dir = WP_PLUGIN_DIR . '/' . $folder;
		wp_mkdir_p( $dir );
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture, not a runtime code path.
			$dir . '/' . $main_file,
			"<?php\n/**\n * Plugin Name: Fake {$folder}\n */\n"
		);

		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( false );
		}
	}

	/**
	 * Removes a fixture plugin written by install_fake_plugin().
	 *
	 * @param string $folder Plugin folder name.
	 * @return void
	 */
	private function remove_fake_plugin( string $folder ): void {
		$dir = WP_PLUGIN_DIR . '/' . $folder;

		if ( is_dir( $dir ) ) {
			array_map( 'unlink', glob( $dir . '/*' ) ?: array() ); // phpcs:ignore WordPress.PHP.DisallowShortTernary.Found -- glob() can return false; empty-array fallback for array_map().
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup, not a runtime code path.
		}

		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( false );
		}
	}

	/** The Connectors tab lists all three recommended IndieWeb plugins with their WPORG links. */
	public function test_connectors_tab_lists_recommended_plugins(): void {
		$_GET['tab'] = 'connectors';
		$output      = $this->render();
		unset( $_GET['tab'] );

		$this->assertStringContainsString( 'https://wordpress.org/plugins/webmention/', $output );
		$this->assertStringContainsString( 'https://wordpress.org/plugins/activitypub/', $output );
		$this->assertStringContainsString( 'https://wordpress.org/plugins/atmosphere/', $output );
		$this->assertStringContainsString( 'Webmention', $output );
		$this->assertStringContainsString( 'ActivityPub', $output );
		$this->assertStringContainsString( 'ATmosphere', $output );
	}

	/**
	 * A 'service' entry (issue #91's Bridgy Fed) links out to its own URL
	 * with a "Get started" action — never a WPORG link, an Install/Activate
	 * button, or an Active/"Installed, not active" status string, since
	 * there is no plugin file to detect a state for.
	 */
	public function test_connectors_tab_renders_bridgy_fed_as_an_external_service(): void {
		$_GET['tab'] = 'connectors';
		$output      = $this->render();
		unset( $_GET['tab'] );

		$this->assertStringContainsString( 'Bridgy Fed', $output );
		$this->assertStringContainsString( 'https://fed.brid.gy/', $output );
		$this->assertStringContainsString( 'Get started', $output );
		$this->assertStringNotContainsString( 'https://wordpress.org/plugins/bridgy', $output );
	}

	/**
	 * Bridgy Fed renders unconditionally as an external link regardless of
	 * capability — unlike a 'plugin' entry, there is no Install/Activate
	 * action gated on install_plugins/activate_plugins to fall back from.
	 */
	public function test_connectors_tab_bridgy_fed_get_started_shown_for_author(): void {
		$_GET['tab'] = 'connectors';
		$output      = $this->render();
		unset( $_GET['tab'] );

		$this->assertMatchesRegularExpression( '/href="https:\/\/fed\.brid\.gy\/"[^>]*class="button button-secondary"[^>]*>Get started</', $output );
	}

	/**
	 * A not-installed connector, viewed by the set_up() 'author' test user
	 * (who has neither install_plugins nor activate_plugins by default),
	 * only ever gets a plain WPORG link — never an Install button implying
	 * an action this user can't actually take.
	 */
	public function test_connectors_tab_shows_wporg_link_only_when_user_cannot_install(): void {
		$_GET['tab'] = 'connectors';
		$output      = $this->render();
		unset( $_GET['tab'] );

		$this->assertStringContainsString( 'Get it from WordPress.org', $output );
		$this->assertStringNotContainsString( 'Install Now', $output );
	}

	/** An administrator (who has install_plugins) sees a real, nonced "Install Now" link for a not-yet-installed connector. */
	public function test_connectors_tab_shows_install_button_for_administrator(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_GET['tab'] = 'connectors';
		$output      = $this->render();
		unset( $_GET['tab'] );

		$this->assertStringContainsString( 'Install Now', $output );
		$this->assertMatchesRegularExpression( '/update\.php\?action=install-plugin(?:&amp;|&#038;|&)plugin=webmention(?:&amp;|&#038;|&)_wpnonce=/', $output );
	}

	/** A connector that's installed and active shows an "Active" state, not an Install/Activate action. */
	public function test_connectors_tab_shows_active_state_for_an_active_connector(): void {
		$this->install_fake_plugin( 'webmention', 'webmention.php' );

		$filter = static function ( $value ) {
			$value   = (array) $value;
			$value[] = 'webmention/webmention.php';

			return $value;
		};
		add_filter( 'option_active_plugins', $filter );

		$_GET['tab'] = 'connectors';
		$output      = $this->render();
		unset( $_GET['tab'] );

		remove_filter( 'option_active_plugins', $filter );
		$this->remove_fake_plugin( 'webmention' );

		$this->assertStringContainsString( 'Active', $output );
		$this->assertStringNotContainsString( 'Install Now', $output );
	}

	/** A connector that's installed but not active shows its own status text, distinct from "not installed" or "active." */
	public function test_connectors_tab_shows_inactive_state_for_installed_but_inactive_connector(): void {
		$this->install_fake_plugin( 'activitypub', 'activitypub.php' );

		$_GET['tab'] = 'connectors';
		$output      = $this->render();
		unset( $_GET['tab'] );

		$this->remove_fake_plugin( 'activitypub' );

		$this->assertStringContainsString( 'Installed, not active.', $output );
	}

	/** An administrator sees a real, nonced Activate link for an installed-but-inactive connector. */
	public function test_connectors_tab_shows_activate_button_for_administrator(): void {
		$this->install_fake_plugin( 'activitypub', 'activitypub.php' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_GET['tab'] = 'connectors';
		$output      = $this->render();
		unset( $_GET['tab'] );

		$this->remove_fake_plugin( 'activitypub' );

		$this->assertMatchesRegularExpression( '/>Activate</', $output );
		$this->assertMatchesRegularExpression( '/plugins\.php\?action=activate(?:&amp;|&#038;|&)plugin=activitypub%2Factivitypub\.php/', $output );
	}

	/**
	 * A republished/renamed connector build (issue #342 — reported for
	 * ATmosphere specifically: "By Automattic", installed under a folder
	 * that no longer matches its historical `wordpress-atmosphere` slug)
	 * still reports 'active', because connector_status() falls back to
	 * Daymark_Plugin_Detector::matches() against the connector's own
	 * classes/constants signals once the folder-slug lookup finds nothing.
	 *
	 * Exercised via Reflection with a synthetic connector array — pointing
	 * at the generic fixture class tests/class-plugin-detector-stub.php
	 * declares, never the real ATmosphere plugin's own `Atmosphere\Publisher`
	 * name — rather than through the real Connectors tab render: aliasing
	 * that real, production-checked class name into place for this test
	 * previously left it defined for the rest of the same PHPUnit process,
	 * which made Daymark_Publish_Helpers's own ATmosphere detection report
	 * "active" for every other test file that ran after it (broke
	 * Test_Publish_Helpers::test_detects_nothing_by_default in CI). No fake
	 * plugin folder is installed for this test — connector_status() must
	 * reach the fallback branch on its own.
	 */
	public function test_connector_status_falls_back_to_class_signal_when_folder_slug_does_not_match(): void {
		$method = new ReflectionMethod( $this->admin_subscriptions, 'connector_status' );

		$status = $method->invoke(
			$this->admin_subscriptions,
			array(
				'folder_slug' => 'daymark-test-nonexistent-connector-folder',
				'classes'     => array( 'Daymark_Test_Fake_Connector_Class' ),
			)
		);

		$this->assertSame( 'active', $status );
	}
}
