/**
 * External dependencies
 */
import { createReduxStore } from '@wordpress/data';

type ScopedValues = Record< string, Record< string, unknown > >;

type State = {
	defaults: ScopedValues;
	values: ScopedValues;
};

type Action =
	| {
			type: 'SET_PREFERENCE_VALUE';
			scope: string;
			name: string;
			value: unknown;
	  }
	| {
			type: 'SET_PREFERENCE_DEFAULTS';
			scope: string;
			defaults: Record< string, unknown >;
	  }
	| { type: 'SET_PERSISTENCE_LAYER' };

const initialState: State = { defaults: {}, values: {} };

function reducer( state: State = initialState, action: Action ): State {
	switch ( action.type ) {
		case 'SET_PREFERENCE_VALUE':
			return {
				...state,
				values: {
					...state.values,
					[ action.scope ]: {
						...state.values[ action.scope ],
						[ action.name ]: action.value,
					},
				},
			};
		case 'SET_PREFERENCE_DEFAULTS':
			return {
				...state,
				defaults: {
					...state.defaults,
					[ action.scope ]: {
						...state.defaults[ action.scope ],
						...action.defaults,
					},
				},
			};
		default:
			return state;
	}
}

function get( state: State, scope: string, name: string ): unknown {
	const value = state.values[ scope ]?.[ name ];
	return value !== undefined ? value : state.defaults[ scope ]?.[ name ];
}

type ThunkArgs = {
	select: { get: ( scope: string, name: string ) => unknown };
	dispatch: { set: ( scope: string, name: string, value: unknown ) => void };
};

const actions = {
	set: ( scope: string, name: string, value: unknown ) => ( {
		type: 'SET_PREFERENCE_VALUE' as const,
		scope,
		name,
		value,
	} ),
	setDefaults: ( scope: string, defaults: Record< string, unknown > ) => ( {
		type: 'SET_PREFERENCE_DEFAULTS' as const,
		scope,
		defaults,
	} ),
	toggle:
		( scope: string, name: string ) =>
		( { select, dispatch }: ThunkArgs ) =>
			dispatch.set( scope, name, ! select.get( scope, name ) ),
	setPersistenceLayer: () => ( {
		type: 'SET_PERSISTENCE_LAYER' as const,
	} ),
};

/**
 * An in-memory stand-in for `core/preferences`, registered in Quick Edit's
 * child registry so the editor's preference reads and writes stay inside the
 * modal.
 *
 * A second instance of core's own store would not do: its reducer holds the
 * persistence layer in a closure shared by every instance, so any `set` in
 * the copy saves the copy's whole state as the user's preferences, replacing
 * everything they had saved. This store keeps the same public API (`get`,
 * `set`, `setDefaults`, `toggle`) and accepts `setPersistenceLayer` without
 * saving anything.
 */
export const quickEditPreferencesStore = createReduxStore( 'core/preferences', {
	reducer,
	actions,
	selectors: { get },
} );
