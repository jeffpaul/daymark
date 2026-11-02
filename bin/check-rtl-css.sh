#!/usr/bin/env bash
#
# Checks that Daymark's stylesheets use logical CSS properties, so the layout
# mirrors for right-to-left languages (issue #524).
#
# Fails on any declaration in assets/*.css that fixes something to the left
# or right side of the screen:
#
#   - physical properties: left, right, margin-left, padding-right,
#     border-left (and its -width/-style/-color longhands), the
#     border-*-left/right-radius corners, scroll-margin-left, and so on
#   - text-align, float, or clear set to left or right
#   - four-value margin, padding, inset, scroll-margin, scroll-padding,
#     border-width, border-style, or border-color shorthands whose right and
#     left values differ
#   - border-radius values whose left and right corners differ
#
# A line that must stay physical (centering with left: 50% and
# translateX(-50%), or something that is never mirrored) can opt out with a
# comment containing "rtl:ignore" on the same line, ideally with the reason.
#
# translateX() offsets can't be judged automatically; mirror them with
# var(--daymark-dir) (see assets/app.css).
#
# Usage: bin/check-rtl-css.sh

set -euo pipefail

cd "$(dirname "$0")/.."

node - assets/*.css <<'NODE'
const fs = require('fs');
const files = process.argv.slice(2);

const PHYSICAL_PROP =
	/^(left|right|(margin|padding|scroll-margin|scroll-padding)-(left|right)|border-(left|right)(-(width|style|color))?|border-(top|bottom)-(left|right)-radius)$/;
const FOUR_SIDE_SHORTHAND =
	/^(margin|padding|inset|scroll-margin|scroll-padding|border-width|border-style|border-color)$/;
const SIDE_KEYWORD_PROP = /^(text-align|float|clear)$/;

// Splits a value on whitespace outside parentheses, so calc(a + b) or
// var(--x, 1px 2px) count as one value.
function splitValues(value) {
	const out = [];
	let depth = 0;
	let current = '';
	for (const ch of value) {
		if ('(' === ch) {
			depth++;
		} else if (')' === ch) {
			depth--;
		}
		if (0 === depth && /\s/.test(ch)) {
			if (current) {
				out.push(current);
			}
			current = '';
		} else {
			current += ch;
		}
	}
	if (current) {
		out.push(current);
	}
	return out;
}

function problem(prop, value) {
	const clean = value.replace(/!important/i, '').trim();
	const values = splitValues(clean);

	if (PHYSICAL_PROP.test(prop)) {
		return 'is a physical property; use the inline-start/inline-end form';
	}
	if (SIDE_KEYWORD_PROP.test(prop) && /^(left|right)$/.test(clean)) {
		return 'uses left/right; use start/end (inline-start/inline-end for float and clear)';
	}
	if (FOUR_SIDE_SHORTHAND.test(prop) && 4 === values.length && values[1] !== values[3]) {
		return 'sets different right and left values; use the -block and -inline forms';
	}
	if ('border-radius' === prop && !clean.includes('/') && values.length > 1) {
		const [tl, tr = tl, br = tl, bl = tr] = values;
		if (tl !== tr || br !== bl) {
			return 'gives left and right corners different radii; use border-start-start-radius and friends';
		}
	}
	return '';
}

let violations = 0;

for (const file of files) {
	const lines = fs.readFileSync(file, 'utf8').split('\n');
	let inComment = false;

	lines.forEach((line, idx) => {
		let clean = line;
		if (inComment) {
			const end = clean.indexOf('*/');
			if (-1 === end) {
				return;
			}
			clean = clean.slice(end + 2);
			inComment = false;
		}
		while (clean.includes('/*')) {
			const start = clean.indexOf('/*');
			const end = clean.indexOf('*/', start + 2);
			if (-1 === end) {
				clean = clean.slice(0, start);
				inComment = true;
				break;
			}
			clean = clean.slice(0, start) + clean.slice(end + 2);
		}

		if (line.includes('rtl:ignore')) {
			return;
		}

		const declaration = /(?:^|[;{\s])([a-zA-Z-]+)\s*:\s*([^;{}]+)/g;
		let match;
		while (null !== (match = declaration.exec(clean))) {
			const prop = match[1].toLowerCase();
			const value = match[2].trim().toLowerCase();
			const reason = problem(prop, value);
			if (reason) {
				console.error(`::error file=${file},line=${idx + 1}::"${prop}: ${value}" ${reason} (or add an rtl:ignore comment with the reason).`);
				violations++;
			}
		}
	});
}

if (violations > 0) {
	console.error(`Found ${violations} physical left/right declaration(s).`);
	process.exit(1);
}
console.log(`All ${files.length} stylesheets use logical properties for right-to-left layouts.`);
NODE
