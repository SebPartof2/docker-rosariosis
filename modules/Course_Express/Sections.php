<?php
/**
 * Course Express: Import Sections
 *
 * 3 steps wizard:
 * 1. Upload (or paste) the CSV list of sections.
 * 2. Review the sections which will be created, with the settings each one gets.
 * 3. Create the sections (Course Periods).
 *
 * The Section Defaults must have been saved before sections can be imported.
 *
 * @package RosarioSIS
 * @subpackage modules
 */

require_once 'modules/Scheduling/includes/Courses.fnc.php';
require_once 'modules/Course_Express/includes/Sections.fnc.php';

DrawHeader( ProgramTitle() );

$error = $note = [];

if ( ! AllowEdit() )
{
	$error[] = _( 'You are not allowed to use this program.' );

	echo ErrorMessage( $error );

	return;
}

if ( ! CourseExpressDefaultsAreSet() )
{
	echo ErrorMessage(
		[ _( 'Please set the default settings every section gets before importing sections.' ) ],
		'warning'
	);

	echo '<div class="center"><br />' .
		button(
			'settings',
			_( 'Section Defaults' ),
			URLEscape( 'Modules.php?modname=Course_Express/Defaults.php' )
		) .
		'</div>';

	return;
}

$modfunc = $_REQUEST['modfunc'];

if ( ! in_array( $modfunc, [ 'preview', 'create' ], true ) )
{
	$modfunc = '';
}

/**
 * Step 1 -- Read the file (or the pasted text) & save the sections in the session
 */
if ( $modfunc === 'preview' )
{
	$csv_text = $file_name = '';

	if ( ! empty( $_FILES['csv_file']['name'] ) )
	{
		$csv_text = _courseExpressSectionsFileRead( 'csv_file', $file_name, $error );
	}
	elseif ( ! empty( $_POST['csv_text'] ) )
	{
		// Get the text from $_POST: $_REQUEST is escaped for SQL in Warehouse.php.
		$csv_text = (string) $_POST['csv_text'];

		$file_name = _( 'Pasted sections' );
	}
	elseif ( ! CourseExpressSectionsSession()['ROWS'] )
	{
		$error[] = _( 'Please attach a file.' );
	}

	if ( ! $error
		&& $csv_text !== '' )
	{
		$messages = [];

		$rows = CourseExpressSectionsCSVParse( $csv_text, $messages );

		if ( ! $rows )
		{
			$error = array_merge( $error, $messages );
		}
		else
		{
			$note = array_merge( $note, $messages );

			CourseExpressSectionsSession( [ 'ROWS' => $rows, 'FILE_NAME' => $file_name ] );
		}
	}

	if ( $error )
	{
		// Back to step 1.
		$modfunc = '';
	}
}

$session = CourseExpressSectionsSession();

if ( $modfunc !== ''
	&& ! $session['ROWS'] )
{
	$error[] = _( 'Please start over: your sections are no longer available.' );

	$modfunc = '';
}

/**
 * Step 3 -- Create the sections
 */
if ( $modfunc === 'create' )
{
	$rows = CourseExpressSectionsAnalyze( $session['ROWS'] );

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

	$created = 0;

	$skipped = [];

	foreach ( $rows as $i => $row )
	{
		if ( $row['STATUS'] !== 'new' )
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

		if ( CourseExpressSectionCreate( $row ) )
		{
			$created++;
		}
		else
		{
			$row['STATUS'] = 'skipped';

			$row['STATUS_TEXT'] = _( 'The section could not be created.' );

			$skipped[] = $row;
		}
	}

	// Done: forget the file.
	CourseExpressSectionsSession( false );

	_courseExpressSectionsStepsDraw( 3 );

	echo ErrorMessage( $error );

	$note[] = button( 'check' ) . '&nbsp;' . sprintf(
		_( '%d sections were created.' ),
		$created
	);

	echo ErrorMessage( $note, 'note' );

	if ( $skipped )
	{
		PopTable( 'header', _( 'Sections not created' ) );

		_courseExpressSectionsList( $skipped, false );

		PopTable( 'footer' );
	}

	echo '<div class="center"><br />' .
		button(
			'add',
			_( 'Import more sections' ),
			URLEscape( 'Modules.php?modname=' . $_REQUEST['modname'] )
		) .
		'</div>';

	return;
}

/**
 * Step 2 -- Review the sections which will be created
 */
if ( $modfunc === 'preview' )
{
	_courseExpressSectionsStepsDraw( 2 );

	echo ErrorMessage( $note, 'note' );

	$rows = CourseExpressSectionsAnalyze( $session['ROWS'] );

	$creatable = 0;

	foreach ( $rows as $row )
	{
		if ( $row['STATUS'] === 'new' )
		{
			$creatable++;
		}
	}

	echo '<form action="' . URLEscape( 'Modules.php?modname=' . $_REQUEST['modname'] .
		'&modfunc=create' ) . '" method="POST">';

	PopTable( 'header', _( 'Sections to Create' ) );

	echo '<p class="center">' . sprintf(
		_( '%d sections out of %d will be created. Tick "Skip" on any you do not want.' ),
		$creatable,
		count( $rows )
	) . '</p>';

	_courseExpressSectionsList( $rows, true );

	echo '<div class="center"><br />' .
		( $creatable ?
			Buttons( _( 'Create Sections' ) ) :
			ErrorMessage( [ _( 'There is no section to create.' ) ], 'note' ) ) .
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
_courseExpressSectionsStepsDraw( 1 );

echo ErrorMessage( $error );

echo '<form action="' . URLEscape( 'Modules.php?modname=' . $_REQUEST['modname'] .
	'&modfunc=preview' ) . '" method="POST" enctype="multipart/form-data">';

PopTable( 'header', _( 'Sections File' ) );

echo '<p>' . sprintf(
	_( 'Create sections for the courses of %s in bulk. The file must contain one section per line, with these columns:' ),
	'<b>' . CourseExpressHTML( ParseMLField( SchoolInfo( 'TITLE' ) ) ) . '</b>'
) . '</p>';

$fields_info = [
	'COURSE' => [ _( 'Course Number' ), _( 'The short name of an existing course' ) ],
	'PERIOD' => [ _( 'Period' ), _( 'A school period name, short name or ID' ) ],
	'MARKING_PERIOD' => [ _( 'Marking Period' ), _( 'A marking period name, short name or ID' ) ],
	'TEACHER' => [ _( 'Teacher' ), _( 'A teacher ID, username or full name' ) ],
];

$aliases = CourseExpressSectionAliases();

$columns_html = '';

$column_number = 0;

foreach ( $fields_info as $field => $info )
{
	$column_number++;

	$columns_html .= '<tr>
		<td>' . $column_number . '. ' . $info[0] . '</td>
		<td><code>' . implode( '</code>, <code>', $aliases[ $field ] ) . '</code></td>
		<td>' . $info[1] . '</td>
	</tr>';
}

echo '<table class="widefat"><thead><tr>
		<th>' . _( 'Column' ) . '</th>
		<th>' . _( 'Header names accepted' ) . '</th>
		<th>' . _( 'Matched with' ) . '</th>
	</tr></thead><tbody>' . $columns_html . '</tbody></table>';

echo '<p>' . _( 'Every column is required. The first line can be a header row naming the columns; the names are not case sensitive. Without a header row, the columns are read in the order above.' ) . '</p>';

echo '<p>' . _( 'Example:' ) . '</p>';

echo '<pre>' . CourseExpressHTML( _courseExpressSectionsSampleCSV() ) . '</pre>';

echo '<br />' . FileInput( 'csv_file', _( 'CSV File' ) );

echo '<br /><br />' . FormatInputTitle( _( 'Or paste the sections here' ), 'csv_text' ) .
	'<br /><textarea id="csv_text" name="csv_text" rows="6" class="width-100p" ' .
	'placeholder="' . AttrEscape( 'MAT101,1,FY,1001' ) . '"></textarea>';

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
function _courseExpressSectionsStepsDraw( $current )
{
	$steps = [
		1 => _( 'Sections File' ),
		2 => _( 'Sections' ),
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
function _courseExpressSectionsFileRead( $input, &$file_name, &$error )
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
 * Output the sections list
 *
 * Local function
 *
 * @param  array   $rows       Analyzed rows.
 * @param  boolean $checkboxes Add a checkbox to leave a section out.
 *
 * @return void
 */
function _courseExpressSectionsList( $rows, $checkboxes )
{
	$settings = CourseExpressSettings();

	$periods = CourseExpressPeriodsLookup();

	$marking_periods = CourseExpressMarkingPeriodsLookup();

	$teachers = CourseExpressTeachersLookup();

	$sections_LO = [];

	foreach ( $rows as $i => $row )
	{
		$is_new = $row['STATUS'] === 'new';

		$section_settings = [];

		if ( $is_new )
		{
			foreach ( $settings as $setting => $definition )
			{
				$section_settings[] = '<b>' . CourseExpressHTML( $definition['TITLE'] ) . '</b>: ' .
					CourseExpressSettingDisplay( $setting, $row['SETTINGS'][ $setting ] );
			}

			if ( $row['SETS'] )
			{
				$section_settings[] = '<i>' . sprintf(
					_( 'Overridden by: %s' ),
					CourseExpressHTML( implode( ', ', $row['SETS'] ) )
				) . '</i>';
			}
		}

		$section = [
			'LINE' => $row['LINE'],
			'COURSE' => CourseExpressHTML( $row['COURSE_TITLE'] ?
				$row['COURSE_TITLE'] . ' (' . $row['COURSE'] . ')' :
				$row['COURSE'] ),
			'SHORT_NAME' => CourseExpressHTML( $row['SHORT_NAME'] ),
			'PERIOD' => CourseExpressHTML( $row['PERIOD_ID'] ?
				issetVal( $periods['LABELS'][ $row['PERIOD_ID'] ], $row['PERIOD'] ) :
				$row['PERIOD'] ),
			'MARKING_PERIOD' => CourseExpressHTML( $row['MARKING_PERIOD_ID'] ?
				issetVal( $marking_periods['LABELS'][ $row['MARKING_PERIOD_ID'] ], $row['MARKING_PERIOD'] ) :
				$row['MARKING_PERIOD'] ),
			'TEACHER' => CourseExpressHTML( $row['TEACHER_ID'] ?
				issetVal( $teachers['LABELS'][ $row['TEACHER_ID'] ], $row['TEACHER'] ) :
				$row['TEACHER'] ),
			'CREDITS' => CourseExpressHTML( $row['CREDITS'] ),
			'SETTINGS' => $section_settings ?
				implode( '<br />', $section_settings ) :
				CourseExpressHTML( $row['STATUS_TEXT'] ),
		];

		if ( $checkboxes )
		{
			$section['SKIP'] = $is_new ?
				'<input type="checkbox" name="' . AttrEscape( 'skip_rows[' . $i . ']' ) .
					'" value="Y" />' :
				'-';
		}

		$sections_LO[] = $section;
	}

	$columns = [
		'LINE' => _( 'Line' ),
		'COURSE' => _( 'Course' ),
		'SHORT_NAME' => _( 'Short Name' ),
		'PERIOD' => _( 'Period' ),
		'MARKING_PERIOD' => _( 'Marking Period' ),
		'TEACHER' => _( 'Teacher' ),
		'CREDITS' => _( 'Credits' ),
		'SETTINGS' => _( 'Settings' ),
	];

	if ( $checkboxes )
	{
		$columns['SKIP'] = _( 'Skip' );
	}

	ListOutput(
		CourseExpressListIndex( $sections_LO ),
		$columns,
		'Section',
		'Sections',
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
function _courseExpressSectionsSampleCSV()
{
	// Not translated: CourseExpressHeaderMapFields() recognizes the English column names.
	return "Course Number,Period,Marking Period,Teacher ID\n" .
		"MAT101,1,Full Year,1001\n" .
		"MAT101,2,Full Year,1001\n" .
		"SCI201,3,Semester 1,1002";
}
