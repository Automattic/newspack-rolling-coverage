import { spawnSync } from 'node:child_process';
import {
	chmodSync,
	existsSync,
	mkdirSync,
	linkSync,
	mkdtempSync,
	rmSync,
	writeFileSync,
} from 'node:fs';
import { homedir, tmpdir } from 'node:os';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseArgs } from 'node:util';
import { createInterface } from 'node:readline';
import { chromium } from 'playwright-core';
import sharp from 'sharp';

const HERE = dirname( fileURLToPath( import.meta.url ) );
const PUBLIC_DIR = resolve( HERE, '../public' );
const LOGINS_DIR = join( homedir(), '.cache', 'newspack-rc-shot' );

const VIEWPORT = { width: 1512, height: 982 };
const SCALE = 2;
const SPOTLIGHT_MARGIN = 8;
const SPOTLIGHT_DIM = 'rgba(0, 0, 0, 0.45)';
const LOGIN_NAME = 'james';
const SETTLE_TIMEOUT = 15000;
const LONG_WAIT_TIMEOUT = 120000;
const VIDEO_FPS = 30;
const VIDEO_CRF = 18;
const VIDEO_ENCODE = [
	'-c:v',
	'libx264',
	'-preset',
	'veryslow',
	'-crf',
	String( VIDEO_CRF ),
	'-profile:v',
	'high',
	'-level:v',
	'5.1',
	'-pix_fmt',
	'yuv420p',
	'-color_primaries',
	'bt709',
	'-color_trc',
	'bt709',
	'-colorspace',
	'bt709',
	'-x264-params',
	'colorprim=bt709:transfer=bt709:colormatrix=bt709',
	'-movflags',
	'+faststart',
	'-an',
];

const EDITOR_PREFERENCES = {
	core: {
		showBlockBreadcrumbs: false,
		fixedToolbar: true,
		enableChoosePatternModal: false,
		welcomeGuide: false,
	},
	'core/edit-post': {
		fullscreenMode: false,
		welcomeGuide: false,
		welcomeGuideTemplate: false,
	},
	'core/edit-site': {
		welcomeGuide: false,
		welcomeGuidePage: false,
		welcomeGuideStyles: false,
		welcomeGuideTemplate: false,
	},
};

const HIDE = `
::-webkit-scrollbar { display: none !important; }
* { scrollbar-width: none !important; }
#atomic-proxy-bar, .newspack-notice__is-debug,
#cmplz-cookiebanner-container, .cmplz-cookiebanner, #cmplz-manage-consent,
#wp-admin-bar-updates, #wp-admin-bar-annotate-ai, #wp-admin-bar-stats,
#wp-admin-bar-reader, #wp-admin-bar-debug-bar,
#wp-admin-bar-password_protected, #wp-admin-bar-campaigns_preview_toggle,
#wp-admin-bar-np-manager-admin-bar-menu,
#wp-admin-bar-woocommerce-site-visibility-badge,
#wp-admin-bar-wpseo-menu { display: none !important; }`;

const USAGE = `Usage: npm run shot -- <section>/<name>-<n> --site <url> [options]

  <section>/<name>-<n>   Where the image goes, e.g. blocks/rolling-coverage-2
  --site <url>           The screenshot site, e.g. https://screenshots.local
  --url <path>           The page to open, relative to --site (default wp-admin/)
  --logged-out           Open the page as a signed-out reader
  --spotlight <selector> Dim everything except this element. Repeat it to
                         spotlight the box around several elements
  --click <selector>     Click an element before capturing (repeatable)
  --fill <selector>      Type the next --value into a field, or pick it
                         from a menu (repeatable)
  --value <text>         What the --fill before it types or picks
  --hover <selector>     Rest the pointer on an element (repeatable)
  --scroll <selector|px> Scroll an element into the middle of the window,
                         or the page, editor canvas or open modal by a
                         number of pixels, negative to go up as
                         --scroll=-300 (repeatable)
  --press <key>          Press a key, such as Escape (repeatable)
  --wait <ms>            Wait this many milliseconds (repeatable)
  --eval <js>            Run JavaScript in the page and its frames
                         (repeatable)
  --run <command>        Run a command on this computer, such as WP-CLI,
                         and wait for it to finish (repeatable)
  --before-check <ms>    Wait until this long before the liveblog's next
                         check for new entries (repeatable)
  --wait-for <selector>  Wait until an element shows, such as an entry
                         that arrives (repeatable)
                         Steps run in the order you give them.
  --mouse <x,y>          Leave the pointer at this point in the window
                         instead of the bottom right corner
  --now                  Capture without waiting for you (headless)
  --out <file>           Write the image somewhere else

Video (an MP4 instead of a WebP; needs ffmpeg):
  --video                Record the steps as a video, without waiting for you
  --record               Start recording here instead of before the first
                         step. Recording stops after the last step
  --poster <file>        Also save the video's first frame as a WebP

Sign in as James Smith: from WP_USER and WP_PASSWORD, or a prompt.`;

function fail( message ) {
	console.error( `shot: ${ message }` );
	process.exit( 1 );
}

function parse() {
	let parsed;
	try {
		parsed = parseArgs( {
			allowPositionals: true,
			tokens: true,
			options: {
				site: { type: 'string', default: process.env.SHOT_SITE },
				url: { type: 'string', default: 'wp-admin/' },
				'logged-out': { type: 'boolean', default: false },
				spotlight: { type: 'string', multiple: true, default: [] },
				click: { type: 'string', multiple: true, default: [] },
				fill: { type: 'string', multiple: true, default: [] },
				value: { type: 'string', multiple: true, default: [] },
				hover: { type: 'string', multiple: true, default: [] },
				scroll: { type: 'string', multiple: true, default: [] },
				press: { type: 'string', multiple: true, default: [] },
				wait: { type: 'string', multiple: true, default: [] },
				eval: { type: 'string', multiple: true, default: [] },
				mouse: { type: 'string' },
				now: { type: 'boolean', default: false },
				out: { type: 'string' },
				video: { type: 'boolean', default: false },
				record: { type: 'boolean', multiple: true, default: [] },
				poster: { type: 'string' },
				run: { type: 'string', multiple: true, default: [] },
				'before-check': { type: 'string', multiple: true, default: [] },
				'wait-for': { type: 'string', multiple: true, default: [] },
				help: { type: 'boolean', default: false },
			},
		} );
	} catch ( error ) {
		fail(
			`${ error.message }\n\nA negative --scroll value needs an equals sign: --scroll=-300.\n\n${ USAGE }`
		);
	}
	const { values, positionals, tokens } = parsed;
	if ( values.help ) {
		console.log( USAGE );
		process.exit( 0 );
	}
	const name = positionals[ 0 ];
	if ( ! name || ! /^[a-z0-9-]+\/[a-z0-9-]+-\d+$/.test( name ) ) {
		fail( `name the image as <section>/<name>-<n>.\n\n${ USAGE }` );
	}
	if ( ! values.site ) {
		fail(
			`pass --site with your screenshot site's address.\n\n${ USAGE }`
		);
	}
	const steps = [];
	for ( const token of tokens ) {
		if ( 'option' !== token.kind ) {
			continue;
		}
		if ( [ 'click', 'fill', 'hover' ].includes( token.name ) ) {
			steps.push( { action: token.name, selector: token.value } );
		} else if ( 'scroll' === token.name ) {
			const pixels = /^-?\d+$/.test( token.value.trim() )
				? Number( token.value )
				: null;
			steps.push( {
				action: 'scroll',
				selector: token.value,
				pixels,
			} );
		} else if ( 'wait-for' === token.name ) {
			steps.push( { action: 'wait-for', selector: token.value } );
		} else if ( 'run' === token.name ) {
			steps.push( { action: 'run', command: token.value } );
		} else if ( 'before-check' === token.name ) {
			const ms = Number( token.value );
			if ( ! Number.isInteger( ms ) || ms < 0 ) {
				fail(
					`give --before-check a number of milliseconds.\n\n${ USAGE }`
				);
			}
			steps.push( { action: 'before-check', ms } );
		} else if ( 'record' === token.name ) {
			if ( steps.some( ( step ) => 'record' === step.action ) ) {
				fail( `give --record once.\n\n${ USAGE }` );
			}
			steps.push( { action: 'record' } );
		} else if ( 'press' === token.name ) {
			steps.push( { action: 'press', key: token.value } );
		} else if ( 'eval' === token.name ) {
			steps.push( { action: 'eval', script: token.value } );
		} else if ( 'wait' === token.name ) {
			const ms = Number( token.value );
			if ( ! Number.isInteger( ms ) || ms < 0 ) {
				fail( `give --wait a number of milliseconds.\n\n${ USAGE }` );
			}
			steps.push( { action: 'wait', ms } );
		} else if ( 'value' === token.name ) {
			const step = steps[ steps.length - 1 ];
			if ( ! step || 'fill' !== step.action || undefined !== step.text ) {
				fail(
					`put each --value right after its --fill.\n\n${ USAGE }`
				);
			}
			step.text = token.value;
		}
	}
	if (
		steps.some(
			( step ) => 'fill' === step.action && undefined === step.text
		)
	) {
		fail( `give each --fill a --value.\n\n${ USAGE }` );
	}
	let mouse = null;
	if ( values.mouse ) {
		const match = /^(\d+),(\d+)$/.exec( values.mouse.trim() );
		if ( ! match ) {
			fail( `give --mouse as x,y, such as 400,300.\n\n${ USAGE }` );
		}
		mouse = { x: Number( match[ 1 ] ), y: Number( match[ 2 ] ) };
	}
	if ( ! values.video ) {
		if ( values.record.length || values.poster ) {
			fail( `--record and --poster need --video.\n\n${ USAGE }` );
		}
	} else if ( values.spotlight.length ) {
		fail( `a video can't have a spotlight.\n\n${ USAGE }` );
	}
	const fromCwd = ( file ) =>
		resolve( process.env.INIT_CWD || process.cwd(), file );
	const site = siteUrl( values.site );
	return {
		name,
		site,
		target: new URL( values.url.replace( /^\/+/, '' ), site ),
		loggedOut: values[ 'logged-out' ],
		spotlight: values.spotlight,
		mouse,
		steps,
		video: values.video,
		poster: values.poster ? fromCwd( values.poster ) : null,
		interactive:
			! values.now &&
			! values.video &&
			process.stdin.isTTY &&
			process.stdout.isTTY,
		out: values.out
			? fromCwd( values.out )
			: join(
					PUBLIC_DIR,
					'screenshots',
					`${ name }.${ values.video ? 'mp4' : 'webp' }`
				),
	};
}

function siteUrl( value ) {
	const withScheme = /^[a-z][a-z0-9+.-]*:\/\//i.test( value )
		? value
		: `https://${ value }`;
	let url;
	try {
		url = new URL( withScheme );
	} catch {
		fail( `--site isn't a web address: ${ value }` );
	}
	if ( ! [ 'http:', 'https:' ].includes( url.protocol ) ) {
		fail( `--site needs an http or https address: ${ value }` );
	}
	url.search = '';
	url.hash = '';
	if ( ! url.pathname.endsWith( '/' ) ) {
		url.pathname += '/';
	}
	return url;
}

function ask( question, { hidden = false } = {} ) {
	return new Promise( ( done ) => {
		const rl = createInterface( {
			input: process.stdin,
			output: process.stdout,
			terminal: true,
		} );
		if ( hidden ) {
			rl._writeToOutput = ( text ) => {
				if ( text.startsWith( question ) ) {
					process.stdout.write( question );
				}
			};
		}
		rl.question( question, ( answer ) => {
			if ( hidden ) {
				process.stdout.write( '\n' );
			}
			rl.close();
			done( answer );
		} );
	} );
}

async function launch( headless, video ) {
	try {
		return await chromium.launch( {
			headless,
			// Without it, a headless browser records at the viewport's own size.
			args: video ? [ `--force-device-scale-factor=${ SCALE }` ] : [],
			...( process.env.CHROME_PATH
				? { executablePath: process.env.CHROME_PATH }
				: { channel: 'chrome' } ),
		} );
	} catch ( error ) {
		fail(
			`couldn't start Google Chrome. Install it from https://www.google.com/chrome/, or set CHROME_PATH to a Chrome or Chromium binary.\n${
				error.message.split( '\n' )[ 0 ]
			}`
		);
	}
}

async function goto( page, url ) {
	try {
		await page.goto( url );
	} catch ( error ) {
		fail(
			`couldn't open ${ url }: ${
				error.message.split( '\n' )[ 0 ]
			}\nCheck the --site address, and that the site is running.`
		);
	}
}

async function signIn( context, site, interactive ) {
	let user = process.env.WP_USER;
	let password = process.env.WP_PASSWORD;
	if ( ! user || ! password ) {
		if ( ! interactive ) {
			fail( 'set WP_USER and WP_PASSWORD to sign in as James Smith.' );
		}
		user = user || ( await ask( 'WordPress username: ' ) );
		password =
			password ||
			( await ask( 'WordPress password: ', { hidden: true } ) );
	}
	const page = await context.newPage();
	await goto( page, new URL( 'wp-login.php', site ).href );
	await page.fill( '#user_login', user );
	await page.fill( '#user_pass', password );
	await page.click( '#wp-submit' );
	const signedIn = await page
		.waitForURL( ( url ) => ! url.pathname.endsWith( '/wp-login.php' ), {
			timeout: 30000,
		} )
		.then( () => true )
		.catch( () => false );
	if ( ! signedIn ) {
		fail( 'signing in failed. Check the username and password.' );
	}
	await page.close();
}

function loginFile( site ) {
	const name = `${ site.host }${ site.pathname }`.replace(
		/[^a-z0-9.-]+/gi,
		'_'
	);
	return join( LOGINS_DIR, `${ name }.json` );
}

async function restNonce( page, site ) {
	return page.evaluate(
		( ajax ) => fetch( ajax ).then( ( r ) => r.text() ),
		new URL( 'wp-admin/admin-ajax.php?action=rest-nonce', site ).href
	);
}

async function currentUser( page, site ) {
	return page
		.evaluate(
			async ( { endpoint, nonce } ) => {
				const me = await fetch( `${ endpoint }&context=edit`, {
					headers: { 'X-WP-Nonce': nonce },
				} ).then( ( r ) => r.json() );
				return {
					username: me?.username,
					preferences: me?.meta?.persisted_preferences || {},
				};
			},
			{
				endpoint: new URL( '?rest_route=/wp/v2/users/me', site ).href,
				nonce: await restNonce( page, site ),
			}
		)
		.catch( () => ( {} ) );
}

async function savePreferences( page, site, current ) {
	const merged = { ...current, _modified: new Date().toISOString() };
	Object.entries( EDITOR_PREFERENCES ).forEach( ( [ scope, values ] ) => {
		merged[ scope ] = { ...( current[ scope ] || {} ), ...values };
	} );
	const saved = await page
		.evaluate(
			async ( { endpoint, nonce, preferences } ) =>
				fetch( endpoint, {
					method: 'POST',
					headers: {
						'X-WP-Nonce': nonce,
						'Content-Type': 'application/json',
					},
					body: JSON.stringify( {
						meta: { persisted_preferences: preferences },
					} ),
				} ).then( ( r ) => r.ok ),
			{
				endpoint: new URL( '?rest_route=/wp/v2/users/me', site ).href,
				nonce: await restNonce( page, site ),
				preferences: merged,
			}
		)
		.catch( () => false );
	if ( ! saved ) {
		console.warn(
			"shot: couldn't save the editor preferences. Turn off fullscreen mode and block breadcrumbs, and turn on Top toolbar, in the editor yourself."
		);
	}
}

function blockOtherHosts( context, site ) {
	return context.route( '**/*', ( route ) => {
		const url = new URL( route.request().url() );
		if (
			url.origin === site.origin ||
			[ 'data:', 'blob:' ].includes( url.protocol )
		) {
			return route.continue();
		}
		return route.abort();
	} );
}

async function openContext( browser, options ) {
	const settings = {
		viewport: VIEWPORT,
		deviceScaleFactor: SCALE,
		colorScheme: 'light',
		ignoreHTTPSErrors: true,
	};
	const file = loginFile( options.site );
	const context = await browser.newContext( {
		...settings,
		...( ! options.loggedOut && existsSync( file )
			? { storageState: file }
			: {} ),
	} );
	await blockOtherHosts( context, options.site );
	await context.addInitScript( ( css ) => {
		const add = () => {
			const style = document.createElement( 'style' );
			style.textContent = css;
			( document.head || document.documentElement ).appendChild( style );
		};
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', add );
		} else {
			add();
		}
	}, HIDE );
	if ( options.loggedOut ) {
		return context;
	}

	const probe = await context.newPage();
	await goto( probe, new URL( 'wp-admin/', options.site ).href );
	const cached = ! probe.url().includes( 'wp-login.php' );
	if ( ! cached ) {
		await signIn( context, options.site, options.interactive );
		await goto( probe, new URL( 'wp-admin/', options.site ).href );
	}
	const user = await currentUser( probe, options.site );
	if ( user.username !== LOGIN_NAME ) {
		fail(
			`signed in as "${
				user.username || 'an unknown user'
			}", not as James Smith (${ LOGIN_NAME }). Screenshots are taken as the James Smith account the seed creates. ${
				cached
					? `Delete ${ file } and run again, signing in as James Smith.`
					: 'Nothing was saved; run again and sign in as James Smith.'
			}`
		);
	}
	if ( ! cached ) {
		mkdirSync( LOGINS_DIR, { recursive: true, mode: 0o700 } );
		await context.storageState( { path: file } );
		chmodSync( file, 0o600 );
	}
	await savePreferences( probe, options.site, user.preferences );
	await probe.close();
	return context;
}

async function settle( page ) {
	await page.waitForLoadState( 'load' ).catch( () => {} );
	await page
		.waitForLoadState( 'networkidle', { timeout: SETTLE_TIMEOUT } )
		.catch( () => {} );
	await page.evaluate( () => document.fonts.ready ).catch( () => {} );
	await page.waitForTimeout( 1000 );
}

async function nextFrames( page ) {
	await page.evaluate(
		() =>
			new Promise( ( done ) =>
				requestAnimationFrame( () => requestAnimationFrame( done ) )
			)
	);
}

async function closeGuides( page ) {
	const close = page.locator(
		'.components-guide button[aria-label="Close"], .newspack-lightbox__close'
	);
	await close
		.first()
		.waitFor( { state: 'visible', timeout: 4000 } )
		.then( () => close.first().click() )
		.catch( () => {} );
}

async function findInFrames( page, selector ) {
	const deadline = Date.now() + SETTLE_TIMEOUT;
	while ( Date.now() < deadline ) {
		for ( const frame of page.frames() ) {
			const locator = frame.locator( selector ).first();
			if ( await locator.count().catch( () => 0 ) ) {
				return locator;
			}
		}
		await page.waitForTimeout( 250 );
	}
	return null;
}

async function click( page, selector ) {
	const locator = await findInFrames( page, selector );
	if ( ! locator ) {
		fail( `--click found nothing for ${ selector }` );
	}
	const navigated = page
		.waitForEvent( 'framenavigated', {
			predicate: ( frame ) => frame === page.mainFrame(),
			timeout: SETTLE_TIMEOUT,
		} )
		.catch( () => null );
	const leaving = page
		.waitForRequest(
			( request ) =>
				request.isNavigationRequest() &&
				request.frame() === page.mainFrame(),
			{ timeout: 500 }
		)
		.then( () => true )
		.catch( () => false );
	await locator.click();
	if ( await leaving ) {
		await navigated;
	}
	await settle( page );
}

async function fill( page, selector, text ) {
	const locator = await findInFrames( page, selector );
	if ( ! locator ) {
		fail( `--fill found nothing for ${ selector }` );
	}
	const tag = await locator.evaluate( ( element ) => element.tagName );
	if ( 'SELECT' === tag ) {
		await locator.selectOption( { label: text } ).catch( () => {
			fail( `--fill found no "${ text }" in the menu ${ selector }` );
		} );
	} else {
		await locator.fill( text );
	}
	await settle( page );
}

async function hover( page, selector ) {
	const locator = await findInFrames( page, selector );
	if ( ! locator ) {
		fail( `--hover found nothing for ${ selector }` );
	}
	await locator.hover();
	await page.waitForTimeout( 1000 );
}

async function scroll( page, step ) {
	if ( null === step.pixels ) {
		const locator = await findInFrames( page, step.selector );
		if ( ! locator ) {
			fail( `--scroll found nothing for ${ step.selector }` );
		}
		await locator.evaluate( ( element ) =>
			element.scrollIntoView( { block: 'center', behavior: 'instant' } )
		);
		await page.waitForTimeout( 500 );
		return;
	}
	const main = page.mainFrame();
	const passes = [
		{ frame: main, part: 'modal' },
		...page
			.frames()
			.filter( ( frame ) => frame !== main )
			.map( ( frame ) => ( { frame, part: 'canvas' } ) ),
		{ frame: main, part: 'page' },
	];
	let scrolled = false;
	for ( const { frame, part } of passes ) {
		scrolled = await frame
			.evaluate( ( { pixels, part: where } ) => {
				const canScroll = ( element ) =>
					element.getClientRects().length > 0 &&
					element.scrollHeight > element.clientHeight + 1 &&
					[ 'auto', 'scroll' ].includes(
						getComputedStyle( element ).overflowY
					);
				let target = null;
				if ( 'modal' === where ) {
					target = [
						...document.querySelectorAll(
							'.components-modal__content, .components-modal__frame'
						),
					].find( canScroll );
				} else if ( 'canvas' === where ) {
					target = document.scrollingElement;
				} else {
					target =
						[
							...document.querySelectorAll(
								'.interface-interface-skeleton__content, .edit-site-layout__canvas, .editor-visual-editor'
							),
						].find( canScroll ) || document.scrollingElement;
				}
				if ( ! target ) {
					return false;
				}
				const before = target.scrollTop;
				target.scrollBy( { top: pixels, behavior: 'instant' } );
				return target.scrollTop !== before;
			}, { pixels: step.pixels, part } )
			.catch( () => false );
		if ( scrolled ) {
			break;
		}
	}
	if ( ! scrolled ) {
		console.warn( `shot: nothing scrolled for --scroll ${ step.selector }.` );
	}
	await page.waitForTimeout( 500 );
}

function runCommand( command ) {
	console.log( `shot: running ${ command }` );
	const result = spawnSync( command, {
		shell: true,
		stdio: 'inherit',
		cwd: process.env.INIT_CWD || process.cwd(),
	} );
	if ( 0 !== result.status ) {
		fail( `--run failed: ${ command }` );
	}
}

async function beforeCheck( page, ms ) {
	const waited = await page.evaluate(
		async ( { before, timeout } ) => {
			const deadline = Date.now() + timeout;
			while ( Date.now() < deadline ) {
				const feed = document.querySelector(
					'[data-check-state="waiting"][data-next-check-at]'
				);
				const at = feed
					? Number( feed.dataset.nextCheckAt ) - before
					: 0;
				if ( at > Date.now() ) {
					await new Promise( ( done ) =>
						setTimeout( done, at - Date.now() )
					);
					return true;
				}
				await new Promise( ( done ) => setTimeout( done, 100 ) );
			}
			return false;
		},
		{ before: ms, timeout: LONG_WAIT_TIMEOUT }
	);
	if ( ! waited ) {
		fail(
			'--before-check found no liveblog feed checking for new entries. The liveblog must be live.'
		);
	}
}

async function waitFor( page, selector ) {
	await page
		.locator( selector )
		.first()
		.waitFor( { state: 'visible', timeout: LONG_WAIT_TIMEOUT } )
		.catch( () =>
			fail(
				`--wait-for found nothing showing for ${ selector } within ${
					LONG_WAIT_TIMEOUT / 1000
				} seconds`
			)
		);
}

async function startRecording( page ) {
	const session = await page.context().newCDPSession( page );
	const frames = [];
	const start = Date.now() / 1000;
	session.on( 'Page.screencastFrame', ( { data, metadata, sessionId } ) => {
		frames.push( { data, time: Math.max( metadata.timestamp, start ) } );
		session
			.send( 'Page.screencastFrameAck', { sessionId } )
			.catch( () => {} );
	} );
	await session.send( 'Page.startScreencast', {
		format: 'png',
		maxWidth: VIEWPORT.width * SCALE,
		maxHeight: VIEWPORT.height * SCALE,
		everyNthFrame: 1,
	} );
	return {
		async stop() {
			const end = Date.now() / 1000;
			await session.send( 'Page.stopScreencast' ).catch( () => {} );
			await session.detach().catch( () => {} );
			return { frames, start, end };
		},
	};
}

function ffmpeg( args ) {
	const result = spawnSync( process.env.FFMPEG_PATH || 'ffmpeg', args, {
		stdio: [ 'ignore', 'ignore', 'pipe' ],
	} );
	if ( result.error ) {
		fail(
			"couldn't run ffmpeg. Install it (on a Mac, brew install ffmpeg), or set FFMPEG_PATH to an ffmpeg binary with libx264."
		);
	}
	if ( 0 !== result.status ) {
		fail(
			`ffmpeg failed:\n${ result.stderr.toString().trim().split( '\n' ).slice( -5 ).join( '\n' ) }`
		);
	}
}

async function saveVideo( { frames, start, end }, out, poster ) {
	if ( ! frames.length ) {
		fail( 'the recording has no frames.' );
	}
	const first = Buffer.from( frames[ 0 ].data, 'base64' );
	const { width, height } = await sharp( first ).metadata();
	if (
		width !== VIEWPORT.width * SCALE ||
		height !== VIEWPORT.height * SCALE
	) {
		fail(
			`the recording came out at ${ width }×${ height }, not at twice the viewport.`
		);
	}
	const dir = mkdtempSync( join( tmpdir(), 'newspack-rc-shot-' ) );
	try {
		frames.forEach( ( frame, index ) =>
			writeFileSync(
				join( dir, `source-${ index }.png` ),
				Buffer.from( frame.data, 'base64' )
			)
		);
		// The browser sends a frame only when the page changes, so each
		// moment of the video shows the last frame sent before it.
		const count = Math.max( 1, Math.round( ( end - start ) * VIDEO_FPS ) );
		let source = 0;
		for ( let index = 0; index < count; index++ ) {
			const at = start + index / VIDEO_FPS;
			while (
				source + 1 < frames.length &&
				frames[ source + 1 ].time <= at
			) {
				source++;
			}
			linkSync(
				join( dir, `source-${ source }.png` ),
				join( dir, `frame-${ String( index ).padStart( 5, '0' ) }.png` )
			);
		}
		mkdirSync( dirname( out ), { recursive: true } );
		ffmpeg( [
			'-y',
			'-framerate',
			String( VIDEO_FPS ),
			'-i',
			join( dir, 'frame-%05d.png' ),
			'-vf',
			'scale=out_color_matrix=bt709:out_range=tv,format=yuv420p',
			...VIDEO_ENCODE,
			out,
		] );
		console.log(
			`Saved ${ out } (${ ( count / VIDEO_FPS ).toFixed( 1 ) } seconds)`
		);
	} finally {
		rmSync( dir, { recursive: true, force: true } );
	}
	if ( poster ) {
		mkdirSync( dirname( poster ), { recursive: true } );
		await sharp( first ).webp( { quality: 85 } ).toFile( poster );
		console.log( `Saved ${ poster }` );
	}
}

async function runStep( page, step, video ) {
	if ( 'record' === step.action ) {
		video.recording = await startRecording( page );
		return;
	}
	if ( 'run' === step.action ) {
		runCommand( step.command );
		return;
	}
	if ( 'before-check' === step.action ) {
		await beforeCheck( page, step.ms );
		return;
	}
	if ( 'wait-for' === step.action ) {
		await waitFor( page, step.selector );
		return;
	}
	if ( 'fill' === step.action ) {
		await fill( page, step.selector, step.text );
	} else if ( 'hover' === step.action ) {
		await hover( page, step.selector );
	} else if ( 'scroll' === step.action ) {
		await scroll( page, step );
	} else if ( 'press' === step.action ) {
		await page.keyboard.press( step.key );
		await settle( page );
	} else if ( 'wait' === step.action ) {
		await page.waitForTimeout( step.ms );
	} else if ( 'eval' === step.action ) {
		for ( const frame of page.frames() ) {
			await frame.evaluate( step.script ).catch( ( error ) => {
				if ( frame === page.mainFrame() ) {
					console.warn(
						`shot: --eval failed: ${ error.message.split( '\n' )[ 0 ] }`
					);
				}
			} );
		}
		await page.waitForTimeout( 600 );
	} else {
		await click( page, step.selector );
	}
}

function watchAltClicks( page ) {
	const install = () =>
		page.frames().forEach( ( frame ) =>
			frame
				.evaluate( () => {
					if ( window.__shotWatching ) {
						return;
					}
					window.__shotWatching = true;
					const swallow = ( event ) => {
						if ( ! event.altKey ) {
							return;
						}
						event.preventDefault();
						event.stopImmediatePropagation();
						if ( event.type !== 'click' ) {
							return;
						}
						window.__shotPick?.style.removeProperty( 'outline' );
						window.__shotPick = event.target;
						window.__shotPickedAt = Date.now();
						event.target.style.outline = '2px dashed #d63638';
					};
					[ 'pointerdown', 'mousedown', 'mouseup', 'click' ].forEach(
						( type ) =>
							window.addEventListener( type, swallow, true )
					);
				} )
				.catch( () => {} )
		);
	install();
	return setInterval( install, 1000 );
}

async function clearOutlines( page ) {
	for ( const frame of page.frames() ) {
		await frame
			.evaluate( () =>
				window.__shotPick?.style.removeProperty( 'outline' )
			)
			.catch( () => {} );
	}
}

async function pickedBox( page ) {
	let latest = null;
	for ( const frame of page.frames() ) {
		const at = await frame
			.evaluate( () => window.__shotPick && window.__shotPickedAt )
			.catch( () => null );
		if ( ! at || ( latest && latest.at > at ) ) {
			continue;
		}
		const handle = await frame
			.evaluateHandle( () => window.__shotPick )
			.catch( () => null );
		const element = handle?.asElement();
		const box = element ? await element.boundingBox() : null;
		latest = { at, box };
	}
	return latest;
}

async function drawSpotlight( page, box ) {
	await page.evaluate(
		( { x, y, width, height, margin, dim } ) => {
			const overlay = document.createElement( 'div' );
			Object.assign( overlay.style, {
				position: 'fixed',
				left: `${ x - margin }px`,
				top: `${ y - margin }px`,
				width: `${ width + margin * 2 }px`,
				height: `${ height + margin * 2 }px`,
				boxShadow: `0 0 0 200vmax ${ dim }`,
				pointerEvents: 'none',
				zIndex: '2147483647',
			} );
			document.body.appendChild( overlay );
		},
		{ ...box, margin: SPOTLIGHT_MARGIN, dim: SPOTLIGHT_DIM }
	);
}

async function main() {
	const options = parse();
	const browser = await launch( ! options.interactive, options.video );
	try {
		const context = await openContext( browser, options );
		const page = await context.newPage();
		page.on( 'frameattached', ( frame ) =>
			frame
				.waitForLoadState( 'domcontentloaded' )
				.then( () => frame.addStyleTag( { content: HIDE } ) )
				.catch( () => {} )
		);
		await goto( page, options.target.href );
		await settle( page );
		await closeGuides( page );
		const video = { recording: null };
		if (
			options.video &&
			! options.steps.some( ( step ) => 'record' === step.action )
		) {
			video.recording = await startRecording( page );
		}
		for ( const step of options.steps ) {
			await runStep( page, step, video );
		}
		if ( options.video ) {
			if ( ! video.recording ) {
				fail( '--record never ran.' );
			}
			await saveVideo(
				await video.recording.stop(),
				options.out,
				options.poster
			);
			return;
		}
		const pointerSteps = options.steps.filter( ( step ) =>
			[ 'click', 'fill', 'hover' ].includes( step.action )
		);
		if ( options.mouse ) {
			await page.mouse.move( options.mouse.x, options.mouse.y );
			await page.waitForTimeout( 400 );
		} else if (
			pointerSteps.length &&
			'hover' !== pointerSteps[ pointerSteps.length - 1 ].action
		) {
			await page.mouse.move( VIEWPORT.width - 1, VIEWPORT.height - 1 );
		}

		let watcher = null;
		if ( options.interactive ) {
			watcher = watchAltClicks( page );
			await ask(
				'Get the page into the state you need. Alt-click an element to spotlight it. Press Enter to capture. '
			);
			clearInterval( watcher );
		}

		let box = null;
		if ( options.spotlight.length ) {
			const boxes = [];
			for ( const selector of options.spotlight ) {
				const locator = await findInFrames( page, selector );
				if ( ! locator ) {
					fail( `--spotlight found nothing for ${ selector }` );
				}
				await nextFrames( page );
				const found = await locator.boundingBox();
				if ( ! found ) {
					fail(
						`--spotlight matched ${ selector }, but it isn't visible on the page.`
					);
				}
				boxes.push( found );
			}
			const left = Math.min( ...boxes.map( ( b ) => b.x ) );
			const top = Math.min( ...boxes.map( ( b ) => b.y ) );
			box = {
				x: left,
				y: top,
				width: Math.max( ...boxes.map( ( b ) => b.x + b.width ) ) - left,
				height:
					Math.max( ...boxes.map( ( b ) => b.y + b.height ) ) - top,
			};
		} else if ( watcher ) {
			await nextFrames( page );
			const pick = await pickedBox( page );
			box = pick?.box || null;
			if ( pick && ! box ) {
				console.warn(
					'shot: the element you Alt-clicked is no longer visible, so the image has no spotlight.'
				);
			}
		}
		await clearOutlines( page );
		if ( box ) {
			await drawSpotlight( page, box );
		}
		await nextFrames( page );
		await page.waitForTimeout( 300 );

		const png = await page.screenshot( { type: 'png' } );
		mkdirSync( dirname( options.out ), { recursive: true } );
		const { width, height } = await sharp( png )
			.webp( { quality: 85 } )
			.toFile( options.out );
		console.log( `Saved ${ options.out }` );
		const inPublic = relative( PUBLIC_DIR, options.out );
		if ( ! inPublic.startsWith( '..' ) ) {
			const src = `/${ inPublic.split( '\\' ).join( '/' ) }`;
			console.log(
				`<Screenshot label="..." src="${ src }" width={${ width }} height={${ height }} />`
			);
		}
	} finally {
		await browser.close();
	}
}

main();
