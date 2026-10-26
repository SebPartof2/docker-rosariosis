<?php
/**
 * Course Express: Move Sections
 *
 * 3 steps wizard:
 * 1. Upload (or paste) the CSV list of moves.
 * 2. Review who moves where, and why a move will not happen.
 * 3. Move the students.
 *
 * @package RosarioSIS
 * @subpackage modules
 */

require_once 'modules/Course_Express/includes/MoveSections.fnc.php';

DrawHeader( ProgramTitle() );

$error = $note = [];

if ( ! AllowEdit() )
{
	$error[] = _( 'You are not allowed to use this program.' );

	echo ErrorMessage( $error );

	return;
}

$modfunc = $_REQUEST['modfunc'];

if ( ! in_array( $modfunc, [ 'preview', 'move' ], true ) )
{
	$modfunc = '';
}

/**
 * Step 1 -- Read the file (or the pasted text) & save the moves in the session
 */
if ( $modfunc === 'preview' )
{
	$csv_text = $file_name = '';

	$posted_file = ! empty( $_FILES['csv_file']['name'] ) || ! empty( $_POST['csv_text'] );

	if ( ! empty( $_FILES['csv_file']['name'] ) )
	{
		$csv_text = _courseExpressMovesFileRead( 'csv_file', $file_name, $error );
	}
	elseif ( ! empty( $_POST['csv_text'] ) )
	{
		// Get the text from $_POST: $_REQUEST is escaped for SQL in Warehouse.php.
		$csv_text = (string) $_POST['csv_text'];

		$file_name = _( 'Pasted moves' );
	}
	elseif ( ! CourseExpressMovesSession()['ROWS'] )
	{
		$error[] = _( 'Please attach a file.' );
	}

	if ( ! $error
		&& $posted_file )
	{
		$messages = [];

		$rows = CourseExpressMovesCSVParse( $csv_text, $messages );

		if ( ! $rows )
		{
			$error = array_merge( $error, $messages );
		}
		else
		{
			$note = array_merge( $note, $messages );

			CourseExpressMovesSession( [
				'ROWS' => $rows,
				'FILE_NAME' => $file_name,
				'OVERRIDE' => ! empty( $_REQUEST['override'] ),
			] );
		}
	}

	if ( $error )
	{
		// Back to step 1.
		$modfunc = '';
	}
}

$session = CourseExpressMovesSession();

if ( $modfunc !== ''
	&& ! $session['ROWS'] )
{
	$error[] = _( 'Please start over: your moves are no longer available.' );

	$modfunc = '';
}

/**
 * Step 3 -- Move the students
 */
if ( $modfunc === 'move' )
{
	$rows = CourseExpressMovesAnalyze( $session['ROWS'], $session['OVERRIDE'] );

	// The rows to leave out are posted, not the rows to apply. A long file
	// cannot post a ticked box per row: PHP stops reading at max_input_vars,
	// 1000 by default, and ListOutput() only ever draws the first 1000 rows
	// anyway -- so everything past row 1000 had no box to tick and would have
	// been dropped. Posting only the exceptions keeps the form small however
	// long the file is.
	$skip_rows = (array) issetVal( $_REQUEST['skip_rows'], [] );

	if ( CourseExpressInputTruncated() )
	{
		// Some of the ticks were lost on the way in: applying now would apply
		// rows the user asked to leave out.
		echo ErrorMessage( [ _( 'Too many rows were skipped at once for the server to read them all. Please leave fewer rows out, or take them out of the file, and try again.' ) ] );

		return;
	}

	$moved = 0;

	$skipped = [];

	foreach ( $rows as $i => $row )
	{
		if ( $row['STATUS'] !== 'move' )
		{
			$skipped[] = $row;

			continue;
		}

		if ( ! empty( $skip_rows[ $i ] ) )
		{
			$row['STATUS'] = 'skipped';

			$row['STATUS_TEXT'] = _( 'Skipped.' );

			$skipped[] = $row;

			continue;
		}

		if ( CourseExpressMoveApply( $row ) )
		{
			$moved++;
		}
		else
		{
			$row['STATUS'] = 'skipped';

			$row['STATUS_TEXT'] = _( 'The student could not be moved.' );

			$skipped[] = $row;
		}
	}

	// Done: forget the file.
	CourseExpressMovesSession( false );

	_courseExpressMovesStepsDraw( 3 );

	echo ErrorMessage( $error );

	$note[] = button( 'check' ) . '&nbsp;' . sprintf(
		_( '%d students were moved.' ),
		$moved
	);

	echo ErrorMessage( $note, 'note' );

	if ( $skipped )
	{
		PopTable( 'header', _( 'Students not moved' ) );

		_courseExpressMovesList( $skipped, false );

		PopTable( 'footer' );
	}

	echo '<div class="center"><br />' .
		button(
			'add',
			_( 'Move more students' ),
			URLEscape( 'Modules.php?modname=' . $_REQUEST['modname'] )
		) .
		'</div>';

	return;
}

/**
 * Step 2 -- Review the moves
 */
if ( $modfunc === 'preview' )
{
	_courseExpressMovesStepsDraw( 2 );

	echo ErrorMessage( $note, 'note' );

	$rows = CourseExpressMovesAnalyze( $session['ROWS'], $session['OVERRIDE'] );

	$movable = 0;

	foreach ( $rows as $row )
	{
		if ( $row['STATUS'] === 'move' )
		{
			$movable++;
		}
	}

	echo '<form action="' . URLEscape( 'Modules.php?modname=' . $_REQUEST['modname'] .
		'&modfunc=move' ) . '" method="POST">';

	if ( $session['OVERRIDE'] )
	{
		echo ErrorMessage(
			[ _( 'A full or clashing section will not stop a move: the reason is shown on the row instead.' ) ],
			'warning'
		);
	}

	PopTable( 'header', _( 'Moves to Make' ) );

	echo '<p class="center">' . sprintf(
		_( '%d students out of %d will be moved. Tick "Skip" on any you do not want.' ),
		$movable,
		count( $rows )
	) . '</p>';

	_courseExpressMovesList( $rows, true );

	echo '<div class="center"><br />' .
		( $movable ?
			Buttons( _( 'Move Students' ) ) :
			ErrorMessage( [ _( 'There is no student to move.' ) ], 'note' ) ) .
		'</div>';

	PopTable( 'footer' );

	echo '</form>';

	echo '<div class="center">' .
		button(
			'back',
			_( 'Start over' ),
			URLEscape( 'Modules.php?modname=' . $_REQUEST['modname'] )
		) .
		'</div>';

	return;
}

/**
 * Step 1 -- Upload the file
 */
_courseExpressMovesStepsDraw( 1 );

echo ErrorMessage( $error );

echo '<form action="' . URLEscape( 'Modules.php?modname=' . $_REQUEST['modname'] .
	'&modfunc=preview' ) . '" method="POST" enctype="multipart/form-data">';

PopTable( 'header', _( 'Moves File' ) );

echo '<p>' . sprintf(
	_( 'Move students between the sections of a course at %s. The file must contain one move per line, with these columns:' ),
	'<b>' . CourseExpressHTML( ParseMLField( SchoolInfo( 'TITLE' ) ) ) . '</b>'
) . '</p>';

$fields_info = [
	'STUDENT' => [
		sprintf( _( '%s ID' ), Config( 'NAME' ) ),
		_( 'The ID of a student enrolled in this school' ),
	],
	'COURSE' => [
		_( 'Course Number' ),
		_( 'The short name of the course they are in' ),
	],
	'FROM' => [
		_( 'From Section' ),
		_( 'The section they are in now' ),
	],
	'TO' => [
		_( 'To Section' ),
		_( 'The section to move them to' ),
	],
];

$aliases = CourseExpressMoveAliases();

$columns_html = '';

$column_number = 0;

foreach ( $fields_info as $field => $info )
{
	$column_number++;

	$columns_html .= '<tr>
		<td>' . $column_number . '. ' . $info[0] . '</td>
		<td><code>' . implode( '</code>, <code>', array_slice( $aliases[ $field ], 0, 8 ) ) . '</code></td>
		<td>' . $info[1] . '</td>
	</tr>';
}

echo '<table class="widefat"><thead><tr>
		<th>' . _( 'Column' ) . '</th>
		<th>' . _( 'Header names accepted' ) . '</th>
		<th>' . _( 'Holds' ) . '</th>
	</tr></thead><tbody>' . $columns_html . '</tbody></table>';

echo '<p>' . _( 'Every column is required. A section can be named in full ("Section 2"), by its number alone ("2"), or by its Course Period ID. The first line can be a header row; without one, the columns are read in the order above.' ) . '</p>';

echo '<p>' . _( 'Example:' ) . '</p>';

echo '<pre>' . CourseExpressHTML( _courseExpressMovesSampleCSV() ) . '</pre>';

echo ErrorMessage(
	[ _( 'The schedule entry is moved, so the start date is kept. Grades and period attendance already recorded stay with the section they were recorded in, exactly as when a section is changed on the Student Schedule page.' ) ],
	'warning'
);

echo '<br />' . FileInput( 'csv_file', _( 'CSV File' ) );

echo '<br /><br />' . FormatInputTitle( _( 'Or paste the moves here' ), 'csv_text' ) .
	'<br /><textarea id="csv_text" name="csv_text" rows="6" class="width-100p" ' .
	'placeholder="' . AttrEscape( '1000123,MAT101,1,2' ) . '"></textarea>';

echo '<br /><div class="center">' .
	'<label><input type="checkbox" name="override" value="Y"' .
		( $session['OVERRIDE'] ? ' checked' : '' ) . ' /> ' .
		_( 'Move even when the section is full, or clashes with another course' ) . '</label>' .
	'</div>';

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
function _courseExpressMovesStepsDraw( $current )
{
	$steps = [
		1 => _( 'Moves File' ),
		2 => _( 'Moves' ),
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
 * Read the uploaded CSV file
 *
 * Local function
 *
 * @param  string $input     Name of the file input.
 * @param  string $file_name Uploaded file name, passed by reference.
 * @param  array  $error     The errors array, passed by reference.
 *
 * @return string File contents, empty string on error.
 */
function _courseExpressMovesFileRead( $input, &$file_name, &$error )
{
	$upload_error = issetVal( $_FILES[ $input ]['error'], UPLOAD_ERR_NO_FILE );

	if ( $upload_error === UPLOAD_ERR_INI_SIZE
		|| $upload_error === UPLOAD_ERR_FORM_SIZE )
	{
		require_once 'ProgramFunctions/FileUpload.fnc.php';

		$error[] = sprintf( _( 'Maximum file size: %01.0fMb' ), FileUploadMaxSize() );

		return '';
	}

	$extension = mb_strtolower( (string) mb_strrchr( $_FILES[ $input ]['name'], '.' ) );

	if ( ! in_array( $extension, [ '.csv', '.tsv', '.txt' ], true ) )
	{
		$error[] = sprintf(
			_( 'The file must have one of the following extensions: %s' ),
			'.csv, .tsv, .txt'
		);

		return '';
	}

	if ( $upload_error !== UPLOAD_ERR_OK
		|| empty( $_FILES[ $input ]['tmp_name'] )
		|| ! is_uploaded_file( $_FILES[ $input ]['tmp_name'] ) )
	{
		$error[] = _( 'The file could not be read.' );

		return '';
	}

	$contents = file_get_contents( $_FILES[ $input ]['tmp_name'] );

	if ( $contents === false )
	{
		$error[] = _( 'The file could not be read.' );

		return '';
	}

	$file_name = basename( (string) $_FILES[ $input ]['name'] );

	return $contents;
}

/**
 * Output the moves list
 *
 * Local function
 *
 * @param  array   $rows       Analyzed rows.
 * @param  boolean $checkboxes Add a checkbox for the students which will move.
 *
 * @return void
 */
function _courseExpressMovesList( $rows, $checkboxes )
{
	$moves_LO = [];

	foreach ( $rows as $i => $row )
	{
		$is_move = $row['STATUS'] === 'move';

		$from = (string) issetVal( $row['FROM_SECTION']['SHORT_NAME'], '' );

		$to = (string) issetVal( $row['TO_SECTION']['SHORT_NAME'], '' );

		$seats = '';

		if ( $to !== '' )
		{
			$free = issetVal( $row['TO_SECTION']['SEATS_FREE'], null );

			$seats = $free === null ? _( 'No limit' ) : (string) $free;
		}

		$move_LO = [
			'LINE' => $row['LINE'],
			'STUDENT_ID' => CourseExpressHTML( $row['STUDENT_ID'] ),
			'STUDENT' => CourseExpressHTML( $row['STUDENT_NAME'] ),
			'COURSE' => CourseExpressHTML( $row['COURSE_TITLE'] ?
				$row['COURSE_TITLE'] . ' (' . $row['COURSE'] . ')' :
				$row['COURSE'] ),
			'FROM' => CourseExpressHTML( $from !== '' ? $from : $row['FROM'] ),
			'TO' => CourseExpressHTML( $to !== '' ? $to : $row['TO'] ),
			'FREE_SEATS' => CourseExpressHTML( $seats ),
			'STATUS' => $is_move && $row['STATUS_TEXT'] === '' ?
				'<b>' . _( 'Move' ) . '</b>' :
				( $is_move ?
					'<b>' . _( 'Move' ) . '</b> — ' . $row['STATUS_TEXT'] :
					$row['STATUS_TEXT'] ),
		];

		if ( $checkboxes )
		{
			$move_LO['SKIP'] = $is_move ?
				'<input type="checkbox" name="' . AttrEscape( 'skip_rows[' . $i . ']' ) .
					'" value="Y" />' :
				'-';
		}

		$moves_LO[] = $move_LO;
	}

	$columns = [
		'LINE' => _( 'Line' ),
		'STUDENT_ID' => sprintf( _( '%s ID' ), Config( 'NAME' ) ),
		'STUDENT' => _( 'Student' ),
		'COURSE' => _( 'Course' ),
		'FROM' => _( 'From' ),
		'TO' => _( 'To' ),
		'FREE_SEATS' => _( 'Free Seats' ),
		'STATUS' => _( 'Status' ),
	];

	if ( $checkboxes )
	{
		$columns['SKIP'] = _( 'Skip' );
	}

	ListOutput(
		CourseExpressListIndex( $moves_LO ),
		$columns,
		'Move',
		'Moves',
		[],
		[],
		[ 'save' => false, 'search' => false, 'sort' => false, 'add' => false ]
	);
}

/**
 * Sample CSV, used as example on the upload screen
 *
 * Local function
 *
 * @return string Sample CSV.
 */
function _courseExpressMovesSampleCSV()
{
	return sprintf( _( '%s ID' ), Config( 'NAME' ) ) . ',' . _( 'Course Number' ) . ',' .
		_( 'From Section' ) . ',' . _( 'To Section' ) . "\n" .
		'1000123,MAT101,1,2' . "\n" .
		'1000124,MAT101,1,2' . "\n" .
		'1000125,SCI201,Section 2,Section 1';
}
