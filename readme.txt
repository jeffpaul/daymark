=== Daymark ===
Contributors:      jeffpaul
Tags:              social, rss, publishing, syndication, indieweb
Requires at least: 7.1
Tested up to:      7.2
Requires PHP:      8.2
Stable tag:        0.23.0
License:           GPL-2.0-or-later
License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html

Your own social app: follow your friends in one calm, chronological feed, and post from your phone to your site and the networks you use.

== Description ==

Daymark is your own social app, built on your WordPress site. Follow the people you care about by subscribing to their blogs and websites. Their new posts arrive in one simple timeline, newest first, with no algorithm and no ads, and you can like, comment on, reblog, bookmark, or share any of them without leaving the app. It works like a feed reader that follows sites the way a social app follows friends, so keeping up with the people you care about can be a daily habit that doesn't need a social network.

When you have something to share, post a photo, video, voice note, check-in, or quick thought in seconds, as easily as you would in a social app. It lives on your own site first, under your own name, then goes out to the social networks you've connected, and replies and likes from there come back to your site. The IndieWeb calls this POSSE: Publish on your Own Site, Syndicate Elsewhere. Friends can reach you however suits them: on your site, from their own Daymark, or on the network where they saw your post.

= Why people love Daymark =

* **Your friends, in order.** Follow any blog or site with a feed, any WordPress site, or a friend you already follow in the Friends plugin. Their posts appear in your Timeline newest first, beside your own, and Daymark opens right where you left off.
* **Like, comment, and reblog like you would on a social app.** Your like or comment reaches the original site, and a reblog becomes a post on your own site with your thoughts on top.
* **Bring the people you already follow.** Import an OPML file from your feed reader, the sites you follow in the WordPress.com Reader (with Jetpack), or your reading list from a Microsub server.
* **Follow a site from wherever you find it.** Reading a post somewhere else? Click the Daymark bookmarklet to follow its site. Add the "Follow me with Daymark" block to your own site, and visitors who use Daymark can follow you from theirs.
* **Never miss the people who matter most.** Star a favorite site and your phone gets a notification when it publishes.
* **Daymark sites know each other.** When you follow a friend who uses Daymark, their site is told, and likes, reblogs, and comments travel between your two sites with nothing else to install.
* **It feels like your favorite social app — because your site deserves to.** Add Daymark to your phone's home screen and it opens like a real app: fast, focused, and built for one-handed use. No admin menus, no clutter — just capture, caption, and go.
* **Your camera is one tap away.** Choose Photo, Video, or Audio and Daymark opens your camera or microphone right away, ready to capture the moment. Already have the shot? Grabbing it from your library is just as easy.
* **Share to Daymark from other apps on Android.** Found something worth posting in Photos, Chrome, or any other app? Use your phone's own Share button and send it straight to Daymark — it's waiting for your caption before you've even opened the app. (iPhone and iPad don't let web apps receive shares yet.)
* **You never lose your work.** Start a caption, get interrupted, lose your signal on the subway — Daymark quietly saves everything as you go. Publish with no connection at all and it goes out the moment you're back online. Nothing you write is ever at risk of disappearing.
* **Tap Publish and move on with your day.** You're never stuck staring at a spinner, even for a big video or a whole gallery of photos — Daymark confirms instantly and finishes the upload quietly in the background.
* **It's genuinely yours, for good.** Everything you publish is a real WordPress post, not a locked-in, proprietary format. Your content works with your theme, your feeds, your backups — and stays exactly where it is even if you ever stop using Daymark.
* **Reach further, without extra work.** Already use a plugin to share to Bluesky, Mastodon, or elsewhere? Daymark works alongside it, and lets you choose per-post where each one of your posts should also go. Replies from those networks flow back to you automatically, right inside Daymark. Owning your posts isn't only about where they start. It's also about the conversation coming back to something you own.
* **A helping hand, never a replacement for yours.** Ask for a suggested caption, title, or tags, or let Daymark describe a photo for accessibility — every suggestion is yours to accept, edit, or ignore, and Daymark works exactly the same with none of it turned on. AI never gets between you and the Publish button.

= Your site, your rules =

Daymark never asks you to choose between "easy" and "yours." Your own site is always where a post lives first; anywhere else it appears is a bonus, never a requirement. There's no subscription, no account to create anywhere else, and nothing about what you publish depends on a company staying in business or an app staying in the store.

= Back to the open web =

**Publish once. Own it forever. Share everywhere.**

The web didn't lose because it lacked ownership. It lost because it became slower than social media.

Social apps made posting effortless, and that's a big reason so much of what we share now lives inside a few walled gardens, where the company owns the feed, decides who sees your posts, and can change the rules or shut down whenever it likes. Daymark exists to get more people publishing to sites they own, with WordPress, by making that just as easy as posting to a social app.

Daymark isn't a new social network. It's a new way to use WordPress. It follows the IndieWeb's approach rather than building yet another network. You publish on your own site, then share copies to the networks your friends already use, so you can still reach them where they are. You follow friends' own sites instead of their accounts on someone else's platform. And it all runs on open standards that any site can speak: RSS, Atom, and JSON Feed for following, Webmention for likes, replies, and reblogs between sites, microformats so other sites and readers understand your posts, and WebSub so new posts arrive quickly. Every site that joins in makes the open web a little more social, and the walled gardens a little less necessary.

WordPress doesn't need to become a social network. It needs to become the best place for social-shaped content to begin.

= A note on privacy and external services =

Daymark sends nothing to its authors: no analytics, no tracking, and no account to create. Everything you publish is stored on your own site. It contacts outside services only for something you did, or because a visitor's browser needs to draw a page, and every one of them is listed under "External services" below with what it receives and when.

Sharing to other networks happens only through the publishing plugins you choose to install yourself (such as ActivityPub or Webmention). Daymark doesn't post to any social network directly. Optional AI suggestions go through WordPress's own AI tools and whichever provider you've configured, and Daymark never sees or stores an API key of its own.

You're in control of the location-related services: "What does Daymark quietly capture, and can I turn it off?" in the FAQ shows exactly what's captured and how to turn any of it off in Settings -> Daymark -> Data & privacy.

= External services =

Requests your site makes carry your site's address in the User-Agent (`Daymark/<version>; https://yoursite.example/`), as WordPress's own requests do. Each site and service has its own terms and privacy policy, which apply to what it receives.

**The sites you follow and respond to.** Daymark works like a feed reader, so your site talks directly to the sites and servers you choose, with no Daymark service in between. It fetches their feeds and pages, sends them your likes, comments, and follows, asks them for replies, and checks the Webmentions they send you. "What does my site send to the sites I follow?" in the FAQ lists each of these requests, what it carries, and when it is made.

**Link previews and embeds.** When a post you're reading links to another page, or replies to one, Daymark may fetch that page (for its Open Graph title, description, and image) or ask the link's oEmbed provider, such as YouTube or Vimeo, for an embed. Only the link's address is sent. The same goes for a link in a post's Featured Content and for a page you open the Daymark bookmarklet on. Visitors to your site never trigger these requests. "What does my site send to the sites I follow?" in the FAQ has the details.

**Bridgy Fed (only if you've marked your site as bridged).** If you've marked your site as bridged with Bridgy Fed (Settings -> Daymark -> Connectors), a like or reblog of a fediverse or Bluesky post goes to fed.brid.gy as a Webmention with your like's own address. Bridgy Fed then reads that page and delivers the like. See https://fed.brid.gy/docs for its terms and privacy policy.

**WordPress.com (only with Jetpack connected).** If you have linked a WordPress.com account through Jetpack, likes and comments on WordPress.com sites go through WordPress.com using that connection (the post and your comment text), and Daymark reads the likes your own posts have received there. Settings -> Daymark -> Import/Export can also import the sites you follow in the WordPress.com Reader: your site asks WordPress.com for your follow list, using your linked account, only when you click "Load my Reader follows". Nothing is sent back to WordPress.com, and the sites you then import are fetched like any other subscription. If the Jetpack plugin is connected, your site also asks WordPress.com once an hour for your newest subscribers (their names and when they subscribed), so it can tell you about new ones. See the [WordPress.com Terms of Service](https://wordpress.com/tos/) and the [Automattic Privacy Policy](https://automattic.com/privacy/).

**OpenStreetMap Nominatim (Check In place names).** When you compose a Check In, your site asks Nominatim for a place name: it sends your captured coordinates to name where you are, and whatever you type into the Place field to search for a place. A search also sends the area you're in, rounded to about 10 km, so places near you come first. The request comes from your site's server, and its User-Agent identifies your site, as Nominatim's policy requires. Nothing is sent if you don't allow location access and don't use the Place search. See the [Nominatim usage policy](https://operations.osmfoundation.org/policies/nominatim/) and the [OpenStreetMap Foundation privacy policy](https://osmfoundation.org/wiki/Privacy_Policy).

**OpenStreetMap map images (Check In posts).** A Check In post that has a location shows a small map image loaded directly from tile.openstreetmap.org by the reader's own browser, both on your site's post page and inside Daymark. The request tells OpenStreetMap which map square is being shown (roughly a kilometre across) and the reader's IP address, so this happens for your visitors, not only for you. See the [tile usage policy](https://operations.osmfoundation.org/policies/tiles/) and the [privacy policy](https://osmfoundation.org/wiki/Privacy_Policy). Check In location can be switched off in Settings -> Daymark -> Data & privacy.

**Open-Meteo (weather).** When a Check In is first saved with a captured location, which can happen while you're still writing it, your site makes one request to api.open-meteo.com with the latitude and longitude, and stores the current temperature and conditions with the post. It isn't displayed anywhere yet. Open-Meteo's free service is for non-commercial use (sites without advertising or subscriptions) under a CC BY 4.0 license, so if your site is commercial, turn weather capture off in Settings -> Daymark -> Data & privacy. See the [terms and privacy policy](https://open-meteo.com/en/terms).

**Push notifications (only on devices where you turn them on).** If you turn on notifications on a device (the Me screen in the app), your browser creates a push address at its vendor's push service: Apple (push.apple.com) for Safari, Google (fcm.googleapis.com) for Chrome and most other browsers, Mozilla (push.services.mozilla.com) for Firefox, or Microsoft (notify.windows.com). Your site stores that address and sends a short message to it when someone comments on one of your Marks (who commented, which Mark, and the start of the comment), when someone replies to a comment you left on a site you follow (that post's title or the site's name, who replied, and the start of the reply), when a site you starred as a favorite publishes (the site's name and the post's title), or when someone starts following your site (their name). The message is encrypted so only your device can read it; the push service sees only the address, the message size, and your site's address, which identifies the sender. Turning notifications off removes the address. See the privacy policies of [Apple](https://www.apple.com/legal/privacy/), [Google](https://policies.google.com/privacy), [Mozilla](https://www.mozilla.org/privacy/firefox/), or [Microsoft](https://privacy.microsoft.com/privacystatement), depending on your browser.

**Your AI provider (only if you've configured one).** If you've set up an AI provider in WordPress, Daymark sends it text and files through WordPress's own AI Client: your caption, the post type, and an excerpt of any transcript to suggest titles, captions, and tags; the images you pick, to suggest alt text; and audio, only when you ask for a transcript. If the site owner turns on "Read to me" (Settings -> Daymark -> Data & privacy, off by default), tapping Listen on a post in the app sends that post's title and text to the provider to make an audio version. Daymark saves the audio file on your site and reuses it, so each post is sent once unless its text changes. A post from a site you follow is sent the same way when you tap Listen on it. Tag suggestions run automatically a moment after you type a caption, and alt-text suggestions run when you pick an image. To send nothing until you tap an AI button, turn off "Suggest with AI automatically" in Settings -> Daymark -> Data & privacy. Nothing is sent when no provider is configured. Your provider's own terms and privacy policy apply.

= Openly built =

Daymark's code and its full design history are public on [GitHub](https://github.com/jeffpaul/daymark), including an unusually candid record of every decision along the way — built with the help of AI coding tools, under ongoing human direction, review, and testing.

== Installation ==

1. Upload the `daymark` folder to `/wp-content/plugins/`, or install through the WordPress plugins screen.
2. Activate the plugin through the **Plugins** screen.
3. Visit `https://yoursite.example/daymark` on your phone while logged in.
4. Optional: add it to your home screen (iPhone: your browser's Share menu → Add to Home Screen; Android Chrome: menu → Add to Home Screen / Install App). Standalone app display requires HTTPS.

To like a subscribed post, the other site must accept Webmentions (every Daymark site does), or you need the ActivityPub plugin or Jetpack with your WordPress.com account linked. See "Why don't I see a Like icon on subscribed posts?" below.

Activation creates no public pages of its own. Timeline, Explore (with search), Notifications, and Me all live inside the authenticated `/daymark` app shell.

== Frequently Asked Questions ==

= What are the IndieWeb and POSSE? =

The [IndieWeb](https://indieweb.org/) is a community of people who publish on their own websites instead of only on social networks, and who connect those sites to each other with open standards. [POSSE](https://indieweb.org/POSSE) stands for Publish on your Own Site, Syndicate Elsewhere: post on your site first, then share copies to other networks, each linking back to the original. Daymark does both. Your Marks publish to your WordPress site and go out through the sharing plugins you choose, and the sites you follow are other people's own sites, read through their feeds and answered with Webmentions. You don't need to know any of this to use Daymark; it's simply why your posts stay yours.

= How do I publish to social networks? =

A Mark is a standard post, so any publishing plugin you already use shares it when it publishes. Daymark detects popular ones (Jetpack Social, Share on Mastodon, ATmosphere, XPoster, Autopost for X, and more) and notes them under "Where this goes" while you write a Mark; for plugins that expose a per-post control it adds an in-app on/off toggle per Mark (currently ATmosphere for Bluesky, Share on Mastodon, and Autopost for X). Replies come back through federation plugins (ActivityPub, ATmosphere, Webmention) as native comments. A Mark's "Where this went" view (in its ⋯ menu) lists the copies these plugins made, and the Mark's page links to them, so [Bridgy](https://brid.gy/) can bring replies from your copies on networks such as Mastodon and Bluesky back to your site. Daymark also exposes an open connector interface (`daymark_register_connectors`) so a plugin can register a first-class destination. Your site is always the primary destination and publishing never depends on any of this.

= Why don't I see any social networks when I publish? =

Daymark only offers destinations that can actually publish (and pull replies back): a network appears once a connector plugin registers it. With nothing connected, your own site (shown by its address) is the only destination — publishing to your own site always works. (Publishing plugins like Jetpack Social or Share on Mastodon aren't destinations — they appear as an awareness note or a per-Mark toggle instead.)

= How do replies come back to my site? =

If you run the ActivityPub, ATmosphere, or Webmention plugins, replies they deliver arrive as native WordPress comments and are recognized and labeled in Daymark notifications ("Reply from Bluesky", "Reply from the Fediverse", …) — by push, live, with no polling. When a polling connector is registered, an hourly background sync (plus a refresh whenever you view notifications) imports replies from your syndicated copies too, deduplicated per reply.

Commenting on a post from a site you follow works the other way round: tap Comment on the post, write your comment, and Daymark sends it. When the other site accepts Webmentions (every Daymark site does), Daymark publishes your comment as a small Mark on your site with a `u-in-reply-to` link to the post, and sends the other site a Webmention. If you've linked a WordPress.com account through Jetpack, a comment on a WordPress.com site goes through WordPress.com. If the other site can't receive your comment, Daymark opens the post so you can comment on the site itself. Daymark sends and receives Webmentions itself, for every public post on your site, so likes, reblogs, and comments travel between Daymark sites with nothing else installed. If you'd rather use the [Webmention plugin](https://wordpress.org/plugins/webmention/), install it and Daymark turns its own Webmention support off. Install ActivityPub and ATmosphere too, so replies from the fediverse and Bluesky show up in your notifications — Settings -> Daymark's Connectors tab lists all three with an Install/Activate button right there, no need to leave wp-admin. Don't want to install the ActivityPub plugin at all? [Bridgy Fed](https://fed.brid.gy/) is a free, hosted bridge — not a plugin — that gives your site a fediverse and Bluesky presence through the Webmention support above, under an auto-generated handle on its own domain rather than a native handle on yours; it's also listed on the Connectors tab, right alongside the plugin options.

To read the conversation on a post you follow, open it and tap Show replies. Daymark loads the replies from the post's own site, a WordPress site or a Mastodon-style server, and marks the comment you sent. After you comment on a WordPress site through Daymark, Daymark keeps checking it for 30 days and tells you in Notifications when someone replies to your comment. With push notifications on, you also get a push; the Me screen's "Comments on your Marks and replies to your comments" switch controls it. Comments sent through WordPress.com aren't checked yet.

= Why don't I see a Like icon on subscribed posts? =

Liking a subscribed post needs a way to actually tell the original site. Daymark only shows the Like icon when one of these is set up:

* The subscribed site accepts Webmentions. Every Daymark site does, and so does any site running the [Webmention plugin](https://wordpress.org/plugins/webmention/).
* [Jetpack](https://wordpress.org/plugins/jetpack/) is active and you've linked your own WordPress.com account (Jetpack -> My Connection). This covers subscribed sites hosted on WordPress.com or connected to Jetpack.
* The [ActivityPub plugin](https://wordpress.org/plugins/activitypub/) (8.1.0 or later) is active, your own user is enabled as an ActivityPub author, and the subscribed post is a fediverse post (Mastodon, or a site running ActivityPub). Daymark sends it a real ActivityPub Like; reblogging it also sends a boost.
* Your site is bridged with [Bridgy Fed](https://fed.brid.gy/) (check "This site is bridged with Bridgy Fed" on Settings -> Daymark -> Connectors), and the subscribed post is a fediverse or Bluesky post that doesn't accept Webmentions. The Like or Reblog goes through Bridgy Fed.

Without any of these, subscribed posts show no Like icon at all, so you never send a Like nobody receives. These plugins are listed on Settings -> Daymark -> Connectors with an Install/Activate button. A post you already liked keeps its icon so you can unlike it.

= Does Daymark work with Parse This? =

Yes, and it's optional. Parse This isn't in the WordPress.org plugin directory; get it from [GitHub](https://github.com/dshanske/parse-this). With Parse This 2.0.0 or later active, Daymark reads the pages it already fetches more fully. Link previews also show the author and use a page's microformats and JSON-LD, not only its Open Graph tags. Sites you follow through their microformats are read by Parse This's complete parser instead of Daymark's simpler one. Daymark only hands Parse This pages it has already downloaded, and turns off Parse This's own extra requests while it does. Without Parse This, Daymark uses its own parsing, as before.

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

= What does my site send to the sites I follow? =

Daymark has no central service, so your site talks directly to the sites and servers you choose. These requests carry your site's address in the User-Agent, as WordPress's own requests do, and each site's own terms and privacy policy apply. Services run by someone else (maps, weather, push notifications, AI, WordPress.com, Bridgy Fed, and embeds) are listed under "External services" in the description.

**Sites you follow.** When you look up a site to follow (in the app, in Settings -> Daymark, or from the bookmarklet), when you subscribe to it, and afterward on the schedule you choose in Settings -> Daymark, your site requests that site's feed (RSS, Atom, or JSON Feed), home page, or public REST API, and the full page of a post when someone opens it. If a feed advertises a WebSub hub, your site also sends that hub a subscription request containing the feed address, a callback address on your site, and a one-time secret, so the hub can push new posts.

**Link previews and embeds.** Daymark may fetch a page a post links to or replies to, or ask the link's oEmbed provider for an embed, sending only the link's address. A video or audio link you paste into Featured Content in the block editor works the same way; for a post whose author can't publish unfiltered HTML (an Author or Contributor), only the providers WordPress already trusts are used. To give such a post a share image and link preview, your site also asks that provider for the video's thumbnail, or fetches a Featured Content link's page for its Open Graph image, title, and description, once each time the Featured Content changes or, failing that, the first time someone signed in to Daymark sees the post's card. Opening the Daymark bookmarklet on a page does the same for that page: your site asks for an embed and checks whether the page's site can receive a Like, as a Like in the app does. Visitors to your site never trigger these requests. If the Parse This plugin is active, Daymark asks it to read pages Daymark has already fetched; Parse This makes no requests of its own for Daymark.

**Bookmarked posts' images.** When you bookmark a post for offline reading and it has images hosted on another site, your site downloads those images (only the ones in that post) and hands them to the app to save on your device.

**Comments and likes on posts you follow.** What is sent depends on what the other site supports.

* If you use the Webmention or ActivityPub plugins, they send your reply or like themselves.
* Without the Webmention plugin, Daymark sends Webmentions itself. When you publish or update a post, your site sends each site the post links to (including a post you liked, reblogged, or replied to) two addresses: your post's and the linked page's. That site then fetches your post to check it.
* A like or reblog of a fediverse or Bluesky post goes through Bridgy Fed, and likes and comments on WordPress.com sites go through WordPress.com, when you have set those up. Both are listed under "External services" in the description.
* Otherwise Daymark posts your comment to the other WordPress site's public comments endpoint, sending the comment text, your display name, your account email address, and your site's address, the same details WordPress's own comment form collects.

**Replies on posts you follow.** When you tap Show replies on a post from a site you follow, your site asks that site for the post's public replies: a Mastodon-style server's public conversation endpoint, or a WordPress site's public comments endpoint, which your site finds by reading the post's page. After you comment on a WordPress site through Daymark (except through WordPress.com), your site asks that site's comments endpoint for replies to your comment, about once an hour for two days and then less often, for 30 days. For a comment sent as a Webmention, these requests include your site's address until your comment is found among the site's comments.

**Telling sites you follow them.** When you follow a site, your site publishes a small, unlisted page saying so and sends that site a Webmention with the page's address and the site's address. When you unfollow it, the page is removed and the Webmention is sent again, so the site can remove you. Turn this off under "Tell sites when you follow them" in Settings -> Daymark -> General.

**Webmentions you receive.** Without the Webmention plugin, Daymark receives Webmentions itself. When another site says one of its pages links to one of your posts, your site fetches that page once to check the link and read the like, reblog, or reply. One site can send at most 30 an hour. With the Webmention plugin active, Daymark still checks a Webmention that names your home page, since that is how another site tells you it follows you: your site fetches that page once to read the follow.

**Microsub import.** Settings -> Daymark -> Import/Export can import the sites you follow on a Microsub server (a separate feed service some IndieWeb sites use, such as Aperture). Only when you click "Sign in and load my list", your site fetches the web address you typed to find its sign-in page and its Microsub server, and your browser is sent to that sign-in page with your site's address, so you can approve it there (this is IndieAuth). Your site then sends the one-time code it gets back to that address's token endpoint, asks the Microsub server for your channels and the sites each one follows, and asks the token endpoint to cancel the sign-in. Daymark does not store the token. Nothing else is sent, and the sites you then import are fetched like any other subscription.

= How do I follow a site? =

In the Daymark app, open the Me screen and tap "Follow a site", or tap Following there (or "See all" beside People on Explore) and then "+ Follow a site". Paste the site's address, pick one of the feeds Daymark finds, and tap Follow. You can do the same in Settings -> Daymark -> Subscriptions. New posts from the site then appear in your Daymark Timeline. Settings -> Daymark -> General sets how often Daymark checks for new posts.

To follow many sites at once, import an OPML file from your feed reader on the Import / Export tab. With Jetpack and a linked WordPress.com account, you can also import the sites you follow in the WordPress.com Reader. If you keep a reading list on a Microsub server (such as Aperture), sign in with your web address on the same tab and choose which of its sites to follow here; Daymark reads the list once and each site you pick becomes an ordinary followed site. Following sites needs an administrator, since the list is shared by everyone on the site.

You can also follow a site while you're reading it: click the Daymark bookmarklet (see "Can I Reblog or Like a post I'm reading on another site?" below) and tap Follow. If the site has a "Follow me with Daymark" block, type your own site's address into it: your Daymark opens with that site filled in, ready to follow.

= Can an AI agent use Daymark for me? =

Yes, if you connect one. Daymark offers four things through WordPress's Abilities API: post a Mark, read your Timeline, see who responded to your Marks, and follow a site. An agent reaches them with a sign-in you give it (an application password, or the MCP Adapter plugin), acts as you, and can do no more than you can in the app. A Mark it writes is saved as a draft unless you ask it to publish. It can't like, comment, reblog or delete, and reading your notifications doesn't mark them as seen.

Reading your Timeline hands your friends' posts, and the names of people who responded to you, to whichever AI service runs your agent. Daymark itself sends nothing to any AI service for this.

= Can visitors follow my site from their own Daymark? =

Yes. Add the "Follow me with Daymark" block to a page, a sidebar, or your site's footer. A visitor who uses Daymark types their own site's address and taps Follow, and their own Daymark opens with your site filled in. Nothing is followed until they confirm there. The block sends nothing anywhere itself: the visitor's browser simply goes to their own site. It also shows your feed's address, for anyone using another feed reader, and in your feed it shows only that address.

= Can I share the sites I follow as a blogroll? =

Yes, if you want to. In Settings -> Daymark -> General, check "Share the sites you follow as a public blogroll". Daymark then publishes your active subscriptions as an OPML file that feed readers can import, and links to it from every page of your site with `<link rel="blogroll">`. To show the list on a page, add the Blogroll block. It's off by default.

If your site still has entries in WordPress's old Links (blogroll) list, Settings -> Daymark -> Import / Export offers to import them as subscriptions.

= Can I keep Notes off my blog's home page? =

Yes. Notes show on the home page and in the main feed by default, like any other post. To keep them off, check "Keep Notes off your blog's home page and main feed" in Settings -> Daymark -> General. A Note is any post with the Aside post format: Notes you post from Daymark, including reblogs and replies, and Aside posts you write in the block editor. Notes then stay off the home page and the main RSS feed, but keep their own pages and still appear in archives, search, and Daymark's Timeline. Notes stay ordinary posts, so nothing changes if you deactivate Daymark. A block theme's home page follows this setting when its Query Loop block inherits the template's query, which is the usual setup.

You don't need to give a Note a title in the block editor. When you publish an Aside post with no title, Daymark gives it one from its first few words, the same way the app titles a Note, so it gets a readable web address and a title in feeds.

= What's the character count on Notes in the block editor? =

When you write a post with the Aside format, or a Standard post with no title, the block editor's Post sidebar counts down from 300 characters, the length of one Bluesky post. Up to 300 characters, it's a short post: plugins that share to Bluesky, such as ATmosphere, post its text as an ordinary social post, without a title. Past 300, it's a long post, which ATmosphere shares as a link card to it instead of its full text. If it has images, ATmosphere still posts it with its images and shortens the text. A Standard post with a title is always shared as a link card, so the counter doesn't show for it. The count runs in your browser and can differ from ATmosphere's by a few characters; ATmosphere's own panel when you click Publish is the exact check. The counter shows whether or not a Bluesky plugin is active.

= What's a Check In? =

A Check In is a Mark whose point is *where* you are, not media or a written caption — tap the "+ New Mark" launcher's Check In bubble and Daymark quietly reverse-geocodes your captured location into an editable Place field (no address lookup service credentials needed on your end — it uses a free, keyless geocoding service). You can always edit or replace the detected place before publishing, and add your own thoughts too, but neither is required: a Check In with just a place name is a complete, publishable Mark. It publishes as a real post naming the place (linking out to a map when a location was captured), titled "Checked in at {place}" when you haven't added your own caption. You can also turn any Mark you're writing into a Check In with the Place button under its caption, and turning Place off again removes the location it saved.

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

Yes, with the Daymark bookmarklet. Drag the Daymark button to your browser's bookmarks bar. You'll find it in the Daymark section of your profile page (Users -> Profile), in Settings -> Daymark -> General, or under Me -> Reblog from anywhere in the app. On any post you're reading, click Daymark in the bookmarks bar. A small window opens on your own site with the post at the top. Write your thoughts below it, change the title if you like, and tap Reblog: the Reblog is published on your site, with the post embedded when it offers an embed. Like works when the post's site can receive one, the same as in the app. If you're an administrator, the window can also follow the post's site: tap Follow, pick one of the feeds Daymark finds, and tap Follow again. You need to be logged in to your site in that browser.

= Can I create a Mark while offline? =

Yes. Compose, add media, and tap Publish (or Save as Draft) with no connection at all — Daymark saves it on your device and shows the same confirmation screen either way. It publishes automatically as soon as you're back online; until then it waits at the top of your Timeline, marked Offline (a draft waits under Pending on Home). This covers a session already open when you go offline (or start one offline); loading `/daymark` for the very first time with zero connectivity doesn't work yet — that needs a network for the initial page load.

= Does it work offline? =

Mostly, for the part that matters most: creating a Mark works fully offline once the app is open (see the previous question). A conservative service worker also caches the app's static CSS and JS for fast loading. What doesn't work offline: loading `/daymark` for the first time with no connection, and reading your Timeline, notifications, or other people's content — those still need a connection, and REST responses, nonces, HTML, and media are never cached.

== Screenshots ==

1. Timeline — your friends' posts and your own in one feed, newest first, with no algorithm and no ads.
2. New Mark — add photos and a caption, see where it goes (your site first, then the networks you've connected), and tap Publish.
3. A friend's post — read it in full, then like, comment, reblog, or bookmark it without leaving the app.
4. Explore — search everything, see who posted lately, browse your tags, look back at this time in past years, and find sites you might follow.
5. Notifications — replies, likes, and reblogs from other sites, Mastodon, and Bluesky, each labeled by where it came from.
6. Me — your drafts and "Your days", a calendar of the days you published on.
7. Follow a site — enter a site's address, pick a feed, and its new posts show up in your Timeline.
8. Settings -> Daymark — scan the code to open Daymark on your phone, and add the plugins that carry your posts to the fediverse and Bluesky.

== Changelog ==

= 0.23.0 - 2026-11-19 =
**Added**

* Tap a photo while you write a Mark to give it a shape (Original, Square, Tall or Wide, cut from the middle) or turn it with Rotate, so a sideways or badly framed photo no longer means a trip to the Photos app first. Your site makes the edit and keeps the original, and Original puts it back.
* Publish later: under Tags, a new "When" line lets you pick a date and time for a Mark to go out. Publish then reads "Schedule", your site publishes the Mark at that time and sends it to the same places, and the Me screen lists scheduled Marks so you can change the time, publish now, or cancel.
* If you keep your reading list on a Microsub server such as Aperture, Settings -> Daymark -> Import/Export can now bring those sites into Daymark. You sign in once with your web address, pick sites from a checklist grouped by your channels, and each one becomes an ordinary followed site. Daymark reads the list once and keeps no sign-in.
* Search now finds people, tags and places. As you type, matching sites you follow, your tags and your Check In places appear above the results as shortcuts, and the words you type also match tag names and the names of sites you follow. The Date filter gains This Year, Last Year and "Pick a month".
* The Me screen now opens with your drafts and "Your days", a month calendar of the days you published on. Tap a day to see that day's Marks. Your name now shows your site and how many Marks you have published, and the links sit below under "App and account". Builds on the Me screen yusuf-saif proposed in [#432](https://github.com/jeffpaul/daymark/pull/432).
* The Me screen has a new Connections card: your site, each place your Marks are also shared, and how many sites you follow (with a flag when one can't be reached). Tapping a row opens the place to act on it. Following opens a page of every site you follow, with the favorite stars and Follow a site, and a Back button to Me. Builds on the Connections tab yusuf-saif proposed in [#432](https://github.com/jeffpaul/daymark/pull/432).
* On Explore, the People row now rings anyone whose posts have arrived since you last opened Explore, so you can see who is new at a glance. A new "See all" link beside it opens the full list of sites you follow.
* Explore has a new section, "Sites you might follow": a short list of sites you don't follow yet, taken only from people already around your site. Each one says why it is there (it follows you, or its owner replied to or liked your Marks), Follow opens Follow a site with the address filled in, and "Not now" hides it for good. Nothing is ranked and nothing leaves your site.

**Changed**

* Daymark's wordpress.org page has eight new screenshots in place of four that showed screens the app no longer has: the Timeline with friends' posts, the composer, an open post, Explore, Notifications, Me, Follow a site, and the settings page. The README now shows three of them too.
* Explore and Search are now one tab. Search sits at the top with a Bookmarks button beside it, and under it are your recent searches, the people you follow (whoever posted last comes first), your most-used tags and Look back. The tab's icon is a magnifying glass with the compass needle inside.
* On Explore, "On this day" is now "Look back": a strip of small tiles you swipe through. It still shows your posts from this date in earlier years, and now adds one from the same week in a year that has none on the date itself, one from six months ago and one from a month ago, so there is something to find on most days. Tap a tile to open the post. Builds on the "On this day" section yusuf-saif added in [#413](https://github.com/jeffpaul/daymark/pull/413).
* Notifications is now a tab in the bottom bar, where Search used to be, and it shows a mark when something new is waiting. The bell is gone from the top of each screen.
* With nothing typed, search shows a quiet starting screen where it used to show a copy of the Timeline. Its filters now sit in one row that slides sideways, so results start higher on the screen.
* While you write a Mark, the first destination now shows your site's own icon and address (such as "example.com") where it used to say "Your site", and the "Where this goes" list shows your site's name and address. It's a small reminder that every Mark lives on your own site first.
* The "Where this goes" sheet now shows each network's icon and the account your Mark will be posted as: your Bluesky handle from ATmosphere, your Mastodon address from Share on Mastodon, or your X account from Autopost for X, with the sharing plugin named in small print. The network's chip on the composer carries its icon too.
* "Published." now comes with a small check mark that pops in, and the first Mark you ever publish gets a short burst of confetti. Both stay still when your device asks for reduced motion.
* On the composer, Publish is now the one big button. "Save as draft" sits beside it as a quiet text button, since Daymark already saves a draft as you type and when you leave.
* Daymark's plugin description and README now use six lines from the original planning notes, such as "Publish once. Own it forever. Share everywhere.", to say in everyday words why posting on your own site first matters.
* A Timeline with nothing on it yet now greets you with a welcome card: what Daymark is for, a big "Publish your first Mark" button, and "Follow a site" beside it. Before, it was one line of small text.
* A Mark with several photos is quicker to finish. The photo row now ends in an "Add" tile, so another photo or video can be added right where the photos are. Each photo's sheet has previous and next arrows and a "Next photo" button, so you can describe every photo in one pass without closing the sheet in between.
* Daymark's banner on wordpress.org is calmer: a sunrise with the five kinds of Mark (photo, video, check-in, audio, note) in an arc above it, the way they fan out from the + button in the app. It replaces the crowded artwork of glass cards and light trails.

**Fixed**

* On an iPhone or iPad, the Install card no longer tells everyone to look "in Safari". Firefox and Chrome each get their own Add to Home Screen steps, Safari's steps say where Share is hiding on newer iPhones, and any other browser gets steps that name no browser.
* Adding Daymark to an iPhone's home screen from Firefox now shows the Daymark icon instead of a blank tile. Firefox doesn't pass the page's own icon along, so iOS looks for one at the top of your site (`/apple-touch-icon.png`), and Daymark now answers there. That address stands for your whole site, so a site that wants its own picture there can put a file at that address or use the `daymark_root_touch_icon_file` filter.
* Opening a post from a followed site that uses a newer WordPress block theme now shows that post's own text. Some of these sites list other stories lower on the page, and Daymark could show one of those under the post's title. For a post you already opened, use "Refresh content" to fetch it again.

**Developer**

* The sound-file tests now pass on WordPress 7.2, which accepts a browser's MP4 recording named .m4a on its own and stores it as audio/mpeg, like any .m4a file. Daymark keeps that type; older WordPress still gets audio/mp4.
* The browser tests for a Check In at a typed place now wait long enough for the weather lookup on the site, which may take 4 seconds when the weather service is slow. They failed whenever it did.
* The browser test for a per-Mark sharing switch now reads the publish request in a way that can't miss it. The old check could come up empty and fail the test even though the Mark was published correctly.
* A release now goes to the wordpress.org plugin directory by itself. Pushing a version tag still builds the zip and publishes the GitHub release, and the same run then sends that version, with the banner, icon and screenshots, to wordpress.org.
* The plugin no longer carries RECOVERY.md, an old project note that had shipped in every release since August, and the file is gone from the repository. GitHub's source downloads now leave out DEPENDENCIES.md, as the plugin zip already did, and a new check fails when the two would ship different files or a stray Markdown file.

= 0.22.0 - 2026-11-05 =

**Added**

* You can now record sound right in the composer: Record Audio, or Audio under the caption, records with your microphone, and you can listen back before adding it to your Mark. Daymark also takes M4A files, such as an iPhone's Voice Memos, plus AAC, Ogg, and WebM, and turns down a file it can't take as soon as you pick it, naming the formats it does take.
* Writing a Note in the app now shows how many of the 300 characters that fit in one Bluesky post are left, and says when a longer Note will be shared as a link instead, like the block editor's Note count. The caption box also grows as you type instead of scrolling inside itself.
* Leaving the composer with something written now says "Saved to Drafts," with Discard if you'd rather throw it away. A new, empty composer also links to your drafts.
* You can now star and unstar favorite sites directly from the Settings -> Daymark Subscriptions table, including bulk actions and a Favorites filter view.
* A Mark's "Where this went" view now lists the copies your publishing plugins made (Jetpack Social, Share on Mastodon, XPoster, Autopost for X, ATmosphere, Autoblue, and ActivityPub), and the Mark's page links to them, so Bridgy can bring replies from those copies back to your site. Bridgy is now on the Connectors tab.
* On a post from a site you follow, tap Show replies to read the replies on it, from a WordPress site or a Mastodon-style server. After you comment on a WordPress site through Daymark, Daymark checks that site for replies to your comment for 30 days and tells you when someone answers (not yet for comments sent through WordPress.com).
* Follow a site from wherever you find it. The bookmarklet can now follow the site of the post you're reading, and the new "Follow me with Daymark" block lets visitors type their own site's address to open their Daymark ready to follow yours. The block also shows your feed's address for any other feed reader.
* When Like is missing or Comment opens the post on its own site, the full post view now says why under the post, such as "This is a Bluesky post. Daymark can like it once your site is connected to Bridgy Fed." When something on your site would fix it, an administrator also gets a link to it.
* Right-to-left (RTL) layout support for Arabic, Hebrew, Persian, Urdu, and other right-to-left languages. Daymark's layout, menus, arrows, Timeline spine, and gallery sliders now mirror to match reading direction when WordPress uses an RTL language. Keys and swipe gestures in the gallery slider and tab list follow reading direction, and CI enforces logical CSS properties.

**Changed**

* Daymark's plugin description and README now explain the idea behind it: post on your own site first and share copies to other networks (what the IndieWeb calls POSSE), follow friends' own sites the way you'd follow them on a social app, and rely less on walled-garden social networks.
* A Check In now goes where the place you type in Place is, when its name matches a place the search found, instead of your phone's exact spot, so naming a neighborhood or city maps that area. Place search also lists places near you first, and now finds a name with an & in it.
* A Mark with no title now shows its caption once, on its card and in the post view, instead of a bold title made of the caption's first words with the same words again under it. That made-up title is still used for feeds, browser tabs, and link previews.
* Photos and files on a Mark now show as a row of thumbnails under the caption, instead of a large block each that pushed the caption off the screen. Each photo has an ALT badge that fills in once it's described; tap a thumbnail to write its alt text or move it, and tap its × to remove it, now also from a draft you reopened.
* Every Mark you write now has a small "Add" bar under the caption: Photo or video, Audio, and Place. A Note can take a photo or a place without starting over. Turning Place on makes the Mark a Check In, and turning it off again removes the place and location it saved.
* Publish now sits on the composer itself, so a Mark no longer needs a second "Where should this go?" screen. A "Where this goes" row shows your site and each destination as a switch you tap on or off, with the full list one tap away, and categories and tags are folded under the caption.
* Reblogs no longer wait for the connection: Publish takes you straight back to where you were, and a reblog made offline waits on this device and publishes once you're back online. The title, which most people leave as it is, is now folded under "Title".
* Publish and Save as draft now take you straight back to where you started, usually the Timeline with your new Mark on top, and a short message says "Published" (with View) or "Saved as draft." This replaces the separate Success screen; where a Mark went is still on its card's "Where this went" menu.
* Daymark's description on its plugin page and in the Plugins list now leads with what it's for: following your friends' sites in one calm, chronological feed, then posting from your phone to your site and the networks you use.
* With Simple Location 6.1.0 or later, a Check In's weather now shows Simple Location's weather icon and Open-Meteo credit, not just the temperature and a text summary. Simple Location 6.1.0 can read the weather codes from Open-Meteo, the service Daymark uses, so Daymark now passes them along.
* The Publish screen now shows categories and tags as two folded sections. Folded, each shows the current choice (your usual categories, or the tags AI suggested); tap to change it. Before, tags could only be changed inside AI Assist, so suggested tags were added with nowhere to see or remove them, and a tag removed from a draft came back.
* On a site that also runs Post Kinds 4.0, Daymark now files each Mark under the matching kind (Photo, Video, Audio, Note, Check-in, Repost, Reply) instead of leaving every Mark as a Note, and keeps Post Kinds' empty reply/repost box off Marks. Followed WordPress sites that run Post Kinds now show replies, reposts, likes, bookmarks, RSVPs, and check-ins as such on their cards.
* With Post Kinds 4.0.1 or later, Daymark tells Post Kinds not to build its reply/repost box for Marks at all, instead of building it and then hiding it, so Mark pages do a little less work. Nothing on the page looks different.
* With ActivityPub 9.4.0 or later, Daymark looks up fediverse posts for Likes and Reblogs the way the plugin now asks, so sites with debugging turned on no longer log a deprecation notice each time. Likes and Reblogs work the same as before.
* With the Syndication Links plugin active, a Mark's links to its copies on other sites now appear in that plugin's own list, following its display settings, instead of in a separate "Also on" line from Daymark. Notifications no longer suggests turning Syndication Links off.

**Fixed**

* Names now show in the app as you typed them. A site title with an apostrophe read `JP&#039;s Daymark` on your Marks and in the post view, and an & in a category, tag, or author name read `&amp;`.
* A Check In draft opened from Drafts keeps its map when you publish it. Before, the map was left out of the post.
* Times in the app are now right wherever your phone is: a post from a site you follow could read "Just now" for hours on a phone outside UTC, and your own Marks and "Replies last checked" were off when your phone and site were in different time zones. Search's date filters, like Today, also no longer miss or add hours of Marks on a site outside UTC.
* A Mark dated before your newest posts, by its photo or by when you started its draft, now appears where that date puts it on your Timeline right after you publish it, instead of on top until the Timeline reloads.
* Share on Mastodon and Autopost for X now share a Mark you publish from the app when its Mastodon or X switch is on, including a Mark dated by an older photo. Before, both waited for a step only the block editor takes, so neither shared Marks from the app.
* A photo you remove from a Mark that has already saved now comes off the saved draft too. A photo Mark's featured image is now always its first photo; before, a photo that finished uploading later could take its place.
* Emptying a Mark that has already saved no longer shows "Not saved yet — will retry" or leaves a "Couldn't save draft" card. A new Mark you empty is discarded when you leave, and a draft you reopened keeps what it last saved.
* Adding a photo to a photo draft you reopened now makes it a gallery, not a mixed-media Mark.
* A Check In saved on this device while offline now reopens as a Check In with its place, instead of as a Note. A Check In whose location arrives after it was first saved now gets its weather too.
* A file the site won't take (the wrong type, or too large) now leaves the composer right away, with the reason. Before, leaving the composer with that file in it turned your unsent draft into a "Couldn't publish" card; now the draft saves its text. A draft that fails to save now says "Couldn't save draft" instead of "Couldn't publish".
* Unticking every destination or category on the Publish screen is now kept. Before, the site treated an empty choice as no choice and used your usual destinations and categories anyway. And the next Mark of the same type now starts from the choice you just saved, instead of the one from when you opened the app.
* The Publish screen now shows a "Bluesky via ATmosphere" switch wherever ATmosphere shares your posts, and it starts on, so a Mark goes to Bluesky unless you turn it off for that Mark. Before, the switch was missing when ATmosphere came from wordpress.org, and where it did show, it started off and kept that Mark off Bluesky.
* Posts published in the same second now always appear in the same order on the Timeline and in Search. Before, scrolling could show one of them twice and skip another, and the new-posts count could include one you had already seen.
* Push notifications keep arriving after the browser changes or rotates a device's push address, saving the new address right away through the service worker and re-syncing on app start.
* Adding Daymark to your phone's home screen now shows the Daymark icon instead of a blank square. The icon had see-through corners, which iPhones won't use for a home-screen icon; Daymark now gives iPhones a solid square icon and gives Android an icon it can shape like other apps.
* Importing your WordPress.com Reader follows now works. Daymark asked WordPress.com for the list in a way it doesn't answer for Jetpack sites, so the import found no sites. It now uses the request Jetpack's own Blogroll block uses, also brings in feeds you follow that aren't WordPress sites, and subscribes to each site's feed directly, so a long list imports quickly.
* The public blogroll no longer lists a feed whose address might hold a private key, such as a paid podcast feed. Those feeds are still followed; they're just left out of the public list.
* A site using the Webmention plugin now hears when another Daymark site follows it. That plugin refused a follow, because a follow points at your home page rather than at a post, so you never got the new-follower notification. Daymark now picks up follows sent to the plugin's endpoint too.
* Following a site with a long name no longer makes the Search screen wider than your phone's screen. The site filter grew to fit the name and ran off the edge, which also pushed the bottom bar out of view.

**Security**

* Comments that other sites send to yours by Webmention can no longer carry hidden code. Daymark undid a site's disguised markup after its safety check instead of before, so a crafted reply could run code when you opened your comments screen.
* Anyone on the internet could flood a site's Webmention endpoint with made-up web addresses, piling up background jobs that slow every page. Daymark now refuses senders whose address doesn't exist and caps how many Webmentions all senders together can queue each hour.
* Password-protected posts no longer give away what they protect. Someone signed in who couldn't open such a post could still see its excerpt, photos, and featured content on the Timeline, and could find words from it by searching; they now see only its title and date.
* Logging out of the app on a shared browser now turns off that browser's push notifications, so the next person to sign in no longer gets the previous person's reply notifications. A logout from the WordPress login screen can't do this, so turn push off in Me first if you use that.
* A preview of a followed site's post can no longer show a page from your own site inside the app, which would have let that page run with your login. Daymark's outbound-request test now covers more ways of making a request, and the daily cleanup now removes every old unused upload rather than 100 a day.

**Developer**

* Brought docs/roadmap.md up to date: it now lists what shipped since its last update (web push, Check Ins, Featured Content, following and engagement work, the publishing audit), drops items that are done, and links each open item to its issue, including new issues #589 to #592.
* CI now pins its third-party actions and tools to exact versions, Dependabot watches the libraries shipped in `lib/`, and a new check fails when `lib/vendor-prefixed/` doesn't match `lib/composer.lock`.
* Moved CLAUDE.md's architectural decisions table into its own file, docs/architecture-decisions.md, leaving a short pointer, and dropped the finished phased-build checklists. CLAUDE.md is read at the start of every Claude Code session, and the table had grown to about 600,000 characters; sessions now start with about 32,000.

= 0.21.0 - 2026-10-13 =

**Added**

* New "Read to me" setting (Settings -> Daymark -> Data & privacy, off by default). When it's on and your AI provider can turn text into speech, a post in the app's full post view shows a Listen button at the top. The first tap makes an audio version, and Daymark saves it so the post is only sent to your provider once.
* The full post view now has Previous and Next buttons at the top. They move to the post above or below on the Timeline, Search results, or On this day list you opened it from, without going back to the list first. At the last post loaded so far, Next loads the next batch of posts, so you can keep reading to the end of the list. Back then returns to the list at the last post you read.
* You can now get push notifications on your phone or computer when someone comments on one of your Marks. Turn them on per device on the Me screen in the app. On iPhone and iPad, add Daymark to your Home Screen first.
* Star a site you follow in Explore to make it a favorite. Daymark checks favorites every hour, and with push notifications on, sends one when a favorite site publishes. Tapping it opens the new post.
* Push notifications now also tell you when you gain a follower: another Daymark or IndieWeb site, someone on the fediverse (through the ActivityPub plugin, which the Friends plugin also uses), or a Jetpack email or WordPress.com subscriber. Following a site now tells that site, which you can turn off in Settings -> Daymark -> General.
* A new setting shows the Timeline, Search, and Explore as a compact list: each post becomes one row with its title and a small thumbnail, so more posts fit on the screen. It's off by default; turn it on under Settings -> Daymark -> General.
* A new setting in Settings -> Daymark -> General shows the number of unread notifications on Daymark's app icon once the app is installed. It is off by default.
* A new Reading setting in Settings -> Daymark -> General shows the Daymark app's text in the [Bionic Reading](https://bionic-reading.com/) font. It is off by default. Daymark can't include the font, so it uses a Bionic Reading family from the site's Font Library, a web font file you host, or a copy installed on the reader's device.

**Changed**

* The "Try Daymark" Playground demo now also follows xkcd, so its Timeline shows comic image posts next to the sample blogs.

**Fixed**

* The Parse This card on Settings -> Daymark -> Connectors now links to Parse This on GitHub, where it's published, and no longer shows an Install button that couldn't work. Parse This isn't in the WordPress.org plugin directory. The readme's Parse This answer says so too.
* More of Daymark now appears in your language once translations for it exist on translate.wordpress.org: the Featured Content control in the block editor, the offline version of the app, weather labels, and the fallback suggestions shown when an AI provider can't answer. Dates in the app now use WordPress's own translated month and day names.
* After a Daymark update, the app saved to your home screen now runs the new version the first time you open it. Before, it kept running the old version for one more launch, and on iPhone that could last until the phone fully closed the app.

**Developer**

* `bin/check-i18n.sh` checks every translatable string the way wordpress.org extracts them, and CI now runs it.

[View the full changelog history](https://github.com/jeffpaul/daymark/blob/main/CHANGELOG.md).

== Upgrade Notice ==

= 0.23.0 =
Crop or rotate a photo and schedule a Mark from the composer. Explore and Search are now one tab that finds people, tags and places, Notifications has its own tab, and Me opens with a calendar of your days. Explore adds Look back and Sites you might follow.

= 0.22.0 =
Publish now sits on the composer, you can record sound in the app, and photos show as a strip with alt-text badges. Each Mark lists the copies shared to other networks, so replies can flow back. Right-to-left languages are supported. Includes five security fixes, so update when you can.

= 0.21.0 =
Push notifications arrive for comments, new posts from favorite sites, and new followers. Read to me adds an AI audio version of a post. The post view gains Previous and Next, and new settings add a compact Timeline, an unread count on the app icon, and the Bionic Reading font.

= 0.20.0 =
Daymark now sends and receives Webmentions itself, so Likes, Reblogs, and comments reach other Daymark sites. Also new: Notifications tabs, following sites from the app, WordPress.com Reader import, resumable video uploads, and a dark theme. Location is now captured only for Check Ins.

= 0.19.0 =
Featured Content adds galleries, quotes, and links beside audio and video, shown on Timeline cards, the post view, and link previews. The Timeline remembers where you left off, Search gains a Date filter, and Explore adds On this day.
