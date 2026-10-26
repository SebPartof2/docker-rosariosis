/**
 * Install MySQL
 * Required if the plugin has menu entries or tables
 * - Add profile exceptions for the plugin to appear in the menu
 * - Add program config options if any (to every school)
 * - Add plugin specific tables (and their eventual sequences & indexes)
 *   if any: see rosariosis_mysql.sql file for examples
 * - Add plugin specific templates if any
 *
 * @package MCP Server plugin
 */

/**
 * config Table
 *
 * Student fields never sent to AI assistants (custom_fields IDs, comma separated).
 * Default: hide the Social Security field.
 */
INSERT INTO config (school_id, title, config_value)
SELECT 0, 'MCP_SERVER_HIDDEN_FIELDS', COALESCE(CONCAT(',', GROUP_CONCAT(id), ','), '')
FROM custom_fields
WHERE title LIKE 'Social Security%'
HAVING NOT EXISTS (SELECT 1
	FROM config
	WHERE title='MCP_SERVER_HIDDEN_FIELDS');
