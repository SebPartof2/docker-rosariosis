<?php
/**
 * Request Express functions
 *
 * CSV parsing, resolution of the requested courses, and the replacement of a
 * student's Course Requests.
 *
 * Importing a student REPLACES their requests: the requests they have are
 * deleted, then the ones listed in the file are added. Re-importing a corrected
 * file therefore leaves exactly what the file says.
 *
 * @package RosarioSIS
 * @subpackage modules
 */

/**
 * Maximum number of rows read in a single CSV file
 */
if ( ! defined( 'REQUEST_EXPRESS_MAX_ROWS' ) )
{
	define( 'REQUEST_EXPRESS_MAX_ROWS', 2000 );
}

/**
 * Maximum number of courses requested by a single student
 */
if ( ! defined( 'REQUEST_EXPRESS_MAX_REQUESTS' ) )
{
	define( 'REQUEST_EXPRESS_MAX_REQUESTS', 40 );
}

/**
 * CSV column header aliases
 *
 * @return array Associative `[ FIELD => [ alias, ... ] ]`, in column order.
 */
function RequestExpressColumnAliases()
{
	return [
		'STUDENT' => [
			'student id', 'student i d', 'studentid', 'student identifier',
			'student number', 'student no', 'student num', 'student code',
			'id', 'id number', 'id no', 'sid', 's id', 'stu id',
			'sis id', 'rosariosis id', 'local id', 'state id', 'student',
		],
		'REQUESTS' => [
			'requests', 'request', 'course requests', 'requested courses',
			'courses', 'course', 'course numbers', 'course number',
			'course codes', 'course code', 'short names', 'short name',
			'schedule', 'sections',
		],
	];
}

/**
 * Parse the CSV text into request rows
 *
 * Without a recognized header row, the columns are read in this order:
 * Student ID, Requests.
 *
 * @param  string $csv_text CSV file contents.
 * @param  array  $messages Messages, passed by reference.
 *
 * @return array Rows: `[ 'LINE' => int, 'STUDENT' => string, 'REQUESTS' => string ]`.
 */
function RequestExpressCSVParse( $csv_text, &$messages )
{
	$csv_text = (string) $csv_text;

	// Remove UTF-8 Byte Order Mark. Note: the BOM is 3 bytes but 1 character,
	// so it has to be cut with the byte based substr(), not mb_substr().
	if ( substr( $csv_text, 0, 3 ) === "\xEF\xBB\xBF" )
	{
		$csv_text = substr( $csv_text, 3 );
	}

	$csv_text = str_replace( [ "\r\n", "\r" ], "\n", $csv_text );

	if ( trim( $csv_text ) === '' )
	{
		$messages[] = _( 'The file is empty.' );

		return [];
	}

	if ( ! mb_check_encoding( $csv_text, 'UTF-8' ) )
	{
		// Excel commonly exports CSV files as ISO-8859-1 / Windows-1252.
		$csv_text = mb_convert_encoding( $csv_text, 'UTF-8', 'ISO-8859-1' );
	}

	$first_line = '';

	foreach ( explode( "\n", $csv_text ) as $line )
	{
		if ( trim( $line ) !== '' )
		{
			$first_line = $line;

			break;
		}
	}

	$delimiter = RequestExpressDelimiterDetect( $first_line );

	// Use a stream so fgetcsv() handles quoted values containing line breaks.
	$stream = fopen( 'php://temp', 'r+' );

	if ( ! $stream )
	{
		$messages[] = _( 'The file could not be read.' );

		return [];
	}

	fwrite( $stream, $csv_text );

	rewind( $stream );

	$aliases = RequestExpressColumnAliases();

	$fields = array_keys( $aliases );

	$map = [];

	$rows = [];

	$line_number = 0;

	$too_many_rows = false;

	while ( ( $cells = fgetcsv( $stream, 0, $delimiter, '"', '' ) ) !== false )
	{
		$line_number++;

		if ( $cells === [ null ] )
		{
			// Blank line.
			continue;
		}

		$cells = array_map( 'RequestExpressCellClean', $cells );

		if ( implode( '', $cells ) === '' )
		{
			// All cells empty.
			continue;
		}

		if ( ! $map )
		{
			$map = RequestExpressHeaderMap( $cells, $aliases );

			if ( $map )
			{
				// Header row, do not import it.
				continue;
			}

			// No cell named a column: no header row, read the columns in order.
			$map = array_flip( $fields );

			$messages[] = _( 'No header row was found, the columns were read in this order:' ) .
				' ' . _( 'Student ID' ) . ', ' . _( 'Requests' ) . '.';
		}

		if ( count( $rows ) >= REQUEST_EXPRESS_MAX_ROWS )
		{
			$too_many_rows = true;

			break;
		}

		$row = [ 'LINE' => $line_number ];

		foreach ( $fields as $field )
		{
			$index = isset( $map[ $field ] ) ? $map[ $field ] : -1;

			$row[ $field ] = isset( $cells[ $index ] ) ? $cells[ $index ] : '';
		}

		$rows[] = $row;
	}

	fclose( $stream );

	if ( $too_many_rows )
	{
		$messages[] = sprintf(
			_( 'Only the first %d rows of the file were read.' ),
			REQUEST_EXPRESS_MAX_ROWS
		);
	}

	if ( ! $rows
		&& ! $messages )
	{
		// The file was read, but it holds no row.
		$messages[] = _( 'No requests were found in the file.' );
	}

	return $rows;
}

/**
 * Detect the CSV delimiter used in a line
 *
 * Note: the space is never a delimiter, it separates the requested courses.
 *
 * @param  string $line First non empty line of the CSV.
 *
 * @return string Delimiter: `,`, `;`, tab or `|`. Defaults to `,`.
 */
function RequestExpressDelimiterDetect( $line )
{
	$best = ',';

	$best_count = 0;

	foreach ( [ ',', ';', "\t", '|' ] as $delimiter )
	{
		$count = substr_count( $line, $delimiter );

		if ( $count > $best_count )
		{
			$best_count = $count;

			$best = $delimiter;
		}
	}

	return $best;
}

/**
 * Clean a CSV cell
 *
 * @param  string $cell CSV cell.
 *
 * @return string Cleaned cell.
 */
function RequestExpressCellClean( $cell )
{
	$cell = strip_tags( (string) $cell );

	$cell = preg_replace( '/[\x00-\x1F\x7F]/u', ' ', $cell );

	return trim( (string) preg_replace( '/\s+/u', ' ', $cell ) );
}

/**
 * Normalize a header or a course short name for comparison purposes
 *
 * @param  string $text Text.
 *
 * @return string Lowercase, punctuation free, space collapsed text.
 */
function RequestExpressNormalize( $text )
{
	$text = trim( (string) $text );

	// Split camel case & letter/digit boundaries, so "StudentID" normalizes
	// like "Student ID".
	$text = preg_replace( '/(\p{Ll})(\p{Lu})/u', '$1 $2', $text );

	$text = preg_replace( '/(\p{L})(\p{N})/u', '$1 $2', $text );

	$text = preg_replace( '/(\p{N})(\p{L})/u', '$1 $2', $text );

	$text = mb_strtolower( $text );

	$text = str_replace( [ '_', '-', '.', '#', '/' ], ' ', $text );

	$text = preg_replace( '/[^\p{L}\p{N} ]/u', '', $text );

	$text = preg_replace( '/\s+/u', ' ', $text );

	return trim( (string) $text );
}

/**
 * Map the CSV header cells to the fields
 *
 * A row naming at least one field is a header row. The fields it does not name
 * take, in order, the columns it does not use. A file with no header row at all
 * is left alone: its first row is data.
 *
 * @param  array $cells   Cells of the first CSV row.
 * @param  array $aliases `[ FIELD => [ header alias, ... ] ]`, in column order.
 *
 * @return array Empty if the row is not a header row, else `[ FIELD => column index ]`.
 */
function RequestExpressHeaderMap( $cells, $aliases )
{
	$map = [];

	foreach ( (array) $cells as $index => $cell )
	{
		$header = RequestExpressNormalize( $cell );

		if ( $header === '' )
		{
			continue;
		}

		foreach ( $aliases as $field => $field_aliases )
		{
			if ( isset( $map[ $field ] ) )
			{
				// First matching column wins.
				continue;
			}

			if ( in_array( $header, $field_aliases, true ) )
			{
				$map[ $field ] = $index;

				break;
			}
		}
	}

	if ( ! $map )
	{
		// No cell names a field: this is not a header row.
		return [];
	}

	$used = array_flip( $map );

	$free = [];

	for ( $index = 0; $index < count( (array) $cells ); $index++ )
	{
		if ( ! isset( $used[ $index ] ) )
		{
			$free[] = $index;
		}
	}

	foreach ( array_keys( $aliases ) as $field )
	{
		if ( isset( $map[ $field ] )
			|| ! $free )
		{
			continue;
		}

		$map[ $field ] = array_shift( $free );
	}

	return $map;
}

/**
 * Courses of the current school year & school, by short name
 *
 * @return array Associative `[ normalized short name => [ course, ... ] ]`.
 */
function RequestExpressCoursesByShortName()
{
	static $courses = null;

	if ( $courses !== null )
	{
		return $courses;
	}

	$courses_RET = DBGet( "SELECT COURSE_ID,SUBJECT_ID,TITLE,SHORT_NAME
		FROM courses
		WHERE SYEAR='" . UserSyear() . "'
		AND SCHOOL_ID='" . UserSchool() . "'" );

	$courses = [];

	foreach ( (array) $courses_RET as $course )
	{
		$key = RequestExpressNormalize( $course['SHORT_NAME'] );

		if ( $key === '' )
		{
			continue;
		}

		$course['TITLE'] = ParseMLField( $course['TITLE'] );

		$courses[ $key ][] = $course;
	}

	return $courses;
}

/**
 * Split a requests cell into course short names
 *
 * The short names are separated by a space, as documented. The comma, the
 * semicolon and the pipe are accepted too. When the whole cell is the short
 * name of a course, it is taken as one request: that is how a short name
 * holding a space is requested.
 *
 * @param  string $requests Requests cell.
 *
 * @return array Course short names, in the order they appear, duplicates removed.
 */
function RequestExpressRequestsSplit( $requests )
{
	$requests = trim( (string) $requests );

	if ( $requests === '' )
	{
		return [];
	}

	$courses = RequestExpressCoursesByShortName();

	if ( isset( $courses[ RequestExpressNormalize( $requests ) ] ) )
	{
		// The whole cell is a course short name: one request.
		return [ $requests ];
	}

	$short_names = preg_split( '/[\s,;|]+/u', $requests, -1, PREG_SPLIT_NO_EMPTY );

	$unique = [];

	foreach ( (array) $short_names as $short_name )
	{
		$key = RequestExpressNormalize( $short_name );

		if ( ! isset( $unique[ $key ] ) )
		{
			// A course can only be requested once: keep the first spelling.
			$unique[ $key ] = $short_name;
		}
	}

	return array_values( $unique );
}

/**
 * Get the Students of the current school year & school, by Student ID
 *
 * A student not enrolled in the current school is reported as not found.
 *
 * @param  array $student_ids Student IDs to look for.
 *
 * @return array Associative `[ student id => [ 'STUDENT_ID', 'FULL_NAME' ] ]`.
 */
function RequestExpressStudentsGet( $student_ids )
{
	$student_ids = array_filter( array_map( 'intval', (array) $student_ids ) );

	if ( ! $student_ids )
	{
		return [];
	}

	$students_RET = DBGet( "SELECT DISTINCT s.STUDENT_ID,s.FIRST_NAME,s.MIDDLE_NAME,s.LAST_NAME
		FROM students s,student_enrollment se
		WHERE se.STUDENT_ID=s.STUDENT_ID
		AND se.SYEAR='" . UserSyear() . "'
		AND se.SCHOOL_ID='" . UserSchool() . "'
		AND s.STUDENT_ID IN(" . implode( ',', $student_ids ) . ")" );

	$students = [];

	foreach ( (array) $students_RET as $student )
	{
		$students[ $student['STUDENT_ID'] ] = [
			'STUDENT_ID' => $student['STUDENT_ID'],
			'FULL_NAME' => implode( ' ', array_filter( [
				$student['FIRST_NAME'],
				issetVal( $student['MIDDLE_NAME'], '' ),
				$student['LAST_NAME'],
			] ) ),
		];
	}

	return $students;
}

/**
 * Get the Course Requests the students already have
 *
 * @param  array $student_ids Student IDs.
 *
 * @return array Associative `[ student id => [ course id => short name|title ] ]`.
 */
function RequestExpressRequestsExisting( $student_ids )
{
	$student_ids = array_filter( array_map( 'intval', (array) $student_ids ) );

	if ( ! $student_ids )
	{
		return [];
	}

	$requests_RET = DBGet( "SELECT sr.STUDENT_ID,sr.COURSE_ID,c.TITLE,c.SHORT_NAME
		FROM schedule_requests sr
		LEFT OUTER JOIN courses c ON (c.COURSE_ID=sr.COURSE_ID)
		WHERE sr.SYEAR='" . UserSyear() . "'
		AND sr.SCHOOL_ID='" . UserSchool() . "'
		AND sr.STUDENT_ID IN(" . implode( ',', $student_ids ) . ")" );

	$requests = [];

	foreach ( (array) $requests_RET as $request )
	{
		$label = issetVal( $request['SHORT_NAME'], '' );

		if ( $label === '' )
		{
			$label = ParseMLField( (string) issetVal( $request['TITLE'], '' ) );
		}

		$requests[ $request['STUDENT_ID'] ][ $request['COURSE_ID'] ] = $label === '' ?
			// A request pointing at a course which no longer exists.
			sprintf( _( 'Course %d' ), (int) $request['COURSE_ID'] ) :
			$label;
	}

	return $requests;
}

/**
 * Analyze the parsed rows
 *
 * Sets, for each row: `STUDENT_ID`, `STUDENT_NAME`, `COURSES` (the requests to
 * set, `[ course id => [ 'SHORT_NAME', 'TITLE', 'SUBJECT_ID' ] ]`), `REMOVED`
 * and `ADDED` (labels), `STATUS` (error|duplicate|not_found|no_change|update)
 * and `STATUS_TEXT`.
 *
 * @param  array $rows         Parsed rows.
 * @param  bool  $clear_blanks An empty requests cell clears the student's requests,
 *                             instead of leaving the row alone.
 *
 * @return array Analyzed rows.
 */
function RequestExpressRowsAnalyze( $rows, $clear_blanks )
{
	$courses = RequestExpressCoursesByShortName();

	$student_ids = [];

	foreach ( $rows as $row )
	{
		$student_ids[] = (int) $row['STUDENT'];
	}

	$students = RequestExpressStudentsGet( $student_ids );

	$existing = RequestExpressRequestsExisting( array_keys( $students ) );

	$seen = [];

	foreach ( $rows as $i => $row )
	{
		$rows[ $i ] += [
			'STUDENT_ID' => '',
			'STUDENT_NAME' => '',
			'COURSES' => [],
			'REMOVED' => [],
			'ADDED' => [],
			'KEPT' => [],
			'STATUS' => 'error',
			'STATUS_TEXT' => '',
		];

		$student_id = trim( (string) $row['STUDENT'] );

		if ( $student_id === '' )
		{
			$rows[ $i ]['STATUS_TEXT'] = sprintf( _( 'No %s ID.' ), Config( 'NAME' ) );

			continue;
		}

		if ( ! ctype_digit( $student_id )
			|| (int) $student_id <= 0 )
		{
			$rows[ $i ]['STATUS_TEXT'] = _( 'Please enter valid Numeric data.' );

			continue;
		}

		$student_id = (string) (int) $student_id;

		$rows[ $i ]['STUDENT_ID'] = $student_id;

		if ( isset( $seen[ $student_id ] ) )
		{
			$rows[ $i ]['STATUS'] = 'duplicate';

			$rows[ $i ]['STATUS_TEXT'] = sprintf(
				_( 'Line %d holds the same %s ID.' ),
				$seen[ $student_id ],
				Config( 'NAME' )
			);

			continue;
		}

		$seen[ $student_id ] = $row['LINE'];

		if ( ! isset( $students[ $student_id ] ) )
		{
			$rows[ $i ]['STATUS'] = 'not_found';

			$rows[ $i ]['STATUS_TEXT'] = _( 'No student with that ID in this school.' );

			continue;
		}

		$rows[ $i ]['STUDENT_NAME'] = $students[ $student_id ]['FULL_NAME'];

		$short_names = RequestExpressRequestsSplit( $row['REQUESTS'] );

		if ( ! $short_names
			&& ! $clear_blanks )
		{
			$rows[ $i ]['STATUS'] = 'no_change';

			$rows[ $i ]['STATUS_TEXT'] = _( 'No request in this row: the student was left alone.' );

			continue;
		}

		if ( count( $short_names ) > REQUEST_EXPRESS_MAX_REQUESTS )
		{
			$rows[ $i ]['STATUS_TEXT'] = sprintf(
				_( 'More than %d courses requested.' ),
				REQUEST_EXPRESS_MAX_REQUESTS
			);

			continue;
		}

		// Resolve every requested course.
		$unknown = [];

		$ambiguous = [];

		$requested = [];

		foreach ( $short_names as $short_name )
		{
			$key = RequestExpressNormalize( $short_name );

			if ( ! isset( $courses[ $key ] ) )
			{
				$unknown[] = $short_name;

				continue;
			}

			if ( count( $courses[ $key ] ) > 1 )
			{
				$ambiguous[] = $short_name;

				continue;
			}

			$course = $courses[ $key ][0];

			$requested[ $course['COURSE_ID'] ] = [
				'SHORT_NAME' => $course['SHORT_NAME'],
				'TITLE' => $course['TITLE'],
				'SUBJECT_ID' => $course['SUBJECT_ID'],
			];
		}

		if ( $unknown
			|| $ambiguous )
		{
			$texts = [];

			if ( $unknown )
			{
				$texts[] = sprintf(
					_( 'No course with the number: %s.' ),
					RequestExpressHTML( implode( ', ', $unknown ) )
				);
			}

			if ( $ambiguous )
			{
				$texts[] = sprintf(
					_( 'Several courses have the number: %s.' ),
					RequestExpressHTML( implode( ', ', $ambiguous ) )
				);
			}

			$rows[ $i ]['STATUS_TEXT'] = implode( ' ', $texts );

			continue;
		}

		$rows[ $i ]['COURSES'] = $requested;

		// What changes for this student.
		$current = (array) issetVal( $existing[ $student_id ], [] );

		foreach ( $current as $course_id => $label )
		{
			if ( isset( $requested[ $course_id ] ) )
			{
				$rows[ $i ]['KEPT'][] = $label;
			}
			else
			{
				$rows[ $i ]['REMOVED'][] = $label;
			}
		}

		foreach ( $requested as $course_id => $course )
		{
			if ( ! isset( $current[ $course_id ] ) )
			{
				$rows[ $i ]['ADDED'][] = $course['SHORT_NAME'] !== '' ?
					$course['SHORT_NAME'] :
					$course['TITLE'];
			}
		}

		if ( ! $rows[ $i ]['REMOVED']
			&& ! $rows[ $i ]['ADDED'] )
		{
			$rows[ $i ]['STATUS'] = 'no_change';

			$rows[ $i ]['STATUS_TEXT'] = _( 'The student already has exactly these requests.' );

			continue;
		}

		$rows[ $i ]['STATUS'] = 'update';

		$rows[ $i ]['STATUS_TEXT'] = '';
	}

	return $rows;
}

/**
 * Replace the Course Requests of one student
 *
 * Every request the student has in the current school year & school is deleted,
 * then the requested courses are added.
 *
 * @uses Request_Express/Import.php|update_requests action hook
 *
 * @param  array $row Analyzed row, of `update` status.
 *
 * @return bool True if the requests were replaced.
 */
function RequestExpressRowApply( $row )
{
	$student_id = (int) issetVal( $row['STUDENT_ID'], 0 );

	if ( ! $student_id )
	{
		return false;
	}

	// Re-importing replaces the requests: clear them all first.
	DBQuery( "DELETE FROM schedule_requests
		WHERE STUDENT_ID='" . $student_id . "'
		AND SYEAR='" . UserSyear() . "'
		AND SCHOOL_ID='" . UserSchool() . "'" );

	foreach ( (array) issetVal( $row['COURSES'], [] ) as $course_id => $course )
	{
		DBInsert( 'schedule_requests', [
			'SYEAR' => UserSyear(),
			'SCHOOL_ID' => UserSchool(),
			'STUDENT_ID' => $student_id,
			'SUBJECT_ID' => (int) $course['SUBJECT_ID'],
			'COURSE_ID' => (int) $course_id,
		] );
	}

	$_REQUEST['student_id'] = $student_id;

	// Hook.
	do_action( 'Request_Express/Import.php|update_requests' );

	return true;
}

/**
 * Get / set the wizard data saved in the session
 *
 * The data is tied to the school year & the school it was read for: switching
 * school or year in the middle of the wizard starts it over.
 *
 * @param  array|bool|null $data Data to save, false to forget it,
 *                               or null to read the saved data (optional).
 *
 * @return array Session data.
 */
function RequestExpressSession( $data = null )
{
	$empty = [ 'ROWS' => [], 'FILE_NAME' => '', 'CLEAR_BLANKS' => false ];

	if ( $data === false )
	{
		unset( $_SESSION['Request_Express'] );

		return $empty;
	}

	if ( is_array( $data ) )
	{
		$_SESSION['Request_Express'] = $data + [
			'SYEAR' => UserSyear(),
			'SCHOOL_ID' => UserSchool(),
		];
	}

	$session = issetVal( $_SESSION['Request_Express'], [] );

	if ( ! $session
		|| issetVal( $session['SYEAR'], '' ) != UserSyear()
		|| issetVal( $session['SCHOOL_ID'], '' ) != UserSchool() )
	{
		return $empty;
	}

	foreach ( $empty as $key => $default )
	{
		$empty[ $key ] = issetVal( $session[ $key ], $default );
	}

	return $empty;
}

/**
 * Index a list from 1, the way DBGet() does
 *
 * ListOutput() reads $result[1] to $result[N]: a list built with $list[] = ...
 * starts at 0 and would lose its first row.
 *
 * @param  array $list List indexed from 0.
 *
 * @return array Same list, indexed from 1.
 */
function RequestExpressListIndex( $list )
{
	$list = array_values( (array) $list );

	if ( ! $list )
	{
		return [];
	}

	return array_combine( range( 1, count( $list ) ), $list );
}

/**
 * Escape a value for HTML output
 *
 * CSV values do not go through the Warehouse.php $_REQUEST sanitization.
 *
 * @param  string $value Value.
 *
 * @return string Escaped value, or '-' if empty.
 */
function RequestExpressHTML( $value )
{
	$value = (string) $value;

	if ( $value === '' )
	{
		return '-';
	}

	return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
}

/**
 * Did PHP stop reading the posted form part way through?
 *
 * PHP reads at most `max_input_vars` fields from a POST -- 1000 by default --
 * and silently drops the rest. The review screen posts only the rows to leave
 * out, so this is normally nowhere near the limit; it would take a thousand
 * ticked boxes to reach it. If it ever is reached, some of those ticks were
 * lost and rows the user meant to skip would be applied, so the import stops.
 *
 * @return bool True if the form looks cut short.
 */
function RequestExpressInputTruncated()
{
	$max = (int) ini_get( 'max_input_vars' );

	if ( ! $max )
	{
		return false;
	}

	return count( $_POST, COUNT_RECURSIVE ) >= $max;
}
