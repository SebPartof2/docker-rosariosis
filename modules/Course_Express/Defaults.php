<?php
/**
 * Course Express: Section Defaults
 *
 * The settings every section created by Course Express gets, unless an
 * override set says otherwise.
 *
 * Not on this page:
 * - Short Name, Teacher, Period, Marking Period & Credits are derived from
 *   the imported section;
 * - Room is always left empty and Gender Restriction is always None.
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

$settings = CourseExpressSettings();

if ( $_REQUEST['modfunc'] === 'save' )
{
	$values = CourseExpressSettingsRequest( 'values' );

	if ( $settings['CALENDAR_ID']['OPTIONS']
		&& $values['CALENDAR_ID'] === '' )
	{
		// A Course Period always belongs to a calendar.
		$error[] = _( 'Calendar' ) . ': ' . _( 'Please fill in the required fields' );
	}

	if ( $values['DAYS'] === '' )
	{
		// A section without meeting days never meets.
		$error[] = _( 'Meeting Days' ) . ': ' . _( 'Please fill in the required fields' );
	}

	if ( ! $error )
	{
		CourseExpressDefaultsSet( $values );

		$note[] = button( 'check' ) . '&nbsp;' . _( 'Your changes were saved.' );
	}

	// Unset modfunc & values & redirect URL.
	RedirectURL( [ 'modfunc', 'values' ] );
}

echo ErrorMessage( $error );

echo ErrorMessage( $note, 'note' );

if ( ! $settings['CALENDAR_ID']['OPTIONS'] )
{
	echo ErrorMessage(
		[ _( 'Please create a calendar first, in School > Calendars.' ) ],
		'warning'
	);
}

$defaults = CourseExpressDefaultsGet();

if ( ! CourseExpressDefaultsAreSet() )
{
	echo ErrorMessage(
		[ _( 'These settings have never been saved. Sections cannot be imported before you save them.' ) ],
		'warning'
	);
}
elseif ( $default_problems = CourseExpressSettingsProblems( $defaults ) )
{
	// Typically right after a rollover: the IDs belong to the previous school year.
	echo ErrorMessage(
		array_merge(
			[ _( 'Sections cannot be imported until these settings are saved again:' ) ],
			array_map( 'CourseExpressHTML', $default_problems )
		),
		'warning'
	);
}

echo '<form action="' . URLEscape( 'Modules.php?modname=' . $_REQUEST['modname'] .
	'&modfunc=save' ) . '" method="POST">';

PopTable( 'header', _( 'Section Defaults' ) );

echo '<p>' . _( 'Every section created by Course Express gets these settings, unless one of its override sets says otherwise.' ) . '</p>';

$inputs = CourseExpressSettingsInputs( 'values', $defaults );

echo '<table class="widefat"><tbody>';

foreach ( $inputs as $setting => $input )
{
	echo '<tr><td>' . $input . '</td></tr>';
}

echo '</tbody></table>';

echo '<div class="center"><br />' . Buttons( _( 'Save' ) ) . '</div>';

PopTable( 'footer' );

echo '</form>';

/**
 * The settings which are never asked for
 */
PopTable( 'header', _( 'Fixed &amp; derived settings' ) );

echo '<table class="widefat"><thead><tr>
		<th>' . _( 'Setting' ) . '</th>
		<th>' . _( 'Value' ) . '</th>
	</tr></thead><tbody>
	<tr><td>' . _( 'Short Name' ) . '</td><td>' .
		sprintf( _( '"%s", counting on from the sections the course already has' ),
			sprintf( _( 'Section %d' ), 1 ) ) . '</td></tr>
	<tr><td>' . _( 'Teacher' ) . '</td><td>' . _( 'From the imported section' ) . '</td></tr>
	<tr><td>' . _( 'Period' ) . '</td><td>' . _( 'From the imported section' ) . '</td></tr>
	<tr><td>' . _( 'Marking Period' ) . '</td><td>' . _( 'From the imported section' ) . '</td></tr>
	<tr><td>' . _( 'Credits' ) . '</td><td>' . _( 'The credit hours of the course' ) . '</td></tr>
	<tr><td>' . _( 'Room' ) . '</td><td>' . _( 'Always empty' ) . '</td></tr>
	<tr><td>' . _( 'Gender Restriction' ) . '</td><td>' . _( 'Always None' ) . '</td></tr>
	</tbody></table>';

PopTable( 'footer' );
