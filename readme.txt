=== Daymark ===
Contributors:      jeffpaul
Tags:              publishing, mobile, pwa, syndication, indieweb
Requires at least: 7.0
Tested up to:      7.1
Requires PHP:      8.2
Stable tag:        0.19.0
License:           GPL-2.0-or-later
License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html

Publish to your own site as fast as you'd post to a social app: photos, videos, voice notes, and quick thoughts, all from your phone, all truly yours.

== Description ==

Your phone is full of moments worth sharing. Daymark makes your own WordPress site the fastest, most natural place to share them — no app store, no algorithm, no platform that can change the rules on you tomorrow.

Open Daymark on your phone, tap to capture a photo, a video, or a quick voice note (or pick one you already took), add a caption, and publish. That's it. What you publish lives on your own site, under your own name, for as long as you want it there.

= Why people love publishing with Daymark =

* **It feels like your favorite social app — because your site deserves to.** Add Daymark to your phone's home screen and it opens like a real app: fast, focused, and built for one-handed use. No admin menus, no clutter — just capture, caption, and go.
* **Your camera is one tap away.** Choose Photo, Video, or Audio and Daymark opens your camera or microphone right away, ready to capture the moment. Already have the shot? Grabbing it from your library is just as easy.
* **Share to Daymark from other apps on Android.** Found something worth posting in Photos, Chrome, or any other app? Use your phone's own Share button and send it straight to Daymark — it's waiting for your caption before you've even opened the app. (iPhone and iPad don't let web apps receive shares yet.)
* **Follow the sites you love.** Subscribe to any blog or site with a feed, and its new posts appear in your Daymark Timeline beside your own. Like, comment, reblog, or bookmark them without leaving the app.
* **You never lose your work.** Start a caption, get interrupted, lose your signal on the subway — Daymark quietly saves everything as you go. Publish with no connection at all and it goes out the moment you're back online. Nothing you write is ever at risk of disappearing.
* **Tap Publish and move on with your day.** You're never stuck staring at a spinner, even for a big video or a whole gallery of photos — Daymark confirms instantly and finishes the upload quietly in the background.
* **It's genuinely yours, for good.** Everything you publish is a real WordPress post, not a locked-in, proprietary format. Your content works with your theme, your feeds, your backups — and stays exactly where it is even if you ever stop using Daymark.
* **Reach further, without extra work.** Already use a plugin to share to Bluesky, Mastodon, or elsewhere? Daymark works alongside it, and lets you choose per-post where each one of your posts should also go. Replies from those networks flow back to you automatically, right inside Daymark.
* **A helping hand, never a replacement for yours.** Ask for a suggested caption, title, or tags, or let Daymark describe a photo for accessibility — every suggestion is yours to accept, edit, or ignore, and Daymark works exactly the same with none of it turned on.

= Your site, your rules =

Daymark never asks you to choose between "easy" and "yours." Your own site is always where a post lives first; anywhere else it appears is a bonus, never a requirement. There's no subscription, no account to create anywhere else, and nothing about what you publish depends on a company staying in business or an app staying in the store.

= A note on privacy and external services =

Daymark sends nothing to its authors: no analytics, no tracking, and no account to create. Everything you publish is stored on your own site. It contacts outside services only for something you did, or because a visitor's browser needs to draw a page, and every one of them is listed under "External services" below with what it receives and when.

Sharing to other networks happens only through the publishing plugins you choose to install yourself (such as ActivityPub or Webmention). Daymark doesn't post to any social network directly. Optional AI suggestions go through WordPress's own AI tools and whichever provider you've configured, and Daymark never sees or stores an API key of its own.

You're in control of the location-related services: see "What does Daymark quietly capture, and can I turn it off?" below to see exactly what's captured and to turn any of it off in Settings -> Daymark -> Data & privacy.

= External services =

**Sites you follow.** When you subscribe to a site, and afterward on the schedule you choose in Settings -> Daymark, your site requests that site's feed (RSS, Atom, or JSON Feed), home page, or public REST API, and the full page of a post when someone opens it. These requests carry your site's address in the User-Agent (`Daymark/<version>; https://yoursite.example/`), as WordPress's own requests do. If a feed advertises a WebSub hub, your site also sends that hub a subscription request containing the feed address, a callback address on your site, and a one-time secret, so the hub can push new posts. Each site or hub you choose to follow has its own terms and privacy policy.

**Link previews and embeds.** When a post you're reading links to another page, or replies to one, Daymark may fetch that page (for its Open Graph title, description, and image) or ask the link's oEmbed provider, such as YouTube or Vimeo, for an embed. Only the link's address is sent. A video or audio link you paste into Featured Content in the block editor works the same way; for a post whose author can't publish unfiltered HTML (an Author or Contributor), only the providers WordPress already trusts are used. To give such a post a share image, your site also asks that provider for the video's thumbnail, or fetches a Featured Content link's page for its Open Graph image, title, and description (shown as a link preview on the post's page and in the Daymark app), once each time the Featured Content changes, or, if that didn't happen, the first time someone signed in to Daymark sees the post's card in the app. When you open the Daymark bookmarklet on a page, your site does the same for that page: it asks for an embed and checks whether the page's site can receive a Like, the same check a Like in the app makes. Visitors to your site never trigger these requests. The provider's own terms and privacy policy apply. If the Parse This plugin is active, Daymark asks it to read pages Daymark has already fetched; Parse This makes no requests of its own for Daymark.

**Bookmarked posts' images.** When you bookmark a post for offline reading and it has images hosted on another site, your site downloads those images (only the ones in that post) and hands them to the app to save on your device. The request carries your site's address in the User-Agent, as WordPress's own requests do. The image host's own terms and privacy policy apply.

**Comments and likes on posts you follow.** What is sent depends on what the other site supports.

* If you use the Webmention or ActivityPub plugins, they send your reply or like themselves.
* Without the Webmention plugin, Daymark sends Webmentions itself. When you publish or update a post, your site sends each site the post links to (including a post you liked, reblogged, or replied to) two addresses: your post's and the linked page's. That site then fetches your post to check it.
* If you've marked your site as bridged with Bridgy Fed (Settings -> Daymark -> Connectors), a like or reblog of a fediverse or Bluesky post goes to fed.brid.gy as a Webmention with your like's own address. Bridgy Fed then reads that page and delivers the like. See https://fed.brid.gy/docs for its terms and privacy policy.
* Otherwise Daymark posts your comment to the other WordPress site's public comments endpoint, sending the comment text, your display name, your account email address, and your site's address, the same details WordPress's own comment form collects.
* If you have linked a WordPress.com account through Jetpack, likes and comments on WordPress.com sites go through WordPress.com using that connection (the post and your comment text), and Daymark reads the likes your own posts have received there. See the [WordPress.com Terms of Service](https://wordpress.com/tos/) and the [Automattic Privacy Policy](https://automattic.com/privacy/).

**Webmentions you receive.** Without the Webmention plugin, Daymark receives Webmentions itself. When another site says one of its pages links to one of your posts, your site fetches that page once to check the link and read the like, reblog, or reply. The request carries your site's address in the User-Agent. One site can send at most 30 an hour.

**WordPress.com Reader import.** If you have linked a WordPress.com account through Jetpack, Settings -> Daymark -> Import/Export can import the sites you follow in the WordPress.com Reader. Your site asks WordPress.com for your follow list, using your linked account, only when you click "Load my Reader follows". Nothing is sent back to WordPress.com, and the sites you then import are fetched like any other subscription. See the [WordPress.com Terms of Service](https://wordpress.com/tos/) and the [Automattic Privacy Policy](https://automattic.com/privacy/).

**OpenStreetMap Nominatim (Check In place names).** When you compose a Check In, your site asks Nominatim for a place name: it sends your captured coordinates to name where you are, and whatever you type into the Place field to search for a place. The request comes from your site's server, and its User-Agent identifies your site, as Nominatim's policy requires. Nothing is sent if you don't allow location access and don't use the Place search. See the [Nominatim usage policy](https://operations.osmfoundation.org/policies/nominatim/) and the [OpenStreetMap Foundation privacy policy](https://osmfoundation.org/wiki/Privacy_Policy).

**OpenStreetMap map images (Check In posts).** A Check In post that has a location shows a small map image loaded directly from tile.openstreetmap.org by the reader's own browser, both on your site's post page and inside Daymark. The request tells OpenStreetMap which map square is being shown (roughly a kilometre across) and the reader's IP address, so this happens for your visitors, not only for you. See the [tile usage policy](https://operations.osmfoundation.org/policies/tiles/) and the [privacy policy](https://osmfoundation.org/wiki/Privacy_Policy). Check In location can be switched off in Settings -> Daymark -> Data & privacy.

**Open-Meteo (weather).** After you publish a Check In with a captured location, your site makes one request to api.open-meteo.com with the latitude and longitude, and stores the current temperature and conditions with the post. It isn't displayed anywhere yet. Open-Meteo's free service is for non-commercial use (sites without advertising or subscriptions) under a CC BY 4.0 license, so if your site is commercial, turn weather capture off in Settings -> Daymark -> Data & privacy. See the [terms and privacy policy](https://open-meteo.com/en/terms).

**Your AI provider (only if you've configured one).** If you've set up an AI provider in WordPress, Daymark sends it text and files through WordPress's own AI Client: your caption, the post type, and an excerpt of any transcript to suggest titles, captions, and tags; the images you pick, to suggest alt text; and audio, only when you ask for a transcript. Tag suggestions run automatically a moment after you type a caption, and alt-text suggestions run when you pick an image. To send nothing until you tap an AI button, turn off "Suggest with AI automatically" in Settings -> Daymark -> Data & privacy. Nothing is sent when no provider is configured. Your provider's own terms and privacy policy apply.

= Openly built =

Daymark's code and its full design history are public on [GitHub](https://github.com/jeffpaul/daymark), including an unusually candid record of every decision along the way — built with the help of AI coding tools, under ongoing human direction, review, and testing.

== Installation ==

1. Upload the `daymark` folder to `/wp-content/plugins/`, or install through the WordPress plugins screen.
2. Activate the plugin through the **Plugins** screen.
3. Visit `https://yoursite.example/daymark` on your phone while logged in.
4. Optional: add it to your home screen (Safari: Share → Add to Home Screen; Chrome: menu → Add to Home Screen / Install App). Standalone app display requires HTTPS.

To like a subscribed post, the other site must accept Webmentions (every Daymark site does), or you need the ActivityPub plugin or Jetpack with your WordPress.com account linked. See "Why don't I see a Like icon on subscribed posts?" below.

Activation creates no public pages of its own. Timeline, Explore, Search, and Me all live inside the authenticated `/daymark` app shell.

== Frequently Asked Questions ==

= How do I publish to social networks? =

A Mark is a standard post, so any publishing plugin you already use shares it when it publishes. Daymark detects popular ones (Jetpack Social, Share on Mastodon, ATmosphere, XPoster, Autoshare for Twitter, and more) and notes them on the publish screen; for plugins that expose a per-post control it adds an in-app on/off toggle per Mark (currently Share on Mastodon, Autoshare for Twitter, and ATmosphere for Bluesky). Replies come back through federation plugins (ActivityPub, ATmosphere, Webmention) as native comments. Daymark also exposes an open connector interface (`daymark_register_connectors`) so a plugin can register a first-class destination. Your site is always the primary destination and publishing never depends on any of this.

= Why don't I see any social networks on the publish screen? =

Daymark only offers destinations that can actually publish (and pull replies back): a network appears once a connector plugin registers it. With nothing connected, "Your Site" is the only destination — publishing to your own site always works. (Publishing plugins like Jetpack Social or Share on Mastodon aren't destinations — they appear as an awareness note or a per-Mark toggle instead.)

= How do replies come back to my site? =

If you run the ActivityPub, ATmosphere, or Webmention plugins, replies they deliver arrive as native WordPress comments and are recognized and labeled in Daymark notifications ("Reply from Bluesky", "Reply from the Fediverse", …) — by push, live, with no polling. When a polling connector is registered, an hourly background sync (plus a refresh whenever you view notifications) imports replies from your syndicated copies too, deduplicated per reply.

Commenting on a post from a site you follow works the other way round: tap Comment on the post, write your comment, and Daymark sends it. When the other site accepts Webmentions (every Daymark site does), Daymark publishes your comment as a small Mark on your site with a `u-in-reply-to` link to the post, and sends the other site a Webmention. If you've linked a WordPress.com account through Jetpack, a comment on a WordPress.com site goes through WordPress.com. If the other site can't receive your comment, Daymark opens the post so you can comment on the site itself. Daymark sends and receives Webmentions itself, for every public post on your site, so likes, reblogs, and comments travel between Daymark sites with nothing else installed. If you'd rather use the [Webmention plugin](https://wordpress.org/plugins/webmention/), install it and Daymark turns its own Webmention support off. Install ActivityPub and ATmosphere too, so replies from the fediverse and Bluesky show up in your notifications — Settings -> Daymark's Connectors tab lists all three with an Install/Activate button right there, no need to leave wp-admin. Don't want to install the ActivityPub plugin at all? [Bridgy Fed](https://fed.brid.gy/) is a free, hosted bridge — not a plugin — that gives your site a fediverse and Bluesky presence through the Webmention support above, under an auto-generated handle on its own domain rather than a native handle on yours; it's also listed on the Connectors tab, right alongside the plugin options.

= Why don't I see a Like icon on subscribed posts? =

Liking a subscribed post needs a way to actually tell the original site. Daymark only shows the Like icon when one of these is set up:

* The subscribed site accepts Webmentions. Every Daymark site does, and so does any site running the [Webmention plugin](https://wordpress.org/plugins/webmention/).
* [Jetpack](https://wordpress.org/plugins/jetpack/) is active and you've linked your own WordPress.com account (Jetpack -> My Connection). This covers subscribed sites hosted on WordPress.com or connected to Jetpack.
* The [ActivityPub plugin](https://wordpress.org/plugins/activitypub/) (8.1.0 or later) is active, your own user is enabled as an ActivityPub author, and the subscribed post is a fediverse post (Mastodon, or a site running ActivityPub). Daymark sends it a real ActivityPub Like; reblogging it also sends a boost.
* Your site is bridged with [Bridgy Fed](https://fed.brid.gy/) (check "This site is bridged with Bridgy Fed" on Settings -> Daymark -> Connectors), and the subscribed post is a fediverse or Bluesky post that doesn't accept Webmentions. The Like or Reblog goes through Bridgy Fed.

Without any of these, subscribed posts show no Like icon at all, so you never send a Like nobody receives. These plugins are listed on Settings -> Daymark -> Connectors with an Install/Activate button. A post you already liked keeps its icon so you can unlike it.

= Does Daymark work with Parse This? =

Yes, and it's optional. With [Parse This](https://wordpress.org/plugins/parse-this/) 2.0.0 or later active, Daymark reads the pages it already fetches more fully. Link previews also show the author and use a page's microformats and JSON-LD, not only its Open Graph tags. Sites you follow through their microformats are read by Parse This's complete parser instead of Daymark's simpler one. Daymark only hands Parse This pages it has already downloaded, and turns off Parse This's own extra requests while it does. Without Parse This, Daymark uses its own parsing, as before.

= Does Daymark work with the Friends plugin? =

Yes. If you already follow someone through the [Friends plugin](https://wordpress.org/plugins/friends/), subscribing to their site in Daymark reads their posts straight from Friends' own cache instead of independently re-fetching their site a second time — Friends already does the real fetching, parsing, and post-format classification for a friend, so Daymark just reuses it. This only ever applies to a friend you've already added in Friends' own UI; Daymark doesn't add friends on Friends' behalf, and a site Friends doesn't yet follow subscribes exactly as it always has (via its RSS/Atom feed, WordPress REST API, or microformats2 markup).

= Which AI providers work with AI Assist? =

Any WordPress AI Client provider plugin — Anthropic (Claude), Google (Gemini), or OpenAI (GPT). Daymark never talks to an AI vendor directly and never stores API keys; it goes through the core AI Client, and the first configured provider powers caption, title, alt text, tag, and transcript suggestions. Without a configured provider, the AI Assist UI simply does not appear.

= What does Daymark quietly capture, and can I turn it off? =

Composing a Mark quietly captures a few pieces of metadata in the background, without any field to fill in: the date/time it was created, camera details from a photo's own EXIF data (camera model, aperture, ISO, and similar), an estimated reading time for a longer caption, and AI-suggested tags. A Check In also captures your device's location (only if your browser grants permission) and the current weather there. No other kind of Mark captures a location. None of it is required, none of it can block or delay publishing, and anything that isn't available (permission denied, no EXIF data, no AI provider configured, etc.) is simply left out rather than causing an error.

A Check In's page always shows its place name and a map, so its location is public. With the Simple Location plugin active, its coordinates, place, and weather are also copied into that plugin's own data. Weather and richer photo details aren't shown anywhere in Daymark itself yet. You can turn off Check In location, weather, and camera details separately in Settings -> Daymark -> Data & privacy, with no code. Without Check In location, you type or search for the place instead.

The same tab has two more choices. "Hold imported replies" keeps replies Daymark imports from connected networks waiting for your approval. "Suggest with AI automatically" controls whether your caption and photos go to your AI provider while you write; when it's off, nothing is sent until you tap an AI button.

A developer can also set these from code, which takes priority over the checkboxes; the settings screen then shows the setting as set by code. See [the Daymark developer docs on GitHub](https://github.com/jeffpaul/daymark) for the filters.

Turning off Check In location also stops the weather lookup, since weather needs a location. "Coordinates in page markup" (off by default) adds a Check In's exact coordinates to its page as machine-readable h-geo markup, for other sites and feed readers.

= How do I follow a site? =

In the Daymark app, tap "+ Follow a site" in Explore's Following section, or "Follow a site" on the Me screen. Paste the site's address, pick one of the feeds Daymark finds, and tap Follow. You can do the same in Settings -> Daymark -> Subscriptions. New posts from the site then appear in your Daymark Timeline. Settings -> Daymark -> General sets how often Daymark checks for new posts.

To follow many sites at once, import an OPML file from your feed reader on the Import / Export tab. With Jetpack and a linked WordPress.com account, you can also import the sites you follow in the WordPress.com Reader. Following sites needs an administrator, since the list is shared by everyone on the site.

= Can I share the sites I follow as a blogroll? =

Yes, if you want to. In Settings -> Daymark -> General, check "Share the sites you follow as a public blogroll". Daymark then publishes your active subscriptions as an OPML file that feed readers can import, and links to it from every page of your site with `<link rel="blogroll">`. To show the list on a page, add the Blogroll block. It's off by default.

If your site still has entries in WordPress's old Links (blogroll) list, Settings -> Daymark -> Import / Export offers to import them as subscriptions.

= Can I keep Notes off my blog's home page? =

Yes. Notes show on the home page and in the main feed by default, like any other post. To keep them off, check "Keep Notes off your blog's home page and main feed" in Settings -> Daymark -> General. A Note is any post with the Aside post format: Notes you post from Daymark, including reblogs and replies, and Aside posts you write in the block editor. Notes then stay off the home page and the main RSS feed, but keep their own pages and still appear in archives, search, and Daymark's Timeline. Notes stay ordinary posts, so nothing changes if you deactivate Daymark. A block theme's home page follows this setting when its Query Loop block inherits the template's query, which is the usual setup.

You don't need to give a Note a title in the block editor. When you publish an Aside post with no title, Daymark gives it one from its first few words, the same way the app titles a Note, so it gets a readable web address and a title in feeds.

= What's the character count on Notes in the block editor? =

When you write a post with the Aside format, or a Standard post with no title, the block editor's Post sidebar counts down from 300 characters, the length of one Bluesky post. Up to 300 characters, it's a short post: plugins that share to Bluesky, such as ATmosphere, post its text as an ordinary social post, without a title. Past 300, it's a long post, which ATmosphere shares as a link card to it instead of its full text. If it has images, ATmosphere still posts it with its images and shortens the text. A Standard post with a title is always shared as a link card, so the counter doesn't show for it. The count runs in your browser and can differ from ATmosphere's by a few characters; ATmosphere's own panel when you click Publish is the exact check. The counter shows whether or not a Bluesky plugin is active.

= What's a Check In? =

A Check In is a Mark whose point is *where* you are, not media or a written caption — tap the "+ New Mark" launcher's Check In bubble and Daymark quietly reverse-geocodes your captured location into an editable Place field (no address lookup service credentials needed on your end — it uses a free, keyless geocoding service). You can always edit or replace the detected place before publishing, and add your own thoughts too, but neither is required: a Check In with just a place name is a complete, publishable Mark. It publishes as a real post naming the place (linking out to a map when a location was captured), titled "Checked in at {place}" when you haven't added your own caption.

If you have the [Simple Location plugin](https://wordpress.org/plugins/simple-location/) active, a Mark's captured coordinates (and a Check In's own place name) are additionally bridged into that plugin's own data the moment you publish — its reverse-geocoding, "posted from" display, and map/archive view all become available for free. When weather was also captured, its temperature and a short condition description (such as "Mostly clear") are bridged too, so Simple Location can show the weather. Settings -> Daymark's Connectors tab recommends it for exactly this.

= Does Daymark create a custom post type? =

No. Every Mark is a standard post with post meta, so your content is fully portable and remains intact and readable if you deactivate the plugin.

= What happens if I close the app or lose connection while composing? =

Your work is always safe. The composer autosaves your caption, media, alt text, and destination choices as you go, so a closed tab, a phone call, or switching apps doesn't lose it. With a connection, it saves straight to a real draft on your site — reopen Daymark and it's waiting under Drafts on the Me screen (Home shows how many you have). Without one, it saves to your device instead, shows up under Pending on Home, and publishes or saves itself automatically the moment you're back online — you don't need to do anything.

= Why doesn't Publish make me wait for a big video or gallery to finish uploading? =

It never does, for any Mark, whether or not there's a big upload involved. Tapping Publish (or Save as Draft) saves your Mark right away and takes you straight to the confirmation screen; the actual upload and any syndication happen in the background afterward. It shows at the top of your Timeline right away, marked "Uploading…", and becomes the finished post the moment the upload is done (a draft shows under Pending on Home, then moves to Drafts) — usually fast enough that you'll never even notice, but for a large video or podcast file it's the difference between an instant tap and a long wait staring at a spinner.

Files upload in small parts, starting the moment you pick them. If your connection drops, or you close the app after tapping Publish, the upload carries on from where it stopped instead of starting over. Videos and audio can be up to 500 MB each, and photos up to 50 MB.

= Can I share a photo to Daymark from another app? =

Yes, on Android, once you've installed Daymark to your home screen (the Install card on Home, or Install Daymark on the Me screen, does this in one tap). Share a photo, video, a link, or selected text from almost any app, and pick Daymark — it creates a draft with whatever you shared and opens straight into the composer so you can add a caption and publish. You need to be logged in already; the share sheet has no way to log you in first.

iPhone and iPad don't support sharing to a web app yet, so Daymark doesn't appear in their share sheet. There, open Daymark and pick the photo from the composer instead.

= Can I Reblog or Like a post I'm reading on another site? =

Yes, with the Daymark bookmarklet. Drag the Daymark button to your browser's bookmarks bar. You'll find it in Settings -> Daymark -> General, or under Me -> Reblog from anywhere in the app. On any post you're reading, click Daymark in the bookmarks bar. A small window opens on your own site with the post at the top. Write your thoughts below it, change the title if you like, and tap Reblog: the Reblog is published on your site, with the post embedded when it offers an embed. Like works when the post's site can receive one, the same as in the app. You need to be logged in to your site in that browser.

= Can I create a Mark while offline? =

Yes. Compose, add media, and tap Publish (or Save as Draft) with no connection at all — Daymark saves it on your device and shows the same confirmation screen either way. It publishes automatically as soon as you're back online; until then it waits at the top of your Timeline, marked Offline (a draft waits under Pending on Home). This covers a session already open when you go offline (or start one offline); loading `/daymark` for the very first time with zero connectivity doesn't work yet — that needs a network for the initial page load.

= Does it work offline? =

Mostly, for the part that matters most: creating a Mark works fully offline once the app is open (see the previous question). A conservative service worker also caches the app's static CSS and JS for fast loading. What doesn't work offline: loading `/daymark` for the first time with no connection, and reading your Timeline, notifications, or other people's content — those still need a connection, and REST responses, nonces, HTML, and media are never cached.

== Screenshots ==

1. Home — the phone-first app shell: your Timeline, a count of your drafts, one-tap publishing.
2. Create — pick media, add a caption, and get AI-suggested alt text for each image, editable before you publish.
3. Publish — your site is always the destination; file the Mark under categories (remembered per type), or save as a draft.
4. Notifications — replies from syndicated copies flow back automatically, labeled by source.

== Changelog ==

= 0.19.0 - 2026-10-02 =
**Added**

* The Timeline now remembers the newest post you've seen, on your account, so it follows you across devices. When you come back, it opens at that post instead of the top, with newer posts above it. A "new posts" button shows how many there are and jumps to the newest one.
* The Search screen gained a **Date** dropdown beside the existing keyword/type/source filters, reusing the same buckets the Timeline itself groups its items into (Today / This Week / Last Week / This Month / Last Month) so a label means the same thing in both places. It combines with the other filters and covers both your Marks and subscription posts, backed by the same merged `GET /timeline` endpoint.
* Explore's Timeline gained an **On this day** section showing the Marks you published on this calendar date in past years — last year's memory, the year before's, and so on, newest first — sitting between Bookmarks and Following. Backed by the same merged `GET /timeline` endpoint the rest of Explore and Timeline already use, filtered to your own Marks from prior years (nothing from today itself), so the section shares every card behavior the others already have: open the full post view, bookmark, like, comment, share. Marks you publish today won't appear here until next year.
* Publishing a Mark with a captured location and weather while the Simple Location plugin is active now also bridges the temperature and a human-readable condition text into Simple Location's own post-meta at publish time, so that plugin's weather display extends to the Mark for free — no duplicate weather fetch or storage inside Daymark. The weather code itself is deliberately not bridged, since Simple Location's icon/code vocabulary is OpenWeatherMap-derived and can't map Daymark's Open-Meteo codes; a bridged-but-wrong code would render worse than no code at all.
* Featured Content now supports a Gallery type: choose "Set featured content", pick several images in the media picker (tap each to select), and they show as an accessible carousel (swipe or click-drag, arrow keys, dots) in place of the featured image on the post's page. On the home page, archives, and search a gallery shows only its first image, and a Timeline card shows the first four as a small 2x2 grid.
* The Featured Content sidebar control (block editor) now supports two more kinds alongside audio, video, and gallery: a **quote** (text with an optional author and an http(s) source link, shown as a real `<blockquote>` with attribution in place of your featured image) and a **link** (a readable link out to a web page, available only when the post's own Format is set to Link). Add them with the "Add a quote" and "Add a link" links beside "Set featured content".
* A post whose Featured Content is a gallery, a video or audio, a quote, or a link now shares well: its image (the gallery's first photo, the video's thumbnail or cover art, or the linked page's image) becomes the post's oEmbed thumbnail and its Open Graph and Twitter Card image, and a quote becomes the share description. With Yoast SEO, Rank Math, All in One SEO, or Jetpack's sharing tags active, Daymark hands the image to that plugin instead of printing its own tags.
* You can now put a Featured Content gallery's images in the order you want. After you pick images, WordPress's own gallery editor opens, where you drag them into order (mouse or touch), remove or caption one, or add more. Replace on a gallery opens that editor with its images in their saved order. The post's carousel, its Timeline card, and the sidebar preview all follow that order.
* Opening a post from the Timeline now shows its Featured Content (a video, audio, gallery, quote, or link) or its featured image at the top of the full post view. A photo that's already in the post isn't shown twice. Posts from sites you follow show their featured image the same way. A gallery in the post itself, such as a Mark with several photos, also shows as a slider there instead of stacked photos. Gallery sliders show each photo's caption under it. A bookmarked post's gallery works as a slider offline too.
* Gallery cards on the Timeline now show up to four photos as a 2x2 grid of rounded tiles, with "+N" when there are more. This covers gallery Marks, Check Ins with several photos, posts whose Featured Content is a gallery, and gallery posts from sites you follow, which previously showed a single photo or a short strip.
* A post with a featured image, from a site you follow or an ordinary post on your own site, now shows it as a full-width photo at the top of its Timeline card, the same way your own photo Marks do, instead of a small thumbnail beside the text. The excerpt still shows below it.
* If your site reaches the fediverse through Bridgy Fed instead of the ActivityPub plugin, you can now like and reblog fediverse and Bluesky posts you follow. Check "This site is bridged with Bridgy Fed" on Settings -> Daymark -> Connectors; Daymark then sends those Likes and Reblogs through Bridgy Fed with the Webmention plugin. Posts on sites that accept Webmentions still get them directly.
* Notifications now show quote posts: when someone on the fediverse reblogs one of your Marks with their own comment, it appears in that Mark's conversation as "Quoted your Mark on the Fediverse", with their words, a link to their post, and a Reply button, and it marks Notifications as unread.
* A post whose Featured Content is a quote now shows the quote on its Timeline card, where a featured image would go, with its credit below it. Before, the quote only appeared once the post was opened.
* A post whose Featured Content is a video or audio now shows the video's real thumbnail (or the file's cover art) on its Timeline card, with a play button, instead of a placeholder. A thumbnail that was never fetched is looked up the first time the card is shown.
* A post whose Featured Content is a link now shows a preview of the linked page, with its image, title, and site, on its Timeline card and at the top of the full post view, instead of a bare link. In the post view the preview opens the page.
* A post whose Featured Content is a link now shows a preview of the linked page on its own page too, not just in the Daymark app: the page's image, title, description, and site, as one link. The preview is saved when the Featured Content is saved, so a visitor never causes a fetch of the linked page.

**Changed**

* The Reblog screen now asks for your own words first: it opens with the cursor in "Your thoughts", says why a line of commentary helps, and if you leave it empty asks once before reblogging anyway.

**Fixed**

* The Timeline no longer jumps to the last post you saw if you have already started scrolling while it loads.
* Going back from a post you opened on the Timeline, in Search, or from Explore's "On this day" now returns you to that post's place in the list instead of the top. The Timeline keeps every page you had scrolled through, and Search keeps your search text, filters, and results. If you unsubscribe, reblog, or comment from the post view, the list reloads instead so it shows the change.
* The Playground preview and the public "Try Daymark" demo no longer stop with a critical error while adding sample content, and the sample Marks are now published to the Timeline instead of saved as drafts.
* A Timeline card with a small thumbnail (most subscribed posts, plus article and audio Marks) now shows its title on its own line above the thumbnail, so it lines up with the title of a card that has no thumbnail.
* The Timeline's sunrise and sunset marks and the line through each date heading now sit on the same vertical line as the rest of the rail, after the type icons moved under the site icon. With drafts showing, the sunrise now sits above them and the line runs on through to the Timeline.
* When Featured Content has no preview, Replace and Remove now show as always-visible buttons below the message instead of faint hover-only ones.
* A Mark's reblog count now includes reblogs that add commentary (quote posts from the fediverse) and reblogs by other authors on your own site, not only plain boosts.
* The app's security policy no longer blocks the small script WordPress adds to set up the language's text direction. The browser console no longer shows a Content Security Policy error when the app loads.
* Bookmarked posts now keep their images offline even when the images are hosted on another site, which is most posts from sites you follow. Before, only images on your own site were saved and the rest showed as broken offline.

**Developer**

* The WordPress Playground previews (the public "Try Daymark" link and each pull request's preview button) now open with sample Marks for each type and Featured Content kind: a single photo, a photo gallery, two Check Ins (one with a photo), and Featured Content notes using a gallery, a quote, a link, and a YouTube video. The sample photos are small images checked into the repository, so nothing is downloaded or generated while the preview loads.
* CI's smoke and browser test jobs now install WP-CLI with their own script (`bin/install-wp-cli.sh`: retries, then a SHA-512 check) instead of `setup-php`'s `tools: wp-cli`, whose download failed intermittently and stopped those jobs before any test ran.

= 0.18.0 - 2026-09-30 =

**Added**

* Liking a Mastodon post, or a post from any site running the ActivityPub plugin, now sends it a real ActivityPub Like through the ActivityPub plugin (8.1.0 or later, with your user enabled as an author); unliking sends an Undo. Reblogging one also sends a boost, undone when you unreblog. The origin gets one Like, not a second one by Webmention.
* A Like or Comment you've sent on a subscribed post now says, in its icon's hover text and screen-reader label, whether it actually reached the original site — delivered, pending, or not delivered.
* With Jetpack connected, WordPress.com likes on your own Marks now count toward each Mark's like total and show up in Notifications ("Ada liked this"). Jetpack stores these likes on WordPress.com instead of on your site, so Daymark now fetches them in the background.
* A Checkin Mark with a resolved location now shows a small map preview — a single OpenStreetMap tile with a pin at your captured spot — leading its Timeline card and its full post view, above the place name. A Checkin published before this change doesn't gain one retroactively; only a newly published Checkin's content includes it.
* A Check In can now carry an optional photo or video ("see it's me at the Leaning Tower of Pisa!") — the composer's picker, previously hidden for Check In entirely, now shows a small, plain "+ Add a photo or video" text link, deliberately understated next to the Place field; attaching one never reclassifies the Mark away from Check In, and its Timeline card now shows the attached media instead of no media slot at all.

**Changed**

* The Like icon on a subscribed post now also appears when the ActivityPub plugin can deliver the Like, and the readme, README, and Connectors tab list ActivityPub as a third way to like subscribed posts, alongside Webmention and Jetpack.
* The Like icon on a subscribed post now only appears when a Like can actually reach that post's site — through your linked WordPress.com account (Jetpack), or the Webmention plugin plus a site that accepts Webmentions — instead of creating a Like nobody would ever receive. A post you've already liked keeps its icon so you can unlike it.
* The readme, README, and the Webmention and Jetpack entries on Settings -> Daymark -> Connectors now say plainly that liking a subscribed post needs one of those two plugins; without either, subscribed posts show no Like icon.
* A Timeline card's leading site icon and type icon are now stacked in one column instead of sitting side by side, reclaiming that horizontal space for the card's own title, excerpt, and media.
* That leading column now top-aligns with each card instead of sitting centered against it — the site icon's top edge lines up with the card's own title/content, and the type icon still follows directly underneath, instead of drifting toward the card's vertical midpoint on a taller card (a long excerpt, a photo banner).
* The composer's "Mark type: {Type}" line — previously its own paragraph below the media picker — is now a chip in the header, next to "New Mark"/"Edit Draft", for every Mark type.
* Explore's Following list is now sorted alphabetically by site name instead of subscribe order.
* The Me screen's "Your Marks" link is now labeled "My Marks", matching the same wording Search's own Source filter already uses for the identical scope.
* Search's Source filter dropdown default option is now labeled "All sites" instead of "All", to distinguish it from the type-filter chips' own "All" option just above it.
* The Timeline's Reblog icon now says "Reblog"/"Undo reblog" on hover and to screen readers, instead of "Repost"/"Undo repost" — matching the name this action has used everywhere else (the dedicated Reblog screen, its "Reblog: {title}" published title) since it gained its own preview step. Every other user-facing "Repost" string (the stat row's screen-reader count, a subscribed post's fallback title for someone else's own repost-type entry, and the plugin-overlap notification) was updated to match. Internal names — the `_daymark_repost_of` post meta key, the `repost_of` REST field, and the microformats2 `u-repost-of` markup (an IndieWeb spec-mandated property name) — are unchanged.
* The Reblog screen's top-left "Cancel" is now the same Daymark-icon-plus-arrow back link every other screen (Notifications, the full-screen post view) already uses, instead of plain text — the accessible name ("Cancel") is unchanged, only its visual presentation.
* The Success screen's footer no longer repeats a second "View Timeline" link below "Create Another" — the same link already appears right above it, next to the confirmation message.
* A Checkin Mark's title now always says where you checked in (e.g. "Checked in at Wildcat Stadium"), even when you also typed a comment — a typed comment used to become the title instead, pushing the place name down into the body text. The comment itself still publishes in full as body text either way.
* Settings → Daymark now needs the Administrator role (`manage_options`) instead of any role that can edit posts, because it changes site-wide settings and the shared subscription list; Authors, Contributors and Editors no longer see it or the in-app Unsubscribe, and the app no longer links to it for them.
* The plugin's readme now lists every outside service Daymark can contact, what each one receives and when, and where to read its terms, including the map images Check In posts load for your visitors and the limits on Open-Meteo's free weather service.

**Fixed**

* Likes and comments on subscribed posts now actually reach the original post. The Webmention plugin never saw the liked or replied-to link (it only reads a post's saved content), so nothing was sent; and the WordPress.com (Jetpack) like and comment route was calling the wrong API address, so it silently fell back to a local Like.
* Liking or commenting through WordPress.com (Jetpack) no longer falls back to the slower path just because the post's own site refused Daymark's page fetch.
* Likes no longer show up as ordinary posts — in wp-admin's Posts list, on your site's home page and archives (including block-theme Query Loops), in its RSS feed, the REST API, or anywhere a social-sharing plugin picks up new posts. A Like now lives on its own hidden post type whose only public presence is its own permalink (kept so Webmention likes still verify); existing Likes are moved over automatically, and their old URLs redirect.
* The composer's empty existing-media and preview slots no longer reserve a full row of blank vertical space when nothing is attached — most noticeable around Check In's own small "+ Add a photo or video" text link, which previously sat with a disproportionate amount of whitespace above and below it.
* Scrolling past the top or bottom of the Timeline in an ordinary mobile browser tab (Safari/Chrome) no longer lets the page's native overscroll bounce slide content up past the header before snapping back — matching how the installed app already behaves, since it has no browser chrome to bounce past. Home's own pull-to-refresh gesture is unaffected; it never relied on this native bounce.
* Saving Daymark to your phone's home screen now always uses Daymark's own icon, even when your site has its own Site Icon configured (Settings -> General) — previously the home-screen icon used your Site Icon instead, and could show a blank icon if it failed to load. Your browser tab's own favicon is unaffected and still shows your Site Icon.
* A Timeline card's title or excerpt containing a long unbroken run of characters — most commonly a bare URL pulled in verbatim from a subscribed post — no longer overflows past the card's own edge; it now wraps like the rest of the card's text.
* The Check In composer's Place field search results are now height-capped and independently scrollable, and each result's address line is clipped to one line instead of wrapping across two or three — on iOS, with the on-screen keyboard covering the bottom half of the screen, more than one search result could previously render entirely behind the keyboard with no way to scroll down and pick it.
* An installed Daymark now picks up new versions of its own scripts and styles after a plugin update instead of keeping the ones it first installed with.
* A site's push-update subscription (WebSub) that its hub never confirmed is now retried after ten minutes, up to three tries, instead of staying stuck until it was replaced; posts still arrive by polling in the meantime.

**Security**

* Daymark now checks every address it fetches for a subscribed site, a link or feed preview, a comment delivery, a Like, or a location lookup, including each redirect and any address a remote page points it at, and refuses any that is private, link-local, or a cloud-metadata address. WordPress 7.0.0 through 7.0.2 did not do this on their own.
* A subscribed site's inline styles, and any classes borrowed from Daymark's own interface, are now removed from posts you open in the app, including posts you bookmarked earlier, so a hostile site can't lay its own content over Daymark's controls.
* The subscription post routes now answer only for real subscription posts. Before, an Author-level user could read the title and excerpt of another user's draft or private post by ID.
* A password-protected post's content is no longer shown in the app to someone who can't edit that post.
* Another user's exact captured location is no longer sent to every Author; only someone who can edit a Mark receives it, apart from a Check In's chosen place.
* Sharing files to Daymark from your phone's share sheet now needs the same upload permission as adding them in the app, so a Contributor can't add media that way.
* A WebSub hub's verification request now has to present a token Daymark put in the callback address, so no one else can answer a subscription's pending verification.
* Opening Notifications no longer loads images embedded in a federated reply, which told the reply's author when you viewed it.
* Other Authors' posts can no longer carry Daymark's own interface classes into the app to cover it with a fake screen, and the Friends source no longer matches an ordinary user account that sets its website to a friend's address.
* The offline copy of the app's settings no longer includes the Log out link's one-time code.
* Featured Content links from Authors and Contributors now use only the video and audio providers WordPress already trusts, so a remote page can no longer choose the player shown on a published post. Nothing changes on a one-person site.
* Marks you haven't sent yet and bookmarks saved for offline reading now stay with the person who made them on a shared browser, instead of being sent from (or shown to) whoever logs in next. Anything already waiting when you update goes to the first person who opens Daymark afterward.

**Developer**

* Continuous integration is faster and cheaper to run: a pull request that changes only documentation now skips the heavy test steps, a new push to a pull request cancels the run still going for the previous commit, and the test jobs use the runner's own MySQL instead of starting a container, which removes about half a minute from each job.

= 0.17.0 - 2026-09-17 =

**Added**

* A new Check In Mark type: tap the launcher's Check In bubble, and Daymark quietly reverse-geocodes your captured location into an editable Place field — no media, no caption required, though you can add your own thoughts too. The Place field also searches as you type, so you can pick a real venue instead of only editing the reverse-geocoded guess — each suggestion also shows its full address alongside the name, so you can tell apart two similarly-named places. The launcher's 5 type bubbles are also spaced further apart now, so a thumb tap is less likely to land on the wrong one. Publishes as a real post leading with the place name (linking out to a map when a location resolved), auto-titled "Checked in at {place}" when there's no caption. Once the Simple Location plugin is active, a Mark's captured coordinates (and a Checkin's own place name) are additionally bridged into its own data at publish time — reverse-geocoding, a "posted from" display, and a map/archive view all become available for free, with no duplicate location code inside Daymark. The Connectors tab (Settings -> Daymark) now recommends Simple Location for this.
* Subscribing to a site running the MF2 Feed WordPress plugin now reads its structured JSON feed instead of scraping h-entry HTML markup, when a live check confirms it's actually available — more reliable parsing of the same content.
* A "link"-kind subscription post's Timeline card now shows the same best-effort link preview (image, title, description) the full post view already did — previously only plain excerpt text.
* Liking or commenting on a subscribed post whose own site is WordPress.com-hosted or Jetpack-connected now goes straight to WordPress.com's real Like/Comment API — the same one the official Jetpack app uses — once you've personally linked your own WordPress.com account through Jetpack (Jetpack -> My Connection). No local Mark is published and no browser redirect is needed for those sites; every other subscribed site is unaffected and keeps working exactly as before. The Connectors tab (Settings -> Daymark) now also recommends the full Jetpack plugin for this.
* The Posts list in wp-admin now shows a small icon next to a post's title indicating its post format (Aside, Image, Video, Standard, etc.); WordPress core's own "All formats" dropdown, which already appears on this screen once any post uses a non-Standard format, remains the way to filter by format. Quick Edit also gained its own Format field — core's Quick Edit row has never exposed post format at all, only the classic editor and block editor sidebar could set one.
* A new **"Set featured content"** button in the block editor sidebar, right below "Set featured image" in the same Featured Image panel, for any post type that already shows one (not just Marks): opens the same media modal overlay as "Set featured image," titled "Featured content," with a third "Add by URL" tab alongside "Upload files"/"Media Library" for pasting a YouTube/Vimeo/podcast-episode link with a live preview — audio vs. video is detected automatically rather than asked up front. Once set, a small preview (the audio/video file itself, or a resolved oEmbed preview for a pasted URL) shows in the sidebar with Replace/Remove overlaid on it, revealed on hover/focus — matching how core's own "Set featured image" thumbnail shows its own Replace/Remove, rather than a separate row below the preview — and it's shown in place of your Featured Image everywhere your theme already renders one, with no theme changes needed; a theme that wants it to render somewhere different instead can opt out and use the new `daymark_the_featured_content()`/`daymark_has_featured_content()`/`daymark_get_featured_content()` template tags directly. A pasted provider link (e.g. a private/unlisted video) that oEmbed can't resolve now shows a plain "No preview available" message instead of a broken player, both in the sidebar and on the front end. The modal's own "Media Library" tab also gained a Video/Audio content-type filter, so a library mixing both isn't one long scroll to find the file you actually want. Gallery, quote, and a link-format-specific link field are planned as follow-up phases of the same feature.
* The WordPress Playground preview (both the per-PR preview button and the public "Try Daymark" blueprint) now also seeds a sample Mark that uses a YouTube video as its Featured Content, so a reviewer sees that feature already working without setting it up themselves. Published as a plain text Mark with no picked/generated media of its own — unlike an earlier, now-removed demo-Mark seeding step that used to generate a JPEG and could crash a Playground instance whose GD extension couldn't produce one, this reuses the exact same publisher call the subscription-seeding step above already makes.

**Changed**

* Settings -> Daymark's "Choose from available feeds" action no longer reloads the whole page — clicking it now loads that site's discovered feeds directly into its own row, so you stay right where you were instead of the page jumping back to the top of the subscriptions table.
* Made the "plugin overlap" notification's own text clearer about what it's flagging and what to do about it — each message now names specifically what Daymark already renders that the other plugin might duplicate, and always suggests deactivating whichever one you don't need. Also fixes the IndieBlocks message, which previously referred to "the above" even when shown on its own with no other overlap notification alongside it.
* Reblogging now opens a dedicated preview screen — the reblogged post as a real quote, an editable title, and a field for your own thoughts — instead of publishing straight away from a small caption sheet. Nothing is created until you tap Publish.

**Fixed**

* Home's Drafts row no longer shows its leading icon misaligned against the Timeline rows and rail beneath it — a Draft has no leading site icon, so its type icon now lands on the same shared rail position every other row's type icon already sits at, instead of flush against the screen edge.
* A Draft's own card no longer shows a "Draft" chip — Home's Drafts row and Me's own Drafts list already group it under a "Drafts" section heading, so the chip only ever repeated what that heading already said.
* A Mark with Featured Content set (audio or video, for now) now shows it on the Timeline card — previously the card showed nothing at all for a Note or Checkin Mark whose only visual content was its Featured Content.
* A YouTube/Vimeo/podcast link pasted into the new Featured Content "Add by URL" tab — or any subscription oEmbed/link preview — inside a WordPress Playground preview would wrongly show "No preview available" for every URL, even a fully public, working one. The SSRF safety check that runs before any of these fetches was mistaking WordPress Playground's own simulated DNS lookups for a real, private/internal address; it now also recognizes Playground's php-wasm runtime directly, rather than relying only on a SAPI name that isn't consistent across every Playground build/version. Real self-hosted sites are unaffected either way.
* A subscribed site's own long name (its plain `<title>` tag can carry a full tagline, not just a short name) no longer overflows and widens a Timeline card — the displayed name is now shortened with an ellipsis, with the full name still available as a hover tooltip.
* The full-screen post view's back arrow/Daymark icon now genuinely top-align against the post title — a follow-up fix layered on top of an earlier attempt (#315) that only top-aligned the back link's own box, not its arrow/icon content.
* Tapping Like on the same subscribed post twice no longer creates two identical Like Marks — a stale like-state on another screen (or a rapid double-tap) is now recognized and reused instead. Also adds defense-in-depth against an independent plugin like Jetpack Social auto-sharing a Like Mark externally, on top of Daymark's own "Like Marks never syndicate" guarantee.
* The composer's own header (New Mark / Edit Draft) still showed a plain "Back" text link instead of the arrow-plus-Daymark-icon chrome every other screen with a back destination already uses — now matches Notifications and the full-screen post view.
* Opening the "+ New Mark" launcher now dims the header along with the rest of the Timeline, instead of leaving it the one bright, still-tappable thing on an otherwise darkened screen.

**Developer**

* Fixed the release workflow's tag/version guard, which was comparing the plugin header's `Version:` and readme.txt's `Stable tag:` fields against the pushed tag using a whitespace-sensitive match — both fields are column-aligned with padding, so the extracted value silently carried that padding and never matched, failing the 0.16.0 tag push before it built or published anything. The guard now strips all whitespace from every value before comparing.

= 0.16.0 - 2026-09-12 =

**Added**

* Subscribing to a new site now shows every feed Daymark found for it up front, with the one most likely to capture the full post and its metadata (WordPress REST API, then Friends, then RSS/Atom, then microformats2) checked for you by default — pick any others you'd also like to follow before confirming.
* Subscribing to (or "Choose from available feeds" on) a multilingual site — Polylang, WPML, MultilingualPress, and similar — now shows a separate feed candidate for each language it advertises, labeled by language, so you can follow the one you actually read instead of an unfiltered, every-language feed.
* A "link"-kind subscription post's own detected link now shows a clickable preview — title, excerpt, and featured/Open Graph image — when the linked page has Open Graph or Twitter Card tags, closing the gap the existing oEmbed-only preview left for an ordinary article link.
* Notifications now flags when Post Kinds, the Microformats 2 plugin, Syndication Links, or IndieBlocks is active alongside Daymark — each duplicates something Daymark already renders natively on your Marks (Like/Repost/Comment markup, h-entry/h-card output, or syndication links). Daymark never suppresses the other plugin's own behavior; the notice just lets you know, with a link to Plugins, and stays until you dismiss it.

**Changed**

* Settings -> Daymark's "Check for other feeds" action is now "Choose from available feeds", and lets you check as many of the discovered feeds as you like — each one you check becomes its own new subscription, so you can follow more than one feed from the same site instead of only switching between them.
* The picker's default-checked candidate now prefers a feed matching your site's own Settings -> General -> Site Language, falling back to English and then today's richness ranking — rather than always defaulting to the richest source regardless of language.
* On a Timeline card and the full-screen post view, the Like/Comment/Reblog/Bookmark/... interaction row now sits below the site name and date instead of above it.
* Settings -> Daymark's "Choose from available feeds" picker no longer locks an already-subscribed candidate's checkbox — unchecking one (your current feed, or another feed you also follow from the same site) and submitting now unsubscribes it, immediately removing its previously-loaded posts, while any newly-checked candidate is subscribed to as before. This is what lets you actually switch a site's feed in one submit — e.g. swapping an unscoped WordPress REST API feed that mixed every language together for a correctly language-scoped RSS/Atom feed — instead of only ever adding feeds. The button is renamed "Update feeds".
* Subscribing to a brand-new site now shows its discovered feeds inline, directly below the Subscribe button, instead of after a full page reload — the button's loading label reads "Loading feed details…" while discovery runs. The picker is now a single-select list (pick exactly one feed to follow) with an editable site name (a pencil icon next to the discovered title, matching the existing per-subscription name editor) and a "Save Subscription" button that saves the feed and the (optionally edited) name together.
* README.md and readme.txt now lead with plain-language reasons to publish with Daymark instead of a technical feature list, aimed at a non-technical site owner deciding whether to install it. README.md's developer/extender detail (architecture, hooks, connectors, contributing) moved below a clear divider instead of being interleaved with the pitch.
* readme.txt's generated Changelog section now only lists the 5 most recent releases, ending with a link to the full history on GitHub, instead of growing to list every release forever.

**Developer**

* Extracted CLAUDE.md's ~31 narrow UI/UX visual-polish decision rows into a new docs/ui-polish-history.md (grouped by theme) and consolidated its 4 subscription content-type/post_format-mapping rows into docs/subscription-type-mapping.md, replacing each with a one-line pointer — CLAUDE.md is auto-loaded as project memory for every session on this repo, so trimming it to load-bearing architectural decisions is a direct cost saving with no loss of the underlying reasoning.
* CONTRIBUTING.md gained guidance aimed at a wider contributor base ahead of a wordpress.org launch: forking instructions for contributors without push access, a pointer to SECURITY.md for vulnerability reports, guidance on claiming an issue before starting significant work, and a note that a first-time contributor's CI runs need maintainer approval before they start.

**Fixed**

* The public "Try Daymark right now in your browser" Playground preview now uses the same install-and-subscriptions-only blueprint as the PR preview button, dropping the extra demo-Mark seeding step that could still crash inside WordPress Playground.
* The "Try Daymark right now in your browser" Playground preview no longer crashes with a critical-error screen while seeding its demo Marks — the demo-image generation helper now guards against a Playground environment whose GD extension can't produce a JPEG, and each demo Mark's publish is now isolated so one failing to seed can't take the rest of the preview down with it.
* The Comment icon in the interaction row no longer shows a visible break in its speech-bubble outline — its icon data was a mangled copy of the intended glyph.
* The Connectors settings tab now correctly recognizes ATmosphere as active even when it's installed under a different folder than its historical `wordpress-atmosphere` name (e.g. a republished build) — it now falls back to detecting the plugin's own defining class/constant, the same way Daymark's publish-side ATmosphere detection already does, instead of relying on a single folder-name check.
* The ⋯ overflow menu (Open original/Share/Routing/Refresh content/Unsubscribe) now opens as a small floating overlay anchored off the ⋯ icon, the same simple-dropdown treatment the old Draft ⋯ menu used, instead of growing the card's own frame open and pushing the rest of the post down the screen.
* Tightened the gap between the header and the top of the Timeline, removed the Notifications icon's bordered-box outline (the footer nav icons never had one), and resized the header's Daymark icon and the Notifications icon to match the footer nav icons' own size.
* Tapping Comment on a subscribed post now checks first whether Daymark can actually deliver a comment there at all — when it can't (most sites, since Webmention is the only delivery path Daymark can guarantee), it skips its own composer entirely and sends you straight to the original post's own comment form, instead of letting you type a comment only to discover afterward that it couldn't be delivered and you'd need to retype it there yourself. When the pre-check does look deliverable but the actual send still fails, the previous raw rejection text ("Sorry, you must be logged in to comment") is now a clear explanation instead — plus the same link straight to the post's own comment form. Settings -> Daymark's Connectors tab now also explains this benefit directly in the Webmention entry's own description: having it active lets other Daymark users comment on your posts from within their own Daymark app instead of being redirected to your site.
* The Reblog/Comment caption sheet's text cursor no longer renders in the wrong place — floating below the visible textarea, overlapping the sheet's own buttons and the page underneath — when the on-screen keyboard is open on iOS Safari. The sheet now tracks the keyboard using the Visual Viewport API instead of a plain CSS viewport unit, which iOS never shrinks to account for the keyboard.
* Reblogging a subscribed post now publishes a Mark titled "Reblog: {origin title}" whose content is a real link to the origin post (its own title as the link text) — previously the Mark's title and content were both just the caption text ("Reposted \"...\"" or whatever you typed), with no link to what you'd actually reblogged anywhere in it. Typing a comment in the Reblog sheet now adds it as its own paragraph below that link, instead of replacing a caption that never linked anywhere.
* Every Mark's permalink page no longer visibly shows Daymark's own machine-readable metadata (its permalink repeated as text, publish date, a reply/repost/like target as a bare out-of-context URL, and your avatar/name) below the actual content — that block is now visually hidden, since it duplicated information the theme's own template already shows a human reader. It's unaffected for the IndieWeb tooling (Webmention, ActivityPub, Bridgy, feed readers) it's actually for, since that reads the page's raw HTML regardless of what's visually hidden.
* The first-time explainer overlay for Comment and Reblog now appears before you start typing, not after you've already sent a comment or published a reblog — dismissing it takes you straight into the compose step it was meant to introduce. Like, Bookmark, "Open original", and Share are unchanged; their overlay still appears right after the (instant) tap, since there's no compose step for it to sit ahead of.
* A subscription post whose own excerpt field was left as a leftover placeholder value (e.g. just the word "Excerpt", never replaced before publishing) no longer shows that literal placeholder text on its Timeline card — it now falls back to real content, the same way an entirely empty excerpt already did. A genuinely short manual excerpt is unaffected.
* Tapping Like on a subscription post no longer creates a post that can show up on your own site's home page, archives, search, RSS/Atom feed, REST API, or XML sitemap — it stays visible only at its own direct permalink, which is what lets your Webmention plugin still verify and deliver the outbound Like to the original post. It also never syndicates to a real destination, whatever your remembered Note-type preference is. Reblog is unaffected — it's still real, visible, publishable content, exactly as before.
* Subscribing to a site inside WordPress Playground previews no longer fails with "Please enter a valid site URL." for every URL, including well-known real sites — Playground's own sandboxed PHP runtime doesn't genuinely support DNS lookups, which was making Daymark's own SSRF safety check wrongly treat every site as unsafe.

= 0.15.0 - 2026-09-10 =

**Added**

* Settings -> Daymark's subscriptions table now has a "Check for other feeds" action per row — useful when Daymark picked the wrong source for a site (e.g. a WordPress REST API that mixes every language together on a multilingual site). It lists every feed/source discovered for that site and lets you switch to a different one without unsubscribing and resubscribing.
* The first time you tap Like, Comment, Reblog, Bookmark, "Open original", or Share, a short overlay explains what that icon does — shown once per icon, right after the tap, never again after that.

**Changed**

* Subscription posts get a new Comment action, replacing the old "Reply" button that opened the full composer — tap it, type your comment, and it's delivered straight to the original post: via Webmention when both your site and the source support it (behind the scenes this still publishes a small Mark on your own site so your Webmention plugin can deliver it, same as before), or posted directly to the source site otherwise. Reblog now asks for an optional comment of your own before publishing, instead of always using a generic "Reposted ..." caption.
* The full-screen post view's standalone "Refresh content" text link is now an icon at the end of the interaction row (after Share), instead of its own row below the post — a shorter, less tall screen with one consistent set of icons for everything you can do with a post.
* The Timeline interaction row's "Open original", Share, Routing (on your own Marks), and "Refresh content" (on the full post view) actions now live behind a new ⋯ overflow menu, keeping Like, Comment, Reblog, and Bookmark as the primary row's exposed icons. A subscription post's overflow menu also gains a new Unsubscribe action — unsubscribe from a site directly from its card or full post view, with a confirmation step first, instead of needing a trip to Settings -> Daymark.

**Fixed**

* Timeline cards whose kind shows a small thumbnail beside the title (an article with a featured image, an audio/podcast post, a subscription post falling back to its site icon) had their interaction icons and site-name/date row indented under that thumbnail, reading as shifted right compared to a thumbnail-less card (note, link). Both rows now line up flush with the card's own left edge on every kind.
* Subscribing to an ordinary, valid site could fail with "Please enter a valid site URL." inside WordPress Playground, including the sites this plugin's own Playground previews try to preset automatically. Root cause: a DNS-resolution function returning something other than a real IP address or a clean failure was wrongly trusted as a "resolved" (and then judged unsafe) address.
* A subscribed WordPress or Friends post with a confirmed Image/Video/Audio/Gallery post format but no explicit featured image (common for themes that show a post's own first inline image instead) showed the subscription's site icon blown up in the card's media banner instead of that image.
* The full-screen post view's back arrow and Daymark icon were vertically centered against the post title's full height, so a long title that wrapped to two or three lines left them floating in the middle of the block instead of level with its first line.
* A plain, no-image subscription post could show a small thumbnail duplicating its own site icon — an author-bio box's avatar photo, embedded in the post's own content by the theme, was being picked up as the card's featured image. Avatar images are no longer treated as post content.
* A Mark's own like/comment/repost counts on its Timeline card no longer show Daymark's orange "active" accent color just because the count is 1 or more — that color is reserved for your own action (a genuine Like/Repost/Comment/Bookmark toggle); these three are always other people's engagement with your Mark, so they now stay a plain, muted count regardless of how high it is.
* A Timeline card's trailing whitespace below its own site-name/date row — before the next card begins — is now identical across every Mark type and post format. Media-dominant cards (image, gallery, video, mixed media) previously had roughly double the trailing space of every other kind (audio, note, article, link, standard), a real inconsistency visible scrolling down a mixed Timeline.

[View the full changelog history](https://github.com/jeffpaul/daymark/blob/main/CHANGELOG.md).

== Upgrade Notice ==

= 0.19.0 =
Featured Content grows into a full set: galleries (reorderable in WordPress's own gallery editor), quotes, and links join audio and video, and each shows on the Timeline card, at the top of the full post view, and in a post's link previews when shared. A link becomes a preview of the linked page on the post's own page too. Gallery cards show a 2x2 photo grid, and followed and ordinary posts show their featured image full width. The Timeline remembers where you left off, Search gains a Date filter, Explore adds "On this day", and Back from a post returns to your place in the list.

= 0.18.0 =
Likes and comments you send on subscribed posts now actually reach the original site, and Likes to Mastodon and other ActivityPub sites are delivered through the ActivityPub plugin. The Like icon only appears when a Like can be delivered. Check In gains an optional photo or video and a map preview. Likes no longer appear as ordinary posts on your site. Settings -> Daymark now needs the Administrator role, and this release includes a set of security hardening fixes, including checks on every redirect Daymark follows, so update when you can.

= 0.17.0 =
A new Check In Mark type reverse-geocodes your location into an editable, searchable Place field. The block editor sidebar gains a "Set featured content" button (audio/video, with more formats planned) that shows in place of your Featured Image everywhere your theme already renders one. Liking or commenting on a WordPress.com/Jetpack-connected subscribed post now goes straight through Jetpack's own API, with no local post or redirect needed. Reblogging opens a preview screen before publishing instead of going straight out. Also fixes a false "No preview available" for Featured Content/link previews inside WordPress Playground, and a Timeline card that showed nothing at all for a Note/Checkin Mark whose only visual content was its Featured Content.

= 0.16.0 =
Subscribing to a new site now shows every feed Daymark found for it up front — the best one pre-checked for you, with a separate option per language on multilingual sites — so you can follow more than one feed from the same site in a single step; "Choose from available feeds" (renamed "Update feeds") now lets you add and drop feeds together too. Link-format subscription posts get a real preview (title, excerpt, image) via Open Graph when there's no oEmbed provider. Reblog now creates a Mark with a real link to the original post instead of just caption text, Comment checks whether it can actually deliver before you start typing, and Like no longer shows up in your own site's feeds, search, or sitemap. Also fixes a Playground preview crash and a false-positive "invalid site URL" failure when subscribing inside WordPress Playground.

= 0.15.0 =
Subscription posts get an instant Comment action (replacing the old "Reply" button) and the Timeline interaction row is tidier: Open original, Share, Routing, and Refresh content now live behind a new ⋯ overflow menu, which also adds a one-tap Unsubscribe for subscription posts. First-time explainer overlays introduce each icon on first tap. Also fixes: a duplicated site-icon thumbnail from author-bio avatars, a like/comment/repost count wrongly shown in Daymark's accent color, uneven card spacing across Mark types, and a Playground subscribe-by-URL failure.

= 0.14.0 =
Settings -> Daymark's URL is shorter now (`?page=daymark`, with the old URL redirecting automatically) and gained a Connectors tab recommending the Webmention/ActivityPub/ATmosphere plugins and Bridgy Fed, plus a Privacy section and a "Check for new posts" frequency setting. The subscriptions table now defaults to A-to-Z sorting and has a search box, and a bookmark on a deleted Mark or post no longer lingers as an orphaned entry.

= 0.13.0 =
`/daymark` now works even on a cold, zero-connectivity load — open it once online, and a later relaunch with no signal at all still gets you a working composer. Tapping a Timeline card now opens a dedicated full-screen post view instead of expanding in place, gallery images can be manually reordered in the composer, and a published Mark's new routing icon shows exactly where it was sent (and whether each destination succeeded).

= 0.12.0 =
Subscribed posts get real engagement: Like and Repost toggles alongside the existing Reply, publishing a small Mark of your own so an installed federation plugin sends the actual outbound activity. The Settings -> Daymark subscriptions table is tidier too — site icon, "Edit name," and Refresh are all now inline icons next to what they act on instead of separate columns/buttons.

= 0.11.0 =
Timeline cards can now be bookmarked for offline viewing and shared via your device's native share menu. Expanding a card grows its own bordered frame instead of overlapping other Timeline elements, with a permanent "open original" icon and hover tooltips on every stat-row icon. The Timeline also now groups cards under relative-period headers (Today, This Week, Last Month, etc.) for easier scanning further back.

= 0.10.0 =
Subscriptions get four new detection sources (WebSub push delivery, microformats2-only sites, WordPress REST API, and Friends-plugin friends) plus more accurate post-type mapping, OPML import/export, and an on-demand icon refresh. Timeline cards gain a repost count and a Reply action on subscribed posts. Published Marks drop their in-app Edit/Delete menu — use wp-admin for that now.

= 0.9.0 =
A redesigned bottom navigation (Timeline, Explore, Search, Me) replaces the old Images/Videos/Audio/Notes pages — existing pages move to Trash automatically and old links redirect to Explore. Composer autosave, offline-first creation, and optimistic publishing mean your work is never lost and Publish never makes you wait. Timeline cards now render by content kind and expand in place instead of navigating away or opening an overlay. If your site still runs Moment (<= 0.5.0), upgrade through an intermediate 0.6.x-0.8.x release first — this version can no longer convert old Moment data.

= 0.8.0 =
Home is now a merged Timeline: subscribe to any site's RSS/Atom feed by URL (Settings → Daymark), interleaved with your own Marks and searchable together. The public /timeline page is removed. Marks now carry outbound h-entry/h-card markup, plus a rel=me profile field.

= 0.7.0 =
Security hardening (rate limiting, an alt-text access-control fix, upload caps, a stricter CSP, AI prompt-injection defenses), a new Path-style launcher and header/footer redesign, a fix so the app only lives at /daymark on migrated installs, and consistent outside-click/Escape dismissal.

= 0.6.1 =
Fixes a 404 at /daymark on installs migrated from Moment. Also adds comment/like counts and inline audio/video/note previews to the public views, Global Styles support for the daymark/* blocks, and an auto-hiding Home footer.

= 0.6.0 =
The plugin is renamed from Moment to Daymark (posts are now called Marks) — required by wordpress.org review. Every identifier changed with no back-compat bridge, but existing installs migrate automatically on update: your app URL, content, and settings all carry over untouched.

= 0.5.0 =
Adds an optional AI-assisted Title field for audio/video Moments, header search with type filters, infinite scroll, per-item edit/delete, and inline notification replies. Removes the podcast type (now just an audio/video Moment) and switches the app to the designed brand icon.

= 0.4.0 =
Adds per-image (AI-assisted) alt text and a per-type category picker, an ATmosphere publish toggle, iOS home-screen polish, and project health files. Removes the bundled Bluesky/Mastodon connector plugins in favor of your existing publishing plugins.

= 0.3.0 =
Notes active social-publishing plugins on the publish screen, icon-based site nav, per-type post formats, and publish-UI polish.

= 0.2.0 =
Adds drafts (save, resume, deferred syndication), an unread notifications indicator, and collision-safe app and section-page URLs.

= 0.1.1 =
Tightens REST API capability checks and moves app assets to the WordPress enqueue API.

= 0.1.0 =
Initial release.
