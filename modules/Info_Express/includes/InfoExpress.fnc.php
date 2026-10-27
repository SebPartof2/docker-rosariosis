<?php
/**
 * Info Express functions
 *
 * CSV parsing, catalog of the editable Student fields, column mapping,
 * value conversion & validation, and the update of the Students.
 *
 * @package RosarioSIS
 * @subpackage modules
 */

/**
 * Maximum number of rows read in a single CSV file
 */
if ( ! defined( 'INFO_EXPRESS_MAX_ROWS' ) )
{
	define( 'INFO_EXPRESS_MAX_ROWS', 2000 );
}

/**
 * Maximum number of columns read in a single CSV file
 */
if ( ! defined( 'INFO_EXPRESS_MAX_COLUMNS' ) )
{
	define( 'INFO_EXPRESS_MAX_COLUMNS', 100 );
}

/**
 * Field ID used to ignore a CSV column
 */
if ( ! defined( 'INFO_EXPRESS_IGNORE' ) )
{
	define( 'INFO_EXPRESS_IGNORE', '' );
}

/**
 * Parse the CSV text
 *
 * Detects the delimiter & reads every column: the mapping is done later,
 * by the user.
 *
 * @param  string $csv_text CSV file contents.
 * @param  array  $messages Messages (errors or notices), passed by reference.
 *
 * @return array `[ 'HEADERS' => [ cell, ... ], 'ROWS' => [ [ 'LINE' => int, 'CELLS' => [] ] ] ]`,
 *               or an empty array if nothing could be read.
 */
function InfoExpressCSVParse( $csv_text, &$messages )
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

	$delimiter = InfoExpressDelimiterDetect( $first_line );

	// Use a stream so fgetcsv() handles quoted values containing line breaks.
	$stream = fopen( 'php://temp', 'r+' );

	if ( ! $stream )
	{
		$messages[] = _( 'The file could not be read.' );

		return [];
	}

	fwrite( $stream, $csv_text );

	rewind( $stream );

	$headers = $rows = [];

	$line_number = 0;

	$column_count = 0;

	$too_many_rows = $too_many_columns = false;

	while ( ( $cells = fgetcsv( $stream, 0, $delimiter, '"', '' ) ) !== false )
	{
		$line_number++;

		if ( $cells === [ null ] )
		{
			// Blank line.
			continue;
		}

		$cells = array_map( 'InfoExpressCellClean', $cells );

		if ( implode( '', $cells ) === '' )
		{
			// All cells empty.
			continue;
		}

		if ( ! $headers )
		{
			// The first non empty row sets the number of columns.
			if ( count( $cells ) > INFO_EXPRESS_MAX_COLUMNS )
			{
				$cells = array_slice( $cells, 0, INFO_EXPRESS_MAX_COLUMNS );

				$too_many_columns = true;
			}

			$headers = $cells;

			$column_count = count( $cells );
		}

		if ( count( $rows ) >= INFO_EXPRESS_MAX_ROWS )
		{
			$too_many_rows = true;

			break;
		}

		// Pad / truncate so every row has the same number of columns.
		$cells = array_slice( $cells, 0, $column_count ) +
			array_fill( 0, $column_count, '' );

		ksort( $cells );

		$rows[] = [ 'LINE' => $line_number, 'CELLS' => $cells ];
	}

	fclose( $stream );

	if ( $too_many_columns )
	{
		$messages[] = sprintf(
			_( 'Only the first %d columns of the file were read.' ),
			INFO_EXPRESS_MAX_COLUMNS
		);
	}

	if ( $too_many_rows )
	{
		$messages[] = sprintf(
			_( 'Only the first %d rows of the file were read.' ),
			INFO_EXPRESS_MAX_ROWS
		);
	}

	if ( ! $rows )
	{
		$messages[] = _( 'No rows were found in the file.' );

		return [];
	}

	return [
		'HEADERS' => $headers,
		'ROWS' => $rows,
		'FIRST_ROW_HEADER' => InfoExpressFirstRowIsHeader( $headers ),
	];
}

/**
 * Does the first row of the file hold the column names?
 *
 * A row naming at least one student field is a header row. Otherwise a row
 * holding a number holds data -- a column name is text -- and a row of plain
 * text is a header row whose names we simply could not match.
 *
 * The user can always correct the guess on the mapping screen.
 *
 * @param  array $cells Cells of the first row.
 *
 * @return bool True if the first row holds the column names.
 */
function InfoExpressFirstRowIsHeader( $cells )
{
	if ( InfoExpressMapGuess( $cells ) )
	{
		// At least one cell names a student field.
		return true;
	}

	foreach ( (array) $cells as $cell )
	{
		$cell = str_replace( [ ' ', ',' ], [ '', '.' ], (string) $cell );

		if ( $cell !== ''
			&& is_numeric( $cell ) )
		{
			// A number: this row holds data.
			return false;
		}
	}

	return true;
}

/**
 * Detect the CSV delimiter used in a line
 *
 * @param  string $line First non empty line of the CSV.
 *
 * @return string Delimiter: `,`, `;`, tab or `|`. Defaults to `,`.
 */
function InfoExpressDelimiterDetect( $line )
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
 * Trim & remove control characters. HTML tags are removed too, like
 * Warehouse.php does for the $_REQUEST values.
 *
 * @param  string $cell CSV cell.
 *
 * @return string Cleaned cell.
 */
function InfoExpressCellClean( $cell )
{
	$cell = strip_tags( (string) $cell );

	$cell = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $cell );

	// Keep the line breaks: they are valid inside a Long Text field.
	$cell = preg_replace( '/[ \t]+/u', ' ', $cell );

	return trim( (string) $cell );
}

/**
 * Normalize a header or a value for comparison purposes
 *
 * @param  string $text Text.
 *
 * @return string Lowercase, punctuation free, space collapsed text.
 */
function InfoExpressNormalize( $text )
{
	$text = trim( (string) $text );

	// Split camel case & letter/digit boundaries, so "StudentID" and "Student2"
	// normalize like "Student ID" and "Student 2".
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
 * Catalog of the Student fields which can be updated
 *
 * Contains the identifier, the Students table columns, the Enrollment columns
 * of the current school year & school, and every Student Field (custom field),
 * whatever its type -- the Files type excepted, which cannot be set from a CSV.
 *
 * Each field:
 * - `TITLE`: translated field name.
 * - `GROUP`: group the field is displayed under, in the mapping pull-down.
 * - `TYPE`: key|text|textarea|numeric|date|select|autos|exports|multiple|radio|id_select.
 * - `TABLE`: students|student_enrollment (the identifier has none).
 * - `OPTIONS`: `[ stored value => label ]` for the select-like types.
 * - `MAXLENGTH`: maximum number of characters, 0 if not limited.
 * - `REQUIRED`: the field cannot be emptied.
 *
 * @return array Associative `[ field id => field ]`, in display order.
 */
function InfoExpressFields()
{
	static $fields = null;

	if ( $fields !== null )
	{
		return $fields;
	}

	$student_group = _( 'Student' );

	$fields = [
		'STUDENT_ID' => [
			'TITLE' => sprintf( _( '%s ID' ), Config( 'NAME' ) ),
			'GROUP' => _( 'Identifier' ),
			'TYPE' => 'key',
			'TABLE' => '',
			'OPTIONS' => [],
			'MAXLENGTH' => 0,
			'REQUIRED' => true,
		],
		'FIRST_NAME' => [
			'TITLE' => _( 'First Name' ),
			'GROUP' => $student_group,
			'TYPE' => 'text',
			'TABLE' => 'students',
			'OPTIONS' => [],
			'MAXLENGTH' => 50,
			'REQUIRED' => true,
		],
		'MIDDLE_NAME' => [
			'TITLE' => _( 'Middle Name' ),
			'GROUP' => $student_group,
			'TYPE' => 'text',
			'TABLE' => 'students',
			'OPTIONS' => [],
			'MAXLENGTH' => 50,
			'REQUIRED' => false,
		],
		'LAST_NAME' => [
			'TITLE' => _( 'Last Name' ),
			'GROUP' => $student_group,
			'TYPE' => 'text',
			'TABLE' => 'students',
			'OPTIONS' => [],
			'MAXLENGTH' => 50,
			'REQUIRED' => true,
		],
		'NAME_SUFFIX' => [
			'TITLE' => _( 'Suffix' ),
			'GROUP' => $student_group,
			'TYPE' => 'select',
			'TABLE' => 'students',
			'OPTIONS' => [
				'Jr' => _( 'Jr' ),
				'Sr' => _( 'Sr' ),
				'II' => _( 'II' ),
				'III' => _( 'III' ),
				'IV' => _( 'IV' ),
				'V' => _( 'V' ),
			],
			'MAXLENGTH' => 3,
			'REQUIRED' => false,
		],
		'USERNAME' => [
			'TITLE' => _( 'Username' ),
			'GROUP' => $student_group,
			'TYPE' => 'text',
			'TABLE' => 'students',
			'OPTIONS' => [],
			'MAXLENGTH' => 100,
			'REQUIRED' => false,
		],
	];

	// Enrollment fields of the current school year & school.

	$enrollment_group = _( 'Enrollment' );

	$grade_levels = DBGet( "SELECT ID,TITLE,SHORT_NAME
		FROM school_gradelevels
		WHERE SCHOOL_ID='" . UserSchool() . "'
		ORDER BY SORT_ORDER IS NULL,SORT_ORDER,TITLE" );

	$grade_options = $grade_aliases = [];

	foreach ( (array) $grade_levels as $grade_level )
	{
		$grade_options[ $grade_level['ID'] ] = $grade_level['TITLE'];

		if ( $grade_level['SHORT_NAME'] !== '' )
		{
			$grade_aliases[ $grade_level['SHORT_NAME'] ] = $grade_level['ID'];
		}
	}

	$fields['GRADE_ID'] = [
		'TITLE' => _( 'Grade Level' ),
		'GROUP' => $enrollment_group,
		'TYPE' => 'id_select',
		'TABLE' => 'student_enrollment',
		'OPTIONS' => $grade_options,
		'ALIASES' => $grade_aliases,
		'MAXLENGTH' => 0,
		'REQUIRED' => true,
	];

	$calendars = DBGet( "SELECT CALENDAR_ID,TITLE
		FROM attendance_calendars
		WHERE SYEAR='" . UserSyear() . "'
		AND SCHOOL_ID='" . UserSchool() . "'
		ORDER BY TITLE" );

	$calendar_options = [];

	foreach ( (array) $calendars as $calendar )
	{
		$calendar_options[ $calendar['CALENDAR_ID'] ] = ParseMLField( $calendar['TITLE'] );
	}

	if ( $calendar_options )
	{
		$fields['CALENDAR_ID'] = [
			'TITLE' => _( 'Calendar' ),
			'GROUP' => $enrollment_group,
			'TYPE' => 'id_select',
			'TABLE' => 'student_enrollment',
			'OPTIONS' => $calendar_options,
			'MAXLENGTH' => 0,
			'REQUIRED' => false,
		];
	}

	$schools = DBGet( "SELECT ID,TITLE
		FROM schools
		WHERE SYEAR='" . UserSyear() . "'
		ORDER BY TITLE" );

	$school_options = [];

	foreach ( (array) $schools as $school )
	{
		$school_options[ $school['ID'] ] = ParseMLField( $school['TITLE'] );
	}

	$fields['NEXT_SCHOOL'] = [
		'TITLE' => _( 'Next School' ),
		'GROUP' => $enrollment_group,
		'TYPE' => 'id_select',
		'TABLE' => 'student_enrollment',
		'OPTIONS' => $school_options,
		'MAXLENGTH' => 0,
		'REQUIRED' => false,
	];

	$enrollment_codes = DBGet( "SELECT ID,TITLE,SHORT_NAME
		FROM student_enrollment_codes
		WHERE SYEAR='" . UserSyear() . "'
		AND TYPE='Add'
		ORDER BY SORT_ORDER IS NULL,SORT_ORDER,TITLE" );

	$code_options = $code_aliases = [];

	foreach ( (array) $enrollment_codes as $enrollment_code )
	{
		$code_options[ $enrollment_code['ID'] ] = ParseMLField( $enrollment_code['TITLE'] );

		if ( $enrollment_code['SHORT_NAME'] !== '' )
		{
			$code_aliases[ $enrollment_code['SHORT_NAME'] ] = $enrollment_code['ID'];
		}
	}

	if ( $code_options )
	{
		$fields['ENROLLMENT_CODE'] = [
			'TITLE' => _( 'Enrollment Code' ),
			'GROUP' => $enrollment_group,
			'TYPE' => 'id_select',
			'TABLE' => 'student_enrollment',
			'OPTIONS' => $code_options,
			'ALIASES' => $code_aliases,
			'MAXLENGTH' => 0,
			'REQUIRED' => false,
		];
	}

	// Student Fields (custom fields), grouped by category.

	$custom_fields = DBGet( "SELECT cf.ID,cf.TITLE,cf.TYPE,cf.SELECT_OPTIONS,cf.REQUIRED,
		sfc.TITLE AS CATEGORY_TITLE
		FROM custom_fields cf,student_field_categories sfc
		WHERE cf.CATEGORY_ID=sfc.ID
		AND cf.TYPE!='files'
		ORDER BY sfc.SORT_ORDER IS NULL,sfc.SORT_ORDER,sfc.TITLE,
			cf.SORT_ORDER IS NULL,cf.SORT_ORDER,cf.TITLE" );

	foreach ( (array) $custom_fields as $custom_field )
	{
		$options = [];

		if ( in_array( $custom_field['TYPE'], [ 'select', 'autos', 'exports', 'multiple' ], true )
			&& $custom_field['SELECT_OPTIONS'] )
		{
			$select_options = explode(
				"\r",
				str_replace( [ "\r\n", "\n" ], "\r", $custom_field['SELECT_OPTIONS'] )
			);

			foreach ( $select_options as $option )
			{
				$option = trim( $option );

				if ( $custom_field['TYPE'] === 'exports' )
				{
					// Export Pull-Down options are "value|exported value".
					$option = explode( '|', $option );

					$option = trim( $option[0] );
				}

				if ( $option !== '' )
				{
					$options[ $option ] = $option;
				}
			}
		}

		$fields[ 'CUSTOM_' . $custom_field['ID'] ] = [
			'TITLE' => ParseMLField( $custom_field['TITLE'] ),
			'GROUP' => ParseMLField( $custom_field['CATEGORY_TITLE'] ),
			'TYPE' => $custom_field['TYPE'],
			'TABLE' => 'students',
			'OPTIONS' => $options,
			'MAXLENGTH' => $custom_field['TYPE'] === 'radio' ? 1 : 0,
			'REQUIRED' => $custom_field['REQUIRED'] === 'Y',
		];
	}

	return $fields;
}

/**
 * Guess which field a CSV column holds, from its header
 *
 * Matches the header against the field IDs (`LAST_NAME`, `CUSTOM_200000004`),
 * the field names, and a few aliases for the identifier.
 *
 * @param  array $headers CSV header cells.
 *
 * @return array Associative `[ column index => field id ]`, only for the guessed columns.
 */
function InfoExpressMapGuess( $headers )
{
	$fields = InfoExpressFields();

	// Normalized field name => field id. The first field wins on a tie.
	$by_name = [];

	foreach ( $fields as $field_id => $field )
	{
		foreach ( [ $field_id, $field['TITLE'] ] as $name )
		{
			$name = InfoExpressNormalize( $name );

			if ( $name !== ''
				&& ! isset( $by_name[ $name ] ) )
			{
				$by_name[ $name ] = $field_id;
			}
		}
	}

	// Common names for the identifier column.
	$id_aliases = [
		'student id', 'student i d', 'studentid', 'student identifier',
		'student number', 'student no', 'student num', 'student nr',
		'student code', 'student key', 'student ref',
		'id', 'id number', 'id no', 'idnumber', 'sid', 's id', 'stu id', 'stdnt id',
		'sis id', 'sis number', 'rosariosis id', 'rosario id',
		'local id', 'state id', 'person id', 'pupil id', 'learner id',
		'number', 'no', 'key', 'ref',
	];

	foreach ( $id_aliases as $alias )
	{
		if ( ! isset( $by_name[ $alias ] ) )
		{
			$by_name[ $alias ] = 'STUDENT_ID';
		}
	}

	$map = [];

	$used = [];

	foreach ( (array) $headers as $index => $header )
	{
		$header = InfoExpressNormalize( $header );

		if ( $header === ''
			|| ! isset( $by_name[ $header ] )
			|| isset( $used[ $by_name[ $header ] ] ) )
		{
			// Unknown header, or the field is already mapped to an earlier column.
			continue;
		}

		$map[ $index ] = $by_name[ $header ];

		$used[ $by_name[ $header ] ] = true;
	}

	return $map;
}

/**
 * Convert a CSV cell to the value to store, and validate it
 *
 * @param  string $field_id Field ID.
 * @param  string $raw      CSV cell.
 * @param  string $error    Error message, passed by reference. Empty if the value is valid.
 *
 * @return string Value to store. Empty string if the field is to be emptied or on error.
 */
function InfoExpressValueConvert( $field_id, $raw, &$error )
{
	$error = '';

	$fields = InfoExpressFields();

	if ( ! isset( $fields[ $field_id ] ) )
	{
		$error = _( 'Unknown field.' );

		return '';
	}

	$field = $fields[ $field_id ];

	$raw = trim( (string) $raw );

	if ( $raw === '' )
	{
		if ( $field['REQUIRED'] )
		{
			$error = _( 'This field cannot be emptied.' );
		}

		return '';
	}

	switch ( $field['TYPE'] )
	{
		case 'key':

			if ( ! ctype_digit( $raw )
				|| (int) $raw <= 0 )
			{
				$error = _( 'Please enter valid Numeric data.' );

				return '';
			}

			return (string) (int) $raw;

		case 'numeric':

			$number = str_replace( [ ' ', ',' ], [ '', '.' ], $raw );

			if ( ! is_numeric( $number ) )
			{
				$error = _( 'Please enter valid Numeric data.' );

				return '';
			}

			// students.CUSTOM_x is a NUMERIC(20,2) column.
			return (string) round( (float) $number, 2 );

		case 'date':

			if ( ! VerifyDate( $raw ) )
			{
				$error = _( 'Please enter a valid date.' );

				return '';
			}

			$date = ExplodeDate( $raw );

			return $date['year'] . '-' . $date['month'] . '-' . $date['day'];

		case 'radio':

			$checked = InfoExpressNormalize( $raw );

			$yes = [ 'y', 'yes', '1', 'true', 'x', 'checked', InfoExpressNormalize( _( 'Yes' ) ) ];

			$no = [ 'n', 'no', '0', 'false', InfoExpressNormalize( _( 'No' ) ) ];

			if ( in_array( $checked, $yes, true ) )
			{
				return 'Y';
			}

			if ( in_array( $checked, $no, true ) )
			{
				return '';
			}

			$error = _( 'Please enter Yes or No.' );

			return '';

		case 'select':
		case 'exports':
		case 'id_select':

			$value = InfoExpressOptionMatch( $field, $raw );

			if ( $value === false )
			{
				$error = sprintf(
					_( '"%s" is not one of the values allowed for this field.' ),
					htmlspecialchars( $raw, ENT_QUOTES, 'UTF-8' )
				);

				return '';
			}

			return $value;

		case 'multiple':

			// The full cell can be one option, otherwise the values are pipe separated.
			$values = InfoExpressOptionMatch( $field, $raw ) !== false ?
				[ $raw ] :
				explode( '|', $raw );

			$matched = [];

			foreach ( $values as $value )
			{
				$value = trim( $value );

				if ( $value === '' )
				{
					continue;
				}

				$option = InfoExpressOptionMatch( $field, $value );

				if ( $option === false )
				{
					$error = sprintf(
						_( '"%s" is not one of the values allowed for this field.' ),
						htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' )
					);

					return '';
				}

				$matched[ $option ] = $option;
			}

			if ( ! $matched )
			{
				return '';
			}

			// Select Multiple from Options field type format.
			return '||' . implode( '||', $matched ) . '||';

		case 'textarea':

			require_once 'ProgramFunctions/MarkDownHTML.fnc.php';

			return SanitizeMarkDown( $raw );

		case 'autos':
		case 'text':
		default:

			if ( $field['MAXLENGTH']
				&& mb_strlen( $raw ) > $field['MAXLENGTH'] )
			{
				$error = sprintf( _( 'Maximum %d characters.' ), $field['MAXLENGTH'] );

				return '';
			}

			return $raw;
	}
}

/**
 * Match a CSV cell against the options of a field
 *
 * Matches the stored value, then the displayed label, then the aliases
 * (grade level & enrollment code short names). Case & accents insensitive.
 *
 * @param  array  $field Field, from InfoExpressFields().
 * @param  string $raw   CSV cell.
 *
 * @return string|false Value to store, or false if the cell matches no option.
 */
function InfoExpressOptionMatch( $field, $raw )
{
	if ( ! $field['OPTIONS'] )
	{
		// No option defined: accept the value as is.
		return $raw;
	}

	$needle = InfoExpressNormalize( $raw );

	if ( $needle === '' )
	{
		return '';
	}

	foreach ( $field['OPTIONS'] as $value => $label )
	{
		if ( InfoExpressNormalize( $value ) === $needle
			|| InfoExpressNormalize( $label ) === $needle )
		{
			return (string) $value;
		}
	}

	foreach ( (array) issetVal( $field['ALIASES'], [] ) as $alias => $value )
	{
		if ( InfoExpressNormalize( $alias ) === $needle )
		{
			return (string) $value;
		}
	}

	return false;
}

/**
 * Format a stored value for display
 *
 * @param  string $field_id Field ID.
 * @param  string $value    Stored value.
 *
 * @return string HTML escaped value, '-' if empty.
 */
function InfoExpressValueDisplay( $field_id, $value )
{
	$fields = InfoExpressFields();

	$value = (string) $value;

	if ( $value === '' )
	{
		return '-';
	}

	if ( ! isset( $fields[ $field_id ] ) )
	{
		return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
	}

	$field = $fields[ $field_id ];

	if ( $field['TYPE'] === 'radio' )
	{
		$value = $value === 'N' ? _( 'No' ) : _( 'Yes' );
	}
	elseif ( $field['TYPE'] === 'multiple' )
	{
		$value = str_replace( '||', ', ', trim( $value, '|' ) );
	}
	elseif ( $field['TYPE'] === 'date' )
	{
		$value = ProperDate( $value, 'short' );
	}
	elseif ( isset( $field['OPTIONS'][ $value ] ) )
	{
		$value = $field['OPTIONS'][ $value ];
	}

	return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
}

/**
 * Get the Students of the current school year & school, by Student ID
 *
 * Only the students the user is allowed to see are returned: a student not
 * enrolled in the current school is reported as not found.
 *
 * @param  array $student_ids Student IDs to look for.
 * @param  array $columns     `students` columns to read.
 *
 * @return array Associative `[ student id => [ column => value ] ]`.
 */
function InfoExpressStudentsGet( $student_ids, $columns )
{
	$student_ids = array_filter( array_map( 'intval', (array) $student_ids ) );

	if ( ! $student_ids )
	{
		return [];
	}

	// Always read the name, to display it.
	$columns = array_unique( array_merge(
		[ 'FIRST_NAME', 'MIDDLE_NAME', 'LAST_NAME' ],
		(array) $columns
	) );

	$select = '';

	foreach ( $columns as $column )
	{
		if ( ! InfoExpressIsColumnName( $column ) )
		{
			// Security: SQL prevent SELECT of anything else than a column name.
			continue;
		}

		$select .= ',s.' . $column;
	}

	$students_RET = DBGet( "SELECT s.STUDENT_ID" . $select . ",
		se.ID AS ENROLLMENT_ID,se.GRADE_ID,se.CALENDAR_ID,se.NEXT_SCHOOL,se.ENROLLMENT_CODE
		FROM students s,student_enrollment se
		WHERE se.STUDENT_ID=s.STUDENT_ID
		AND se.SYEAR='" . UserSyear() . "'
		AND se.SCHOOL_ID='" . UserSchool() . "'
		AND s.STUDENT_ID IN(" . implode( ',', $student_ids ) . ")
		ORDER BY se.START_DATE IS NULL,se.START_DATE" );

	$students = [];

	foreach ( (array) $students_RET as $student )
	{
		// Ordered by START_DATE: the last enrollment record of the year wins.
		$students[ $student['STUDENT_ID'] ] = $student;
	}

	return $students;
}

/**
 * Is the string a valid (custom) column name?
 *
 * @param  string $column Column name.
 *
 * @return bool True if the column name only contains letters, numbers & underscores.
 */
function InfoExpressIsColumnName( $column )
{
	return (bool) preg_match( '/^[A-Za-z][A-Za-z0-9_]*$/', (string) $column );
}

/**
 * Analyze the rows: which Student, which changes, which errors
 *
 * Sets, for each row:
 * - `STUDENT_ID`, `STUDENT_NAME`: the matched student.
 * - `STATUS`: not_found|duplicate|error|no_change|update.
 * - `STATUS_TEXT`: reason, when the row will not be applied.
 * - `CHANGES`: `[ field id => [ 'OLD' => value, 'NEW' => value ] ]`.
 * - `ERRORS`: `[ field id => message ]`.
 *
 * @param  array $rows         Parsed rows.
 * @param  array $map          `[ column index => field id ]`.
 * @param  bool  $clear_blanks Empty cells empty the field, instead of being ignored.
 *
 * @return array Analyzed rows.
 */
function InfoExpressRowsAnalyze( $rows, $map, $clear_blanks )
{
	$fields = InfoExpressFields();

	$id_column = array_search( 'STUDENT_ID', $map, true );

	$student_columns = [];

	foreach ( $map as $field_id )
	{
		if ( isset( $fields[ $field_id ] )
			&& $fields[ $field_id ]['TABLE'] === 'students' )
		{
			$student_columns[] = $field_id;
		}
	}

	// Read every student of the file at once.
	$student_ids = [];

	if ( $id_column !== false )
	{
		foreach ( $rows as $row )
		{
			$student_ids[] = (int) issetVal( $row['CELLS'][ $id_column ], 0 );
		}
	}

	$students = InfoExpressStudentsGet( $student_ids, $student_columns );

	// Usernames already taken, to check the USERNAME field.
	$usernames = in_array( 'USERNAME', $map, true ) ? InfoExpressUsernamesGet() : [];

	$seen_ids = $seen_usernames = [];

	foreach ( $rows as $i => $row )
	{
		$rows[ $i ] += [
			'STUDENT_ID' => '',
			'STUDENT_NAME' => '',
			'ENROLLMENT_ID' => '',
			'STATUS' => 'error',
			'STATUS_TEXT' => '',
			'CHANGES' => [],
			'ERRORS' => [],
		];

		$id_error = '';

		$student_id = $id_column === false ? '' : InfoExpressValueConvert(
			'STUDENT_ID',
			issetVal( $row['CELLS'][ $id_column ], '' ),
			$id_error
		);

		if ( ! $student_id )
		{
			$rows[ $i ]['STATUS'] = 'error';

			$rows[ $i ]['STATUS_TEXT'] = $id_error ? $id_error :
				sprintf( _( 'No %s ID.' ), Config( 'NAME' ) );

			continue;
		}

		$rows[ $i ]['STUDENT_ID'] = $student_id;

		if ( isset( $seen_ids[ $student_id ] ) )
		{
			$rows[ $i ]['STATUS'] = 'duplicate';

			$rows[ $i ]['STATUS_TEXT'] = sprintf(
				_( 'Line %d holds the same %s ID.' ),
				$seen_ids[ $student_id ],
				Config( 'NAME' )
			);

			continue;
		}

		$seen_ids[ $student_id ] = $row['LINE'];

		if ( ! isset( $students[ $student_id ] ) )
		{
			$rows[ $i ]['STATUS'] = 'not_found';

			$rows[ $i ]['STATUS_TEXT'] = _( 'No student with that ID in this school.' );

			continue;
		}

		$student = $students[ $student_id ];

		$rows[ $i ]['STUDENT_NAME'] = implode( ' ', array_filter( [
			$student['FIRST_NAME'],
			issetVal( $student['MIDDLE_NAME'], '' ),
			$student['LAST_NAME'],
		] ) );

		$rows[ $i ]['ENROLLMENT_ID'] = $student['ENROLLMENT_ID'];

		foreach ( $map as $column => $field_id )
		{
			if ( $field_id === 'STUDENT_ID'
				|| ! isset( $fields[ $field_id ] ) )
			{
				continue;
			}

			$raw = issetVal( $row['CELLS'][ $column ], '' );

			if ( $raw === ''
				&& ! $clear_blanks )
			{
				// Empty cell: leave the field untouched.
				continue;
			}

			$error = '';

			$new = InfoExpressValueConvert( $field_id, $raw, $error );

			if ( $error )
			{
				$rows[ $i ]['ERRORS'][ $field_id ] = $error;

				continue;
			}

			if ( $field_id === 'USERNAME'
				&& $new !== '' )
			{
				$taken_by = issetVal( $usernames[ mb_strtolower( $new ) ], '' );

				if ( $taken_by !== '' && $taken_by !== $student_id )
				{
					$rows[ $i ]['ERRORS'][ $field_id ] =
						_( 'A user with that username already exists. Choose a different username and try again.' );

					continue;
				}

				if ( isset( $seen_usernames[ mb_strtolower( $new ) ] ) )
				{
					$rows[ $i ]['ERRORS'][ $field_id ] = sprintf(
						_( 'Line %d holds the same username.' ),
						$seen_usernames[ mb_strtolower( $new ) ]
					);

					continue;
				}

				$seen_usernames[ mb_strtolower( $new ) ] = $row['LINE'];
			}

			$old = (string) issetVal( $student[ $field_id ], '' );

			if ( $old === $new
				|| ( $fields[ $field_id ]['TYPE'] === 'numeric'
					&& $old !== '' && $new !== ''
					&& (float) $old === (float) $new ) )
			{
				continue;
			}

			$rows[ $i ]['CHANGES'][ $field_id ] = [ 'OLD' => $old, 'NEW' => $new ];
		}

		if ( $rows[ $i ]['ERRORS'] )
		{
			$rows[ $i ]['STATUS'] = 'error';

			$rows[ $i ]['STATUS_TEXT'] = implode( ' ', $rows[ $i ]['ERRORS'] );

			continue;
		}

		if ( ! $rows[ $i ]['CHANGES'] )
		{
			$rows[ $i ]['STATUS'] = 'no_change';

			$rows[ $i ]['STATUS_TEXT'] = _( 'Nothing to change.' );

			continue;
		}

		$rows[ $i ]['STATUS'] = 'update';

		$rows[ $i ]['STATUS_TEXT'] = '';
	}

	return $rows;
}

/**
 * Get the usernames already taken, by Students & Users
 *
 * A username held by a user (staff) is marked 'staff': this module can never
 * free it, and 'staff' never matches a student ID.
 *
 * @return array Associative `[ lowercase username => student id|'staff' ]`.
 */
function InfoExpressUsernamesGet()
{
	$usernames_RET = DBGet( "SELECT USERNAME,STUDENT_ID
		FROM students
		WHERE USERNAME IS NOT NULL
		UNION SELECT USERNAME,NULL AS STUDENT_ID
		FROM staff
		WHERE USERNAME IS NOT NULL
		AND SYEAR='" . UserSyear() . "'" );

	$usernames = [];

	foreach ( (array) $usernames_RET as $username )
	{
		if ( $username['USERNAME'] === '' )
		{
			continue;
		}

		$key = mb_strtolower( $username['USERNAME'] );

		$holder = $username['STUDENT_ID'] === '' ? 'staff' : $username['STUDENT_ID'];

		if ( ! isset( $usernames[ $key ] )
			|| $holder === 'staff' )
		{
			// A staff username always wins: it can never be freed.
			$usernames[ $key ] = $holder;
		}
	}

	return $usernames;
}

/**
 * Apply the changes of one row
 *
 * @uses Info_Express/Import.php|update_student action hook
 *
 * @param  array $row Analyzed row, of `update` status.
 *
 * @return bool True if something was updated.
 */
function InfoExpressRowApply( $row )
{
	$fields = InfoExpressFields();

	$student_columns = $enrollment_columns = [];

	foreach ( (array) $row['CHANGES'] as $field_id => $change )
	{
		if ( ! isset( $fields[ $field_id ] )
			|| ! InfoExpressIsColumnName( $field_id ) )
		{
			// Security: SQL prevent UPDATE of anything else than a known column.
			continue;
		}

		if ( $fields[ $field_id ]['TABLE'] === 'students' )
		{
			$student_columns[ $field_id ] = DBEscapeString( $change['NEW'] );
		}
		elseif ( $fields[ $field_id ]['TABLE'] === 'student_enrollment' )
		{
			$enrollment_columns[ $field_id ] = DBEscapeString( $change['NEW'] );
		}
	}

	$updated = false;

	if ( $student_columns )
	{
		DBUpdate(
			'students',
			$student_columns,
			[ 'STUDENT_ID' => (int) $row['STUDENT_ID'] ]
		);

		$updated = true;
	}

	if ( $enrollment_columns
		&& ! empty( $row['ENROLLMENT_ID'] ) )
	{
		DBUpdate(
			'student_enrollment',
			$enrollment_columns,
			[
				'ID' => (int) $row['ENROLLMENT_ID'],
				'SYEAR' => UserSyear(),
				'SCHOOL_ID' => UserSchool(),
			]
		);

		$updated = true;
	}

	if ( $updated )
	{
		$_REQUEST['student_id'] = $row['STUDENT_ID'];

		// Hook.
		do_action( 'Info_Express/Import.php|update_student' );
	}

	return $updated;
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
function InfoExpressSession( $data = null )
{
	$empty = [
		'HEADERS' => [],
		'ROWS' => [],
		'FILE_NAME' => '',
		'FIRST_ROW_HEADER' => true,
		'MAP' => [],
		'CLEAR_BLANKS' => false,
	];

	if ( $data === false )
	{
		unset( $_SESSION['Info_Express'] );

		return $empty;
	}

	if ( is_array( $data ) )
	{
		$_SESSION['Info_Express'] = $data + [
			'SYEAR' => UserSyear(),
			'SCHOOL_ID' => UserSchool(),
		];
	}

	$session = issetVal( $_SESSION['Info_Express'], [] );

	if ( ! $session
		|| issetVal( $session['SYEAR'], '' ) != UserSyear()
		|| issetVal( $session['SCHOOL_ID'], '' ) != UserSchool() )
	{
		// Not our school year / school anymore.
		return $empty;
	}

	foreach ( $empty as $key => $default )
	{
		$empty[ $key ] = issetVal( $session[ $key ], $default );
	}

	return $empty;
}

/**
 * Get the data rows: every row, or every row but the header one
 *
 * @param  array $session Session data, from InfoExpressSession().
 *
 * @return array Rows.
 */
function InfoExpressDataRows( $session )
{
	if ( ! $session['FIRST_ROW_HEADER'] )
	{
		return $session['ROWS'];
	}

	return array_slice( $session['ROWS'], 1, null, true );
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
function InfoExpressListIndex( $list )
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
function InfoExpressHTML( $value )
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
function InfoExpressInputTruncated()
{
	$max = (int) ini_get( 'max_input_vars' );

	if ( ! $max )
	{
		return false;
	}

	return count( $_POST, COUNT_RECURSIVE ) >= $max;
}
