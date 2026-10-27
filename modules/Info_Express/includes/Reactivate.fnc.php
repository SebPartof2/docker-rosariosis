<?php
/**
 * Info Express: Reactivate Students functions
 *
 * A student is active when their enrollment record covers today:
 *
 *     START_DATE IS NOT NULL
 *     AND today >= START_DATE
 *     AND ( END_DATE IS NULL OR today <= END_DATE )
 *
 * which is the same test GetStuList() and Students/Student.php use. A student
 * is therefore inactive for one of three reasons, and each has its own fix:
 * they were dropped (END_DATE is set), they have not started yet (START_DATE is
 * in the future), or their record never got a start date at all.
 *
 * @package RosarioSIS
 * @subpackage modules
 */

/**
 * The students of this school & year who are not active today
 *
 * A student can hold more than one enrollment record for the same year -- they
 * were dropped and taken back, say. Only the students with no active record at
 * all are listed, and it is their latest record that gets fixed.
 *
 * @return array Each entry holds the enrollment `ID`, the student, the dates,
 *               and the `FIX` needed: `end_date`, `start_date` or `both`.
 */
function InfoExpressInactiveStudents()
{
	$today = DBDate();

	$students_RET = DBGet( "SELECT se.ID,se.STUDENT_ID,se.START_DATE,se.END_DATE,
		se.DROP_CODE,s.FIRST_NAME,s.MIDDLE_NAME,s.LAST_NAME,
		sg.TITLE AS GRADE_LEVEL,sec.TITLE AS DROP_TITLE
		FROM student_enrollment se
		JOIN students s ON (s.STUDENT_ID=se.STUDENT_ID)
		LEFT OUTER JOIN school_gradelevels sg ON (sg.ID=se.GRADE_ID)
		LEFT OUTER JOIN student_enrollment_codes sec ON (sec.ID=se.DROP_CODE)
		WHERE se.SYEAR='" . UserSyear() . "'
		AND se.SCHOOL_ID='" . UserSchool() . "'
		AND NOT EXISTS (SELECT 1
			FROM student_enrollment active
			WHERE active.STUDENT_ID=se.STUDENT_ID
			AND active.SYEAR=se.SYEAR
			AND active.SCHOOL_ID=se.SCHOOL_ID
			AND active.START_DATE IS NOT NULL
			AND '" . $today . "'>=active.START_DATE
			AND (active.END_DATE IS NULL OR '" . $today . "'<=active.END_DATE))
		ORDER BY s.LAST_NAME,s.FIRST_NAME,se.START_DATE IS NULL,se.START_DATE DESC,se.ID DESC" );

	$students = [];

	foreach ( (array) $students_RET as $row )
	{
		// The latest record of each student is the one that stands: the query
		// orders them, so the first one seen wins.
		if ( isset( $students[ $row['STUDENT_ID'] ] ) )
		{
			continue;
		}

		$start_date = (string) issetVal( $row['START_DATE'], '' );

		$end_date = (string) issetVal( $row['END_DATE'], '' );

		$needs_start = $start_date === '' || $start_date > $today;

		$needs_end = $end_date !== '' && $end_date < $today;

		if ( ! $needs_start
			&& ! $needs_end )
		{
			// Inactive for a reason this page cannot fix -- an end date in the
			// future with a start date in the future is already covered above,
			// so this only catches data we would rather not touch.
			continue;
		}

		$row['FIX'] = $needs_start && $needs_end ? 'both' : ( $needs_start ? 'start_date' : 'end_date' );

		$row['FULL_NAME'] = implode( ' ', array_filter( [
			$row['FIRST_NAME'],
			issetVal( $row['MIDDLE_NAME'], '' ),
			$row['LAST_NAME'],
		] ) );

		$row['START_DATE'] = $start_date;

		$row['END_DATE'] = $end_date;

		$students[ $row['STUDENT_ID'] ] = $row;
	}

	return $students;
}

/**
 * Say in words what will change on one record
 *
 * @param  array $student One entry of InfoExpressInactiveStudents().
 *
 * @return string Wording, HTML safe.
 */
function InfoExpressReactivateFixText( $student )
{
	$today = ProperDate( DBDate(), 'short' );

	switch ( $student['FIX'] )
	{
		case 'end_date':

			return sprintf(
				_( 'Clear the end date of %s.' ),
				'<b>' . InfoExpressHTML( ProperDate( $student['END_DATE'], 'short' ) ) . '</b>'
			);

		case 'start_date':

			return $student['START_DATE'] === '' ?
				sprintf(
					_( 'No start date: set it to %s.' ),
					'<b>' . InfoExpressHTML( $today ) . '</b>'
				) :
				sprintf(
					_( 'Starts on %s: move it back to %s.' ),
					'<b>' . InfoExpressHTML( ProperDate( $student['START_DATE'], 'short' ) ) . '</b>',
					'<b>' . InfoExpressHTML( $today ) . '</b>'
				);

		case 'both':

			return sprintf(
				_( 'Set the start date to %s and clear the end date.' ),
				'<b>' . InfoExpressHTML( $today ) . '</b>'
			);
	}

	return '';
}

/**
 * Make one student active
 *
 * Only the columns that stand in the way are touched: the end date and its drop
 * code are cleared, and a start date that is missing or still to come is pulled
 * back to today. The grade level, the calendar and the enrollment code are left
 * exactly as they are.
 *
 * No account activation email is sent. Students/Student.php sends one when a
 * single student is enrolled by hand; doing that here would mail every student
 * on the list at once.
 *
 * @uses Info_Express/Reactivate.php|reactivate action hook
 *
 * @param array $student One entry of InfoExpressInactiveStudents().
 *
 * @return bool True if the record was updated.
 */
function InfoExpressReactivate( $student )
{
	$id = (int) issetVal( $student['ID'], 0 );

	if ( ! $id )
	{
		return false;
	}

	$columns = [];

	if ( $student['FIX'] === 'end_date'
		|| $student['FIX'] === 'both' )
	{
		// DBUpdate() turns an empty value into NULL, which is what "no end
		// date" means -- and the drop code has to go with it.
		$columns['END_DATE'] = '';

		$columns['DROP_CODE'] = '';
	}

	if ( $student['FIX'] === 'start_date'
		|| $student['FIX'] === 'both' )
	{
		$columns['START_DATE'] = DBDate();
	}

	if ( ! $columns )
	{
		return false;
	}

	DBUpdate( 'student_enrollment', $columns, [ 'ID' => $id ] );

	do_action( 'Info_Express/Reactivate.php|reactivate' );

	return true;
}
