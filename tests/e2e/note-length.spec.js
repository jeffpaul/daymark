// The block editor's Note length counter: how it counts a Note's text and
// decides between a short note and a long note.
//
// This does not need WordPress. It extracts the counting code from
// assets/note-length-editor.js (the region between the ">>> note-length" and
// "<<< note-length" markers) and runs it in a real browser on a blank page,
// so it uses the browser's own HTML parsing and Intl.Segmenter.
//
//   npx playwright test tests/e2e/note-length.spec.js
import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

const source = fs.readFileSync( path.resolve( __dirname, '../../assets/note-length-editor.js' ), 'utf8' );
const start = source.indexOf( '// >>> note-length' );
const end = source.indexOf( '// <<< note-length' );

if ( start === -1 || end === -1 || end < start ) {
	throw new Error( 'The note-length markers in assets/note-length-editor.js are missing.' );
}

const region = source.slice( start, end );

test.beforeEach( async ( { page } ) => {
	await page.setContent( '<!doctype html><title>note length</title>' );
	await page.evaluate( ( code ) => {
		window.__noteLength = new Function( code + '\nreturn { counterKind, notePlainText, graphemeCount, measureNote };' )();
	}, region );
} );

test( 'shows for an Aside post or an untitled Standard post only', async ( { page } ) => {
	const kinds = await page.evaluate( () =>
		[
			[ 'aside', 'Has a title' ],
			[ 'aside', '' ],
			[ '', '' ],
			[ 'standard', '   ' ],
			[ 'standard', 'A blog post' ],
			[ 'image', '' ],
		].map( ( [ format, title ] ) => window.__noteLength.counterKind( format, title ) )
	);

	expect( kinds ).toEqual( [ 'note', 'note', 'post', 'post', null, null ] );
} );

const paragraph = ( text ) => `<!-- wp:paragraph -->\n<p>${ text }</p>\n<!-- /wp:paragraph -->`;

test( 'counts text the way ATmosphere does', async ( { page } ) => {
	const text = await page.evaluate(
		( html ) => window.__noteLength.notePlainText( html ),
		paragraph( 'Coffee &amp; cake,   <strong>finally</strong>.' ) + '\n\n' + paragraph( 'Second block.' ) + '<script>alert(1)</script>'
	);

	expect( text ).toBe( 'Coffee & cake, finally. Second block.' );
} );

test( 'counts an emoji as one character', async ( { page } ) => {
	const count = await page.evaluate( () => window.__noteLength.graphemeCount( 'Hi 👩‍👩‍👧‍👦!' ) );

	expect( count ).toBe( 5 );
} );

test( 'a Note at the limit is short, one character more is long', async ( { page } ) => {
	const result = await page.evaluate(
		( [ atLimit, overLimit ] ) => [
			window.__noteLength.measureNote( atLimit, [], 300 ),
			window.__noteLength.measureNote( overLimit, [], 300 ),
		],
		[ paragraph( 'a'.repeat( 300 ) ), paragraph( 'a'.repeat( 301 ) ) ]
	);

	expect( result[ 0 ] ).toEqual( { count: 300, remaining: 0, form: 'short' } );
	expect( result[ 1 ] ).toEqual( { count: 301, remaining: -1, form: 'long' } );
} );

test( 'a long Note with a media library image stays short with its images', async ( { page } ) => {
	const result = await page.evaluate( ( html ) => {
		const blocks = [
			{ name: 'core/group', attributes: {}, innerBlocks: [ { name: 'core/image', attributes: { id: 42 }, innerBlocks: [] } ] },
		];
		const noLibraryImage = [ { name: 'core/image', attributes: { url: 'https://example.com/a.jpg' }, innerBlocks: [] } ];

		return [
			window.__noteLength.measureNote( html, blocks, 300 ).form,
			window.__noteLength.measureNote( html, noLibraryImage, 300 ).form,
		];
	}, paragraph( 'a'.repeat( 400 ) ) );

	expect( result ).toEqual( [ 'long-images', 'long' ] );
} );
