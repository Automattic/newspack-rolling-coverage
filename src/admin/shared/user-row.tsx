/**
 * External dependencies
 */
import { Icon, commentAuthorAvatar } from '@wordpress/icons';

interface UserRowProps {
	label: string;
	avatarUrls?: Record< string, string >;
}

/**
 * Avatar and name on one line, falling back to a person icon when the user
 * has no avatar. Matches the Author column in Newspack Newsletters.
 *
 * @param props            Component props.
 * @param props.label      Name to display.
 * @param props.avatarUrls REST `avatar_urls`, keyed by size.
 */
export function UserRow( { label, avatarUrls = {} }: UserRowProps ) {
	const avatarUrl = avatarUrls[ 24 ] || avatarUrls[ 48 ];

	return (
		<span className="newspack-rolling-coverage-user-row">
			{ avatarUrl ? (
				<span className="newspack-rolling-coverage-user-row__avatar">
					<img
						src={ avatarUrl }
						srcSet={
							avatarUrls[ 48 ]
								? `${ avatarUrls[ 48 ] } 2x`
								: undefined
						}
						width={ 16 }
						height={ 16 }
						alt=""
					/>
				</span>
			) : (
				<Icon
					className="newspack-rolling-coverage-user-row__icon"
					icon={ commentAuthorAvatar }
					size={ 24 }
				/>
			) }
			<span>{ label }</span>
		</span>
	);
}
