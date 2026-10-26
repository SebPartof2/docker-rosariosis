MCP Server plugin
=================

A [Model Context Protocol](https://modelcontextprotocol.io/) (MCP) server for RosarioSIS. It lets AI assistants such as Claude answer questions from RosarioSIS data ("How many days has John Smith missed this month?", "Who is in my 2nd period Algebra class?").

- **Read-only**: no tool can create, change or delete data.
- **Same permissions as RosarioSIS**: every token belongs to a RosarioSIS user. The assistant sees what that user sees in RosarioSIS, no more:
	- Administrators: students enrolled in their schools.
	- Teachers: only students scheduled in their own classes, and only the final grades of their classes.
	- Tools are only offered if the user's profile can use the matching RosarioSIS program (for example _Grades > Final Grades_), and student fields are filtered by _Student Info_ tab permissions.
- **Sensitive fields can be hidden**: student fields listed in the plugin configuration are never sent, whatever the permissions. _Social Security_ is hidden by default.
- **Audit log**: every tool call is written to the PHP error log (`docker logs`), with the user and arguments.

## Installation

The plugin is bundled into the Docker image (`plugins/MCP_Server`).

1. Activate it in _School > Configuration > Plugins_.
2. Click _Configuration_ next to the plugin, pick a user and click _Generate Token_. Copy the token: it is only shown once.

Only Administrators can open the plugin configuration. They can generate tokens for themselves or for Teachers, see active tokens, and revoke them.

Tokens are valid for the current school year: generate new ones after Rollover.

## Connect an AI assistant

MCP server URL: `https://your-rosariosis.example.com/plugins/MCP_Server/mcp.php`

The server uses the Streamable HTTP transport and authenticates with the header `Authorization: Bearer <token>`.

**Claude Code**

```bash
claude mcp add --transport http rosariosis https://your-rosariosis.example.com/plugins/MCP_Server/mcp.php \
	--header "Authorization: Bearer rmcp_..."
```

**Claude Desktop** and other clients that only support local (stdio) servers, through [mcp-remote](https://www.npmjs.com/package/mcp-remote):

```json
{
	"mcpServers": {
		"rosariosis": {
			"command": "npx",
			"args": [
				"mcp-remote",
				"https://your-rosariosis.example.com/plugins/MCP_Server/mcp.php",
				"--header",
				"Authorization:${AUTH_HEADER}"
			],
			"env": { "AUTH_HEADER": "Bearer rmcp_..." }
		}
	}
}
```

Always serve RosarioSIS over HTTPS: the token is sent with every request.

## Tools

| Tool | Description | RosarioSIS program required |
| --- | --- | --- |
| `whoami` | Current user, school year, schools, available tools | |
| `list_schools` | Schools with contact info and grade levels | |
| `list_marking_periods` | Years, semesters, quarters and their dates | |
| `search_students` | Search by name, username or ID; filter by school, grade level | Students > Student Info |
| `get_student` | Enrollment history, student fields, addresses & contacts | Students > Student Info (+ tabs) |
| `get_student_schedule` | Classes on a date, or the whole year | Scheduling > Schedule |
| `get_student_attendance` | Daily totals, days absent, period absences | Attendance > Daily Summary |
| `get_student_grades` | Final / report card grades, GPA points, credits | Grades > Final Grades |
| `list_course_periods` | Classes: course, teacher, room, seats, periods | Scheduling > Courses |
| `get_class_roster` | Students in a class on a date | Students > Student Info |
| `search_staff` | Users by name, username, email, profile (Administrators only) | Users > User Info |
| `enrollment_summary` | Students enrolled per school and grade level (Administrators only) | Students > Student Info |

## Privacy

Student records are protected by law (FERPA in the US, GDPR in the EU...). Data the assistant reads is sent to the AI provider. Before connecting one, check your district policy and your agreement with the provider, and hide the fields you do not want shared.

## Files

- `mcp.php`: MCP endpoint, Streamable HTTP transport (stateless, JSON responses).
- `includes/Protocol.fnc.php`: JSON-RPC / MCP messages.
- `includes/Tools.fnc.php`: tool definitions and SQL.
- `includes/Common.fnc.php`: database (PDO), token authentication, permissions.
- `config.inc.php`: token management and hidden fields.
- `install.sql`, `install_mysql.sql`: default hidden fields.

Tokens are stored as SHA-256 hashes in the `program_user_config` table (`PROGRAM='MCP_Server'`, `TITLE='TOKEN_HASH'`).

## Requirements

RosarioSIS 12.4+, PHP 7.4+ with the PDO extension for your database (`pdo_pgsql` is included in the Docker image; MySQL needs `pdo_mysql`).
