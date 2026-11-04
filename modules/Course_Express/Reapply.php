<?php
/**
 * Course Express: Reapply Settings
 *
 * Push the current Section Defaults & override sets onto the sections which
 * already exist -- after a default or an override set was changed, or a
 * course was added to a set, once its sections were imported.
 *
 * 3 steps wizard:
 * 1. Choose the settings to reapply, and the courses.
 * 2. Review the sections which would change, setting by setting.
 * 3. Update the sections.
 *
 * @since 1.2
 *
 * @package RosarioSIS
 * @subpackage modules
 */

require_once 'modules/Course_Express/includes/Reapply.fnc.php';

DrawHeader( ProgramTitle() );

$error = $note = [];

if ( ! AllowEdit() )
{
	$error[] = _( 'You are not allowed to use this program.' );

	echo ErrorMessage( $error );

	return;
}

if ( ! CourseExpressDefaultsAreSet() )
{
	echo ErrorMessage(
		[ _( 'Please set the default settings every section gets first.' ) ],
		'warning'
	);

	echo '<div class="center"><br />' .
		button(
			'settings',
			_( 'Section Defaults' ),
			URLEscape( 'Modules.php?modname=Course_Express/Defaults.php' )
		) .
		'</div>';

	return;
}

$settings = CourseExpressSettings();

$sets = CourseExpressOverrideSetsGet();

$modfunc = $_REQUEST['modfunc'];

if ( ! in_array( $modfunc, [ 'preview', 'apply' ], true ) )
{
	$modfunc = '';
}

// The choices, carried from one step to the next.
$subject_id = (string) (int) issetVal( $_REQUEST['subject_id'], '' );

$set_id = (string) (int) issetVal( $_REQUEST['set_id'], '' );

if ( $set_id
	&& ! isset( $sets[ $set_id ] ) )
{
	$set_id = '0';
}

$only = [];

foreach ( array_keys( $settings ) as $setting )
{
	if ( ! empty( $_REQUEST['reapply'][ $setting ] ) )
	{
		$only[] = $setting;
	}
}

if ( $modfunc !== ''
	&& ! $only )
{
	$error[] = _( 'Please tick at least one setting to reapply.' );

	$modfunc = '';
}

$choices_hidden = '<input type="hidden" name="subject_id" value="' . AttrEscape( $subject_id ) . '" />' .
	'<input type="hidden" name="set_id" value="' . AttrEscape( $set_id ) . '" />';

foreach ( $only as $setting )
{
	$choices_hidden .= '<input type="hidden" name="' . AttrEscape( 'reapply[' . $setting . ']' ) .
		'" value="Y" />';
}

/**
 * Step 3 -- Update the sections
 */
if ( $modfunc === 'apply' )
{
	if ( CourseExpressInputTruncated() )
	{
		// Some of the ticks were lost on the way in: applying now would update
		// sections the user asked to leave out.
		echo ErrorMessage( [ _( 'Too many sections were skipped at once for the server to read them all. Please leave fewer sections out, or narrow down the courses, and try again.' ) ] );

		return;
	}

	// Analyzed again: what is applied is what the settings say now.
	$sections = CourseExpressReapplyAnalyze(
		$only,
		CourseExpressReapplyCourseIds( $subject_id, $set_id )
	);

	// Only the sections to leave out are posted, see Sections.php.
	$skip = (array) issetVal( $_REQUEST['skip'], [] );

	$updated = 0;

	$not_updated = [];

	foreach ( $sections as $cp_id => $section )
	{
		if ( $section['STATUS'] === 'unchanged' )
		{
			continue;
		}

		if ( $section['STATUS'] === 'update'
			&& ! empty( $skip[ $cp_id ] ) )
		{
			$section['STATUS'] = 'skipped';

			$section['STATUS_TEXT'] = _( 'Skipped.' );
		}

		if ( $section['STATUS'] === 'update'
			&& CourseExpressReapplySection( $section ) )
		{
			$updated++;

			continue;
		}

		$not_updated[] = $section;
	}

	_courseExpressReapplyStepsDraw( 3 );

	$note[] = button( 'check' ) . '&nbsp;' . sprintf(
		_( '%d sections were updated.' ),
		$updated
	);

	echo ErrorMessage( $note, 'note' );

	if ( $not_updated )
	{
		PopTable( 'header', _( 'Sections not updated' ) );

		_courseExpressReapplyList( $not_updated, false );

		PopTable( 'footer' );
	}

	echo '<div class="center"><br />' .
		button(
			'back',
			_( 'Reapply other settings' ),
			URLEscape( 'Modules.php?modname=' . $_REQUEST['modname'] )
		) .
		'</div>';

	return;
}

/**
 * Step 2 -- Review the sections which would change
 */
if ( $modfunc === 'preview' )
{
	_courseExpressReapplyStepsDraw( 2 );

	$sections = CourseExpressReapplyAnalyze(
		$only,
		CourseExpressReapplyCourseIds( $subject_id, $set_id )
	);

	$counts = [ 'error' => 0, 'update' => 0, 'unchanged' => 0 ];

	foreach ( $sections as $section )
	{
		$counts[ $section['STATUS'] ]++;
	}

	$listed = array_filter( $sections, function( $section )
	{
		return $section['STATUS'] !== 'unchanged';
	} );

	echo '<form action="' . URLEscape( 'Modules.php?modname=' . $_REQUEST['modname'] .
		'&modfunc=apply' ) . '" method="POST">';

	echo $choices_hidden;

	PopTable( 'header', _( 'Sections to Update' ) );

	echo '<p class="center">' . sprintf(
		_( '%d sections out of %d will be updated; %d already have these settings.' ),
		$counts['update'],
		count( $sections ),
		$counts['unchanged']
	) . ( $counts['update'] ? ' ' . _( 'Tick "Skip" on any you do not want.' ) : '' ) . '</p>';

	if ( count( $listed ) > 1000 )
	{
		echo ErrorMessage( [ sprintf(
			_( 'Only the first 1000 of the %d sections are listed, the ones which cannot be updated first. Every section is updated all the same.' ),
			count( $listed )
		) ], 'note' );
	}

	if ( $listed )
	{
		_courseExpressReapplyList( $listed, true );
	}

	echo '<div class="center"><br />' .
		( $counts['update'] ?
			Buttons( _( 'Update Sections' ) ) :
			ErrorMessage( [ _( 'There is no section to update.' ) ], 'note' ) ) .
		'</div>';

	PopTable( 'footer' );

	echo '</form>';

	echo '<div class="center">' .
		button(
			'back',
			_( 'Start over' ),
			URLEscape( 'Modules.php?modname=' . $_REQUEST['modname'] .
				( $set_id ? '&set_id=' . $set_id : '' ) )
		) .
		'</div>';

	return;
}

/**
 * Step 1 -- Choose the settings & the courses
 */
_courseExpressReapplyStepsDraw( 1 );

echo ErrorMessage( $error );

echo '<form action="' . URLEscape( 'Modules.php?modname=' . $_REQUEST['modname'] .
	'&modfunc=preview' ) . '" method="POST">';

PopTable( 'header', _( 'Reapply Settings' ) );

echo '<p>' . _( 'Give the sections which already exist the settings they would get if they were imported today: the Section Defaults, with the override sets of their course applied. Every section of the courses chosen is compared, including the ones created by hand in Scheduling > Courses; the next screen lists what would change before anything is saved.' ) . '</p>';

$defaults = CourseExpressDefaultsGet();

echo '<table class="widefat"><thead><tr>
	<th>' . _( 'Reapply' ) . '</th>
	<th>' . _( 'Setting' ) . '</th>
	<th>' . _( 'Default' ) . '</th>
</tr></thead><tbody>';

// First visit: every setting ticked. Back from an error: what was ticked.
$ticked = $only ? $only : array_keys( $settings );

foreach ( $settings as $setting => $definition )
{
	echo '<tr><td><input type="checkbox" id="' . AttrEscape( 'reapply_' . $setting ) .
		'" name="' . AttrEscape( 'reapply[' . $setting . ']' ) . '" value="Y"' .
		( in_array( $setting, $ticked, true ) ? ' checked' : '' ) . ' /></td>' .
		'<td><label for="' . AttrEscape( 'reapply_' . $setting ) . '">' .
		CourseExpressHTML( $definition['TITLE'] ) . '</label></td>' .
		'<td>' . CourseExpressSettingDisplay( $setting, $defaults[ $setting ] ) . '</td></tr>';
}

echo '</tbody></table>';

echo '<p>' . _( 'An unticked setting is left as it is on every section: untick Seats, for example, to keep the seats set by hand.' ) . '</p>';

$subjects_RET = DBGet( "SELECT SUBJECT_ID,TITLE
	FROM course_subjects
	WHERE SYEAR='" . UserSyear() . "'
	AND SCHOOL_ID='" . UserSchool() . "'
	ORDER BY SORT_ORDER IS NULL,SORT_ORDER,TITLE" );

$subject_options = [];

foreach ( (array) $subjects_RET as $subject )
{
	$subject_options[ $subject['SUBJECT_ID'] ] = ParseMLField( $subject['TITLE'] );
}

$set_options = [];

foreach ( $sets as $set )
{
	$set_options[ $set['ID'] ] = $set['TITLE'];
}

echo '<table class="widefat"><tr><td>' .
	SelectInput(
		$subject_id ? $subject_id : '',
		'subject_id',
		_( 'Subject' ),
		$subject_options,
		_( 'All' ),
		'',
		false
	) .
	'</td><td>' .
	SelectInput(
		$set_id ? $set_id : '',
		'set_id',
		_( 'Override Set' ),
		$set_options,
		_( 'Any course' ),
		'',
		false
	) .
	'</td></tr></table>';

echo '<p>' . _( 'Choose an override set to reapply only to its courses, for example after changing it.' ) . '</p>';

echo '<div class="center"><br />' . Buttons( _( 'Next' ) ) . '</div>';

PopTable( 'footer' );

echo '</form>';

/**
 * Draw the wizard steps
 *
 * Local function
 *
 * @param  int $current Current step, 1 to 3.
 *
 * @return void
 */
function _courseExpressReapplyStepsDraw( $current )
{
	$steps = [
		1 => _( 'Settings' ),
		2 => _( 'Sections' ),
		3 => _( 'Done' ),
	];

	$html = [];

	foreach ( $steps as $number => $title )
	{
		$html[] = ( $number === $current ? '<b>' : '' ) .
			$number . '. ' . $title .
			( $number === $current ? '</b>' : '' );
	}

	DrawHeader( implode( ' &rsaquo; ', $html ) );
}

/**
 * Output the sections list, with what changes on each
 *
 * Local function
 *
 * @param  array   $sections   Analyzed sections.
 * @param  boolean $checkboxes Add a checkbox to leave a section out.
 *
 * @return void
 */
function _courseExpressReapplyList( $sections, $checkboxes )
{
	$settings = CourseExpressSettings();

	$sections_LO = [];

	foreach ( $sections as $cp_id => $section )
	{
		$changes = [];

		if ( $section['STATUS'] === 'update' )
		{
			foreach ( $section['CHANGES'] as $setting => $change )
			{
				$changes[] = '<b>' . CourseExpressHTML( $settings[ $setting ]['TITLE'] ) . '</b>: ' .
					CourseExpressSettingDisplay( $setting, $change[0] ) . ' &rarr; ' .
					CourseExpressSettingDisplay( $setting, $change[1] );
			}

			if ( $section['SETS'] )
			{
				$changes[] = '<i>' . sprintf(
					_( 'Overridden by: %s' ),
					CourseExpressHTML( implode( ', ', $section['SETS'] ) )
				) . '</i>';
			}
		}
		else
		{
			// STATUS_TEXT is built from translated strings: escape it.
			$changes[] = CourseExpressHTML( $section['STATUS_TEXT'] );
		}

		foreach ( $section['WARNINGS'] as $warning )
		{
			$changes[] = '<span class="legend-red">' . CourseExpressHTML( $warning ) . '</span>';
		}

		$row = [
			'COURSE' => CourseExpressHTML( $section['COURSE_NUMBER'] !== '' ?
				$section['COURSE_TITLE'] . ' (' . $section['COURSE_NUMBER'] . ')' :
				$section['COURSE_TITLE'] ),
			'SECTION' => CourseExpressHTML( $section['TITLE'] ),
			'STUDENTS' => (int) $section['FILLED_SEATS'],
			'CHANGES' => implode( '<br />', $changes ),
		];

		if ( $checkboxes )
		{
			$row['SKIP'] = $section['STATUS'] === 'update' ?
				'<input type="checkbox" name="' . AttrEscape( 'skip[' . (int) $cp_id . ']' ) .
					'" value="Y" />' :
				'-';
		}

		$sections_LO[] = $row;
	}

	$columns = [
		'COURSE' => _( 'Course' ),
		'SECTION' => _( 'Section' ),
		'STUDENTS' => _( 'Students' ),
		'CHANGES' => _( 'Changes' ),
	];

	if ( $checkboxes )
	{
		$columns['SKIP'] = _( 'Skip' );
	}

	ListOutput(
		CourseExpressListIndex( $sections_LO ),
		$columns,
		'Section',
		'Sections',
		[],
		[],
		[ 'save' => false, 'search' => false, 'sort' => false, 'add' => false ]
	);
}
