# Dependencies held back

Updates deliberately not taken, and why, so the question isn't reopened
every release. Checked at each release (see CONTRIBUTING.md, "Releasing").

| Package | Current | Available | Why it's held back | Since |
|---------|---------|-----------|--------------------|-------|
| `phpunit/phpunit` (dev) | 9.6 | 13.x | WordPress's own PHPUnit test library (`wp-phpunit`, used through `bin/install-wp-tests.sh` and `yoast/phpunit-polyfills`) supports PHPUnit up to 9.x. A newer major would break the whole test suite until WordPress core supports it. | 0.19.0 |
