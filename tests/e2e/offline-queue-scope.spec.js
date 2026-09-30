// The offline queue and the bookmark cache are per user and per site.
//
// IndexedDB is shared by everyone who uses one browser origin, and Daymark's
// offline database holds two kinds of personal data: Marks that were queued
// but not sent yet, and cached bookmark content. It used to be one shared
// database, so a second person logging in on the same browser had the first
// person's queued Marks replayed into their own account, and could read the
// first person's cached bookmarks offline. It is now one database per user
// and site, and the old shared one is handed to whoever opens the app first
// after the update.
//
// This does not need a WordPress login. It extracts the database code from
// assets/app.js (the region between the ">>> offline-db" and "<<< offline-db"
// markers) and runs it in a real browser against a plain page on the target
// origin, so it exercises real IndexedDB, including the parts a mock would
// hide (aborting an upgrade so no empty database is left behind, Web Locks,
// concurrent first opens).
//
// Run it against any origin, e.g. a throwaway local server:
//   WP_BASE_URL=http://127.0.0.1:8123 npx playwright test tests/e2e/offline-queue-scope.spec.js
import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

const appSource = fs.readFileSync( path.resolve( __dirname, '../../assets/app.js' ), 'utf8' );
const start = appSource.indexOf( '// >>> offline-db' );
const end = appSource.indexOf( '// <<< offline-db' );

if ( start === -1 || end === -1 || end < start ) {
	throw new Error( 'The offline-db markers in assets/app.js are missing.' );
}

const region = appSource.slice( start, end );

// Any page on the target origin will do; it only needs to be a real document
// with IndexedDB. The login page exists on every WordPress site.
const PAGE = '/wp-login.php';

const SITE_A = 'https://one.example/wp-json/daymark/v1/';
const SITE_B = 'https://two.example/wp-json/daymark/v1/';

test.beforeEach( async ( { page } ) => {
	await page.goto( PAGE );

	await page.evaluate( async () => {
		const databases = ( await indexedDB.databases() ) || [];
		await Promise.all(
			databases.map(
				( { name } ) =>
					new Promise( ( resolve ) => {
						const request = indexedDB.deleteDatabase( name );
						request.onsuccess = request.onerror = request.onblocked = () => resolve();
					} )
			)
		);
	} );

	// Each call to window.__boot() is one "page load" of the app: a fresh copy
	// of the database code with its own module state and its own config.
	await page.evaluate( ( source ) => {
		window.__boot = ( userId, restUrl ) => {
			const config = { currentUser: userId ? { id: userId } : undefined, restUrl };
			return new Function(
				'config',
				source +
					'\nreturn { openOfflineDB, offlineDbName, queuePendingMark, getAllPendingMarks,' +
					' putCachedBookmark, getAllCachedBookmarks };'
			)( config );
		};
	}, region );
} );

// Names of every database on the origin, sorted.
const databaseNames = ( page ) =>
	page.evaluate( async () => ( ( await indexedDB.databases() ) || [] ).map( ( d ) => d.name ).sort() );

test( 'two users on one browser each get their own queue', async ( { page } ) => {
	const result = await page.evaluate(
		async ( { siteA } ) => {
			const alice = window.__boot( 5, siteA );
			await alice.queuePendingMark( 0, { caption: 'Alice private draft' } );
			await alice.queuePendingMark( 0, { caption: 'Alice second draft' } );

			// Same browser, a different account logs in and opens the app.
			const bob = window.__boot( 6, siteA );
			const bobSees = ( await bob.getAllPendingMarks() ).map( ( r ) => r.payload.caption );
			await bob.queuePendingMark( 0, { caption: 'Bob draft' } );

			// Alice comes back.
			const aliceAgain = window.__boot( 5, siteA );
			const aliceSees = ( await aliceAgain.getAllPendingMarks() ).map( ( r ) => r.payload.caption ).sort();
			const bobAgain = window.__boot( 6, siteA );
			const bobSeesLater = ( await bobAgain.getAllPendingMarks() ).map( ( r ) => r.payload.caption );

			return { bobSees, aliceSees, bobSeesLater };
		},
		{ siteA: SITE_A }
	);

	expect( result.bobSees, "Bob never sees Alice's unsent Marks" ).toEqual( [] );
	expect( result.aliceSees, 'Alice keeps hers, and only hers' ).toEqual( [ 'Alice private draft', 'Alice second draft' ] );
	expect( result.bobSeesLater ).toEqual( [ 'Bob draft' ] );
} );

test( 'logging out keeps the queue: nothing is thrown away', async ( { page } ) => {
	const result = await page.evaluate(
		async ( { siteA } ) => {
			const before = window.__boot( 5, siteA );
			await before.queuePendingMark( 0, { caption: 'Unsent when I logged out' } );

			// A logged-out page load has no user, so it opens nothing at all.
			const loggedOut = window.__boot( 0, siteA );
			let opened = true;
			try {
				await loggedOut.openOfflineDB();
			} catch ( err ) {
				opened = false;
			}

			const after = window.__boot( 5, siteA );

			return { opened, seen: ( await after.getAllPendingMarks() ).map( ( r ) => r.payload.caption ) };
		},
		{ siteA: SITE_A }
	);

	expect( result.opened, 'With no signed-in user, no database is opened' ).toBe( false );
	expect( result.seen ).toEqual( [ 'Unsent when I logged out' ] );
} );

test( 'cached bookmarks are per user as well', async ( { page } ) => {
	const result = await page.evaluate(
		async ( { siteA } ) => {
			const alice = window.__boot( 5, siteA );
			await alice.putCachedBookmark( { id: 101, content: '<p>Alice saved this</p>' } );

			const bob = window.__boot( 6, siteA );
			const bobSees = ( await bob.getAllCachedBookmarks() ).map( ( b ) => b.id );
			// The same post ID for a different user is a different record.
			await bob.putCachedBookmark( { id: 101, content: '<p>Bob saved the same post</p>' } );

			const aliceAgain = window.__boot( 5, siteA );

			return {
				bobSees,
				alice: ( await aliceAgain.getAllCachedBookmarks() ).map( ( b ) => b.content ),
			};
		},
		{ siteA: SITE_A }
	);

	expect( result.bobSees ).toEqual( [] );
	expect( result.alice ).toEqual( [ '<p>Alice saved this</p>' ] );
} );

test( 'the same user on two sites sharing one origin stays separate', async ( { page } ) => {
	const result = await page.evaluate(
		async ( { siteA, siteB } ) => {
			const onA = window.__boot( 5, siteA );
			await onA.queuePendingMark( 0, { caption: 'For site A' } );

			// A subdirectory multisite: same browser origin, user 5 again, but
			// this is the other site's REST address.
			const onB = window.__boot( 5, siteB );

			return {
				b: ( await onB.getAllPendingMarks() ).length,
				a: ( await window.__boot( 5, siteA ).getAllPendingMarks() ).length,
				nameA: onA.offlineDbName(),
				nameB: onB.offlineDbName(),
			};
		},
		{ siteA: SITE_A, siteB: SITE_B }
	);

	expect( result.b ).toBe( 0 );
	expect( result.a ).toBe( 1 );
	expect( result.nameA ).not.toBe( result.nameB );
	expect( result.nameA ).toMatch( /^daymark-offline-u5-[0-9a-f]{8}$/ );
} );

test( 'with no old database, nothing is created for it', async ( { page } ) => {
	await page.evaluate( async ( { siteA } ) => {
		const app = window.__boot( 5, siteA );
		await app.getAllPendingMarks();
	}, { siteA: SITE_A } );

	const names = await databaseNames( page );

	expect( names ).toHaveLength( 1 );
	expect( names[ 0 ] ).toMatch( /^daymark-offline-u5-/ );
	expect( names, 'Probing for the old shared database must not leave an empty one behind' ).not.toContain( 'daymark-offline' );
} );

// Builds the pre-update shared database with one queued and one in-flight
// Mark and a cached bookmark, then closes it.
const createLegacyDatabase = ( page ) =>
	page.evaluate( async () => {
		await new Promise( ( resolve, reject ) => {
			const request = indexedDB.open( 'daymark-offline', 2 );
			request.onupgradeneeded = () => {
				const db = request.result;
				db.createObjectStore( 'pending', { keyPath: 'id', autoIncrement: true } );
				db.createObjectStore( 'bookmarks', { keyPath: 'id' } );
			};
			request.onsuccess = () => {
				const db = request.result;
				const tx = db.transaction( [ 'pending', 'bookmarks' ], 'readwrite' );
				tx.objectStore( 'pending' ).add( { targetId: 0, payload: { caption: 'Queued before the update' }, status: 'queued', createdAt: 1, updatedAt: 1 } );
				tx.objectStore( 'pending' ).add( { targetId: 0, payload: { caption: 'Mid-upload at the update' }, status: 'uploading', createdAt: 2, updatedAt: 2 } );
				tx.objectStore( 'bookmarks' ).add( { id: 900, content: '<p>Someone else bookmarked this</p>' } );
				tx.oncomplete = () => {
					db.close();
					resolve();
				};
				tx.onerror = () => reject( tx.error );
			};
			request.onerror = () => reject( request.error );
		} );
	} );

test( 'the first person to open the app after the update claims the old shared queue', async ( { page } ) => {
	await createLegacyDatabase( page );

	const result = await page.evaluate(
		async ( { siteA } ) => {
			const first = window.__boot( 5, siteA );
			const claimed = ( await first.getAllPendingMarks() ).map( ( r ) => ( { caption: r.payload.caption, status: r.status } ) );
			claimed.sort( ( a, b ) => a.caption.localeCompare( b.caption ) );

			// Someone else opens the app afterwards.
			const second = window.__boot( 6, siteA );

			return {
				claimed,
				secondSees: ( await second.getAllPendingMarks() ).length,
				bookmarks: ( await first.getAllCachedBookmarks() ).length,
			};
		},
		{ siteA: SITE_A }
	);

	expect( result.claimed ).toEqual( [
		{ caption: 'Mid-upload at the update', status: 'queued' },
		{ caption: 'Queued before the update', status: 'queued' },
	] );
	expect( result.secondSees, 'The old queue is claimed once, not shared' ).toBe( 0 );
	expect( result.bookmarks, "Another person's cached bookmarks are not carried over" ).toBe( 0 );

	expect( await databaseNames( page ), 'The old shared database is removed' ).not.toContain( 'daymark-offline' );
} );

test( 'two tabs opening together after the update do not duplicate the claimed queue', async ( { page } ) => {
	await createLegacyDatabase( page );

	const count = await page.evaluate(
		async ( { siteA } ) => {
			// Three page loads of the same user at the same moment.
			const loads = [ window.__boot( 5, siteA ), window.__boot( 5, siteA ), window.__boot( 5, siteA ) ];
			const results = await Promise.all( loads.map( ( app ) => app.getAllPendingMarks() ) );
			const settled = await window.__boot( 5, siteA ).getAllPendingMarks();

			return { lengths: results.map( ( r ) => r.length ), settled: settled.length };
		},
		{ siteA: SITE_A }
	);

	expect( count.settled, 'Each old Mark is claimed exactly once' ).toBe( 2 );
} );

test( 'claiming happens once: a later load does not look again or change anything', async ( { page } ) => {
	await createLegacyDatabase( page );

	const result = await page.evaluate(
		async ( { siteA } ) => {
			const first = window.__boot( 5, siteA );
			await first.getAllPendingMarks();
			const a = ( await window.__boot( 5, siteA ).getAllPendingMarks() ).length;
			const b = ( await window.__boot( 5, siteA ).getAllPendingMarks() ).length;

			return { a, b };
		},
		{ siteA: SITE_A }
	);

	expect( result ).toEqual( { a: 2, b: 2 } );
	expect( ( await databaseNames( page ) ).filter( ( n ) => n === 'daymark-offline' ) ).toHaveLength( 0 );
} );
