<?php
/**
 * Test-only stand-in for the Automattic ActivityPub plugin's public API
 * (issue #439), required once from tests/bootstrap.php.
 *
 * The real plugin is not a test dependency, so Daymark_ActivityPub_Engagement
 * is exercised against minimal fakes of exactly the surface it calls:
 * `Activitypub\add_to_outbox()`, `Activitypub\user_can_activitypub()`,
 * `Activitypub\Activity\Activity`, `Activitypub\Http::get_remote_object()`,
 * `Activitypub\Collection\Outbox::undo()`, `Activitypub\Collection\Actors::get_by_id()`
 * and the `ACTIVITYPUB_PLUGIN_VERSION` constant.
 *
 * Inert by default, because a function or constant can never be undefined
 * once declared and this stays loaded for the whole PHPUnit run:
 * user_can_activitypub() returns false until a test enables a user through
 * Daymark_Test_ActivityPub_Stub, so the ActivityPub route is unavailable for
 * every other test file. Each piece is guarded, so a real plugin (if one is
 * ever loaded) wins and the stub steps aside; tests that need the fake skip
 * themselves then (Daymark_Test_ActivityPub_Stub::is_loaded()).
 *
 * Split across files because this repo's coding standards allow one class
 * per file and no functions alongside a class.
 *
 * @package Daymark
 */

require_once __DIR__ . '/class-daymark-test-activitypub-stub.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/class-activity.php';
require_once __DIR__ . '/class-http.php';
require_once __DIR__ . '/class-outbox.php';
require_once __DIR__ . '/class-daymark-test-activitypub-actor.php';
require_once __DIR__ . '/class-actors.php';
