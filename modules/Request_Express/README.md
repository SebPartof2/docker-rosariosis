Request Express module
======================

This module is an add-on for [RosarioSIS](https://www.rosariosis.org)

## Description

Set the students' Course Requests in bulk, from a CSV.

Each line holds a Student ID and the course numbers the student requests,
separated by a space. Review the changes student by student, then apply them.

Two modes are available:

- **Replace** (default) — **importing a student replaces their requests.** Every
  request they have is removed, then the courses listed in the file are added.
  Re-importing a corrected file therefore leaves exactly what the file says —
  importing the same file twice changes nothing the second time.
- **Add only** — non destructive. **No request is ever removed.** The requests
  the student already has are kept, and only the courses of the file they do
  not request yet are added. Importing the same file twice changes nothing the
  second time.

Only the students enrolled in the **current school year** and the **current
school** are updated, and only the courses of that year and school can be
requested. A student of another school is reported as not found.

## Content

Request Express

- Import Course Requests

## Install

1. Copy the `Request_Express` folder inside the `modules` folder of RosarioSIS.
2. Activate the module from the _School > Configuration > Modules_ menu.

Activating the module runs `install.sql` (or `install_mysql.sql` on MySQL),
which gives the program to the Administrator profiles. Individual permissions
can then be adjusted from _Users > Profiles_ or _Users > Permissions_. The
program requires the "Can Edit" permission because it modifies records.

## The 3 steps

1. **Requests File** — upload a `.csv`, `.tsv` or `.txt` file, or paste the
   rows. Choose the mode, **Replace** or **Add only**. One more option sits
   below: whether an empty requests cell removes every request of the student
   (Replace mode only, off by default — an empty cell leaves the student alone).
2. **Changes** — every row, with the student it matched and, for that student,
   the requests **Removed**, **Added** and **Kept**. In Add only mode nothing is
   ever listed as Removed: the requests not in the file are Kept. Rows that
   cannot be applied say why. Tick **Skip** on any student you do not want to
   update.
3. **Done** — the requests are replaced (or added to), and everything that was
   skipped is listed with its reason.

## File format

| # | Column | Header names accepted | Holds |
|---|---|---|---|
| 1 | Student ID | `student id`, `studentid`, `student number`, `sid`, `id`, `local id`, `state id`… | The ID of a student enrolled in this school |
| 2 | Requests | `requests`, `course requests`, `courses`, `course numbers`, `short names`… | The course numbers (short names), separated by a space |

The first line can be a header row naming the columns, in any order; header
names are not case sensitive and `_`, `-`, punctuation and camel case are
ignored, so `StudentID` and `student_id` both work. A header naming only one of
the columns still works: the other takes the column left over. Without a
recognized header row the columns are read in the order above, and the first row
is imported like every other.

The delimiter between the **two columns** (comma, semicolon, tabulation or pipe)
is detected from the first line. UTF-8 and ISO-8859-1 (Excel) are both accepted,
with or without a byte order mark.

Example:

```csv
Student ID,Requests
1000123,"MAT101 SCI201 ENG101"
1000124,"MAT102 SCI201"
```

Quote the requests cell when your delimiter is the comma, so the course numbers
stay in one column.

## Rules

- A course number is a course **short name** of the current school year and
  school. A number matching no course, or matching several, stops that row: the
  row is listed on the review screen with the reason, and nothing is written for
  that student.
- The course numbers are separated by a space. The comma, the semicolon and the
  pipe are accepted too. When the **whole cell** is the short name of a course,
  it is taken as one request — that is how a short name holding a space is
  requested.
- A course requested twice in the same cell is requested once.
- When the same Student ID appears on two lines, only the first is applied; the
  second is reported as a duplicate. A line that fails still claims its student,
  so nothing is silently applied behind a failed line.
- A student whose requests already match the file exactly is left untouched, so
  no request loses its creation date for nothing. In Add only mode, a student
  who already requests every course of the file is left untouched.
- A maximum of 2000 rows is read per file, and 40 courses per student.

## Action hook

The module fires one action hook, see the [`functions/Actions.php`](https://gitlab.com/francoisjacquet/rosariosis/-/blob/mobile/functions/Actions.php)
file of RosarioSIS:

- `Request_Express/Import.php|update_requests`, once per updated student, with
  `$_REQUEST['student_id']` set. It fires in both modes.

## Long files

There is no limit on how many rows a file may hold.

The review screen lists at most **1000 rows** — that is a RosarioSIS limit on
every list in the program — but the list being cut never cuts the import: every
row of the file is applied. When a file is longer than that, the screen says so,
and the rows that will **not** be applied are listed first, so a problem on line
2500 is still the first thing you see.

The tick box on each row means **Skip**, and starts empty. Only the rows you
tick are posted back, which is what keeps a file of any length working: a form
can only carry about a thousand fields before PHP stops reading it, so a screen
that posted one tick per row could never apply more than a thousand rows. If you
ever do tick enough rows to reach that limit, the import stops and says so
rather than applying rows you asked it to leave out.

## License

GPL-2.0-or-later
