<?php
/**
 * Request Express: Import Course Requests
 *
 * 3 steps wizard:
 * 1. Upload (or paste) the CSV list of requests.
 * 2. Review, student by student, which requests are removed and which are added.
 * 3. Replace the requests, or only add the missing ones (non destructive mode).
 *
 * @package RosarioSIS
 * @subpackage modules
 */

require_once 'modules/Request_Express/includes/RequestExpress.fnc.php';

DrawHeader( ProgramTitle() );

$error = $note = [];

if ( ! AllowEdit() )
{
	$error[] = _( 'You are not allowed to use this program.' );

	echo ErrorMessage( $error );

	return;
}

$modfunc = $_REQUEST['modfunc'];

if ( ! in_array( $modfunc, [ 'preview', 'save' ], true ) )
{
	$modfunc = '';
}

/**
 * Step 1 -- Read the file (or the pasted text) & save the rows in the session
 */
if ( $modfunc === 'preview' )
{
	$csv_text = $file_name = '';

	$posted_file = ! empty( $_FILES['csv_file']['name'] ) || ! empty( $_POST['csv_text'] );

	if ( ! empty( $_FILES['csv_file']['name'] ) )
	{
		$csv_text = _requestExpressFileRead( 'csv_file', $file_name, $error );
	}
	elseif ( ! empty( $_POST['csv_text'] ) )
	{
		// Get the text from $_POST: $_REQUEST is escaped for SQL in Warehouse.php.
		$csv_text = (string) $_POST['csv_text'];

		$file_name = _( 'Pasted requests' );
	}
	elseif ( ! RequestExpressSession()['ROWS'] )
	{
		$error[] = _( 'Please attach a file.' );
	}

	if ( ! $error
		&& $posted_file )
	{
		$messages = [];

		$rows = RequestExpressCSVParse( $csv_text, $messages );

		if ( ! $rows )
		{
			$error = array_merge( $error, $messages );
		}
		else
		{
			$note = array_merge( $note, $messages );

			RequestExpressSession( [
				'ROWS' => $rows,
				'FILE_NAME' => $file_name,
				'CLEAR_BLANKS' => ! empty( $_REQUEST['clear_blanks'] ),
				'MODE' => RequestExpressModeSanitize( issetVal( $_REQUEST['mode'], '' ) ),
			] );
		}
	}

	if ( $error )
	{
		// Back to step 1.
		$modfunc = '';
	}
}

$session = RequestExpressSession();

$is_add_mode = $session['MODE'] === 'add';

if ( $modfunc !== ''
	&& ! $session['ROWS'] )
{
	$error[] = _( 'Please start over: your requests are no longer available.' );

	$modfunc = '';
}

/**
 * Step 3 -- Replace the requests
 */
if ( $modfunc === 'save' )
{
	$rows = RequestExpressRowsAnalyze( $session['ROWS'], $session['CLEAR_BLANKS'], $session['MODE'] );

	// The rows to leave out are posted, not the rows to apply. A long file
	// cannot post a ticked box per row: PHP stops reading at max_input_vars,
	// 1000 by default, and ListOutput() only ever draws the first 1000 rows
	// anyway -- so everything past row 1000 had no box to tick and would have
	// been dropped. Posting only the exceptions keeps the form small however
	// long the file is.
	$skip_rows = (array) issetVal( $_REQUEST['skip_rows'], [] );

	if ( RequestExpressInputTruncated() )
	{
		// Some of the ticks were lost on the way in: applying now would apply
		// rows the user asked to leave out.
		echo ErrorMessage( [ _( 'Too many rows were skipped at once for the server to read them all. Please leave fewer rows out, or take them out of the file, and try again.' ) ] );

		return;
	}

	$students_updated = $requests_added = 0;

	$skipped = [];

	foreach ( $rows as $i => $row )
	{
		if ( $row['STATUS'] !== 'update' )
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

		if ( RequestExpressRowApply( $row, $session['MODE'] ) )
		{
			$students_updated++;

			$requests_added += count( $row['COURSES'] );
		}
		else
		{
			$row['STATUS'] = 'skipped';

			$row['STATUS_TEXT'] = $is_add_mode ?
				_( 'The requests could not be added.' ) :
				_( 'The requests could not be replaced.' );

			$skipped[] = $row;
		}
	}

	// Done: forget the file.
	RequestExpressSession( false );

	_requestExpressStepsDraw( 3 );

	echo ErrorMessage( $error );

	$note[] = button( 'check' ) . '&nbsp;' . sprintf(
		( $is_add_mode ?
			_( 'Requests were added to %d students, %d requests in total.' ) :
			_( 'The requests of %d students were replaced, %d requests in total.' ) ),
		$students_updated,
		$requests_added
	);

	echo ErrorMessage( $note, 'note' );

	if ( $skipped )
	{
		PopTable( 'header', _( 'Students not updated' ) );

		_requestExpressRowsList( $skipped, false );

		PopTable( 'footer' );
	}

	echo '<div class="center"><br />' .
		button(
			'add',
			_( 'Import more requests' ),
			URLEscape( 'Modules.php?modname=' . $_REQUEST['modname'] )
		) .
		'</div>';

	return;
}

/**
 * Step 2 -- Review the changes
 */
if ( $modfunc === 'preview' )
{
	_requestExpressStepsDraw( 2 );

	echo ErrorMessage( $note, 'note' );

	$rows = RequestExpressRowsAnalyze( $session['ROWS'], $session['CLEAR_BLANKS'], $session['MODE'] );

	$updatable = 0;

	foreach ( $rows as $row )
	{
		if ( $row['STATUS'] === 'update' )
		{
			$updatable++;
		}
	}

	echo '<form action="' . URLEscape( 'Modules.php?modname=' . $_REQUEST['modname'] .
		'&modfunc=save' ) . '" method="POST">';

	if ( $is_add_mode )
	{
		echo ErrorMessage(
			[ _( 'Add only: the requests the students have are kept, only the ones in the file they do not have yet are added. No request is removed.' ) ],
			'note'
		);
	}
	else
	{
		echo ErrorMessage(
			[ _( 'Importing a student replaces their requests: the ones they have are removed, then the ones in the file are added.' ) ],
			'warning'
		);
	}

	if ( $session['CLEAR_BLANKS']
		&& ! $is_add_mode )
	{
		echo ErrorMessage(
			[ _( 'An empty requests cell will remove every request of the student.' ) ],
			'warning'
		);
	}

	PopTable( 'header', $is_add_mode ? _( 'Requests to Add' ) : _( 'Requests to Replace' ) );

	echo '<p class="center">' . sprintf(
		_( '%d students out of %d will be updated. Tick "Skip" on any you do not want.' ),
		$updatable,
		count( $rows )
	) . '</p>';

	_requestExpressRowsList( $rows, true );

	echo '<div class="center"><br />' .
		( $updatable ?
			Buttons( $is_add_mode ? _( 'Add Requests' ) : _( 'Replace Requests' ) ) :
			ErrorMessage( [ _( 'There is no student to update.' ) ], 'note' ) ) .
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
_requestExpressStepsDraw( 1 );

echo ErrorMessage( $error );

echo '<form action="' . URLEscape( 'Modules.php?modname=' . $_REQUEST['modname'] .
	'&modfunc=preview' ) . '" method="POST" enctype="multipart/form-data">';

PopTable( 'header', _( 'Requests File' ) );

echo '<p>' . sprintf(
	_( 'Set the Course Requests of the students of %s in bulk. The file must contain one student per line, with these columns:' ),
	'<b>' . RequestExpressHTML( ParseMLField( SchoolInfo( 'TITLE' ) ) ) . '</b>'
) . '</p>';

$fields_info = [
	'STUDENT' => [
		sprintf( _( '%s ID' ), Config( 'NAME' ) ),
		_( 'The ID of a student enrolled in this school' ),
	],
	'REQUESTS' => [
		_( 'Requests' ),
		_( 'The course numbers (short names) the student requests, separated by a space' ),
	],
];

$aliases = RequestExpressColumnAliases();

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

echo '<p>' . _( 'The first line can be a header row naming the columns; the names are not case sensitive. Without a header row, the columns are read in the order above.' ) . '</p>';

echo ErrorMessage(
	[ _( 'In Replace mode, importing a student replaces their requests: every request they have is removed, then the courses listed in the file are added. Re-importing a corrected file therefore leaves exactly what the file says. Choose Add only to never remove a request.' ) ],
	'warning'
);

echo '<p>' . _( 'Example:' ) . '</p>';

echo '<pre>' . RequestExpressHTML( _requestExpressSampleCSV() ) . '</pre>';

echo '<p>' . _( 'Quote the requests cell if your separator is the comma, so the course numbers stay in one column.' ) . '</p>';

echo '<br />' . FileInput( 'csv_file', _( 'CSV File' ) );

echo '<br /><br />' . FormatInputTitle( _( 'Or paste the requests here' ), 'csv_text' ) .
	'<br /><textarea id="csv_text" name="csv_text" rows="6" class="width-100p" ' .
	'placeholder="' . AttrEscape( '1000123,MAT101 SCI201 ENG101' ) . '"></textarea>';

$modes_html = [];

foreach ( RequestExpressModes() as $mode => $mode_label )
{
	$modes_html[] = '<label><input type="radio" name="mode" value="' . AttrEscape( $mode ) . '"' .
		( $session['MODE'] === $mode ? ' checked' : '' ) . ' /> ' .
		$mode_label . '</label>';
}

echo '<br /><fieldset><legend>' . _( 'Mode' ) . '</legend>' .
	implode( '<br />', $modes_html ) .
	'</fieldset>';

echo '<br /><div class="center">' .
	'<label><input type="checkbox" name="clear_blanks" value="Y"' .
		( $session['CLEAR_BLANKS'] ? ' checked' : '' ) . ' /> ' .
		_( 'An empty requests cell removes every request of the student' ) .
		' (' . _( 'Replace mode only' ) . ')</label>' .
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
function _requestExpressStepsDraw( $current )
{
	$steps = [
		1 => _( 'Requests File' ),
		2 => _( 'Changes' ),
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
function _requestExpressFileRead( $input, &$file_name, &$error )
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
 * Output the rows list, with the requests removed & added for each student
 *
 * Local function
 *
 * @param  array   $rows       Analyzed rows.
 * @param  boolean $checkboxes Add a checkbox for the students which will be updated.
 *
 * @return void
 */
function _requestExpressRowsList( $rows, $checkboxes )
{
	$rows_LO = [];

	foreach ( $rows as $i => $row )
	{
		$is_update = $row['STATUS'] === 'update';

		$changes = [];

		if ( $row['REMOVED'] )
		{
			$changes[] = '<b>' . _( 'Removed' ) . '</b>: ' .
				RequestExpressHTML( implode( ', ', $row['REMOVED'] ) );
		}

		if ( $row['ADDED'] )
		{
			$changes[] = '<b>' . _( 'Added' ) . '</b>: ' .
				RequestExpressHTML( implode( ', ', $row['ADDED'] ) );
		}

		if ( $row['KEPT'] )
		{
			$changes[] = _( 'Kept' ) . ': ' .
				RequestExpressHTML( implode( ', ', $row['KEPT'] ) );
		}

		$row_LO = [
			'LINE' => $row['LINE'],
			'STUDENT_ID' => RequestExpressHTML( $row['STUDENT_ID'] ),
			'STUDENT_NAME' => RequestExpressHTML( $row['STUDENT_NAME'] ),
			'REQUESTS' => RequestExpressHTML( $row['REQUESTS'] ),
			'CHANGES' => $changes ?
				implode( '<br />', $changes ) :
				RequestExpressHTML( $row['STATUS_TEXT'] ),
		];

		if ( $checkboxes )
		{
			$row_LO['SKIP'] = $is_update ?
				'<input type="checkbox" name="' . AttrEscape( 'skip_rows[' . $i . ']' ) .
					'" value="Y" />' :
				'-';
		}

		$rows_LO[] = $row_LO;
	}

	$columns = [
		'LINE' => _( 'Line' ),
		'STUDENT_ID' => sprintf( _( '%s ID' ), Config( 'NAME' ) ),
		'STUDENT_NAME' => _( 'Student' ),
		'REQUESTS' => _( 'Requests' ),
		'CHANGES' => _( 'Changes' ),
	];

	if ( $checkboxes )
	{
		$columns['SKIP'] = _( 'Skip' );
	}

	ListOutput(
		RequestExpressListIndex( $rows_LO ),
		$columns,
		'Student',
		'Students',
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
function _requestExpressSampleCSV()
{
	return sprintf( _( '%s ID' ), Config( 'NAME' ) ) . ',' . _( 'Requests' ) . "\n" .
		'1000123,"MAT101 SCI201 ENG101"' . "\n" .
		'1000124,"MAT102 SCI201"';
}
