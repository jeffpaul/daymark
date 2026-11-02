#!/usr/bin/env python3
"""Build Daymark's opaque, full-bleed home-screen icons.

The brand artwork (.wordpress-org/src/icon-source.png) is a rounded tile on a
transparent canvas. That is fine for a browser tab or a desktop install, but
not for a phone home screen:

- iOS applies its own rounded mask and expects a square, fully opaque image.
  A transparent apple-touch-icon gets black corners on older versions, and
  newer ones can drop it altogether and show a blank or letter tile.
- Android masks a "maskable" icon to whatever shape the launcher uses and
  needs the glyph inside the central safe zone (a circle 80% of the width).

This script rebuilds the tile's gradient as a smooth background that covers
the whole square, lays the original tile (inside its glossy rim) on top with
a soft edge, and writes:

    assets/apple-touch-icon.png   180x180, glyph at the tile's own scale
    assets/icon-maskable-192.png  192x192, glyph inside the safe zone
    assets/icon-maskable-512.png  512x512, same

Run from the repository root: python3 bin/build-app-icons.py
Needs Pillow and NumPy.
"""

import os

import numpy as np
from PIL import Image, ImageFilter

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SOURCE = os.path.join(ROOT, '.wordpress-org', 'src', 'icon-source.png')
ASSETS = os.path.join(ROOT, 'assets')

# Fit the background on a reduced grid; the gradient is smooth, so this is
# enough and keeps the least-squares solve small.
FIT_STEP = 6
FIT_DEGREE = 3
# Fitted coordinates are clamped to this (1.0 = the tile edge).
EDGE = 0.85


def tile_box(alpha):
    ys, xs = np.where(alpha > 250)
    return xs.min(), ys.min(), xs.max() + 1, ys.max() + 1


def interior_mask(size_x, size_y, box, rim):
    """1.0 inside the tile's rounded rectangle shrunk by the rim, else 0."""
    x0, y0, x1, y1 = box
    # Corner radius of the artwork, measured along its diagonal.
    radius = 0.2 * (x1 - x0)
    ix0, iy0, ix1, iy1 = x0 + rim, y0 + rim, x1 - rim, y1 - rim
    r = max(radius - rim, 1)
    yy, xx = np.mgrid[0:size_y, 0:size_x].astype(float)
    cx = np.clip(xx, ix0 + r, ix1 - r)
    cy = np.clip(yy, iy0 + r, iy1 - r)
    inside = ((xx - cx) ** 2 + (yy - cy) ** 2) <= r * r
    inside &= (xx >= ix0) & (xx < ix1) & (yy >= iy0) & (yy < iy1)
    return inside


def poly_terms(u, v):
    return np.stack(
        [u ** i * v ** j for i in range(FIT_DEGREE + 1) for j in range(FIT_DEGREE + 1 - i)],
        axis=-1,
    )


def main():
    src = Image.open(SOURCE).convert('RGBA')
    px = np.asarray(src).astype(float)
    h, w = px.shape[:2]
    box = tile_box(px[..., 3])
    x0, y0, x1, y1 = box
    side = int(x1 - x0)
    cx, cy = (x0 + x1) / 2, (y0 + y1) / 2

    rim = int(round(0.06 * side))
    inside = interior_mask(w, h, box, rim)

    # Background samples: inside the rim, not the cream glyph or its glow.
    rgb = px[..., :3]
    glyph = Image.fromarray(((rgb.min(axis=2) > 170) * 255).astype(np.uint8))
    glyph = np.asarray(glyph.filter(ImageFilter.MaxFilter(31))) > 0
    sample = inside & ~glyph
    ys, xs = np.where(sample)
    keep = (ys % FIT_STEP == 0) & (xs % FIT_STEP == 0)
    ys, xs = ys[keep], xs[keep]

    # Normalized coordinates centred on the tile, so the fit extrapolates
    # sensibly past the tile's edge for the maskable canvas.
    def norm(x, y):
        return (x - cx) / (side / 2), (y - cy) / (side / 2)

    u, v = norm(xs, ys)
    terms = poly_terms(u, v)
    coeffs = [np.linalg.lstsq(terms, rgb[ys, xs, c], rcond=None)[0] for c in range(3)]

    def render(extent, out_size):
        """Square canvas `extent` source pixels wide, centred on the tile."""
        # Render at 4x the output, then downsample, for clean edges.
        n = out_size * 4
        step = extent / n
        gx = cx - extent / 2 + (np.arange(n) + 0.5) * step
        gy = cy - extent / 2 + (np.arange(n) + 0.5) * step
        xx, yy = np.meshgrid(gx, gy)
        uu, vv = norm(xx, yy)
        # Past the sampled area, hold the edge colour rather than letting
        # the polynomial run off.
        uu, vv = np.clip(uu, -EDGE, EDGE), np.clip(vv, -EDGE, EDGE)
        t = poly_terms(uu, vv)
        bg = np.stack([t @ c for c in coeffs], axis=-1)

        # Original tile pixels, sampled at the same points.
        sx = np.clip(np.round(xx).astype(int), 0, w - 1)
        sy = np.clip(np.round(yy).astype(int), 0, h - 1)
        art = rgb[sy, sx]
        m = inside[sy, sx] & (xx >= 0) & (xx < w) & (yy >= 0) & (yy < h)
        # Feather the seam between the real tile and the rebuilt background.
        feather = Image.fromarray((m * 255).astype(np.uint8)).filter(
            ImageFilter.GaussianBlur(radius=float(max(1.0, (rim * 0.5) / step)))
        )
        f = np.asarray(feather).astype(float)[..., None] / 255.0
        # Fade from art to background only on the art's side of the edge.
        f = np.clip((f - 0.5) * 2.0, 0.0, 1.0)
        out = np.clip(art * f + bg * (1 - f), 0, 255).astype(np.uint8)
        img = Image.fromarray(out, 'RGB').resize((out_size, out_size), Image.LANCZOS)
        return img

    # iOS: the tile itself, edge to edge (iOS rounds the corners).
    render(side, 180).save(os.path.join(ASSETS, 'apple-touch-icon.png'), optimize=True)

    # Android maskable: the glyph's farthest pixel must sit inside a circle
    # 40% of the canvas width from its centre.
    light = (rgb.min(axis=2) > 200) & inside
    ly, lx = np.where(light)
    reach = np.sqrt((lx - cx) ** 2 + (ly - cy) ** 2).max()
    extent = max(side, reach / 0.38)
    for size in (192, 512):
        render(extent, size).save(
            os.path.join(ASSETS, 'icon-maskable-%d.png' % size), optimize=True
        )


if __name__ == '__main__':
    main()
