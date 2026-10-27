<?php
/**
 * Info Express: Enrollment Dates functions
 *
 * Moves the date a student was enrolled, and the date they joined each of their
 * classes, to a date you pick.
 *
 * Only START_DATE is touched, never END_DATE: this moves the day the enrollment
 * begins, it does not enroll or drop anybody. A record whose END_DATE falls
 * before the new date is left alone -- writing the start date past the end date
 * would make the enrollment nonsense, and RosarioSIS reads that as a drop.
 *
 * @package RosarioSIS
 * @subpackage modules
 */

/**
 * The school year's own dates
 *
 * A start date outside them is almost always a slip: scheduling, attendance and
 * the marking periods all sit inside the year.
 *
 * @return array `START_DATE` and `END_DATE`, either may be empty.
 */
function InfoExpressSchoolYearDates()
{
	$full_year_mp = GetFullYearMP();

	if ( ! $full_year_mp )
	{
		return [ 'START_DATE' => '', 'END_DATE' => '' ];
	}

	return [
		'START_DATE' => (string) GetMP( $full_year_mp, 'START_DATE' ),
		'END_DATE' => (string) GetMP( $full_year_mp, 'END_DATE' ),
	];
}

/**
 * Read a list of student IDs typed or pasted in
 *
 * Anything that is not a digit separates one ID from the next, so a column
 * copied out of a spreadsheet, a comma separated list and one ID per line all
 * work.
 *
 * @param  string $text Pasted text.
 *
 * @return array Student IDs, as strings, without repeats.
 */
function InfoExpressStudentIDsRead( $text )
{
	preg_match_all( '/\d+/', (string) $text, $matches );

    $ids = [];

	foreach ( (array) $matches[0] as $id )
	{
		$id = (string) (int) $id;

		if ( $id !== '0' )
		{
			$ids[ $id ] = $id;
		}
	}

	return array_values( $ids );
}

/**
 * The students whose dates would move
 *
 * @param  string $new_date  The date to move to, YYYY-MM-DD.
 * @param  array  $only_ids  Student IDs to keep, empty for every student.
 * @param  bool   $enrollment Move the enrollment date.
 * @param  bool   $schedule  Move the class dates.
 *
 * @return array Each entry keyed by Student ID, see InfoExpressDatesRowStatus().
 */
function InfoExpressDatesAnalyze( $new_date, $only_ids, $enrollment, $schedule )
{
	$students = InfoExpressDatesStudentsGet( $only_ids );

	if ( ! $students )
	{
		return [];
	}

	$classes = $schedule ?
		InfoExpressDatesClassesGet( array_keys( $students ), $new_date ) :
		[];

	$attendance = $schedule ?
		InfoExpressDatesAttendanceGet( array_keys( $students ), $new_date ) :
		[];

	foreach ( $students as $student_id => $student )
	{
		$row = $student + [
			'ENROLLMENT_FROM' => '',
			'ENROLLMENT_MOVES' => false,
			'ENROLLMENT_BLOCKED' => false,
			'CLASSES_TOTAL' => 0,
			'CLASSES_MOVE' => 0,
			'CLASSES_BLOCKED' => 0,
			'ATTENDANCE_BEFORE' => 0,
		];

		// The enrollment record.
		if ( $enrollment )
		{
			$row['ENROLLMENT_FROM'] = $student['START_DATE'];

			if ( $student['END_DATE'] !== ''
				&& $student['END_DATE'] < $new_date )
			{
				// Writing the start date past the end date is not a date change.
				$row['ENROLLMENT_BLOCKED'] = true;
			}
			elseif ( $student['START_DATE'] !== $new_date )
			{
				$row['ENROLLMENT_MOVES'] = true;
			}
		}

		// The classes.
		$student_classes = (array) ( isset( $classes[ $student_id ] ) ? $classes[ $student_id ] : [] );

		$row['CLASSES_TOTAL'] = count( $student_classes );

		foreach ( $student_classes as $class )
		{
			if ( $class['END_DATE'] !== ''
				&& $class['END_DATE'] < $new_date )
			{
				$row['CLASSES_BLOCKED']++;
			}
			elseif ( $class['START_DATE'] !== $new_date )
			{
				$row['CLASSES_MOVE']++;
			}
		}

		$row['ATTENDANCE_BEFORE'] = (int) ( isset( $attendance[ $student_id ] ) ?
			$attendance[ $student_id ] :
			0 );

		$row['STATUS'] = InfoExpressDatesRowStatus( $row );

		$students[ $student_id ] = $row;
	}

	return $students;
}

/**
 * What will happen to one student
 *
 * `move`    -- a date will be written.
 * `nothing` -- everything already sits on the date.
 * `blocked` -- nothing can move: every record ends before the new date.
 *
 * @param  array $row Analyzed row.
 *
 * @return string Status.
 */
function InfoExpressDatesRowStatus( $row )
{
	if ( $row['ENROLLMENT_MOVES']
		|| $row['CLASSES_MOVE'] )
	{
		return 'move';
	}

	if ( $row['ENROLLMENT_BLOCKED']
		|| $row['CLASSES_BLOCKED'] )
	{
		return 'blocked';
	}

	return 'nothing';
}

/**
 * The students of this school & year, latest enrollment record first
 *
 * A student holding more than one record for the year is moved on their latest
 * one, the one that says where they stand now.
 *
 * @param  array $only_ids Student IDs to keep, empty for every student.
 *
 * @return array Keyed by Student ID.
 */
function InfoExpressDatesStudentsGet( $only_ids )
{
	$where = '';

	if ( $only_ids )
	{
		$where = " AND se.STUDENT_ID IN(" .
			implode( ',', array_map( 'intval', $only_ids ) ) . ")";
	}

	$students_RET = DBGet( "SELECT se.ID,se.STUDENT_ID,se.START_DATE,se.END_DATE,
		s.FIRST_NAME,s.MIDDLE_NAME,s.LAST_NAME,sg.TITLE AS GRADE_LEVEL
		FROM student_enrollment se
		JOIN students s ON (s.STUDENT_ID=se.STUDENT_ID)
		LEFT OUTER JOIN school_gradelevels sg ON (sg.ID=se.GRADE_ID)
		WHERE se.SYEAR='" . UserSyear() . "'
		AND se.SCHOOL_ID='" . UserSchool() . "'" . $where . "
		ORDER BY s.LAST_NAME,s.FIRST_NAME,se.START_DATE IS NULL,se.START_DATE DESC,se.ID DESC" );

	$students = [];

	foreach ( (array) $students_RET as $row )
	{
		if ( isset( $students[ $row['STUDENT_ID'] ] ) )
		{
			// Already have their latest record.
			continue;
		}

		$row['START_DATE'] = (string) issetVal( $row['START_DATE'], '' );

		$row['END_DATE'] = (string) issetVal( $row['END_DATE'], '' );

		$row['FULL_NAME'] = implode( ' ', array_filter( [
			$row['FIRST_NAME'],
			issetVal( $row['MIDDLE_NAME'], '' ),
			$row['LAST_NAME'],
		] ) );

		$students[ $row['STUDENT_ID'] ] = $row;
	}

	return $students;
}

/**
 * Every class the students hold
 *
 * @param  array  $student_ids Student IDs.
 * @param  string $new_date    Unused, kept so the call reads with its siblings.
 *
 * @return array Keyed by Student ID, each a list of schedule rows.
 */
function InfoExpressDatesClassesGet( $student_ids, $new_date )
{
	$student_ids = array_filter( array_map( 'intval', (array) $student_ids ) );

	if ( ! $student_ids )
	{
		return [];
	}

	$schedule_RET = DBGet( "SELECT STUDENT_ID,COURSE_PERIOD_ID,START_DATE,END_DATE
		FROM schedule
		WHERE SYEAR='" . UserSyear() . "'
		AND SCHOOL_ID='" . UserSchool() . "'
		AND STUDENT_ID IN(" . implode( ',', $student_ids ) . ")" );

	$classes = [];

	foreach ( (array) $schedule_RET as $row )
	{
		$classes[ $row['STUDENT_ID'] ][] = [
			'COURSE_PERIOD_ID' => $row['COURSE_PERIOD_ID'],
			'START_DATE' => (string) issetVal( $row['START_DATE'], '' ),
			'END_DATE' => (string) issetVal( $row['END_DATE'], '' ),
		];
	}

	return $classes;
}

/**
 * How much period attendance sits before the new date
 *
 * Nothing is done with it -- it is reported so a date moved forward does not
 * quietly leave attendance outside the days the student is enrolled for. When
 * one schedule record is edited by hand, RosarioSIS deletes that attendance;
 * this page does not, because it would be deleting it for a whole school at
 * once.
 *
 * @param  array  $student_ids Student IDs.
 * @param  string $new_date    The date to move to.
 *
 * @return array Keyed by Student ID, the number of records.
 */
function InfoExpressDatesAttendanceGet( $student_ids, $new_date )
{
	$student_ids = array_filter( array_map( 'intval', (array) $student_ids ) );

	if ( ! $student_ids
		|| ! VerifyDate( $new_date ) )
	{
		return [];
	}

	$attendance_RET = DBGet( "SELECT ap.STUDENT_ID,COUNT(*) AS RECORDS
		FROM attendance_period ap,course_periods cp
		WHERE cp.COURSE_PERIOD_ID=ap.COURSE_PERIOD_ID
		AND cp.SYEAR='" . UserSyear() . "'
		AND cp.SCHOOL_ID='" . UserSchool() . "'
		AND ap.STUDENT_ID IN(" . implode( ',', $student_ids ) . ")
		AND ap.SCHOOL_DATE<'" . $new_date . "'
		GROUP BY ap.STUDENT_ID" );

	$counts = [];

	foreach ( (array) $attendance_RET as $row )
	{
		$counts[ $row['STUDENT_ID'] ] = $row['RECORDS'];
	}

	return $counts;
}

/**
 * Move one student's dates
 *
 * @uses Info_Express/EnrollmentDates.php|move_dates action hook
 *
 * @param array  $row        Analyzed row.
 * @param string $new_date   The date to move to.
 * @param bool   $enrollment Move the enrollment date.
 * @param bool   $schedule   Move the class dates.
 *
 * @return array `ENROLLMENT` (bool) and `CLASSES` (int), what was written.
 */
function InfoExpressDatesApply( $row, $new_date, $enrollment, $schedule )
{
	$done = [ 'ENROLLMENT' => false, 'CLASSES' => 0 ];

	if ( ! VerifyDate( $new_date ) )
	{
		return $done;
	}

	if ( $enrollment
		&& ! empty( $row['ENROLLMENT_MOVES'] ) )
	{
		DBUpdate(
			'student_enrollment',
			[ 'START_DATE' => $new_date ],
			[ 'ID' => (int) $row['ID'] ]
		);

		$done['ENROLLMENT'] = true;
	}

	if ( $schedule
		&& ! empty( $row['CLASSES_MOVE'] ) )
	{
		// Leave the records that end before the new date: see the file header.
		DBQuery( "UPDATE schedule
			SET START_DATE='" . $new_date . "'
			WHERE SYEAR='" . UserSyear() . "'
			AND SCHOOL_ID='" . UserSchool() . "'
			AND STUDENT_ID='" . (int) $row['STUDENT_ID'] . "'
			AND START_DATE<>'" . $new_date . "'
			AND (END_DATE IS NULL OR END_DATE>='" . $new_date . "')" );

		$done['CLASSES'] = (int) $row['CLASSES_MOVE'];
	}

	if ( $done['ENROLLMENT']
		|| $done['CLASSES'] )
	{
		do_action( 'Info_Express/EnrollmentDates.php|move_dates' );
	}

	return $done;
}
