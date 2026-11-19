#!/usr/bin/env node
/**
 * Build Daymark's wordpress.org banner and the matching GitHub preview image.
 *
 * The banner is a sunrise in the brand's sunset colors (docs/brand.md) with
 * the five Mark type icons in an arc above the sun: the same icons, in the
 * same order, as the fan that opens from the app's + button. It carries no
 * name and no words, so there is nothing on it to translate.
 *
 * Writes:
 *
 *     .wordpress-org/src/banner.svg              the drawing, kept as the source
 *     .wordpress-org/banner-1544x500.png
 *     .wordpress-org/banner-772x250.png
 *     .wordpress-org/src/opengraph-1280x640.png  GitHub's social preview size
 *
 * Run from the repository root, after `npm ci`: node bin/build-wporg-banner.js
 * Needs Playwright's Chromium (`npx playwright install chromium`).
 */

const fs = require( 'fs' );
const path = require( 'path' );

const ROOT = path.dirname( __dirname );
const OUT = path.join( ROOT, '.wordpress-org' );

const CREAM = '#FFF4E6';
// Deep ember, docs/brand.md.
const EMBER = '#9E2A02';

// The app's own Mark type glyphs: TYPE_ICONS in assets/app.js, 24x24 line
// icons. Keep these in step with that constant.
const TYPE_ICONS = {
	image: '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
	video: '<polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/>',
	checkin:
		'<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
	audio: '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
	note: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>',
};

// Left to right, the order the launcher fans its bubbles out in (app.css).
const ORDER = [ 'image', 'video', 'checkin', 'audio', 'note' ];
// Degrees between neighbouring bubbles, the launcher's own spacing.
const STEP = 25;

/**
 * The banner as an SVG string.
 *
 * @param {Object} o
 * @param {number} o.width   Canvas width.
 * @param {number} o.height  Canvas height.
 * @param {number} o.sunY    The sun's center, below the bottom edge.
 * @param {number} o.sun     The sun's radius.
 * @param {number} o.arc     Radius of the arc the bubbles sit on.
 * @param {number} o.bubble  A bubble's radius.
 * @param {number} o.icon    An icon's width and height.
 * @return {string} SVG markup.
 */
function banner( { width, height, sunY, sun, arc, bubble, icon } ) {
	const cx = width / 2;
	const round = ( n ) => Math.round( n * 100 ) / 100;

	const bubbles = ORDER.map( ( type, i ) => {
		const angle = ( ( 90 + ( 2 - i ) * STEP ) * Math.PI ) / 180;
		const x = round( cx + arc * Math.cos( angle ) );
		const y = round( sunY - arc * Math.sin( angle ) );
		return `
	<circle cx="${ x }" cy="${ y }" r="${ bubble }" fill="${ CREAM }" filter="url(#shadow)"/>
	<g transform="translate(${ round( x - icon / 2 ) } ${ round( y - icon / 2 ) }) scale(${ round( icon / 24 ) })" fill="none" stroke="${ EMBER }" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${ TYPE_ICONS[ type ] }</g>`;
	} ).join( '' );

	return `<svg xmlns="http://www.w3.org/2000/svg" width="${ width }" height="${ height }" viewBox="0 0 ${ width } ${ height }">
	<defs>
		<linearGradient id="sky" x1="0" y1="0" x2="0" y2="1">
			<stop offset="0" stop-color="#9A220E"/>
			<stop offset="0.36" stop-color="#CF3B10"/>
			<stop offset="0.62" stop-color="#E65A16"/>
			<stop offset="0.82" stop-color="#F28430"/>
			<stop offset="1" stop-color="#FCB040"/>
		</linearGradient>
		<radialGradient id="halo" cx="0.5" cy="0.5" r="0.5">
			<stop offset="0.45" stop-color="#FFD27A" stop-opacity="0.55"/>
			<stop offset="1" stop-color="#FFD27A" stop-opacity="0"/>
		</radialGradient>
		<linearGradient id="sun" x1="0" y1="0" x2="0" y2="1">
			<stop offset="0" stop-color="${ CREAM }" stop-opacity="0.95"/>
			<stop offset="0.55" stop-color="#FFE2B0" stop-opacity="0.9"/>
		</linearGradient>
		<filter id="shadow" x="-50%" y="-50%" width="200%" height="200%">
			<feDropShadow dx="0" dy="8" stdDeviation="10" flood-color="#7A1A08" flood-opacity="0.35"/>
		</filter>
	</defs>
	<rect width="${ width }" height="${ height }" fill="url(#sky)"/>
	<circle cx="${ cx }" cy="${ sunY }" r="${ round( sun * 1.9 ) }" fill="url(#halo)"/>
	<circle cx="${ cx }" cy="${ sunY }" r="${ arc }" fill="none" stroke="${ CREAM }" stroke-width="2" opacity="0.28"/>
	<circle cx="${ cx }" cy="${ sunY }" r="${ arc + 150 }" fill="none" stroke="${ CREAM }" stroke-width="2" opacity="0.1"/>
	<circle cx="${ cx }" cy="${ sunY }" r="${ sun }" fill="url(#sun)"/>${ bubbles }
</svg>
`;
}

const WPORG = banner( { width: 1544, height: 500, sunY: 690, sun: 345, arc: 495, bubble: 58, icon: 56 } );
const OPENGRAPH = banner( { width: 1280, height: 640, sunY: 830, sun: 410, arc: 590, bubble: 68, icon: 66 } );

async function render( browser, svg, file, width, height ) {
	const page = await browser.newPage( { viewport: { width, height }, deviceScaleFactor: 1 } );
	// Drawn to fill the viewport, so the half-size banner is redrawn from the
	// vector and not shrunk from the large PNG.
	const fitted = svg.replace( /width="\d+" height="\d+" viewBox/, 'width="100%" height="100%" viewBox' );
	await page.setContent( `<html><body style="margin:0;overflow:hidden">${ fitted }</body></html>` );
	await page.screenshot( { path: file } );
	await page.close();
}

( async () => {
	let chromium;
	try {
		( { chromium } = require( '@playwright/test' ) );
	} catch ( e ) {
		( { chromium } = require( 'playwright' ) );
	}

	fs.writeFileSync( path.join( OUT, 'src', 'banner.svg' ), WPORG );

	const browser = await chromium.launch();
	await render( browser, WPORG, path.join( OUT, 'banner-1544x500.png' ), 1544, 500 );
	await render( browser, WPORG, path.join( OUT, 'banner-772x250.png' ), 772, 250 );
	await render( browser, OPENGRAPH, path.join( OUT, 'src', 'opengraph-1280x640.png' ), 1280, 640 );
	await browser.close();
} )();
