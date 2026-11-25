# Dependencies held back

Updates deliberately not taken, and why, so the question isn't reopened
every release. Checked at each release (see CONTRIBUTING.md, "Releasing").

| Package | Current | Available | Why it's held back | Since |
|---------|---------|-----------|--------------------|-------|
| `phpunit/phpunit` (dev) | 9.6 | 13.x | WordPress's own PHPUnit test library (`wp-phpunit`, used through `bin/install-wp-tests.sh` and `yoast/phpunit-polyfills`) supports PHPUnit up to 9.x. A newer major would break the whole test suite until WordPress core supports it. | 0.19.0 |

Daymark bundles `akirk/wp-app` (^2.3) without namespace prefixing so WP Apps
share the framework registry and launcher integration across plugins. Its
separate manifest and lockfile live in `lib/framework/`; run
`bash bin/build-framework.sh` to install `lib/framework/packages/`. Only the
manifest and lockfile are committed; generated dependencies are ignored.
Root `composer install` also installs the runtime. `bin/build-dist.sh` installs
the locked runtime in a temporary directory and includes it in the release ZIP.

wp-app owns the offline document, safe bootstrap, exact asset precaching, and
navigation fallback. Daymark adds push handlers through `worker_scripts` and
passes its REST URL through `worker_data`. Its extension preserves device push
tokens when retiring old Daymark caches and clears the current shell and token
at logout. Existing IndexedDB storage, uploads, and publishing queues remain
Daymark-owned.

Daymark registers screens and extensionless endpoints within one WpApp
namespace. Screen routes require `edit_posts`; the manifest is public, and
machine-fetched endpoints enforce their own JSON authentication responses.
No old endpoint aliases or legacy route redirects are registered.
