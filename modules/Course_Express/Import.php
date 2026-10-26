<?php
/**
 * Course Express: Import Courses
 *
 * 4 steps wizard:
 * 1. Upload (or paste) the CSV list of courses.
 * 2. Check which Course Subjects already exist & choose the ones to create.
 * 3. Review the Courses which will be created.
 * 4. Create the Course Subjects & the Courses.
 *
 * @package RosarioSIS
 * @subpackage modules
 */

require_once 'modules/Course_Express/includes/CourseExpress.fnc.php';

DrawHeader( ProgramTitle() );

$error = $note = [];

if ( ! AllowEdit() )
{
	$error[] = _( 'You are not allowed to use this program.' );

	echo ErrorMessage( $error );

	return;
}

$modfunc = $_REQUEST['modfunc'];

$allowed_modfuncs = [ 'subjects', 'courses', 'create' ];

if ( ! in_array( $modfunc, $allowed_modfuncs, true ) )
{
	$modfunc = '';
}

/**
 * Step 1 -- Read the file (or the pasted text) & save the courses in the session
 */
if ( $modfunc === 'subjects' )
{
	$csv_text = $file_name = '';

	if ( ! empty( $_FILES['csv_file']['name'] ) )
	{
		$csv_text = _courseExpressFileRead( 'csv_file', $file_name, $error );
	}
	elseif ( ! empty( $_POST['csv_text'] ) )
	{
		// Get the text from $_POST: $_REQUEST is escaped for SQL in Warehouse.php.
		$csv_text = (string) $_POST['csv_text'];

		$file_name = _( 'Pasted courses' );
	}
	else
	{
		$error[] = _( 'Please attach a file.' );
	}

	if ( ! $error )
	{
		$messages = [];

		$rows = CourseExpressCSVParse( $csv_text, $messages );

		if ( ! $rows )
		{
			// Nothing could be read, the messages are errors.
			$error = array_merge( $error, $messages );
		}
		else
		{
			$note = array_merge( $note, $messages );

			CourseExpressSession( [
				'ROWS' => $rows,
				'FILE_NAME' => $file_name,
				'CREATE_SUBJECTS' => [],
			] );
		}
	}

	if ( $error )
	{
		// Back to step 1.
		$modfunc = '';
	}
}

$session = CourseExpressSession();

if ( $modfunc !== ''
	&& ! $session['ROWS'] )
{
	$error[] = _( 'Please start over: your courses are no longer available.' );

	$modfunc = '';
}

/**
 * Step 2 -- Save the Course Subjects the user chose to create
 */
if ( $modfunc === 'courses' )
{
	$subjects = CourseExpressSubjectsAnalyze( $session['ROWS'] );

	$checked = (array) issetVal( $_REQUEST['create_subjects'], [] );

	$create_subjects = [];

	foreach ( $subjects as $subject_key => $subject )
	{
		if ( ! $subject['SUBJECT_ID']
			&& ! empty( $checked[ $subject['KEY'] ] ) )
		{
			$create_subjects[ $subject_key ] = true;
		}
	}

	$session['CREATE_SUBJECTS'] = $create_subjects;

	CourseExpressSession( $session );
}

/**
 * Step 4 -- Create the Course Subjects & the Courses
 */
if ( $modfunc === 'create' )
{
	$subjects_created = $courses_created = 0;

	$subjects = CourseExpressSubjectsAnalyze( $session['ROWS'] );

	foreach ( $subjects as $subject_key => $subject )
	{
		if ( $subject['SUBJECT_ID']
			|| empty( $session['CREATE_SUBJECTS'][ $subject_key ] ) )
		{
			continue;
		}

		if ( CourseExpressSubjectCreate( $subject['NAME'] ) )
		{
			$subjects_created++;
		}
		else
		{
			$error[] = sprintf(
				_( 'The subject "%s" could not be created.' ),
				CourseExpressHTML( $subject['NAME'] )
			);
		}
	}

	// Analyze again: the new subjects now exist & their ID is known.
	$subjects = CourseExpressSubjectsAnalyze( $session['ROWS'] );

	$rows = CourseExpressRowsAnalyze(
		$session['ROWS'],
		$subjects,
		$session['CREATE_SUBJECTS']
	);

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

		$subject_id = $subjects[ $row['SUBJECT_KEY'] ]['SUBJECT_ID'];

		if ( CourseExpressCourseCreate( $subject_id, $row ) )
		{
			$courses_created++;
		}
		else
		{
			$row['STATUS'] = 'skipped';

			$row['STATUS_TEXT'] = _( 'The course could not be created.' );

			$skipped[] = $row;
		}
	}

	// Done: forget the file.
	CourseExpressSession( false );

	_courseExpressStepsDraw( 4 );

	echo ErrorMessage( $error );

	$note[] = button( 'check' ) . '&nbsp;' . sprintf(
		_( '%d subjects and %d courses were created.' ),
		$subjects_created,
		$courses_created
	);

	echo ErrorMessage( $note, 'note' );

	if ( $skipped )
	{
		PopTable( 'header', _( 'Courses not created' ) );

		_courseExpressCoursesList( $skipped, false );

		PopTable( 'footer' );
	}

	echo '<div class="center"><br />' .
		button(
			'add',
			_( 'Import more courses' ),
			URLEscape( 'Modules.php?modname=' . $_REQUEST['modname'] )
		) .
		'</div>';

	return;
}

/**
 * Step 3 -- Review the Courses which will be created
 */
if ( $modfunc === 'courses' )
{
	_courseExpressStepsDraw( 3 );

	echo ErrorMessage( $error );

	$subjects = CourseExpressSubjectsAnalyze( $session['ROWS'] );

	$rows = CourseExpressRowsAnalyze(
		$session['ROWS'],
		$subjects,
		$session['CREATE_SUBJECTS']
	);

	$new_subjects = [];

	foreach ( $subjects as $subject_key => $subject )
	{
		if ( ! $subject['SUBJECT_ID']
			&& ! empty( $session['CREATE_SUBJECTS'][ $subject_key ] ) )
		{
			$new_subjects[] = CourseExpressHTML( $subject['NAME'] );
		}
	}

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

	if ( $new_subjects )
	{
		$subjects_note = [ sprintf(
			_( 'These subjects will be created: %s' ),
			'<b>' . implode( '</b>, <b>', $new_subjects ) . '</b>'
		) ];

		echo ErrorMessage( $subjects_note, 'note' );
	}

	PopTable( 'header', _( 'Courses to Create' ) );

	echo '<p class="center">' . sprintf(
		_( '%d courses out of %d will be created. Tick "Skip" on any you do not want.' ),
		$creatable,
		count( $rows )
	) . '</p>';

	_courseExpressCoursesList( $rows, true );

	echo '<div class="center"><br />' .
		( $creatable ?
			Buttons( _( 'Create Courses' ) ) :
			ErrorMessage( [ _( 'There is no course to create.' ) ], 'note' ) ) .
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
 * Step 2 -- Which Course Subjects already exist?
 */
if ( $modfunc === 'subjects' )
{
	_courseExpressStepsDraw( 2 );

	echo ErrorMessage( $note, 'note' );

	$subjects = CourseExpressSubjectsAnalyze( $session['ROWS'] );

	$rows_in_error = [];

	foreach ( $session['ROWS'] as $row )
	{
		if ( $row['ERRORS'] )
		{
			$rows_in_error[] = $row;
		}
	}

	if ( $rows_in_error )
	{
		$rows_error = [];

		foreach ( $rows_in_error as $row )
		{
			$rows_error[] = sprintf( _( 'Line %d' ), $row['LINE'] ) . ': ' .
				CourseExpressHTML( implode( ' ', $row['ERRORS'] ) );
		}

		array_unshift( $rows_error, _( 'These lines will be ignored:' ) );

		echo ErrorMessage( $rows_error, 'warning' );
	}

	$new_subjects = 0;

	foreach ( $subjects as $subject )
	{
		if ( ! $subject['SUBJECT_ID'] )
		{
			$new_subjects++;
		}
	}

	echo '<form action="' . URLEscape( 'Modules.php?modname=' . $_REQUEST['modname'] .
		'&modfunc=courses' ) . '" method="POST">';

	PopTable( 'header', _( 'Course Subjects' ) );

	echo '<p class="center">' . ( $new_subjects ?
		sprintf(
			_( '%d of the %d subjects found in the file do not exist yet. Do you want to create them?' ),
			$new_subjects,
			count( $subjects )
		) :
		sprintf(
			_( 'The %d subjects found in the file already exist.' ),
			count( $subjects )
		) ) . '</p>';

	$subjects_LO = [];

	foreach ( $subjects as $subject )
	{
		$subjects_LO[] = [
			'SUBJECT' => CourseExpressHTML( $subject['NAME'] ),
			'COURSES' => $subject['COURSES'],
			'STATUS' => $subject['SUBJECT_ID'] ?
				_( 'Exists' ) :
				'<b>' . _( 'New' ) . '</b>',
			'CREATE' => $subject['SUBJECT_ID'] ?
				'-' :
				'<label><input type="checkbox" name="' .
					AttrEscape( 'create_subjects[' . $subject['KEY'] . ']' ) .
					'" value="Y" checked /> ' . _( 'Yes' ) . '</label>',
		];
	}

	ListOutput(
		CourseExpressListIndex( $subjects_LO ),
		[
			'SUBJECT' => _( 'Subject' ),
			'COURSES' => _( 'Courses' ),
			'STATUS' => _( 'Status' ),
			'CREATE' => _( 'Create' ),
		],
		'Subject',
		'Subjects',
		[],
		[],
		[ 'save' => false, 'search' => false, 'sort' => false, 'add' => false ]
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
_courseExpressStepsDraw( 1 );

echo ErrorMessage( $error );

echo '<form action="' . URLEscape( 'Modules.php?modname=' . $_REQUEST['modname'] .
	'&modfunc=subjects' ) . '" method="POST" enctype="multipart/form-data">';

PopTable( 'header', _( 'Courses File' ) );

echo '<p>' . sprintf(
	_( 'Add courses to %s in bulk. The file must contain one course per line, with these columns:' ),
	'<b>' . CourseExpressHTML( ParseMLField( SchoolInfo( 'TITLE' ) ) ) . '</b>'
) . '</p>';

$fields_info = [
	'SUBJECT' => [ _( 'Subject' ), _( 'Yes' ), _( 'Course Subject' ) ],
	'TITLE' => [ _( 'Course' ), _( 'Yes' ), _( 'Course Title' ) ],
	'SHORT_NAME' => [ _( 'Course Number' ), _( 'No' ), _( 'Course Short Name' ) ],
	'CREDIT_HOURS' => [ _( 'Credit Hours' ), _( 'No' ), _( 'Course Credit Hours' ) ],
];

$aliases = CourseExpressColumnAliases();

$columns_html = '';

$column_number = 0;

foreach ( $fields_info as $field => $info )
{
	$column_number++;

	$columns_html .= '<tr>
		<td>' . $column_number . '. ' . $info[0] . '</td>
		<td><code>' . implode( '</code>, <code>', $aliases[ $field ] ) . '</code></td>
		<td>' . $info[1] . '</td>
		<td>' . $info[2] . '</td>
	</tr>';
}

echo '<table class="widefat"><thead><tr>
		<th>' . _( 'Column' ) . '</th>
		<th>' . _( 'Header names accepted' ) . '</th>
		<th>' . _( 'Required' ) . '</th>
		<th>' . _( 'Saved as' ) . '</th>
	</tr></thead><tbody>' . $columns_html . '</tbody></table>';

echo '<p>' . _( 'The first line can be a header row naming the columns; the names are not case sensitive. Without a header row, the columns are read in the order above.' ) .
	' ' . _( 'The comma, the semicolon, the tabulation and the pipe are accepted as separator.' ) . '</p>';

echo '<p>' . _( 'Example:' ) . '</p>';

echo '<pre>' . CourseExpressHTML( _courseExpressSampleCSV() ) . '</pre>';

echo '<br />' . FileInput( 'csv_file', _( 'CSV File' ) );

echo '<br /><br />' . FormatInputTitle( _( 'Or paste the courses here' ), 'csv_text' ) .
	'<br /><textarea id="csv_text" name="csv_text" rows="6" class="width-100p" ' .
	'placeholder="' . AttrEscape( 'Mathematics,Algebra I,MAT101,1' ) . '"></textarea>';

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
function _courseExpressStepsDraw( $current )
{
	$steps = [
		1 => _( 'Courses File' ),
		2 => _( 'Subjects' ),
		3 => _( 'Courses' ),
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
function _courseExpressFileRead( $input, &$file_name, &$error )
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
 * Output the Courses list
 *
 * Local function
 *
 * @param  array   $rows       Analyzed rows.
 * @param  boolean $checkboxes Add a checkbox to leave a course out.
 *
 * @return void
 */
function _courseExpressCoursesList( $rows, $checkboxes )
{
	$courses_LO = [];

	foreach ( $rows as $i => $row )
	{
		$is_new = $row['STATUS'] === 'new';

		$course = [
			'LINE' => $row['LINE'],
			'SUBJECT' => CourseExpressHTML( $row['SUBJECT'] ),
			'TITLE' => CourseExpressHTML( $row['TITLE'] ),
			'SHORT_NAME' => CourseExpressHTML( $row['SHORT_NAME'] ),
			'CREDIT_HOURS' => CourseExpressHTML( $row['CREDIT_HOURS'] ),
			'STATUS' => $is_new ?
				'<b>' . _( 'Create' ) . '</b>' :
				CourseExpressHTML( $row['STATUS_TEXT'] ),
		];

		if ( $checkboxes )
		{
			$course['SKIP'] = $is_new ?
				'<input type="checkbox" name="' . AttrEscape( 'skip_rows[' . $i . ']' ) .
					'" value="Y" />' :
				'-';
		}

		$courses_LO[] = $course;
	}

	$columns = [
		'LINE' => _( 'Line' ),
		'SUBJECT' => _( 'Subject' ),
		'TITLE' => _( 'Course' ),
		'SHORT_NAME' => _( 'Course Number' ),
		'CREDIT_HOURS' => _( 'Credit Hours' ),
		'STATUS' => _( 'Status' ),
	];

	if ( $checkboxes )
	{
		$columns['SKIP'] = _( 'Skip' );
	}

	ListOutput(
		CourseExpressListIndex( $courses_LO ),
		$columns,
		'Course',
		'Courses',
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
function _courseExpressSampleCSV()
{
	// Not translated: CourseExpressHeaderMap() recognizes the English column names.
	return "Subject,Course,Course Number,Credit Hours\n" .
		"Mathematics,Algebra I,MAT101,1\n" .
		"Mathematics,Geometry,MAT102,1\n" .
		"Science,Biology,SCI201,0.5";
}
