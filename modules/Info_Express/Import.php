<?php
/**
 * Info Express: Update Student Info
 *
 * 4 steps wizard:
 * 1. Upload (or paste) the CSV.
 * 2. Map each CSV column to a Student field. One column must hold the Student ID.
 * 3. Review the changes, student by student.
 * 4. Update the Students.
 *
 * @package RosarioSIS
 * @subpackage modules
 */

require_once 'modules/Info_Express/includes/InfoExpress.fnc.php';

DrawHeader( ProgramTitle() );

$error = $note = [];

if ( ! AllowEdit() )
{
	$error[] = _( 'You are not allowed to use this program.' );

	echo ErrorMessage( $error );

	return;
}

$modfunc = $_REQUEST['modfunc'];

if ( ! in_array( $modfunc, [ 'upload', 'map', 'preview', 'save' ], true ) )
{
	$modfunc = '';
}

/**
 * Step 1 -- Read the file (or the pasted text) & save the rows in the session
 */
if ( $modfunc === 'upload' )
{
	$csv_text = $file_name = '';

	if ( ! empty( $_FILES['csv_file']['name'] ) )
	{
		$csv_text = _infoExpressFileRead( 'csv_file', $file_name, $error );
	}
	elseif ( ! empty( $_POST['csv_text'] ) )
	{
		// Get the text from $_POST: $_REQUEST is escaped for SQL in Warehouse.php.
		$csv_text = (string) $_POST['csv_text'];

		$file_name = _( 'Pasted rows' );
	}
	else
	{
		$error[] = _( 'Please attach a file.' );
	}

	if ( ! $error )
	{
		$messages = [];

		$csv = InfoExpressCSVParse( $csv_text, $messages );

		if ( ! $csv )
		{
			$error = array_merge( $error, $messages );
		}
		else
		{
			$note = array_merge( $note, $messages );

			InfoExpressSession( [
				'HEADERS' => $csv['HEADERS'],
				'ROWS' => $csv['ROWS'],
				'FILE_NAME' => $file_name,
				// Guessed by InfoExpressFirstRowIsHeader(), the user can correct it.
				'FIRST_ROW_HEADER' => $csv['FIRST_ROW_HEADER'],
				'MAP' => InfoExpressMapGuess( $csv['HEADERS'] ),
				'CLEAR_BLANKS' => false,
			] );
		}
	}

	// Back to step 1 on error, else move on to the column mapping.
	$modfunc = $error ? '' : 'map';
}

$session = InfoExpressSession();

if ( $modfunc !== ''
	&& ! $session['ROWS'] )
{
	$error[] = _( 'Please start over: your rows are no longer available.' );

	$modfunc = '';
}

/**
 * Step 2 -- Save the column mapping chosen by the user
 */
if ( $modfunc === 'preview' )
{
	$fields = InfoExpressFields();

	$requested_map = (array) issetVal( $_REQUEST['map'], [] );

	$map = [];

	$used = [];

	foreach ( $session['HEADERS'] as $index => $header )
	{
		$field_id = (string) issetVal( $requested_map[ $index ], INFO_EXPRESS_IGNORE );

		if ( $field_id === INFO_EXPRESS_IGNORE
			|| ! isset( $fields[ $field_id ] ) )
		{
			continue;
		}

		if ( isset( $used[ $field_id ] ) )
		{
			$error[] = sprintf(
				_( 'The "%s" field is mapped to more than one column.' ),
				InfoExpressHTML( $fields[ $field_id ]['TITLE'] )
			);

			continue;
		}

		$map[ $index ] = $field_id;

		$used[ $field_id ] = true;
	}

	if ( ! isset( $used['STUDENT_ID'] ) )
	{
		$error[] = sprintf(
			_( 'One column must hold the %s ID.' ),
			Config( 'NAME' )
		);
	}

	if ( count( $map ) < 2 )
	{
		$error[] = _( 'Please map at least one column to a field to update.' );
	}

	$session['MAP'] = $map;

	$session['FIRST_ROW_HEADER'] = ! empty( $_REQUEST['first_row_header'] );

	$session['CLEAR_BLANKS'] = ! empty( $_REQUEST['clear_blanks'] );

	InfoExpressSession( $session );

	if ( $error )
	{
		// Back to step 2.
		$modfunc = 'map';
	}
}

/**
 * Step 4 -- Update the Students
 */
if ( $modfunc === 'save' )
{
	$rows = InfoExpressRowsAnalyze(
		InfoExpressDataRows( $session ),
		$session['MAP'],
		$session['CLEAR_BLANKS']
	);

	// The rows to leave out are posted, not the rows to apply. A long file
	// cannot post a ticked box per row: PHP stops reading at max_input_vars,
	// 1000 by default, and ListOutput() only ever draws the first 1000 rows
	// anyway -- so everything past row 1000 had no box to tick and would have
	// been dropped. Posting only the exceptions keeps the form small however
	// long the file is.
	$skip_rows = (array) issetVal( $_REQUEST['skip_rows'], [] );

	if ( InfoExpressInputTruncated() )
	{
		// Some of the ticks were lost on the way in: applying now would apply
		// rows the user asked to leave out.
		echo ErrorMessage( [ _( 'Too many rows were skipped at once for the server to read them all. Please leave fewer rows out, or take them out of the file, and try again.' ) ] );

		return;
	}

	$students_updated = $fields_updated = 0;

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

		if ( InfoExpressRowApply( $row ) )
		{
			$students_updated++;

			$fields_updated += count( $row['CHANGES'] );
		}
		else
		{
			$row['STATUS'] = 'skipped';

			$row['STATUS_TEXT'] = _( 'The student could not be updated.' );

			$skipped[] = $row;
		}
	}

	// Done: forget the file.
	InfoExpressSession( false );

	_infoExpressStepsDraw( 4 );

	echo ErrorMessage( $error );

	$note[] = button( 'check' ) . '&nbsp;' . sprintf(
		_( '%d students were updated, %d fields in total.' ),
		$students_updated,
		$fields_updated
	);

	echo ErrorMessage( $note, 'note' );

	if ( $skipped )
	{
		PopTable( 'header', _( 'Students not updated' ) );

		_infoExpressRowsList( $skipped, false );

		PopTable( 'footer' );
	}

	echo '<div class="center"><br />' .
		button(
			'add',
			_( 'Update more students' ),
			URLEscape( 'Modules.php?modname=' . $_REQUEST['modname'] )
		) .
		'</div>';

	return;
}

/**
 * Step 3 -- Review the changes
 */
if ( $modfunc === 'preview' )
{
	_infoExpressStepsDraw( 3 );

	$rows = InfoExpressRowsAnalyze(
		InfoExpressDataRows( $session ),
		$session['MAP'],
		$session['CLEAR_BLANKS']
	);

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

	if ( $session['CLEAR_BLANKS'] )
	{
		echo ErrorMessage(
			[ _( 'Empty cells will empty the field.' ) ],
			'warning'
		);
	}

	PopTable( 'header', _( 'Changes to Apply' ) );

	echo '<p class="center">' . sprintf(
		_( '%d students out of %d will be updated. Tick "Skip" on any you do not want.' ),
		$updatable,
		count( $rows )
	) . '</p>';

	_infoExpressRowsList( $rows, true );

	echo '<div class="center"><br />' .
		( $updatable ?
			Buttons( _( 'Update Students' ) ) :
			ErrorMessage( [ _( 'There is no student to update.' ) ], 'note' ) ) .
		'</div>';

	PopTable( 'footer' );

	echo '</form>';

	echo '<div class="center">' .
		button(
			'back',
			_( 'Back to the columns' ),
			URLEscape( 'Modules.php?modname=' . $_REQUEST['modname'] . '&modfunc=map' )
		) .
		'&nbsp;&nbsp;' .
		button(
			'back',
			_( 'Start over' ),
			URLEscape( 'Modules.php?modname=' . $_REQUEST['modname'] )
		) .
		'</div>';

	return;
}

/**
 * Step 2 -- Map the columns to the Student fields
 */
if ( $modfunc === 'map' )
{
	_infoExpressStepsDraw( 2 );

	echo ErrorMessage( $error );

	echo ErrorMessage( $note, 'note' );

	echo '<form action="' . URLEscape( 'Modules.php?modname=' . $_REQUEST['modname'] .
		'&modfunc=preview' ) . '" method="POST">';

	PopTable( 'header', _( 'Columns' ) );

	echo '<p class="center">' . sprintf(
		_( 'Tell what each column of "%s" holds. One column must hold the %s ID, which is used to find the student; the other columns can be mapped to any student field, or left ignored.' ),
		InfoExpressHTML( $session['FILE_NAME'] ),
		Config( 'NAME' )
	) . '</p>';

	if ( ! $session['MAP'] )
	{
		// Nothing was guessed: say so, rather than letting the user submit an empty mapping.
		echo ErrorMessage(
			[ _( 'None of the column names were recognized: please tell what each column holds below.' ) ],
			'warning'
		);
	}

	// Show the first row, so it is clear which one the checkbox skips.
	$first_row_preview = implode( ', ', array_slice( array_filter(
		(array) issetVal( $session['ROWS'][0]['CELLS'], [] ),
		function ( $cell ) { return $cell !== ''; }
	), 0, 4 ) );

	echo '<div class="center">' .
		'<label><input type="checkbox" name="first_row_header" value="Y"' .
			( $session['FIRST_ROW_HEADER'] ? ' checked' : '' ) . ' /> ' .
			sprintf(
				_( 'The first row holds the column names (%s)' ),
				InfoExpressHTML( mb_substr( $first_row_preview, 0, 60 ) )
			) . '</label>' .
		'&nbsp;&nbsp;&nbsp;' .
		'<label><input type="checkbox" name="clear_blanks" value="Y"' .
			( $session['CLEAR_BLANKS'] ? ' checked' : '' ) . ' /> ' .
			_( 'Empty cells empty the field' ) . '</label>' .
		'</div><br />';

	// Up to 3 sample values per column, taken from the data rows.
	$samples = [];

	$sample_rows = array_slice( InfoExpressDataRows( $session ), 0, 3 );

	$columns_LO = [];

	foreach ( $session['HEADERS'] as $index => $header )
	{
		$sample = [];

		foreach ( $sample_rows as $sample_row )
		{
			$value = issetVal( $sample_row['CELLS'][ $index ], '' );

			if ( $value !== '' )
			{
				$sample[] = InfoExpressHTML( mb_substr( $value, 0, 40 ) );
			}
		}

		$columns_LO[] = [
			'COLUMN' => sprintf( _( 'Column %d' ), $index + 1 ),
			'HEADER' => InfoExpressHTML( $header ),
			'SAMPLE' => $sample ? implode( ', ', $sample ) : '-',
			'FIELD' => _infoExpressFieldSelect(
				$index,
				issetVal( $session['MAP'][ $index ], INFO_EXPRESS_IGNORE )
			),
		];
	}

	ListOutput(
		InfoExpressListIndex( $columns_LO ),
		[
			'COLUMN' => _( 'Column' ),
			'HEADER' => _( 'Name' ),
			'SAMPLE' => _( 'First values' ),
			'FIELD' => _( 'Student Field' ),
		],
		'Column',
		'Columns',
		[],
		[],
		[
			'save' => false,
			'search' => false,
			'sort' => false,
			'add' => false,
			'valign-middle' => true,
		]
	);

	echo '<div class="center"><br />' . Buttons( _( 'Next' ) ) . '</div>';

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
_infoExpressStepsDraw( 1 );

echo ErrorMessage( $error );

echo '<form action="' . URLEscape( 'Modules.php?modname=' . $_REQUEST['modname'] .
	'&modfunc=upload' ) . '" method="POST" enctype="multipart/form-data">';

PopTable( 'header', _( 'Student Info File' ) );

echo '<p>' . sprintf(
	_( 'Update the info of the students of %s in bulk. The file must contain one student per line, and one column holding the %s ID. Every other column can be mapped, on the next screen, to any student field.' ),
	'<b>' . InfoExpressHTML( ParseMLField( SchoolInfo( 'TITLE' ) ) ) . '</b>',
	Config( 'NAME' )
) . '</p>';

echo '<p>' . _( 'The first line usually holds the column names; they are matched with the student fields to pre-fill the mapping. The comma, the semicolon, the tabulation and the pipe are accepted as separator.' ) . '</p>';

echo '<p>' . _( 'Example:' ) . '</p>';

echo '<pre>' . InfoExpressHTML( _infoExpressSampleCSV() ) . '</pre>';

echo '<br />' . FileInput( 'csv_file', _( 'CSV File' ) );

echo '<br /><br />' . FormatInputTitle( _( 'Or paste the rows here' ), 'csv_text' ) .
	'<br /><textarea id="csv_text" name="csv_text" rows="6" class="width-100p" ' .
	'placeholder="' . AttrEscape( '1000123,Ana,Ramirez,2011-04-18' ) . '"></textarea>';

echo '<div class="center"><br />' . Buttons( _( 'Next' ) ) . '</div>';

PopTable( 'footer' );

echo '</form>';

/**
 * Draw the wizard steps
 *
 * Local function
 *
 * @param  int $current Current step, 1 to 4.
 *
 * @return void
 */
function _infoExpressStepsDraw( $current )
{
	$steps = [
		1 => _( 'Student Info File' ),
		2 => _( 'Columns' ),
		3 => _( 'Changes' ),
		4 => _( 'Done' ),
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
function _infoExpressFileRead( $input, &$file_name, &$error )
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
 * Pull-down listing every mappable Student field, grouped
 *
 * Local function
 *
 * @param  int    $index    CSV column index.
 * @param  string $selected Selected field ID.
 *
 * @return string Select input HTML.
 */
function _infoExpressFieldSelect( $index, $selected )
{
	$fields = InfoExpressFields();

	$html = '<select name="' . AttrEscape( 'map[' . (int) $index . ']' ) . '">';

	$html .= '<option value="">' . _( 'Do not import' ) . '</option>';

	$group = '';

	foreach ( $fields as $field_id => $field )
	{
		if ( $field['GROUP'] !== $group )
		{
			if ( $group !== '' )
			{
				$html .= '</optgroup>';
			}

			$group = $field['GROUP'];

			$html .= '<optgroup label="' . AttrEscape( $group ) . '">';
		}

		$html .= '<option value="' . AttrEscape( $field_id ) . '"' .
			( $field_id === $selected ? ' selected' : '' ) . '>' .
			InfoExpressHTML( $field['TITLE'] ) . '</option>';
	}

	if ( $group !== '' )
	{
		$html .= '</optgroup>';
	}

	return $html . '</select>';
}

/**
 * Output the rows list, with the changes of each student
 *
 * Local function
 *
 * @param  array   $rows       Analyzed rows.
 * @param  boolean $checkboxes Add a checkbox for the students which will be updated.
 *
 * @return void
 */
function _infoExpressRowsList( $rows, $checkboxes )
{
	$fields = InfoExpressFields();

	$rows_LO = [];

	foreach ( $rows as $i => $row )
	{
		$is_update = $row['STATUS'] === 'update';

		$changes = [];

		foreach ( (array) $row['CHANGES'] as $field_id => $change )
		{
			$changes[] = '<b>' . InfoExpressHTML( $fields[ $field_id ]['TITLE'] ) . '</b>: ' .
				InfoExpressValueDisplay( $field_id, $change['OLD'] ) . ' &rarr; ' .
				InfoExpressValueDisplay( $field_id, $change['NEW'] );
		}

		$row_LO = [
			'LINE' => $row['LINE'],
			'STUDENT_ID' => InfoExpressHTML( $row['STUDENT_ID'] ),
			'STUDENT_NAME' => InfoExpressHTML( $row['STUDENT_NAME'] ),
			'CHANGES' => $changes ?
				implode( '<br />', $changes ) :
				InfoExpressHTML( $row['STATUS_TEXT'] ),
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
		'CHANGES' => _( 'Changes' ),
	];

	if ( $checkboxes )
	{
		$columns['SKIP'] = _( 'Skip' );
	}

	ListOutput(
		InfoExpressListIndex( $rows_LO ),
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
function _infoExpressSampleCSV()
{
	return sprintf( _( '%s ID' ), Config( 'NAME' ) ) . ',' . _( 'First Name' ) . ',' .
		_( 'Last Name' ) . ',' . _( 'Grade Level' ) . ',' . _( 'Birthdate' ) . "\n" .
		'1000123,Ana,Ramirez,10,2011-04-18' . "\n" .
		'1000124,Bilal,Haddad,11,2010-09-02';
}
