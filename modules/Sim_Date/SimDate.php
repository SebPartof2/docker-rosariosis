<?php
/**
 * Sim Date
 *
 * Set the in-world "today" of the school. Hidden: in no menu, open it by URL:
 * Modules.php?modname=Sim_Date/SimDate.php
 *
 * @see docs/sim-date.md
 *
 * @package RosarioSIS
 * @subpackage modules
 */

require_once 'modules/Sim_Date/includes/SimDate.fnc.php';

DrawHeader( _( 'Sim Date' ) );

// Modules.php already checked the program is allowed (AllowUse). Belt and braces.
if ( User( 'PROFILE' ) !== 'admin'
	|| ! AllowEdit() )
{
	echo ErrorMessage( [ _( 'You are not allowed to use this program.' ) ] );

	return;
}

if ( ! function_exists( 'SimDate' ) )
{
	echo ErrorMessage( [ _( 'The RosarioSIS core patch is missing (SimDate() function not found). See docs/sim-date.md.' ) ] );

	return;
}

$calendar_id = SimDateCalendarID();

$actions = [ 'set', 'previous_day', 'next_day', 'next_school_day', 'clear' ];

if ( in_array( $_REQUEST['modfunc'], $actions ) )
{
	$today = DBDate();

	$new_date = false;

	switch ( $_REQUEST['modfunc'] )
	{
		case 'set':

			$new_date = RequestedDate( 'sim_date', '' );

			if ( ! $new_date )
			{
				$error[] = _( 'Please enter a valid date.' );

				$new_date = false;
			}

		break;

		case 'previous_day':

			$new_date = SimDateAddDays( $today, -1 );

		break;

		case 'next_day':

			$new_date = SimDateAddDays( $today, 1 );

		break;

		case 'next_school_day':

			$new_date = $calendar_id ? SimDateNextSchoolDay( $today, $calendar_id ) : '';

			if ( ! $new_date )
			{
				$error[] = $calendar_id ?
					_( 'There is no school day left after this date on the calendar.' ) :
					_( 'There are no calendars yet setup.' );

				$new_date = false;
			}

		break;

		case 'clear':

			$new_date = '';

		break;
	}

	if ( $new_date !== false )
	{
		if ( SimDateSave( $new_date, $_REQUEST['modfunc'] ) )
		{
			$note[] = $new_date ?
				sprintf( _( 'The sim date is now %s.' ), ProperDate( $new_date ) ) :
				_( 'The sim date is off: using the real date.' );
		}
		else
		{
			$error[] = _( 'Please enter a valid date.' );
		}
	}

	// Remove modfunc & date from URL.
	RedirectURL( [ 'modfunc', 'day_sim_date', 'month_sim_date', 'year_sim_date' ] );
}

echo ErrorMessage( $error );

echo ErrorMessage( $note, 'note' );

$sim_date = SimDateValue();

$today = DBDate();

$is_school_day = $calendar_id && SimDateIsSchoolDay( $today, $calendar_id );

$full_year = SimDateMarkingPeriod( 'FY', $today );

if ( ! $full_year )
{
	echo ErrorMessage(
		[ sprintf(
			_( 'Today (%s) is outside the %s school year. Logins, the marking period and the school year do not follow the sim date: roll over to change school year.' ),
			ProperDate( $today ),
			FormatSyear( UserSyear(), Config( 'SCHOOL_SYEAR_OVER_2_YEARS' ) )
		) ],
		'warning'
	);
}

/**
 * Status
 */
PopTable( 'header', _( 'Status' ) );

$status = [
	_( 'Real Date' ) => ProperDate( date( 'Y-m-d' ) ),
	_( 'Sim Date' ) => $sim_date ?
		'<b>' . ProperDate( $sim_date ) . '</b>' :
		_( 'Off (using real date)' ),
	_( 'Quarter' ) => SimDateMarkingPeriod( 'QTR', $today ),
	_( 'Semester' ) => SimDateMarkingPeriod( 'SEM', $today ),
	_( 'Full Year' ) => $full_year,
	_( 'School Day' ) => ! $calendar_id ?
		_( 'There are no calendars yet setup.' ) :
		( $is_school_day ?
			button( 'check' ) . ' ' . _( 'Yes' ) :
			button( 'remove' ) . ' ' . _( 'No' ) . ' (' . _( 'Weekend or holiday' ) . ')' ),
];

echo '<table class="width-100p cellpadding-5">';

foreach ( $status as $label => $value )
{
	echo '<tr><td class="align-right">' . $label . '</td><td>' .
		( $value !== '' ? $value : '<span class="legend-gray">' . _( 'None' ) . '</span>' ) . '</td></tr>';
}

echo '</table>';

PopTable( 'footer' );

/**
 * Controls
 * One form per action: AJAX form submission does not send the clicked button.
 */
$form_url = 'Modules.php?modname=' . $_REQUEST['modname'] . '&modfunc=';

echo '<br><form action="' . URLEscape( $form_url . 'set' ) . '" method="POST">';

DrawHeader(
	DateInput( $today, 'sim_date', _( 'Set Date' ), false, false, true ),
	SubmitButton( _( 'Save' ) )
);

echo '</form>';

$buttons = [
	'previous_day' => _( '-1 Day' ),
	'next_day' => _( '+1 Day' ),
	'next_school_day' => _( 'Next School Day' ),
	'clear' => _( 'Clear' ),
];

$buttons_html = '';

foreach ( $buttons as $action => $label )
{
	if ( $action === 'clear' && ! $sim_date )
	{
		continue;
	}

	$buttons_html .= '<form action="' . URLEscape( $form_url . $action ) . '" method="POST" style="display: inline-block;">' .
		SubmitButton( $label, '', $action === 'clear' ? '' : 'class="button-primary"' ) . '</form> ';
}

DrawHeader( $buttons_html );

/**
 * History
 * Times are real (server) times.
 */
$log_RET = DBGet( "SELECT l.CREATED_AT,l.OLD_VALUE,l.NEW_VALUE,
	" . DisplayNameSQL( 's' ) . " AS FULL_NAME
	FROM sim_date_log l
	LEFT JOIN staff s ON (s.STAFF_ID=l.STAFF_ID)
	ORDER BY l.ID DESC
	LIMIT 50",
	[
		'CREATED_AT' => 'ProperDateTime',
		'OLD_VALUE' => '_makeSimDateLogValue',
		'NEW_VALUE' => '_makeSimDateLogValue',
	]
);

echo '<br>';

ListOutput(
	$log_RET,
	[
		'CREATED_AT' => _( 'Changed at (real time)' ),
		'FULL_NAME' => _( 'User' ),
		'OLD_VALUE' => _( 'From' ),
		'NEW_VALUE' => _( 'To' ),
	],
	'Change',
	'Changes',
	[],
	[],
	[ 'count' => false, 'save' => false, 'search' => false ]
);
