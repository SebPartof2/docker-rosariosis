<?php
/**
 * The base configurations of RosarioSIS
 *
 * You can find more information in the INSTALL.md file
 *
 * @package RosarioSIS
 */

/**
 * Database Settings
 *
 * You can get this info from your web host
 */

// Database type: postgresql or mysql.
$DatabaseType = getenv( 'DBTYPE' );

// Database server hostname: use localhost if on same server.
$DatabaseServer = getenv( 'PGHOST' );

// Database username.
$DatabaseUsername = getenv( 'PGUSER' );

// Database password.
$DatabasePassword = getenv( 'PGPASSWORD' );

// Database name.
$DatabaseName = getenv( 'PGDATABASE' );

// Database port: default is 5432.
$DatabasePort = getenv( 'PGPORT' );


/**
 * Paths
 */

/**
 * Full path to the database dump utility for this server
 *
 * pg_dump for PostgreSQL
 * @example /usr/bin/pg_dump
 * @example C:/Progra~1/PostgreSQL/bin/pg_dump.exe
 *
 * mysqldump for MySQL
 * @example /usr/bin/mysqldump
 * @example C:/wamp/bin/mysql/mysql[version]/mysqldump.exe
 */
$DatabaseDumpPath = getenv( 'DBTYPE' ) === 'mysql' ? '/usr/bin/mysqldump' : '/usr/bin/pg_dump';

/**
 * Full path to wkhtmltopdf binary file
 *
 * An empty string means wkhtmltopdf will not be called
 * and reports will be rendered in HTML instead of PDF
 *
 * @link http://wkhtmltopdf.org
 *
 * @example /usr/local/bin/wkhtmltopdf
 * @example C:/Progra~1/wkhtmltopdf/bin/wkhtmltopdf.exe
 */
$wkhtmltopdfPath = '/usr/local/bin/wkhtmltopdf';


/**
 * Default school year
 *
 * Do not change on install
 * Change after rollover
 * Should match the database to be able to login
 */
$DefaultSyear = getenv( 'ROSARIOSIS_YEAR' );


/**
 * Notify email address
 * where to send error and new administrator notifications
 *
 * Leave empty to not receive email notifications
 */
$RosarioNotifyAddress = getenv( 'ROSARIOSIS_ADMIN_EMAIL' );
$RosarioErrorsAddress = getenv( 'ROSARIOSIS_ADMIN_EMAIL' );


/**
 * Locales
 *
 * Add other languages you want to support here
 *
 * @see locale/ folder
 *
 * For American, French and Spanish:
 *
 * @example array( 'en_US.utf8', 'fr_FR.utf8', 'es_ES.utf8' );
 */
$RosarioLocales = array( getenv( 'ROSARIOSIS_LANG' ).'.utf8' );


/**
 * Time zone: the in-world location
 * Sets the PHP and the PostgreSQL session time zones
 *
 * @link https://www.php.net/manual/en/timezones.php
 */
$Timezone = getenv( 'ROSARIOSIS_TIMEZONE' ) ? getenv( 'ROSARIOSIS_TIMEZONE' ) : 'America/Anchorage';


/**
 * Recommended PHP configuration settings:
 * Uncomment and edit values as needed
 *
 * @link https://www.php.net/manual/en/ini.list.php
 */
// ini_set( 'max_execution_time', 240 );
// ini_set( 'memory_limit', '512M' ); // Maximum memory (RAM) allocated to RosarioSIS
// ini_set( 'session.gc_maxlifetime', 3600 ); // Session timeout: 1 hour.
