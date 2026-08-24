<?php
/**
 * Daymark_Subscriptions table tests (issue #78 — the daymark_subscription
 * custom DB table only).
 *
 * @package Daymark
 */

/**
 * CRUD round-trip and duplicate-feed-url rejection for the
 * `daymark_subscriptions` table.
 */
class Test_Subscriptions extends WP_UnitTestCase {

	/** @var Daymark_Subscriptions */
	private $subscriptions;

	public function set_up(): void {
		parent::set_up();

		// The table is normally created on plugin activation; tests run
		// against a live WP install where activation hooks don't fire, so
		// install() is called directly (it is idempotent either way).
		Daymark_Subscriptions::install();

		$this->subscriptions = new Daymark_Subscriptions();
	}

	/**
	 * Scenario: install() creates a table with the expected columns.
	 *
	 * Verified via DESCRIBE rather than SHOW TABLES: the WP core test suite
	 * transparently rewrites CREATE TABLE to CREATE TEMPORARY TABLE for
	 * test isolation (see WP_UnitTestCase_Base::_create_temporary_tables()),
	 * and MySQL's SHOW TABLES does not list temporary tables — DESCRIBE
	 * (and ordinary DML) works against them exactly as it would a real one.
	 */
	public function test_install_creates_table() {
		global $wpdb;

		$table   = Daymark_Subscriptions::table_name();
		$columns = $wpdb->get_col( "DESCRIBE {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Test assertion against a custom table's schema; table name only, not user input.

		$this->assertSame(
			array(
				'id',
				'site_url',
				'feed_url',
				'source_type',
				'site_title',
				'site_icon_url',
				'status',
				'consecutive_failure_count',
				'last_checked_at',
				'last_manual_refresh_at',
				'created_at',
			),
			$columns
		);
	}

	/** Scenario: create -> get -> update -> delete round trip. */
	public function test_crud_round_trip() {
		$id = $this->subscriptions->create(
			array(
				'site_url'      => 'https://example.com/',
				'feed_url'      => 'https://example.com/feed/',
				'source_type'   => 'feed',
				'site_title'    => 'Example Site',
				'site_icon_url' => 'https://example.com/favicon.ico',
			)
		);

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );

		$row = $this->subscriptions->get( $id );
		$this->assertIsArray( $row );
		$this->assertSame( 'https://example.com/', $row['site_url'] );
		$this->assertSame( 'https://example.com/feed/', $row['feed_url'] );
		$this->assertSame( 'feed', $row['source_type'] );
		$this->assertSame( 'Example Site', $row['site_title'] );
		$this->assertSame( 'active', $row['status'] );
		$this->assertSame( '0', (string) $row['consecutive_failure_count'] );
		$this->assertNotEmpty( $row['created_at'] );

		$by_feed_url = $this->subscriptions->get_by_feed_url( 'https://example.com/feed/' );
		$this->assertIsArray( $by_feed_url );
		$this->assertSame( $id, (int) $by_feed_url['id'] );

		$active = $this->subscriptions->get_active();
		$this->assertCount( 1, $active );
		$this->assertSame( $id, (int) $active[0]['id'] );

		$updated = $this->subscriptions->update(
			$id,
			array(
				'status'                    => 'error',
				'consecutive_failure_count' => 3,
				'last_checked_at'           => '2026-01-01 00:00:00',
			)
		);
		$this->assertTrue( $updated );

		$row = $this->subscriptions->get( $id );
		$this->assertSame( 'error', $row['status'] );
		$this->assertSame( '3', (string) $row['consecutive_failure_count'] );
		$this->assertSame( '2026-01-01 00:00:00', $row['last_checked_at'] );

		// An error-status subscription no longer appears in the active list.
		$this->assertCount( 0, $this->subscriptions->get_active() );

		$this->assertTrue( $this->subscriptions->delete( $id ) );
		$this->assertNull( $this->subscriptions->get( $id ) );
	}

	/** Scenario: consecutive_failure_count increments and resets. */
	public function test_failure_count_increment_and_reset() {
		$id = $this->subscriptions->create( array( 'feed_url' => 'https://example.org/feed/' ) );
		$this->assertIsInt( $id );

		$this->assertSame( 1, $this->subscriptions->increment_failure_count( $id ) );
		$this->assertSame( 2, $this->subscriptions->increment_failure_count( $id ) );

		$row = $this->subscriptions->get( $id );
		$this->assertSame( '2', (string) $row['consecutive_failure_count'] );

		$this->assertTrue( $this->subscriptions->reset_failure_count( $id ) );
		$row = $this->subscriptions->get( $id );
		$this->assertSame( '0', (string) $row['consecutive_failure_count'] );
	}

	/**
	 * Scenario: inserting a second row with a feed_url already in use fails
	 * cleanly with a WP_Error — not a fatal, and no second row is created —
	 * whether caught by the application-level pre-check or (in a race) by
	 * the table's own UNIQUE index.
	 */
	public function test_duplicate_feed_url_rejected_cleanly() {
		$feed_url = 'https://duplicate-example.com/feed/';

		$first = $this->subscriptions->create( array( 'feed_url' => $feed_url ) );
		$this->assertIsInt( $first );

		$second = $this->subscriptions->create( array( 'feed_url' => $feed_url ) );
		$this->assertWPError( $second );
		$this->assertSame( 'daymark_subscription_duplicate', $second->get_error_code() );

		global $wpdb;
		$table = Daymark_Subscriptions::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion counting rows in a custom table.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE feed_url = %s", $feed_url ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table name only, not user input.
		);
		$this->assertSame( 1, $count );
	}

	/** Scenario: an empty/missing feed_url is rejected rather than inserted. */
	public function test_missing_feed_url_rejected() {
		$result = $this->subscriptions->create( array( 'site_url' => 'https://example.com/' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'daymark_subscription_invalid_feed_url', $result->get_error_code() );
	}

	/** Scenario: an unrecognized status falls back to 'active' rather than storing garbage. */
	public function test_invalid_status_falls_back_to_active() {
		$id = $this->subscriptions->create(
			array(
				'feed_url' => 'https://example.net/feed/',
				'status'   => 'not-a-real-status',
			)
		);
		$this->assertIsInt( $id );

		$row = $this->subscriptions->get( $id );
		$this->assertSame( 'active', $row['status'] );
	}

	/**
	 * Scenario: get_flagged() returns only `status = 'error'` rows, and
	 * leaves active rows out — the mirror image of get_active(). This is
	 * the query Daymark_Notifications::get_notifications() (issue #78,
	 * "Dead feed detection") builds `dead_feed` items from.
	 */
	public function test_get_flagged_returns_only_error_status() {
		$active_id = $this->subscriptions->create( array( 'feed_url' => 'https://active-example.com/feed/' ) );
		$this->assertIsInt( $active_id );

		$flagged_id = $this->subscriptions->create( array( 'feed_url' => 'https://flagged-example.com/feed/' ) );
		$this->assertIsInt( $flagged_id );
		$this->assertTrue(
			$this->subscriptions->update(
				$flagged_id,
				array(
					'status'                    => 'error',
					'consecutive_failure_count' => 7,
					'last_checked_at'           => '2026-01-01 00:00:00',
				)
			)
		);

		$flagged = $this->subscriptions->get_flagged();

		$this->assertCount( 1, $flagged );
		$this->assertSame( $flagged_id, (int) $flagged[0]['id'] );
		$this->assertSame( 'error', $flagged[0]['status'] );

		$flagged_ids = array_column( $flagged, 'id' );
		$this->assertNotContains( (string) $active_id, array_map( 'strval', $flagged_ids ) );
	}

	/** Scenario: an unrecognized source_type is still accepted (future values reserved, not enum-locked). */
	public function test_future_source_type_is_accepted() {
		$id = $this->subscriptions->create(
			array(
				'feed_url'    => 'https://example.dev/feed/',
				'source_type' => 'activitypub',
			)
		);
		$this->assertIsInt( $id );

		$row = $this->subscriptions->get( $id );
		$this->assertSame( 'activitypub', $row['source_type'] );
	}
}
