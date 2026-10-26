<?php
/**
 * Sim Date program functions
 *
 * @package RosarioSIS
 * @subpackage modules
 */

/**
 * Save the sim date & log the change.
 * Uses the global (SCHOOL_ID=0) SIM_DATE config.
 *
 * @param string $new_date Date (YYYY-MM-DD) or '' to turn off.
 * @param string $action   Action, for the log.
 *
 * @return bool False if invalid date.
 */
function SimDateSave( $new_date, $action )
{
	global $_ROSARIO;

	$new_date = (string) $new_date;

	if ( $new_date !== ''
		&& ! SimDateIsValid( $new_date ) )
	{
		return false;
	}

	$old_date = SimDateValue();

	if ( $new_date === $old_date )
	{
		return true;
	}

	$global_row = DBGetOne( "SELECT 1
		FROM config
		WHERE TITLE='SIM_DATE'
		AND SCHOOL_ID='0'" );

	if ( ! $global_row )
	{
		// Config() would insert it for the current school: create the global row first.
		DBInsert(
			'config',
			[ 'SCHOOL_ID' => '0', 'TITLE' => 'SIM_DATE', 'CONFIG_VALUE' => '' ]
		);

		// Reload Config() cache.
		unset( $_ROSARIO['Config'] );
	}

	Config( 'SIM_DATE', $new_date );

	DBInsert(
		'sim_date_log',
		[
			'STAFF_ID' => User( 'STAFF_ID' ),
			'OLD_VALUE' => $old_date,
			'NEW_VALUE' => $new_date,
			'ACTION' => $action,
		]
	);

	// Current user: move to the new marking period now.
	SimDateSyncSession();

	return true;
}

/**
 * Add days to a date.
 *
 * @param string $date Date (YYYY-MM-DD).
 * @param int    $days Days, can be negative.
 *
 * @return string Date (YYYY-MM-DD).
 */
function SimDateAddDays( $date, $days )
{
	$datetime = date_create( $date );

	$datetime->modify( ( $days < 0 ? '-' : '+' ) . abs( (int) $days ) . ' days' );

	return $datetime->format( 'Y-m-d' );
}

/**
 * Active calendar: the default calendar of the current school & school year,
 * else the first one (same as School Setup > Calendars).
 *
 * @return int Calendar ID or 0 if none.
 */
function SimDateCalendarID()
{
	$calendar_id = DBGetOne( "SELECT CALENDAR_ID
		FROM attendance_calendars
		WHERE SYEAR='" . UserSyear() . "'
		AND SCHOOL_ID='" . UserSchool() . "'
		ORDER BY DEFAULT_CALENDAR IS NULL,DEFAULT_CALENDAR DESC,CALENDAR_ID
		LIMIT 1" );

	return (int) $calendar_id;
}

/**
 * Is date a school day on the calendar?
 * Same rule as School Setup > Calendars: the day has minutes.
 * Weekends & holidays have none.
 *
 * @param string $date        Date (YYYY-MM-DD).
 * @param int    $calendar_id Calendar ID.
 *
 * @return bool
 */
function SimDateIsSchoolDay( $date, $calendar_id )
{
	return (bool) DBGetOne( "SELECT 1
		FROM attendance_calendar
		WHERE CALENDAR_ID='" . (int) $calendar_id . "'
		AND SCHOOL_DATE='" . $date . "'
		AND MINUTES>0" );
}

/**
 * Next school day on the calendar, after date.
 *
 * @param string $date        Date (YYYY-MM-DD).
 * @param int    $calendar_id Calendar ID.
 *
 * @return string Date (YYYY-MM-DD) or '' if none left on the calendar.
 */
function SimDateNextSchoolDay( $date, $calendar_id )
{
	return (string) DBGetOne( "SELECT MIN(SCHOOL_DATE)
		FROM attendance_calendar
		WHERE CALENDAR_ID='" . (int) $calendar_id . "'
		AND SCHOOL_DATE>'" . $date . "'
		AND MINUTES>0" );
}

/**
 * Current marking period title.
 *
 * @param string $mp   QTR, SEM or FY.
 * @param string $date Date (YYYY-MM-DD).
 *
 * @return string Title or '' if none.
 */
function SimDateMarkingPeriod( $mp, $date )
{
	$mp_id = GetCurrentMP( $mp, $date, false );

	return $mp_id ? GetMP( $mp_id, 'TITLE' ) : '';
}

/**
 * Log value: date or Off.
 *
 * @param string $value  Date or ''.
 * @param string $column Column.
 *
 * @return string
 */
function _makeSimDateLogValue( $value, $column )
{
	return $value ? ProperDate( $value ) : _( 'Off' );
}
