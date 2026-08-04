#!/usr/bin/env bash
# Daymark plugin — WP-CLI smoke test suite (E2E scenarios 1-10, doc 13).
# Usage: WP=/path/to/wp-cli-wrapper bash tests/smoke.sh
# Runs against a LIVE WordPress site with the daymark plugin installed.
# Creates test content and removes everything it creates on exit.

WP="${WP:-wp}"
PASS=0
FAIL=0
# Portable mktemp: GNU requires an XXXXXX template (`-t name` is BSD/macOS-only).
export DAYMARK_SMOKE_STATE="${DAYMARK_SMOKE_STATE:-$(mktemp "${TMPDIR:-/tmp}/daymark-smoke-state.XXXXXX")}"
if [ -z "$DAYMARK_SMOKE_STATE" ]; then
  echo "FATAL: could not create state temp file" >&2
  exit 1
fi
echo '{}' > "$DAYMARK_SMOKE_STATE"

say()  { printf '\n=== %s ===\n' "$1"; }
ok()   { PASS=$((PASS+1)); echo "PASS: $1"; }
bad()  { FAIL=$((FAIL+1)); echo "FAIL: $1"; }

# Run a PHP snippet through wp eval; tally PASS:/FAIL: lines it prints.
run_eval() {
  local label="$1" php="$2" out p f
  out="$("$WP" eval "$php" 2>&1)"
  echo "$out"
  p=$(grep -c '^PASS' <<<"$out" || true)
  f=$(grep -c '^FAIL' <<<"$out" || true)
  PASS=$((PASS+p))
  FAIL=$((FAIL+f))
  if [ "${p:-0}" -eq 0 ] && [ "${f:-0}" -eq 0 ]; then
    FAIL=$((FAIL+1))
    echo "FAIL: ${label} produced no assertions (fatal error above?)"
  fi
}

cleanup() {
  say "Cleanup"
  "$WP" plugin activate daymark >/dev/null 2>&1 || true
  "$WP" eval '
    $sf = getenv("DAYMARK_SMOKE_STATE");
    $state = json_decode((string) @file_get_contents($sf), true) ?: array();
    foreach (array("image_id", "note_id", "plain_note_id", "override_id", "normal_id") as $key) {
      if (!empty($state[$key])) { wp_delete_post((int) $state[$key], true); }
    }
    if (!empty($state["att_id"])) { wp_delete_attachment((int) $state["att_id"], true); }
    delete_user_meta(1, "daymark_destination_prefs");
    echo "INFO: cleanup complete (" . count(array_filter($state)) . " tracked items)\n";
  ' 2>&1 || echo "WARN: cleanup eval failed — check for leftover 'Smoke' posts"
  rm -f "$DAYMARK_SMOKE_STATE"
}
trap cleanup EXIT

# ---------------------------------------------------------------------------
say "Test 1: Plugin active + Daymark_Plugin class exists (scenario 1 precondition)"
status="$("$WP" plugin list --name=daymark --field=status 2>/dev/null)"
if [ "$status" = "active" ]; then ok "daymark plugin is active"; else bad "daymark plugin status is '$status', expected active"; fi

run_eval "class check" '
  echo class_exists("Daymark_Plugin") ? "PASS: Daymark_Plugin class exists\n" : "FAIL: Daymark_Plugin class missing\n";
'

# ---------------------------------------------------------------------------
say "Test 2: REST routes registered; unauthenticated GET /marks -> 401"
read -r -d '' PHP <<'PHP' || true
wp_set_current_user(0);
$routes = rest_get_server()->get_routes();
foreach (array(
  "/daymark/v1/marks",
  "/daymark/v1/ai/suggestions",
  "/daymark/v1/marks/(?P<id>\d+)/sync-responses",
  "/daymark/v1/notifications",
) as $route) {
  echo isset($routes[$route]) ? "PASS: route registered: {$route}\n" : "FAIL: route missing: {$route}\n";
}
$req = new WP_REST_Request("GET", "/daymark/v1/marks");
$res = rest_do_request($req);
$code = $res->get_status();
echo 401 === $code ? "PASS: unauthenticated GET /marks returns 401\n" : "FAIL: unauthenticated GET /marks returned {$code}, expected 401\n";
PHP
run_eval "REST auth" "$PHP"

# ---------------------------------------------------------------------------
say "Test 3: Publish an image Mark (scenario 2)"
read -r -d '' PHP <<'PHP' || true
wp_set_current_user(1);
$sf = getenv("DAYMARK_SMOKE_STATE");
$state = json_decode((string) @file_get_contents($sf), true) ?: array();
$tmp = wp_tempnam("daymark-smoke.png");
$img = imagecreatetruecolor(64, 64);
imagefilledrectangle($img, 0, 0, 63, 63, imagecolorallocate($img, 30, 120, 200));
imagepng($img, $tmp);
imagedestroy($img);
$files = array("daymark_media" => array(
  "name" => "daymark-smoke-test.png",
  "type" => "image/png",
  "tmp_name" => $tmp,
  "error" => 0,
  "size" => (int) filesize($tmp),
));
$post_id = Daymark_Plugin::instance()->publisher->publish(array("caption" => "Smoke test image daymark"), $files);
if (is_wp_error($post_id)) { echo "FAIL: image publish returned WP_Error: " . $post_id->get_error_message() . "\n"; return; }
$state["image_id"] = (int) $post_id;
$post = get_post($post_id);
echo "post" === $post->post_type ? "PASS: image Mark is post_type=post\n" : "FAIL: post_type is {$post->post_type}, expected post\n";
echo "publish" === $post->post_status ? "PASS: image Mark is published\n" : "FAIL: post_status is {$post->post_status}, expected publish\n";
echo "1" === get_post_meta($post_id, "_daymark_is_mark", true) ? "PASS: _daymark_is_mark = 1\n" : "FAIL: _daymark_is_mark not 1\n";
$type = get_post_meta($post_id, "_daymark_primary_type", true);
echo "image" === $type ? "PASS: _daymark_primary_type = image\n" : "FAIL: _daymark_primary_type is {$type}, expected image\n";
$media_ids = json_decode((string) get_post_meta($post_id, "_daymark_media_ids", true), true);
if (is_array($media_ids) && count($media_ids) === 1) {
  echo "PASS: _daymark_media_ids has 1 attachment\n";
  $att = (int) $media_ids[0];
  $state["att_id"] = $att;
  $att_post = get_post($att);
  echo $att_post && "attachment" === $att_post->post_type ? "PASS: attachment exists in Media Library\n" : "FAIL: attachment {$att} missing\n";
  echo $att_post && (int) $att_post->post_parent === (int) $post_id ? "PASS: attachment is child of Mark post\n" : "FAIL: attachment parent is " . ($att_post ? $att_post->post_parent : "n/a") . ", expected {$post_id}\n";
  echo (int) get_post_thumbnail_id($post_id) === $att ? "PASS: attachment set as featured image\n" : "FAIL: featured image is " . get_post_thumbnail_id($post_id) . ", expected {$att}\n";
  $file = get_attached_file($att);
  echo $file && file_exists($file) ? "PASS: attachment file exists on disk\n" : "FAIL: attachment file missing on disk\n";
} else {
  echo "FAIL: _daymark_media_ids invalid: " . wp_json_encode($media_ids) . "\n";
}
file_put_contents($sf, wp_json_encode($state));
PHP
run_eval "image Mark" "$PHP"

# ---------------------------------------------------------------------------
say "Test 4: Publish a note Mark, caption only (scenario 3)"
read -r -d '' PHP <<'PHP' || true
wp_set_current_user(1);
$sf = getenv("DAYMARK_SMOKE_STATE");
$state = json_decode((string) @file_get_contents($sf), true) ?: array();
$post_id = Daymark_Plugin::instance()->publisher->publish(array("caption" => "Smoke test note daymark", "syndication_targets" => array("bluesky")));
if (is_wp_error($post_id)) { echo "FAIL: note publish returned WP_Error: " . $post_id->get_error_message() . "\n"; return; }
$state["note_id"] = (int) $post_id;
$post = get_post($post_id);
echo "post" === $post->post_type ? "PASS: note Mark is post_type=post\n" : "FAIL: post_type is {$post->post_type}\n";
echo "1" === get_post_meta($post_id, "_daymark_is_mark", true) ? "PASS: note marked as Mark\n" : "FAIL: note not marked as Mark\n";
$type = get_post_meta($post_id, "_daymark_primary_type", true);
echo "note" === $type ? "PASS: caption-only Mark detected as type note\n" : "FAIL: _daymark_primary_type is {$type}, expected note\n";
$targets = json_decode((string) get_post_meta($post_id, "_daymark_syndication_targets", true), true);
echo is_array($targets) && in_array("bluesky", $targets, true) ? "PASS: explicit bluesky target stored\n" : "FAIL: note targets are " . wp_json_encode($targets) . ", expected to contain bluesky\n";
$external = json_decode((string) get_post_meta($post_id, "_daymark_external_posts", true), true);
echo is_array($external) && isset($external["bluesky"]) ? "PASS: mocked syndication stored bluesky external post reference\n" : "FAIL: _daymark_external_posts missing bluesky: " . wp_json_encode($external) . "\n";
$sstatus = get_post_meta($post_id, "_daymark_syndication_status", true);
echo "mocked" === $sstatus ? "PASS: _daymark_syndication_status = mocked\n" : "FAIL: syndication status is {$sstatus}, expected mocked\n";
// Auto-applied defaults only target CONNECTED connectors: with none
// configured, a caption-only publish records the model default but
// syndicates nowhere.
$plain_id = Daymark_Plugin::instance()->publisher->publish(array("caption" => "Smoke test unconnected defaults"));
if (is_wp_error($plain_id)) { echo "FAIL: plain note publish returned WP_Error\n"; return; }
$state["plain_note_id"] = (int) $plain_id;
$plain_targets = json_decode((string) get_post_meta($plain_id, "_daymark_syndication_targets", true), true);
$plain_defaults = json_decode((string) get_post_meta($plain_id, "_daymark_default_destinations", true), true);
echo array() === $plain_targets ? "PASS: no-selection publish targets nothing (bluesky not connected)\n" : "FAIL: no-selection targets are " . wp_json_encode($plain_targets) . ", expected []\n";
echo is_array($plain_defaults) && in_array("bluesky", $plain_defaults, true) ? "PASS: model default (bluesky) still recorded in _daymark_default_destinations\n" : "FAIL: _daymark_default_destinations is " . wp_json_encode($plain_defaults) . "\n";
echo "not_attempted" === get_post_meta($plain_id, "_daymark_syndication_status", true) ? "PASS: no-selection publish syndication status not_attempted\n" : "FAIL: status is " . get_post_meta($plain_id, "_daymark_syndication_status", true) . "\n";
$prefs = get_user_meta(1, "daymark_destination_prefs", true);
echo is_array($prefs) && isset($prefs["note"]) && in_array("bluesky", $prefs["note"], true) ? "PASS: explicit note selection remembered in destination prefs\n" : "FAIL: destination prefs are " . wp_json_encode($prefs) . "\n";
file_put_contents($sf, wp_json_encode($state));
PHP
run_eval "note Mark" "$PHP"

# ---------------------------------------------------------------------------
say "Test 5: Registry defaults by type (scenario 4)"
read -r -d '' PHP <<'PHP' || true
$r = Daymark_Syndication_Registry::instance();
$cases = array(
  "note"    => array("bluesky"),
  "image"   => array("instagram"),
  "gallery" => array("instagram"),
  "video"   => array("youtube"),
  "audio"   => array(),
  "mixed"   => array(),
);
foreach ($cases as $type => $expected) {
  $actual = $r->get_defaults_for_type($type);
  echo $actual === $expected
    ? "PASS: defaults for {$type} = " . wp_json_encode($expected) . "\n"
    : "FAIL: defaults for {$type} are " . wp_json_encode($actual) . ", expected " . wp_json_encode($expected) . "\n";
}
$connectors = $r->get_connectors();
echo 7 === count($connectors) ? "PASS: 7 built-in connectors registered\n" : "FAIL: " . count($connectors) . " connectors registered, expected 7\n";
PHP
run_eval "registry defaults" "$PHP"

# ---------------------------------------------------------------------------
say "Test 6: Explicit empty target selection is respected (scenario 5)"
read -r -d '' PHP <<'PHP' || true
wp_set_current_user(1);
$sf = getenv("DAYMARK_SMOKE_STATE");
$state = json_decode((string) @file_get_contents($sf), true) ?: array();
$post_id = Daymark_Plugin::instance()->publisher->publish(array(
  "caption" => "Smoke override note",
  "syndication_targets" => array(),
));
if (is_wp_error($post_id)) { echo "FAIL: override publish returned WP_Error: " . $post_id->get_error_message() . "\n"; return; }
$state["override_id"] = (int) $post_id;
$targets = json_decode((string) get_post_meta($post_id, "_daymark_syndication_targets", true), true);
echo array() === $targets ? "PASS: explicit empty selection stored as []\n" : "FAIL: targets are " . wp_json_encode($targets) . ", expected []\n";
$defaults = json_decode((string) get_post_meta($post_id, "_daymark_default_destinations", true), true);
echo is_array($defaults) && in_array("bluesky", $defaults, true) ? "PASS: defaults remain stored for future Marks\n" : "FAIL: stored defaults are " . wp_json_encode($defaults) . "\n";
$sstatus = get_post_meta($post_id, "_daymark_syndication_status", true);
echo "not_attempted" === $sstatus ? "PASS: no syndication attempted with empty selection\n" : "FAIL: syndication status is {$sstatus}, expected not_attempted\n";
file_put_contents($sf, wp_json_encode($state));
PHP
run_eval "target override" "$PHP"

# ---------------------------------------------------------------------------
say "Test 7: AI Assist mock fallback + real path report (scenario 6)"
read -r -d '' PHP <<'PHP' || true
add_filter("wp_supports_ai", "__return_false");
$ctx = array("text" => "Morning walk in the park", "media_count" => 1, "media_types" => array("image"));
$s = (new Daymark_AI_Assist())->get_suggestions($ctx);
$keys_ok = isset($s["caption"], $s["alt_text"], $s["tags"], $s["is_mocked"], $s["provider_label"]);
echo $keys_ok ? "PASS: suggestion bundle has all contract keys\n" : "FAIL: bundle missing keys: " . wp_json_encode(array_keys($s)) . "\n";
echo true === $s["is_mocked"] ? "PASS: wp_supports_ai=false forces is_mocked=true\n" : "FAIL: is_mocked is " . wp_json_encode($s["is_mocked"]) . ", expected true\n";
echo "Demo Mode" === $s["provider_label"] ? "PASS: mock provider label is Demo Mode\n" : "FAIL: provider_label is {$s["provider_label"]}\n";
$s2 = (new Daymark_AI_Assist())->get_suggestions($ctx);
echo $s === $s2 ? "PASS: mock suggestions are deterministic\n" : "FAIL: mock suggestions differ between identical calls\n";
remove_filter("wp_supports_ai", "__return_false");
$real = new Daymark_AI_Assist();
if (!$real->is_available()) {
  echo "INFO: real AI path not available on this site (no configured provider) — mock-only\n";
} else {
  echo "INFO: real AI path available via provider: " . $real->get_provider_label() . "\n";
  $rs = $real->get_suggestions(array("text" => "Quick smoke test note", "media_count" => 0, "media_types" => array()));
  if (empty($rs["is_mocked"])) {
    echo "INFO: real path returned live suggestions (is_mocked=false) from " . $rs["provider_label"] . "\n";
  } else {
    echo "INFO: real path fell back to mock (provider error) — acceptable, publishing not blocked\n";
  }
}
PHP
run_eval "AI Assist" "$PHP"

# ---------------------------------------------------------------------------
say "Test 8: Conversation backflow + notifications scope (scenarios 7, 8)"
read -r -d '' PHP <<'PHP' || true
wp_set_current_user(1);
$sf = getenv("DAYMARK_SMOKE_STATE");
$state = json_decode((string) @file_get_contents($sf), true) ?: array();
$note_id = (int) ($state["note_id"] ?? 0);
if (!$note_id) { echo "FAIL: no note Mark from Test 4 to sync\n"; return; }

$normal_id = wp_insert_post(array(
  "post_type" => "post", "post_status" => "publish",
  "post_title" => "Smoke normal post", "post_content" => "Not a mark",
));
$state["normal_id"] = (int) $normal_id;
file_put_contents($sf, wp_json_encode($state));
$normal_comment = wp_insert_comment(array(
  "comment_post_ID" => $normal_id, "comment_content" => "Normal post comment",
  "comment_author" => "Smoke Tester", "comment_approved" => 1,
));

$nonce = wp_create_nonce("wp_rest");
$req = new WP_REST_Request("POST", "/daymark/v1/marks/{$note_id}/sync-responses");
$req->set_header("X-WP-Nonce", $nonce);
$req->set_param("networks", array("bluesky"));
$res = rest_do_request($req);
$data = $res->get_data();
echo 200 === $res->get_status() ? "PASS: sync-responses returned 200\n" : "FAIL: sync-responses returned " . $res->get_status() . "\n";
$count = (int) ($data["imported_count"] ?? 0);
echo $count >= 1 ? "PASS: sync imported {$count} response(s)\n" : "FAIL: imported_count is {$count}, expected >= 1\n";

$req2 = new WP_REST_Request("GET", "/daymark/v1/notifications");
$req2->set_header("X-WP-Nonce", $nonce);
$items = rest_do_request($req2)->get_data();
$labels = array_column($items, "source_label");
$ids = array_map("intval", array_column($items, "comment_ID"));
echo in_array("Reply from Bluesky", $labels, true) ? "PASS: notifications include 'Reply from Bluesky' item\n" : "FAIL: no 'Reply from Bluesky' label in notifications: " . wp_json_encode(array_unique($labels)) . "\n";
echo !in_array((int) $normal_comment, $ids, true) ? "PASS: normal post comment excluded from Daymark notifications\n" : "FAIL: normal post comment {$normal_comment} leaked into notifications\n";

$req3 = new WP_REST_Request("POST", "/daymark/v1/marks/{$note_id}/sync-responses");
$req3->set_header("X-WP-Nonce", $nonce);
$req3->set_param("networks", array("bluesky"));
$data3 = rest_do_request($req3)->get_data();
$count3 = (int) ($data3["imported_count"] ?? -1);
echo 0 === $count3 ? "PASS: repeat sync deduplicated (imported_count=0)\n" : "FAIL: repeat sync imported {$count3}, expected 0\n";

$req4 = new WP_REST_Request("POST", "/daymark/v1/marks/{$normal_id}/sync-responses");
$req4->set_header("X-WP-Nonce", $nonce);
$code4 = rest_do_request($req4)->get_status();
echo 404 === $code4 ? "PASS: sync against non-Mark post returns 404\n" : "FAIL: sync against non-Mark post returned {$code4}, expected 404\n";
PHP
run_eval "backflow" "$PHP"

# ---------------------------------------------------------------------------
say "Test 9: Portability — deactivate, content intact, reactivate (scenario 9)"
if "$WP" plugin deactivate daymark >/dev/null 2>&1; then ok "plugin deactivated"; else bad "plugin deactivate failed"; fi

read -r -d '' PHP <<'PHP' || true
$sf = getenv("DAYMARK_SMOKE_STATE");
$state = json_decode((string) @file_get_contents($sf), true) ?: array();
echo !class_exists("Daymark_Plugin") ? "PASS: Daymark classes not loaded while deactivated\n" : "FAIL: Daymark_Plugin still loaded after deactivation\n";
$image_id = (int) ($state["image_id"] ?? 0);
$note_id = (int) ($state["note_id"] ?? 0);
$att_id = (int) ($state["att_id"] ?? 0);
$img_post = get_post($image_id);
echo $img_post && "publish" === $img_post->post_status ? "PASS: image Mark post survives deactivation\n" : "FAIL: image Mark post {$image_id} missing after deactivation\n";
echo $img_post && false !== strpos($img_post->post_content, "wp:image") ? "PASS: post_content still standard core/image block markup\n" : "FAIL: post_content lost block markup\n";
echo "1" === get_post_meta($image_id, "_daymark_is_mark", true) ? "PASS: _daymark_is_mark meta preserved\n" : "FAIL: _daymark_is_mark meta lost\n";
$note_post = get_post($note_id);
echo $note_post && "publish" === $note_post->post_status ? "PASS: note Mark post survives deactivation\n" : "FAIL: note Mark post {$note_id} missing after deactivation\n";
$file = get_attached_file($att_id);
echo get_post($att_id) && $file && file_exists($file) ? "PASS: media remains in Media Library and on disk\n" : "FAIL: attachment {$att_id} lost after deactivation\n";
$rendered = apply_filters("the_content", $img_post ? $img_post->post_content : "");
echo false !== strpos($rendered, "<img") ? "PASS: post renders readable image markup via normal WP rendering\n" : "FAIL: rendered content has no <img>: " . substr(wp_strip_all_tags($rendered), 0, 80) . "\n";
PHP
run_eval "portability" "$PHP"

if "$WP" plugin activate daymark >/dev/null 2>&1; then ok "plugin reactivated"; else bad "plugin reactivate failed"; fi

# ---------------------------------------------------------------------------
say "Test 10: Timeline/images/notes views contain the right Marks (scenarios 2, 3)"
read -r -d '' PHP <<'PHP' || true
$sf = getenv("DAYMARK_SMOKE_STATE");
$state = json_decode((string) @file_get_contents($sf), true) ?: array();
$image_id = (int) ($state["image_id"] ?? 0);
$note_id = (int) ($state["note_id"] ?? 0);
$img_link = 'href="' . esc_url(get_permalink($image_id)) . '"';
$note_link = 'href="' . esc_url(get_permalink($note_id)) . '"';
$timeline = do_shortcode('[daymark_timeline count="50"]');
$images = do_shortcode('[daymark_images count="50"]');
$notes = do_shortcode('[daymark_notes count="50"]');
echo false !== strpos($timeline, $img_link) ? "PASS: timeline view contains the image Mark\n" : "FAIL: image Mark missing from timeline view\n";
echo false !== strpos($timeline, $note_link) ? "PASS: timeline view contains the note Mark\n" : "FAIL: note Mark missing from timeline view\n";
echo false !== strpos($images, $img_link) ? "PASS: images view contains the image Mark\n" : "FAIL: image Mark missing from images view\n";
echo false === strpos($images, $note_link) ? "PASS: note Mark excluded from images view\n" : "FAIL: note Mark leaked into images view\n";
echo false !== strpos($notes, $note_link) ? "PASS: notes view contains the note Mark\n" : "FAIL: note Mark missing from notes view\n";
echo false === strpos($notes, $img_link) ? "PASS: image Mark excluded from notes view\n" : "FAIL: image Mark leaked into notes view\n";
PHP
run_eval "views" "$PHP"

# ---------------------------------------------------------------------------
say "Summary"
echo "PASS: $PASS"
echo "FAIL: $FAIL"
[ "$FAIL" -eq 0 ] && echo "RESULT: ALL SMOKE TESTS PASSED" || echo "RESULT: $FAIL FAILURE(S)"
exit $([ "$FAIL" -eq 0 ] && echo 0 || echo 1)
