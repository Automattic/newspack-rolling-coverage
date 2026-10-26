/**
 * An overflow reloads the page, but a reload can land on the same cached copy
 * and overflow again, so the page holds back another reload into the same
 * cursor, with or without session storage to remember the last one.
 */
import {
	allowsOverflowReload,
	OVERFLOW_RELOAD_RETRY_MS,
} from '../../src/blocks/rolling-coverage/overflow-reload';

const KEY = 'newspack-rolling-coverage-overflow-reload-2';
const NOW = 1_800_000_000_000;

function memoryStorage(): Pick< Storage, 'getItem' | 'setItem' > {
	const items = new Map< string, string >();

	return {
		getItem: ( key ) => items.get( key ) ?? null,
		setItem: ( key, value ) => {
			items.set( key, value );
		},
	};
}

const blocked = () => {
	throw new DOMException( 'The operation is insecure.', 'SecurityError' );
};

test( 'an overflow reloads the page and records the reload', () => {
	const storage = memoryStorage();

	expect( allowsOverflowReload( () => storage, KEY, 'c1', NOW, 500 ) ).toBe(
		true
	);
	expect( JSON.parse( storage.getItem( KEY ) ?? 'null' ) ).toEqual( {
		cursor: 'c1',
		time: NOW,
	} );
} );

test( 'another reload into the same cursor waits the retry time', () => {
	const storage = memoryStorage();

	allowsOverflowReload( () => storage, KEY, 'c1', NOW, 500 );

	expect(
		allowsOverflowReload(
			() => storage,
			KEY,
			'c1',
			NOW + OVERFLOW_RELOAD_RETRY_MS - 1,
			500
		)
	).toBe( false );
	expect(
		allowsOverflowReload(
			() => storage,
			KEY,
			'c1',
			NOW + OVERFLOW_RELOAD_RETRY_MS,
			500
		)
	).toBe( true );
} );

test( 'a reload into another cursor goes ahead at once', () => {
	const storage = memoryStorage();

	allowsOverflowReload( () => storage, KEY, 'c1', NOW, 500 );

	expect( allowsOverflowReload( () => storage, KEY, 'c2', NOW, 500 ) ).toBe(
		true
	);
} );

test( 'without session storage, a page reloads only once it has been open the retry time', () => {
	expect(
		allowsOverflowReload(
			blocked,
			KEY,
			'c1',
			NOW,
			OVERFLOW_RELOAD_RETRY_MS - 1
		)
	).toBe( false );
	expect(
		allowsOverflowReload(
			blocked,
			KEY,
			'c1',
			NOW,
			OVERFLOW_RELOAD_RETRY_MS
		)
	).toBe( true );
} );

test( 'storage that refuses the write, or holds an unreadable record, counts as none', () => {
	const full = {
		getItem: () => null,
		setItem: () => {
			throw new DOMException( 'Quota exceeded.', 'QuotaExceededError' );
		},
	};
	const garbled = { getItem: () => '{', setItem: () => undefined };

	expect( allowsOverflowReload( () => full, KEY, 'c1', NOW, 500 ) ).toBe(
		false
	);
	expect( allowsOverflowReload( () => garbled, KEY, 'c1', NOW, 500 ) ).toBe(
		false
	);
	expect(
		allowsOverflowReload(
			() => full,
			KEY,
			'c1',
			NOW,
			OVERFLOW_RELOAD_RETRY_MS
		)
	).toBe( true );
} );
