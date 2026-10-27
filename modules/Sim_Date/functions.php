<?php
/**
 * Sim Date module functions
 *
 * Loaded on every request while the module is active.
 * The sim date itself lives in core: SimDate() in functions/Date.php (patched).
 *
 * @see docs/sim-date.md
 *
 * @package RosarioSIS
 * @subpackage modules
 */

add_action( 'Warehouse.php|header_head', 'SimDateHeaderHead' );

add_action( 'Warehouse.php|footer', 'SimDateFooter' );

SimDateSyncSession();

/**
 * Sim date, or empty string when off (using the real date).
 *
 * @return string Sim date or ''.
 */
function SimDateValue()
{
	if ( ! function_exists( 'SimDateIsValid' ) )
	{
		// Core patch missing: the sim date has no effect.
		return '';
	}

	$sim_date = (string) Config( 'SIM_DATE' );

	return SimDateIsValid( $sim_date ) ? $sim_date : '';
}

/**
 * Move the user's current marking period along when the sim date moved.
 *
 * RosarioSIS only picks the current marking period at login or when the
 * school / school year is changed. Without this, users logged in while an admin
 * moves the sim date would stay in the old marking period.
 * A marking period the user picked themselves is kept until the date moves again.
 */
function SimDateSyncSession()
{
	if ( empty( $_SESSION['UserSchool'] )
		|| empty( $_SESSION['UserSyear'] )
		|| ! function_exists( 'SimDate' ) )
	{
		return;
	}

	$today = DBDate();

	if ( isset( $_SESSION['SimDateSeen'] )
		&& $_SESSION['SimDateSeen'] !== $today )
	{
		$mp_id = GetCurrentMP( 'QTR', $today, false );

		if ( $mp_id )
		{
			$_SESSION['UserMP'] = $mp_id;
		}
	}

	$_SESSION['SimDateSeen'] = $today;
}

/**
 * Sim date for the browser: meta tag & date picker script.
 * No inline script: RosarioSIS' Content Security Policy forbids it.
 *
 * @uses Warehouse.php|header_head action hook
 */
function SimDateHeaderHead()
{
	?>
	<meta name="sim-date" content="<?php echo AttrEscape( SimDateValue() ); ?>">
	<script src="modules/Sim_Date/js/SimDate.js?v=1.1"></script>
	<?php
}

/**
 * Sim date for the browser, refreshed on every (AJAX) page.
 * The meta tag in the head is only output on full page loads.
 * data-today: the school "today" (sim or real date), for the left menu date.
 *
 * @uses Warehouse.php|footer action hook
 */
function SimDateFooter()
{
	?>
	<input type="hidden" disabled id="sim_date" data-value="<?php echo AttrEscape( SimDateValue() ); ?>" data-today="<?php echo AttrEscape( DBDate() ); ?>" />
	<?php
}
