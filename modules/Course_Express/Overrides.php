<?php
/**
 * Course Express: Section Overrides
 *
 * Override sets: a named group of courses, plus the section settings that
 * replace the defaults for those courses. For example a "Lunch" set holding
 * the lunch courses, overriding the Grading Scale to "Not Graded".
 *
 * @package RosarioSIS
 * @subpackage modules
 */

require_once 'modules/Course_Express/includes/Sections.fnc.php';

DrawHeader( ProgramTitle() );

$error = $note = [];

if ( ! AllowEdit() )
{
	$error[] = _( 'You are not allowed to use this program.' );

	echo ErrorMessage( $error );

	return;
}

$_REQUEST['set_id'] = issetVal( $_REQUEST['set_id'], '' );

if ( $_REQUEST['set_id'] !== 'new' )
{
	$_REQUEST['set_id'] = (string) (int) $_REQUEST['set_id'];
}

$settings = CourseExpressSettings();

/**
 * Save a set
 */
if ( $_REQUEST['modfunc'] === 'save' )
{
	// From $_POST: $_REQUEST is already escaped for SQL in Warehouse.php,
	// and the title is escaped below.
	$title = trim( strip_tags( (string) issetVal( $_POST['title'], '' ) ) );

	if ( $title === '' )
	{
		$error[] = _( 'Please fill in the required fields' );
	}

	if ( ! empty( $_REQUEST['override']['DAYS'] )
		&& CourseExpressSettingsRequest( 'values' )['DAYS'] === '' )
	{
		// A section without meeting days never meets.
		$error[] = _( 'Meeting Days' ) . ': ' . _( 'Please fill in the required fields' );
	}

	if ( ! $error )
	{
		$values = CourseExpressSettingsRequest( 'values' );

		// Which settings does this set override?
		$overridden = [];

		foreach ( array_keys( $settings ) as $setting )
		{
			if ( ! empty( $_REQUEST['override'][ $setting ] ) )
			{
				$overridden[] = $setting;
			}
		}

		$columns = [
			'TITLE' => DBEscapeString( mb_substr( $title, 0, 100 ) ),
			'SORT_ORDER' => is_numeric( issetVal( $_REQUEST['sort_order'], '' ) ) ?
				(string) (int) $_REQUEST['sort_order'] : '',
			'OVERRIDDEN' => $overridden ? ',' . implode( ',', $overridden ) . ',' : '',
		];

		foreach ( $settings as $setting => $definition )
		{
			// A setting which is not overridden is stored empty.
			$columns[ $setting ] = in_array( $setting, $overridden, true ) ?
				DBEscapeString( $values[ $setting ] ) :
				'';
		}

		if ( $_REQUEST['set_id'] === 'new' )
		{
			$set_id = DBInsert(
				'course_express_override_sets',
				[ 'SYEAR' => UserSyear(), 'SCHOOL_ID' => UserSchool() ] + $columns,
				'id'
			);

			$_REQUEST['set_id'] = (string) $set_id;
		}
		else
		{
			$set_id = $_REQUEST['set_id'];

			DBUpdate(
				'course_express_override_sets',
				$columns,
				[
					'ID' => (int) $set_id,
					'SYEAR' => UserSyear(),
					'SCHOOL_ID' => UserSchool(),
				]
			);
		}

		if ( $set_id )
		{
			// The courses of the set: replace them all.
			DBQuery( "DELETE FROM course_express_override_courses
				WHERE SET_ID='" . (int) $set_id . "'" );

			$course_ids = array_filter( array_map(
				'intval',
				(array) issetVal( $_REQUEST['courses'], [] )
			) );

			if ( $course_ids )
			{
				// Security: only the courses of the current school year & school.
				$allowed_RET = DBGet( "SELECT COURSE_ID
					FROM courses
					WHERE SYEAR='" . UserSyear() . "'
					AND SCHOOL_ID='" . UserSchool() . "'
					AND COURSE_ID IN(" . implode( ',', $course_ids ) . ")" );

				foreach ( (array) $allowed_RET as $allowed )
				{
					DBInsert( 'course_express_override_courses', [
						'SET_ID' => (int) $set_id,
						'COURSE_ID' => (int) $allowed['COURSE_ID'],
					] );
				}
			}

			$note[] = button( 'check' ) . '&nbsp;' . _( 'Your changes were saved.' );
		}
	}

	if ( ! $error )
	{
		// Unset modfunc & values & redirect URL.
		RedirectURL( [ 'modfunc', 'title', 'sort_order', 'values', 'override', 'courses' ] );
	}
}

/**
 * Delete a set
 */
if ( $_REQUEST['modfunc'] === 'delete'
	&& $_REQUEST['set_id']
	&& $_REQUEST['set_id'] !== 'new' )
{
	if ( DeletePrompt( _( 'Override Set' ) ) )
	{
		DBQuery( "DELETE FROM course_express_override_courses
			WHERE SET_ID='" . (int) $_REQUEST['set_id'] . "'" );

		DBQuery( "DELETE FROM course_express_override_sets
			WHERE ID='" . (int) $_REQUEST['set_id'] . "'
			AND SYEAR='" . UserSyear() . "'
			AND SCHOOL_ID='" . UserSchool() . "'" );

		$_REQUEST['set_id'] = '';

		// Unset modfunc & set_id & redirect URL.
		RedirectURL( [ 'modfunc', 'set_id' ] );
	}
	else
	{
		return;
	}
}

echo ErrorMessage( $error );

echo ErrorMessage( $note, 'note' );

$sets = CourseExpressOverrideSetsGet();

/**
 * One set: the edit form
 */
if ( $_REQUEST['set_id'] )
{
	$is_new = $_REQUEST['set_id'] === 'new';

	// Note: do not use issetVal() here, it takes its argument by reference and
	// would add a null entry to $sets for an unknown set.
	$set = $is_new || ! isset( $sets[ $_REQUEST['set_id'] ] ) ?
		[] :
		$sets[ $_REQUEST['set_id'] ];

	if ( ! $is_new
		&& ! $set )
	{
		echo ErrorMessage( [ _( 'The override set was not found.' ) ] );

		$_REQUEST['set_id'] = '';
	}
	else
	{
		echo '<form action="' . URLEscape( 'Modules.php?modname=' . $_REQUEST['modname'] .
			'&set_id=' . $_REQUEST['set_id'] . '&modfunc=save' ) . '" method="POST">';

		PopTable( 'header', $is_new ?
			_( 'New Override Set' ) :
			CourseExpressHTML( $set['TITLE'] ) );

		echo '<table class="widefat"><tr><td>' .
			TextInput(
				issetVal( $set['TITLE'], '' ),
				'title',
				_( 'Title' ),
				'required maxlength="100"',
				// No click-to-edit div: its input lives in a <template> and is
				// never submitted. TextInput() escapes the value itself.
				false
			) .
			'</td><td>' .
			TextInput(
				issetVal( $set['SORT_ORDER'], '' ),
				'sort_order',
				_( 'Sort Order' ),
				' type="number" step="1" min="0" max="999"',
				false
			) .
			'</td></tr></table>';

		echo '<p>' . _( 'Tick a setting to override it for the courses of this set. The unticked settings keep their default.' ) . '</p>';

		$inputs = CourseExpressSettingsInputs( 'values', $set );

		echo '<table class="widefat"><thead><tr>
			<th>' . _( 'Override' ) . '</th>
			<th>' . _( 'Setting' ) . '</th>
		</tr></thead><tbody>';

		foreach ( $inputs as $setting => $input )
		{
			$checked = ! empty( $set['OVERRIDDEN'] )
				&& in_array( $setting, $set['OVERRIDDEN'], true );

			echo '<tr><td><input type="checkbox" name="' .
				AttrEscape( 'override[' . $setting . ']' ) . '" value="Y"' .
				( $checked ? ' checked' : '' ) . ' /></td><td>' . $input . '</td></tr>';
		}

		echo '</tbody></table>';

		echo '<p>' . _( 'Courses in this set:' ) . '</p>';

		echo _courseExpressCoursesSelect( array_keys( (array) issetVal( $set['COURSES'], [] ) ) );

		echo '<div class="center"><br />' . Buttons( _( 'Save' ) ) . '</div>';

		PopTable( 'footer' );

		echo '</form>';

		echo '<div class="center">' .
			button(
				'back',
				_( 'Back to the sets' ),
				URLEscape( 'Modules.php?modname=' . $_REQUEST['modname'] )
			) .
			( $is_new ? '' : '&nbsp;&nbsp;' . button(
				'remove',
				_( 'Delete' ),
				URLEscape( 'Modules.php?modname=' . $_REQUEST['modname'] .
					'&set_id=' . $_REQUEST['set_id'] . '&modfunc=delete' )
			) ) .
			'</div>';

		return;
	}
}

/**
 * The list of sets
 */
$sets_LO = [];

foreach ( $sets as $set )
{
	$overridden = [];

	foreach ( $set['OVERRIDDEN'] as $setting )
	{
		if ( ! isset( $settings[ $setting ] ) )
		{
			continue;
		}

		$overridden[] = '<b>' . CourseExpressHTML( $settings[ $setting ]['TITLE'] ) . '</b>: ' .
			CourseExpressSettingDisplay( $setting, $set[ $setting ] );
	}

	$courses = [];

	foreach ( $set['COURSES'] as $course )
	{
		$courses[] = CourseExpressHTML( $course['TITLE'] );
	}

	$sets_LO[] = [
		'TITLE' => '<a href="' . URLEscape( 'Modules.php?modname=' . $_REQUEST['modname'] .
			'&set_id=' . $set['ID'] ) . '">' . CourseExpressHTML( $set['TITLE'] ) . '</a>',
		'OVERRIDES' => $overridden ? implode( '<br />', $overridden ) : '-',
		'COURSES' => $courses ? implode( ', ', $courses ) : '-',
	];
}

DrawHeader(
	'',
	button(
		'add',
		_( 'Add an Override Set' ),
		URLEscape( 'Modules.php?modname=' . $_REQUEST['modname'] . '&set_id=new' )
	)
);

ListOutput(
	CourseExpressListIndex( $sets_LO ),
	[
		'TITLE' => _( 'Override Set' ),
		'OVERRIDES' => _( 'Overrides' ),
		'COURSES' => _( 'Courses' ),
	],
	'Override Set',
	'Override Sets',
	[],
	[],
	[ 'save' => false, 'search' => false, 'sort' => false, 'add' => false ]
);

/**
 * Checkbox list of every Course, grouped by Subject
 *
 * Note: not a `<select multiple>`. The theme sets every select to a fixed
 * 27px height, so a sized multiple select collapses to a one line scroller,
 * and picking several courses would mean Ctrl-clicking inside it.
 *
 * Local function
 *
 * @param  array $selected Selected Course IDs.
 *
 * @return string Checkbox list HTML.
 */
function _courseExpressCoursesSelect( $selected )
{
	$courses_RET = DBGet( "SELECT c.COURSE_ID,c.TITLE,c.SHORT_NAME,cs.TITLE AS SUBJECT_TITLE
		FROM courses c,course_subjects cs
		WHERE c.SUBJECT_ID=cs.SUBJECT_ID
		AND c.SYEAR='" . UserSyear() . "'
		AND c.SCHOOL_ID='" . UserSchool() . "'
		ORDER BY cs.SORT_ORDER IS NULL,cs.SORT_ORDER,cs.TITLE,c.TITLE" );

	if ( ! $courses_RET )
	{
		return ErrorMessage( [ _( 'There is no course yet.' ) ], 'warning' );
	}

	$selected = array_flip( (array) $selected );

	$html = '<div class="course-express-courses" style="max-height: 22rem; overflow-y: auto;' .
		' border: 1px solid #cacaca; padding: 0.5em 0.75em;">';

	$subject = null;

	$count = 0;

	foreach ( (array) $courses_RET as $course )
	{
		$subject_title = ParseMLField( $course['SUBJECT_TITLE'] );

		if ( $subject_title !== $subject )
		{
			if ( $subject !== null )
			{
				$html .= '</div>';
			}

			$subject = $subject_title;

			$html .= '<div style="margin-bottom: 0.75em;"><b>' .
				CourseExpressHTML( $subject_title ) . '</b><br />';
		}

		$label = ParseMLField( $course['TITLE'] );

		if ( $course['SHORT_NAME'] !== '' )
		{
			$label .= ' (' . $course['SHORT_NAME'] . ')';
		}

		$checked = isset( $selected[ $course['COURSE_ID'] ] );

		$html .= '<label class="checkbox-label" style="display: inline-block;' .
			' min-width: 15em; margin-right: 1em;">' .
			'<input type="checkbox" name="courses[]" value="' .
			AttrEscape( $course['COURSE_ID'] ) . '"' . ( $checked ? ' checked' : '' ) .
			'>&nbsp;' . CourseExpressHTML( $label ) . '</label> ';

		$count++;
	}

	if ( $subject !== null )
	{
		$html .= '</div>';
	}

	$html .= '</div>';

	$html .= FormatInputTitle( sprintf(
		_( 'Tick the courses of this set. %d courses in this school.' ),
		$count
	) );

	return $html;
}
