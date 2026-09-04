# Security Policy

## Supported versions

Daymark is an active prototype. Security fixes are made against the latest
release only; there are no long-term support branches.

| Version | Supported |
| ------- | --------- |
| 0.10.x  | ✅        |
| < 0.10  | ❌        |

## Reporting a vulnerability

**Please do not report security issues in public GitHub issues, pull requests,
or discussions.** Public disclosure before a fix is available puts every user
at risk.

Instead, report privately in either of these ways:

- **Preferred:** open a private advisory via GitHub's
  [Report a vulnerability](https://github.com/jeffpaul/daymark/security/advisories/new)
  form (Security tab → Report a vulnerability).
- **Email:** <jeffpaul@hotmail.com> with "Daymark security" in the subject.

Please include enough detail to reproduce: affected version, environment
(WordPress and PHP versions), steps or a proof of concept, and the impact you
observed.

## What to expect

- **Acknowledgement** within 5 business days.
- An assessment of severity and, if valid, a plan and rough timeline for a fix.
- Coordinated disclosure: we will agree on a public disclosure date once a fix
  is released, and credit you in the release notes unless you prefer to remain
  anonymous.

## Scope

This policy covers the Daymark plugin code in this repository. Vulnerabilities in
WordPress core, PHP, or third-party plugins (including any syndication,
federation, or AI provider plugins Daymark integrates with) should be reported to
those projects directly. Issues in how Daymark *uses* those integrations are in
scope here.
