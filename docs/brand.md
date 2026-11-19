# Daymark brand colors

The Daymark brand palette is a sunset range — red through orange to gold:

| Token | Value | Use |
|---|---|---|
| Primary ember | `#C93A06` | Primary actions, accents, brand marks |
| Deep ember | `#9E2A02` | Pressed/hover states, emphasis, dark surfaces |
| Light gold | `#FFD9A8` | Tints, highlights, chips, subtle backgrounds |
| Transparent ember | `rgba(201, 58, 6, 0.12)` | Washes, focus rings, selected states |

The app shell applies these throughout via the `--daymark-accent*` custom
properties in `assets/app.css`; the manifest theme color and app icon use the
same palette. The banner and icon artwork carries the range as a gradient:
deep red overhead through orange to gold at the horizon.

Primary and deep both clear 4.5:1 on white, so either is safe for body text.

## Banner

The wordpress.org banner is a sunrise in these colors with the five Mark type
icons in cream bubbles on an arc above the sun, in the order they fan out from
the app's + button. It has no name and no words, so nothing on it needs
translating. `bin/build-wporg-banner.js` draws it: it writes the source
drawing (`.wordpress-org/src/banner.svg`), both banner sizes, and the GitHub
preview image (`.wordpress-org/src/opengraph-1280x640.png`, uploaded by hand
under the repository's Settings, Social preview). Change the script and run it
again; don't edit the PNGs.
