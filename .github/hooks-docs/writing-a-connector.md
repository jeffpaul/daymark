---
id: writing-a-connector
title: Writing a Connector
sidebar_label: Writing a Connector
sidebar_position: 1
---

# Writing a Connector

Daymark ships with seven built-in **mocked** destinations (Bluesky, Mastodon,
Instagram, YouTube, TikTok, Threads, X) — none of them make a real network
call. Core Daymark deliberately owns no social API credentials or OAuth flows
(see the plugin's non-goals). A real destination is added by a separate
plugin that implements one interface and registers itself on init — this
page is a complete, minimal example of doing that.

## The contract

A connector implements `Daymark_Syndication_Connector`
(`includes/connectors/interface-syndication-connector.php`):

```php
interface Daymark_Syndication_Connector {
    public function get_id(): string;
    public function get_label(): string;
    public function supports_daymark_type( string $type ): bool;
    public function is_connected(): bool;
    public function publish( int $post_id, array $payload ): array;
    public function get_status_label(): string;
}
```

- `get_id()` — a unique machine slug, e.g. `'my-network'`.
- `get_label()` — the human-readable name shown in the composer's
  destination list.
- `supports_daymark_type( $type )` — whether this destination can represent
  a given Mark type (`note`, `image`, `video`, `audio`, `gallery`, `mixed`).
  Daymark checks this both in the UI (to grey out an unsupported toggle) and
  again server-side before ever calling `publish()`.
- `is_connected()` — `true` only once real credentials are configured. A
  connector that returns `false` here is never offered as a publish
  destination and never receives type-based auto-defaults (see
  "Destination visibility" in CLAUDE.md) — it can still be selected
  explicitly via the REST API, in which case `publish()` should fail
  gracefully rather than assume a live session.
- `publish( $post_id, $payload )` — **must never throw**. Always return a
  result array, even on failure.
- `get_status_label()` — a short UI string, e.g. `'Connected'` or
  `'Not connected'`.

## The `publish()` payload

`$payload` is the same Mark context Daymark's own `daymark_published` action
receives — build against these keys, not undocumented internals:

```php
array(
    'post_id'              => 123,
    'primary_type'         => 'image',              // note|image|video|audio|gallery|mixed
    'media_ids'            => array( 45, 46 ),       // attachment IDs, in display order
    'caption'              => 'A day at the coast.',
    'syndication_targets'  => array( 'my-network' ), // every selected destination this publish
    'default_destinations' => array( 'my-network' ), // type-based defaults that applied
    'post_status'          => 'publish',             // or 'draft' — drafts never syndicate
    'ai_assist_used'       => false,
    'created_from'         => 'mobile',
)
```

Resolve the Mark's permalink and any per-image alt text from `$post_id`
itself (`get_permalink()`, `get_post_meta()`) rather than expecting them on
the payload — it deliberately carries only what routing/composition needs,
not the full post.

## The result shape

```php
return array(
    'success'             => true,
    'external_id'         => 'abc123',
    'external_url'        => 'https://example.com/posts/abc123',
    'status'              => 'published',   // 'published' | 'mocked' | 'failed'
    'message'             => '',
    'backflow_supported'  => true,          // optional — omit or false if replies can't be pulled back
);
```

Every attempted destination is recorded in `_daymark_external_posts` —
success, failure, an unsupported Mark type, or a destination that's since
been deactivated all show up there and in the Timeline's own routing
popover (see "Per-Mark routing transparency" in CLAUDE.md). Returning a
result rather than throwing is what keeps one failing connector from ever
blocking the rest of a Mark's selected destinations.

## A minimal complete example

```php
<?php
/**
 * Plugin Name: Daymark Connector: Example Network
 */

add_action( 'daymark_register_connectors', function ( Daymark_Syndication_Registry $registry ) {
    $registry->register_connector( new My_Example_Connector() );
} );

class My_Example_Connector implements Daymark_Syndication_Connector {

    public function get_id(): string {
        return 'my-network';
    }

    public function get_label(): string {
        return __( 'My Network', 'my-network-connector' );
    }

    public function supports_daymark_type( string $type ): bool {
        return in_array( $type, array( 'note', 'image', 'gallery' ), true );
    }

    public function is_connected(): bool {
        return (bool) get_option( 'my_network_access_token' );
    }

    public function publish( int $post_id, array $payload ): array {
        $token = get_option( 'my_network_access_token' );

        if ( ! $token ) {
            return array(
                'success'      => false,
                'external_id'  => null,
                'external_url' => null,
                'status'       => 'failed',
                'message'      => __( 'My Network is not connected.', 'my-network-connector' ),
            );
        }

        $response = wp_remote_post(
            'https://api.example.com/v1/posts',
            array(
                'headers' => array( 'Authorization' => 'Bearer ' . $token ),
                'body'    => array(
                    'text'      => $payload['caption'],
                    'permalink' => get_permalink( $post_id ),
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return array(
                'success'      => false,
                'external_id'  => null,
                'external_url' => null,
                'status'       => 'failed',
                'message'      => $response->get_error_message(),
            );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        return array(
            'success'             => true,
            'external_id'         => (string) ( $body['id'] ?? '' ),
            'external_url'        => (string) ( $body['url'] ?? '' ),
            'status'              => 'published',
            'message'             => '',
            'backflow_supported'  => false,
        );
    }

    public function get_status_label(): string {
        return $this->is_connected()
            ? __( 'Connected', 'my-network-connector' )
            : __( 'Not connected', 'my-network-connector' );
    }
}
```

Register credentials through your own plugin's settings screen — core
Daymark stores none. `Daymark_Connector_Base`
(`includes/connectors/class-connector-base.php`) is available to extend if
a mock-only connector is all a given destination needs, but a real
connector generally implements the interface directly, as above.

## Where this fits

- `daymark_register_connectors` fires on `init`, after Daymark's own seven
  built-in connectors are already registered — see
  `Daymark_Syndication_Registry` in the Hooks reference for the action
  itself.
- `daymark_default_destinations` lets a connector plugin (or a site's own
  `functions.php`) add itself to the type-based auto-selected defaults
  without touching core.
- `daymark_syndication_complete` fires once every selected destination for
  a Mark has been attempted, with the full results array — useful for
  logging or a follow-up action, not for changing what was published.

See the Hooks reference for the exact signature of each of these.
