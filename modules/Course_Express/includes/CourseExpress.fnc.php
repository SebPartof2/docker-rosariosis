<?php
/**
 * Course Express functions
 *
 * CSV parsing, analysis (which Subjects / Courses already exist)
 * and creation of Course Subjects & Courses.
 *
 * @package RosarioSIS
 * @subpackage modules
 */

/**
 * Maximum number of Courses accepted in a single CSV file
 */
if ( ! defined( 'COURSE_EXPRESS_MAX_ROWS' ) )
{
	define( 'COURSE_EXPRESS_MAX_ROWS', 1000 );
}

/**
 * CSV column header aliases
 *
 * Maps an internal field to the various column names accepted in the CSV header row.
 * Header names are normalized by CourseExpressHeaderNormalize() before being compared.
 *
 * @return array Associative `[ FIELD => [ alias, ... ] ]`
 */
function CourseExpressColumnAliases()
{
	return [
		'SUBJECT' => [
			'subject',
			'subjects',
			'subject title',
			'subject name',
			'course subject',
			'department',
		],
		'TITLE' => [
			'course',
			'course title',
			'course name',
			'title',
			'name',
		],
		'SHORT_NAME' => [
			'course number',
			'course no',
			'course code',
			'short name',
			'number',
			'code',
		],
		'CREDIT_HOURS' => [
			'credit hours',
			'credit hour',
			'credits',
			'credit',
			'hours',
		],
	];
}

/**
 * Normalize a CSV header cell so it can be matched against the aliases
 *
 * Lowercase, underscores & dashes to spaces, punctuation removed, spaces collapsed.
 *
 * @param  string $header Header cell.
 *
 * @return string Normalized header.
 */
function CourseExpressHeaderNormalize( $header )
{
	$header = mb_strtolower( trim( (string) $header ) );

	$header = str_replace( [ '_', '-', '.', '#' ], ' ', $header );

	// Remove anything which is not a letter, a number or a space.
	$header = preg_replace( '/[^\p{L}\p{N} ]/u', '', $header );

	// Collapse spaces.
	$header = preg_replace( '/\s+/u', ' ', $header );

	return trim( (string) $header );
}

/**
 * Normalize a Subject or Course title for comparison purposes
 *
 * Used to detect Subjects / Courses which already exist, and duplicates inside the CSV.
 *
 * @param  string $title Title.
 *
 * @return string Lowercase, space collapsed title.
 */
function CourseExpressTitleNormalize( $title )
{
	$title = mb_strtolower( trim( (string) $title ) );

	return trim( (string) preg_replace( '/\s+/u', ' ', $title ) );
}

/**
 * Detect the CSV delimiter used in a line
 *
 * @param  string $line First non empty line of the CSV.
 *
 * @return string Delimiter: `,`, `;`, tab or `|`. Defaults to `,`.
 */
function CourseExpressDelimiterDetect( $line )
{
	$delimiters = [ ',', ';', "\t", '|' ];

	$best = ',';

	$best_count = 0;

	foreach ( $delimiters as $delimiter )
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
 * Parse the CSV text into Course rows
 *
 * Detects the delimiter, the (optional) header row and maps the columns.
 * Without a header row, columns are read in this order:
 * Subject, Course Title, Course Number, Credit Hours.
 *
 * Each returned row is an array:
 * `[ 'LINE' => int, 'SUBJECT' => string, 'TITLE' => string, 'SHORT_NAME' => string,
 *    'CREDIT_HOURS' => string, 'ERRORS' => array ]`
 *
 * @example $rows = CourseExpressCSVParse( $csv_text, $error );
 *
 * @param  string $csv_text CSV file contents.
 * @param  array  $errors   The errors array, passed by reference.
 *
 * @return array Rows, or empty array if the CSV could not be parsed.
 */
function CourseExpressCSVParse( $csv_text, &$errors )
{
	$rows = CourseExpressGenericCSVParse(
		$csv_text,
		CourseExpressColumnAliases(),
		[ _( 'Subject' ), _( 'Course' ), _( 'Course Number' ), _( 'Credit Hours' ) ],
		COURSE_EXPRESS_MAX_ROWS,
		$errors
	);

	if ( ! $rows )
	{
		if ( ! $errors )
		{
			// The file was read, but it holds no course.
			$errors[] = _( 'No courses were found in the file.' );
		}

		return [];
	}

	foreach ( $rows as $i => $row )
	{
		$rows[ $i ] = CourseExpressRowValidate( $row );
	}

	return $rows;
}

/**
 * Parse a CSV text into rows of named fields
 *
 * Shared by the Courses and the Sections imports. Detects the delimiter, the
 * UTF-8 byte order mark and a non UTF-8 encoding, then reads the (optional)
 * header row to map the columns. Without a recognized header row, the columns
 * are read in the order of the $aliases array.
 *
 * @param  string $csv_text        CSV file contents.
 * @param  array  $aliases      `[ FIELD => [ header alias, ... ] ]`, in column order.
 * @param  array  $order_labels Translated field names, for the "no header row" message.
 * @param  int    $max_rows     Maximum number of rows read.
 * @param  array  $messages     Messages, passed by reference.
 *
 * @return array Rows: `[ 'LINE' => int, FIELD => string, ... ]`.
 */
function CourseExpressGenericCSVParse( $csv_text, $aliases, $order_labels, $max_rows, &$messages )
{
	$csv_text = (string) $csv_text;

	// Remove UTF-8 Byte Order Mark. Note: the BOM is 3 bytes but 1 character,
	// so it has to be cut with the byte based substr(), not mb_substr().
	// Escape sequences, not literal bytes: an editor re-encoding the file must not break them.
	if ( substr( $csv_text, 0, 3 ) === "\xEF\xBB\xBF" )
	{
		$csv_text = substr( $csv_text, 3 );
	}

	// Normalize line endings so the delimiter detection reads a single line.
	$csv_text = str_replace( [ "\r\n", "\r" ], "\n", $csv_text );

	if ( trim( $csv_text ) === '' )
	{
		$messages[] = _( 'The file is empty.' );

		return [];
	}

	if ( ! mb_check_encoding( $csv_text, 'UTF-8' ) )
	{
		// Excel commonly exports CSV files as Windows-1252. Not ISO-8859-1:
		// it would turn curly quotes, dashes & the euro sign (0x80-0x9F) into control characters.
		$csv_text = mb_convert_encoding( $csv_text, 'UTF-8', 'Windows-1252' );
	}

	// First non empty line, used to detect the delimiter.
	$first_line = '';

	foreach ( explode( "
", $csv_text ) as $line )
	{
		if ( trim( $line ) !== '' )
		{
			$first_line = $line;

			break;
		}
	}

	$delimiter = CourseExpressDelimiterDetect( $first_line );

	// Use a stream so fgetcsv() handles quoted values containing line breaks.
	$stream = fopen( 'php://temp', 'r+' );

	if ( ! $stream )
	{
		$messages[] = _( 'The file could not be read.' );

		return [];
	}

	fwrite( $stream, $csv_text );

	rewind( $stream );

	$fields = array_keys( $aliases );

	// Column mapping: field => column index.
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

		$cells = array_map( 'CourseExpressCellClean', $cells );

		if ( implode( '', $cells ) === '' )
		{
			// All cells empty.
			continue;
		}

		if ( ! $map )
		{
			$map = CourseExpressHeaderMapFields( $cells, $aliases );

			if ( $map )
			{
				// Header row, do not import it.
				continue;
			}

			// No cell named a field: no header row, read the columns in order.
			$map = array_flip( $fields );

			$messages[] = _( 'No header row was found, the columns were read in this order:' ) .
				' ' . implode( ', ', $order_labels ) . '.';
		}

		if ( count( $rows ) >= $max_rows )
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
			$max_rows
		);
	}

	return $rows;
}

/**
 * Clean a CSV cell
 *
 * Trim, remove HTML tags & control characters.
 * $_REQUEST values get the same treatment in Warehouse.php.
 *
 * @param  string $cell CSV cell.
 *
 * @return string Cleaned cell.
 */
function CourseExpressCellClean( $cell )
{
	$cell = strip_tags( (string) $cell );

	// Remove control characters (including line breaks inside quoted values).
	$cell = preg_replace( '/[\x00-\x1F\x7F]/u', ' ', $cell );

	return trim( (string) preg_replace( '/\s+/u', ' ', $cell ) );
}

/**
 * Map the CSV header cells to the Course fields
 *
 * @see CourseExpressColumnAliases()
 *
 * @param  array $cells Cells of the first CSV row.
 *
 * @return array Empty if the row is not a header row, else `[ FIELD => column index ]`.
 */
function CourseExpressHeaderMap( $cells )
{
	return CourseExpressHeaderMapFields( $cells, CourseExpressColumnAliases() );
}

/**
 * Map the CSV header cells to named fields
 *
 * A row naming at least one field is a header row. The fields it does not name
 * take, in order, the columns it does not use: a header naming only some of the
 * columns still works, and a file with no header row at all is left alone.
 *
 * The first column matching a field wins; a field is never mapped twice.
 *
 * @param  array $cells   Cells of the first CSV row.
 * @param  array $aliases `[ FIELD => [ header alias, ... ] ]`, in column order.
 *
 * @return array Empty if the row is not a header row, else `[ FIELD => column index ]`.
 */
function CourseExpressHeaderMapFields( $cells, $aliases )
{
	$map = [];

	foreach ( (array) $cells as $index => $cell )
	{
		$header = CourseExpressHeaderNormalize( $cell );

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

	// Columns the header row did not name.
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
 * Validate a parsed row
 *
 * Sets the `ERRORS` key & normalizes the Credit Hours.
 *
 * @param  array $row Parsed row.
 *
 * @return array Row with the `ERRORS` key set.
 */
function CourseExpressRowValidate( $row )
{
	$row['ERRORS'] = [];

	if ( $row['SUBJECT'] === '' )
	{
		$row['ERRORS'][] = _( 'Subject' ) . ': ' . _( 'Please fill in the required fields' );
	}

	if ( $row['TITLE'] === '' )
	{
		$row['ERRORS'][] = _( 'Course' ) . ': ' . _( 'Please fill in the required fields' );
	}

	// courses.SHORT_NAME is a varchar(25) column.
	if ( mb_strlen( $row['SHORT_NAME'] ) > 25 )
	{
		$row['ERRORS'][] = _( 'Course Number' ) . ': ' .
			sprintf( _( 'Maximum %d characters.' ), 25 );
	}

	if ( $row['CREDIT_HOURS'] !== '' )
	{
		// Accept the comma as decimal separator, and thousands separators.
		$credit_hours = str_replace( [ ' ', ',' ], [ '', '.' ], $row['CREDIT_HOURS'] );

		if ( ! is_numeric( $credit_hours ) )
		{
			$row['ERRORS'][] = _( 'Credit Hours' ) . ': ' . _( 'Please enter a valid number.' );
		}
		elseif ( $credit_hours < 0 )
		{
			$row['ERRORS'][] = _( 'Credit Hours' ) . ': ' . _( 'Please enter a valid number.' );
		}
		// courses.CREDIT_HOURS is a numeric(6,2) column.
		elseif ( $credit_hours >= 10000 )
		{
			$row['ERRORS'][] = _( 'Credit Hours' ) . ': ' .
				sprintf( _( 'Maximum value is %s.' ), '9999.99' );
		}
		else
		{
			$row['CREDIT_HOURS'] = (string) round( (float) $credit_hours, 2 );
		}
	}

	return $row;
}

/**
 * Get the existing Course Subjects of the current school year & school
 *
 * @return array Associative `[ normalized title => [ 'SUBJECT_ID' => id, 'TITLE' => title ] ]`.
 */
function CourseExpressSubjectsExisting()
{
	$subjects_RET = DBGet( "SELECT SUBJECT_ID,TITLE
		FROM course_subjects
		WHERE SYEAR='" . UserSyear() . "'
		AND SCHOOL_ID='" . UserSchool() . "'" );

	$subjects = [];

	foreach ( (array) $subjects_RET as $subject )
	{
		// Titles can be multilingual, match both the raw & the translated value.
		$titles = [ $subject['TITLE'], ParseMLField( $subject['TITLE'] ) ];

		foreach ( $titles as $title )
		{
			$key = CourseExpressTitleNormalize( $title );

			if ( $key !== ''
				&& ! isset( $subjects[ $key ] ) )
			{
				$subjects[ $key ] = [
					'SUBJECT_ID' => $subject['SUBJECT_ID'],
					'TITLE' => ParseMLField( $subject['TITLE'] ),
				];
			}
		}
	}

	return $subjects;
}

/**
 * Get the existing Courses of the current school year & school, indexed by Subject
 *
 * @return array Associative `[ subject_id => [ 'TITLES' => [ normalized title => 1 ],
 *               'SHORT_NAMES' => [ normalized short name => 1 ] ] ]`.
 */
function CourseExpressCoursesExisting()
{
	$courses_RET = DBGet( "SELECT SUBJECT_ID,TITLE,SHORT_NAME
		FROM courses
		WHERE SYEAR='" . UserSyear() . "'
		AND SCHOOL_ID='" . UserSchool() . "'" );

	$courses = [];

	foreach ( (array) $courses_RET as $course )
	{
		$subject_id = $course['SUBJECT_ID'];

		if ( ! isset( $courses[ $subject_id ] ) )
		{
			$courses[ $subject_id ] = [ 'TITLES' => [], 'SHORT_NAMES' => [] ];
		}

		foreach ( [ $course['TITLE'], ParseMLField( $course['TITLE'] ) ] as $title )
		{
			$key = CourseExpressTitleNormalize( $title );

			if ( $key !== '' )
			{
				$courses[ $subject_id ]['TITLES'][ $key ] = 1;
			}
		}

		$short_name = CourseExpressTitleNormalize( $course['SHORT_NAME'] );

		if ( $short_name !== '' )
		{
			$courses[ $subject_id ]['SHORT_NAMES'][ $short_name ] = 1;
		}
	}

	return $courses;
}

/**
 * List the distinct Subjects of the parsed rows & tell which ones already exist
 *
 * Rows in error are ignored.
 *
 * @param  array $rows Parsed rows.
 *
 * @return array Associative `[ normalized subject => [ 'KEY', 'NAME', 'SUBJECT_ID', 'COURSES' ] ]`.
 */
function CourseExpressSubjectsAnalyze( $rows )
{
	$existing = CourseExpressSubjectsExisting();

	$subjects = [];

	foreach ( (array) $rows as $row )
	{
		if ( $row['ERRORS']
			|| $row['SUBJECT'] === '' )
		{
			continue;
		}

		$key = CourseExpressTitleNormalize( $row['SUBJECT'] );

		if ( ! isset( $subjects[ $key ] ) )
		{
			$subjects[ $key ] = [
				// md5() so the key is safe to use as an HTML input name.
				'KEY' => md5( $key ),
				'NAME' => $row['SUBJECT'],
				'SUBJECT_ID' => isset( $existing[ $key ] ) ? $existing[ $key ]['SUBJECT_ID'] : '',
				'COURSES' => 0,
			];
		}

		$subjects[ $key ]['COURSES']++;
	}

	return $subjects;
}

/**
 * Determine, for each row, what will happen on creation
 *
 * Sets the `STATUS` & `STATUS_TEXT` keys:
 * - `error`: the row could not be read.
 * - `duplicate`: the same course is listed twice in the file.
 * - `exists`: a course with the same title already exists in the subject.
 * - `exists_number`: a course with the same course number already exists in the subject.
 * - `no_subject`: the subject will not be created, so the course cannot be created.
 * - `new`: the course will be created.
 *
 * @param  array $rows     Parsed rows.
 * @param  array $subjects Subjects, from CourseExpressSubjectsAnalyze().
 * @param  array $create   Normalized subjects the user chose to create `[ normalized subject => true ]`.
 *
 * @return array Rows with the `STATUS`, `STATUS_TEXT` & `SUBJECT_KEY` keys set.
 */
function CourseExpressRowsAnalyze( $rows, $subjects, $create )
{
	$existing_courses = CourseExpressCoursesExisting();

	// Titles & course numbers already seen in the file, per subject.
	$seen = [];

	foreach ( (array) $rows as $i => $row )
	{
		$subject_key = CourseExpressTitleNormalize( $row['SUBJECT'] );

		$rows[ $i ]['SUBJECT_KEY'] = $subject_key;

		if ( $row['ERRORS'] )
		{
			$rows[ $i ]['STATUS'] = 'error';

			$rows[ $i ]['STATUS_TEXT'] = implode( ' ', $row['ERRORS'] );

			continue;
		}

		$title_key = CourseExpressTitleNormalize( $row['TITLE'] );

		$short_name_key = CourseExpressTitleNormalize( $row['SHORT_NAME'] );

		if ( ! isset( $seen[ $subject_key ] ) )
		{
			$seen[ $subject_key ] = [ 'TITLES' => [], 'SHORT_NAMES' => [] ];
		}

		if ( isset( $seen[ $subject_key ]['TITLES'][ $title_key ] )
			|| ( $short_name_key !== ''
				&& isset( $seen[ $subject_key ]['SHORT_NAMES'][ $short_name_key ] ) ) )
		{
			$rows[ $i ]['STATUS'] = 'duplicate';

			$rows[ $i ]['STATUS_TEXT'] = _( 'Listed twice in the file.' );

			continue;
		}

		$subject_id = isset( $subjects[ $subject_key ] ) ? $subjects[ $subject_key ]['SUBJECT_ID'] : '';

		if ( ! $subject_id )
		{
			if ( empty( $create[ $subject_key ] ) )
			{
				$rows[ $i ]['STATUS'] = 'no_subject';

				$rows[ $i ]['STATUS_TEXT'] = _( 'The subject will not be created.' );

				continue;
			}

			// New subject: nothing to compare the course with.
			$seen[ $subject_key ]['TITLES'][ $title_key ] = 1;

			if ( $short_name_key !== '' )
			{
				$seen[ $subject_key ]['SHORT_NAMES'][ $short_name_key ] = 1;
			}

			$rows[ $i ]['STATUS'] = 'new';

			$rows[ $i ]['STATUS_TEXT'] = '';

			continue;
		}

		if ( isset( $existing_courses[ $subject_id ]['TITLES'][ $title_key ] ) )
		{
			$rows[ $i ]['STATUS'] = 'exists';

			$rows[ $i ]['STATUS_TEXT'] = _( 'The course already exists in this subject.' );

			continue;
		}

		if ( $short_name_key !== ''
			&& isset( $existing_courses[ $subject_id ]['SHORT_NAMES'][ $short_name_key ] ) )
		{
			$rows[ $i ]['STATUS'] = 'exists_number';

			$rows[ $i ]['STATUS_TEXT'] = _( 'The course number already exists in this subject.' );

			continue;
		}

		$seen[ $subject_key ]['TITLES'][ $title_key ] = 1;

		if ( $short_name_key !== '' )
		{
			$seen[ $subject_key ]['SHORT_NAMES'][ $short_name_key ] = 1;
		}

		$rows[ $i ]['STATUS'] = 'new';

		$rows[ $i ]['STATUS_TEXT'] = '';
	}

	return $rows;
}

/**
 * Create a Course Subject
 *
 * @uses Course_Express/Import.php|create_course_subject action hook
 *
 * @param  string $title Subject title.
 *
 * @return string|false New Subject ID, or false on error.
 */
function CourseExpressSubjectCreate( $title )
{
	$title = trim( (string) $title );

	if ( $title === '' )
	{
		return false;
	}

	$sort_order_RET = DBGet( "SELECT MAX(SORT_ORDER) AS SORT_ORDER
		FROM course_subjects
		WHERE SYEAR='" . UserSyear() . "'
		AND SCHOOL_ID='" . UserSchool() . "'" );

	$sort_order = (int) issetVal( $sort_order_RET[1]['SORT_ORDER'], 0 ) + 1;

	$subject_id = DBInsert(
		'course_subjects',
		[
			'SYEAR' => UserSyear(),
			'SCHOOL_ID' => UserSchool(),
			'TITLE' => DBEscapeString( $title ),
			'SORT_ORDER' => $sort_order,
		],
		'id'
	);

	if ( $subject_id )
	{
		$_REQUEST['subject_id'] = $subject_id;

		// Hook.
		do_action( 'Course_Express/Import.php|create_course_subject' );
	}

	return $subject_id;
}

/**
 * Create a Course
 *
 * @uses Course_Express/Import.php|create_course action hook
 *
 * @param  string $subject_id Course Subject ID.
 * @param  array  $row        Parsed row.
 *
 * @return string|false New Course ID, or false on error.
 */
function CourseExpressCourseCreate( $subject_id, $row )
{
	if ( ! (int) $subject_id
		|| trim( (string) $row['TITLE'] ) === '' )
	{
		return false;
	}

	$columns = [
		'SYEAR' => UserSyear(),
		'SCHOOL_ID' => UserSchool(),
		'SUBJECT_ID' => (int) $subject_id,
		'TITLE' => DBEscapeString( trim( $row['TITLE'] ) ),
	];

	if ( $row['SHORT_NAME'] !== '' )
	{
		$columns['SHORT_NAME'] = DBEscapeString( $row['SHORT_NAME'] );
	}

	if ( $row['CREDIT_HOURS'] !== '' )
	{
		$columns['CREDIT_HOURS'] = $row['CREDIT_HOURS'];
	}

	$course_id = DBInsert( 'courses', $columns, 'id' );

	if ( $course_id )
	{
		$_REQUEST['subject_id'] = $subject_id;

		$_REQUEST['course_id'] = $course_id;

		// Hook.
		do_action( 'Course_Express/Import.php|create_course' );
	}

	return $course_id;
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
 * @return array Session data: `[ 'ROWS' => [], 'FILE_NAME' => '', 'CREATE_SUBJECTS' => [] ]`.
 */
function CourseExpressSession( $data = null )
{
	$empty = [ 'ROWS' => [], 'FILE_NAME' => '', 'CREATE_SUBJECTS' => [] ];

	if ( $data === false )
	{
		unset( $_SESSION['Course_Express'] );

		return $empty;
	}

	if ( is_array( $data ) )
	{
		$_SESSION['Course_Express'] = $data + [
			'SYEAR' => UserSyear(),
			'SCHOOL_ID' => UserSchool(),
		];
	}

	$session = issetVal( $_SESSION['Course_Express'], [] );

	if ( ! $session
		|| issetVal( $session['SYEAR'], '' ) != UserSyear()
		|| issetVal( $session['SCHOOL_ID'], '' ) != UserSchool() )
	{
		// Not our school year / school anymore.
		return $empty;
	}

	return [
		'ROWS' => issetVal( $session['ROWS'], [] ),
		'FILE_NAME' => issetVal( $session['FILE_NAME'], '' ),
		'CREATE_SUBJECTS' => issetVal( $session['CREATE_SUBJECTS'], [] ),
	];
}

/**
 * The "Download everything" link
 *
 * Two things make it a download rather than a page:
 *
 * - `target="_blank"`, so ajaxLink() hands the click back to the browser
 *   instead of fetching it and dropping the CSV into the page as text;
 * - `_ROSARIO_PDF=true`, so Warehouse() skips the page HTML altogether.
 *
 * The same pair the export button built into every RosarioSIS list uses.
 *
 * @since 1.1
 *
 * @param  string $params URL parameters of the report, without the leading &.
 *
 * @return string Link HTML.
 */
function CourseExpressDownloadLink( $params )
{
	$url = 'Modules.php?modname=' . $_REQUEST['modname'] .
		'&modfunc=download&_ROSARIO_PDF=true' .
		( $params ? '&' . $params : '' );

	$label = _( 'Download everything' );

	return '<a href="' . URLEscape( $url ) . '" class="list-save" target="_blank">' .
		'<img src="' . URLEscape( 'assets/themes/' . Preferences( 'THEME' ) . '/btn/download.png' ) .
		'" class="button" alt="' . AttrEscape( $label ) . '">&nbsp;<b>' .
		CourseExpressHTML( $label ) . '</b></a>';
}

/**
 * Output a report: the whole thing as a CSV, or on screen
 *
 * ListOutput() cuts a list down to 1000 rows before it saves it, so its own
 * export cannot reach past row 1000; switching its pagination on only narrows
 * that to the page being looked at. So the download is written here instead,
 * from the full result, and the list on screen is paginated so every row can
 * still be reached by eye.
 *
 * Downloading ends the request.
 *
 * @since 1.1
 *
 * @param array  $columns  `[ key => heading ]`.
 * @param array  $rows     The full result, indexed from 0.
 * @param string $slug     File name, without its extension.
 * @param string $singular List singular name.
 * @param string $plural   List plural name.
 *
 * @return void
 */
function CourseExpressReportOutput( $columns, $rows, $slug, $singular = 'Row', $plural = 'Rows' )
{
	if ( issetVal( $_REQUEST['modfunc'], '' ) === 'download' )
	{
		CourseExpressCSVDownload( $columns, $rows, $slug );
	}

	if ( count( (array) $rows ) > 1000 )
	{
		echo ErrorMessage(
			[ sprintf(
				_( '%d rows. The list shows 1000 per page — use Download to get them all in one file.' ),
				count( (array) $rows )
			) ],
			'note'
		);
	}

	ListOutput(
		CourseExpressListIndex( $rows ),
		$columns,
		$singular,
		$plural,
		[],
		[],
		[
			'add' => false,
			'valign-middle' => true,
			// Every row reachable, a page at a time.
			'pagination' => true,
		]
	);
}

/**
 * Send the whole result as a CSV, and end the request
 *
 * @since 1.1
 *
 * @param array  $columns `[ key => heading ]`.
 * @param array  $rows    The full result.
 * @param string $slug    File name, without its extension.
 *
 * @return void Never returns: the request ends here.
 */
function CourseExpressCSVDownload( $columns, $rows, $slug )
{
	$delimiter = Preferences( 'DELIMITER' );

	if ( ! $delimiter )
	{
		$delimiter = ',';
	}

	$lines = [ CourseExpressCSVLine( array_values( (array) $columns ), $delimiter ) ];

	foreach ( (array) $rows as $row )
	{
		$cells = [];

		foreach ( (array) $columns as $key => $heading )
		{
			$cells[] = CourseExpressCSVCell( issetVal( $row[ $key ], '' ) );
		}

		$lines[] = CourseExpressCSVLine( $cells, $delimiter );
	}

	$output = implode( "\n", $lines ) . "\n";

	$file_name = $slug . '-' . date( 'Y-m-d' ) . '.csv';

	// The page has already been written into the output buffer: drop it, or it
	// would be sent as part of the file.
	while ( ob_get_level() )
	{
		ob_end_clean();
	}

	header( 'Content-Type: text/csv; charset=UTF-8' );

	header( 'Content-Disposition: attachment; filename="' . $file_name . '"' );

	header( 'Content-Length: ' . strlen( $output ) );

	header( 'Cache-Control: no-store' );

	// A byte order mark, so Excel opens the accents right.
	echo "\xEF\xBB\xBF" . $output;

	exit();
}

/**
 * A cell, as plain text
 *
 * The report cells hold a little markup for the screen; a file wants none.
 *
 * @since 1.1
 *
 * @param  string $value Cell value.
 *
 * @return string Plain text.
 */
function CourseExpressCSVCell( $value )
{
	$value = str_ireplace( [ '<br>', '<br/>', '<br />' ], "\n", (string) $value );

	$value = strip_tags( $value );

	$value = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );

	return trim( $value );
}

/**
 * One CSV line, quoted where it has to be
 *
 * @since 1.1
 *
 * @param  array  $cells     The cells.
 * @param  string $delimiter Field delimiter.
 *
 * @return string The line.
 */
function CourseExpressCSVLine( $cells, $delimiter )
{
	$quoted = [];

	foreach ( (array) $cells as $cell )
	{
		$cell = (string) $cell;

		if ( $cell !== ''
			&& in_array( mb_substr( $cell, 0, 1 ), [ '=', '-', '+', '@', "\t", "\r" ], true ) )
		{
			// Security: a leading =, -, + or @ makes a formula in a spreadsheet.
			$cell = "'" . $cell;
		}

		if ( mb_strpos( $cell, $delimiter ) !== false
			|| mb_strpos( $cell, '"' ) !== false
			|| mb_strpos( $cell, "\n" ) !== false )
		{
			$cell = '"' . str_replace( '"', '""', $cell ) . '"';
		}

		$quoted[] = $cell;
	}

	return implode( $delimiter, $quoted );
}

/**
 * Index a list from 1, the way DBGet() does
 *
 * ListOutput() reads $result[1] to $result[N]: a list built with $list[] = ...
 * starts at 0 and would lose its first row.
 *
 * @since 1.1
 *
 * @param  array $list List indexed from 0.
 *
 * @return array Same list, indexed from 1.
 */
function CourseExpressListIndex( $list )
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
function CourseExpressHTML( $value )
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
function CourseExpressInputTruncated()
{
	$max = (int) ini_get( 'max_input_vars' );

	if ( ! $max )
	{
		return false;
	}

	return count( $_POST, COUNT_RECURSIVE ) >= $max;
}
