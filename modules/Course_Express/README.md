Course Express module
=====================

This module is an add-on for [RosarioSIS](https://www.rosariosis.org)

## Description

Create Course Subjects, Courses and sections (Course Periods) in bulk, from CSV lists.

- **Import Courses** reads a list of courses (subject, course title, course number and
  credit hours), tells you which Course Subjects already exist, offers to create the
  missing ones, then shows you exactly which Courses will be created.
- **Import Sections** reads a list of sections (course number, period, marking period
  and teacher) and creates the Course Periods, with the settings you set once in
  _Section Defaults_ and adjust per group of courses in _Section Overrides_.
- **Move Sections** reads a list of students and moves each from one section of a
  course to another.

Everything is created in the **current school year** and the **current school**.

## Content

Course Express

- Import Courses
- Import Sections
- Move Sections
- Master Schedule
- Schedule Troubleshoot
- Empty Sections
- Section Defaults
- Section Overrides

## Install

1. Copy the `Course_Express` folder inside the `modules` folder of RosarioSIS.
2. Activate the module from the _School > Configuration > Modules_ menu.

Activating the module runs `install.sql` (or `install_mysql.sql` on MySQL), which
creates the 2 override set tables and gives the programs to the Administrator
profiles. Individual permissions can then be adjusted from _Users > Profiles_ or
_Users > Permissions_. The programs require the "Can Edit" permission because they
create records.

## Courses file format

One course per line. The first line can be a header row naming the columns, in any
order; header names are not case sensitive and `_`, `-` and punctuation are ignored.
Without a recognized header row, the columns are read in the order below and the first
row is imported like every other.

| # | Column        | Header names accepted                                    | Required | Saved as              |
|---|---------------|----------------------------------------------------------|----------|-----------------------|
| 1 | Subject       | `subject`, `subjects`, `subject title`, `subject name`, `course subject`, `department` | Yes | Course Subject title |
| 2 | Course        | `course`, `course title`, `course name`, `title`, `name`  | Yes      | Course title          |
| 3 | Course Number | `course number`, `course no`, `course code`, `short name`, `number`, `code` | No | Course short name |
| 4 | Credit Hours  | `credit hours`, `credit hour`, `credits`, `credit`, `hours` | No     | Course credit hours   |

The comma, the semicolon, the tabulation and the pipe are accepted as separator; it is
detected from the first line. Values can be quoted with `"`. UTF-8 and ISO-8859-1
(Excel) files are both accepted, with or without a byte order mark.

Example:

```csv
Subject,Course,Course Number,Credit Hours
Mathematics,Algebra I,MAT101,1
Mathematics,Geometry,MAT102,1
Science,Biology,SCI201,0.5
```

Instead of uploading a `.csv`, `.tsv` or `.txt` file, the same content can be pasted
in the text area on the first screen.

## Rules

- A course number is limited to 25 characters, and credit hours to a number between
  0 and 9999.99. A comma is accepted as decimal separator. Lines breaking these rules
  are listed on screen and ignored.
- A subject already exists when its title matches, ignoring the case, the extra spaces
  and the [multilingual](https://gitlab.com/francoisjacquet/rosariosis/-/wikis/Multilingual-fields)
  suffixes.
- A course is not created when the subject already contains a course with the same
  title, or with the same course number. Courses listed twice in the file are created
  once.
- A maximum of 1000 rows is read per file.
- The first row is a header row when **at least one** of its cells names a column; the
  columns it does not name take, in order, the columns it does not use, so a partly
  named header still works. When no cell names a column, the first row is data: it is
  never dropped, and the columns are read in the documented order.

## Move Sections

Moves students between the sections of a course, one move per line.

| # | Column | Header names accepted | Holds |
|---|---|---|---|
| 1 | Student ID | `student id`, `studentid`, `student number`, `sid`, `id`, `local id`, `state id` | The ID of a student enrolled in this school |
| 2 | Course Number | `course number`, `course no`, `course code`, `course short name`, `course`, `number`, `code` | The short name of the course they are in |
| 3 | From Section | `from section`, `from`, `current section`, `current`, `old section`, `old` | The section they are in now |
| 4 | To Section | `to section`, `to`, `new section`, `new`, `target section`, `target` | The section to move them to |

A section can be named in full (`Section 2`), by its number alone (`2`), or by its
Course Period ID. Every column is required. Example:

```csv
Student ID,Course Number,From Section,To Section
1000123,MAT101,1,2
1000124,MAT101,1,2
1000125,SCI201,Section 2,Section 1
```

### What happens

The student's schedule entry is **updated in place**, exactly as when you change
the section on the Student Schedule page: the start date and the history are kept,
and the marking period follows the section they move to. The `Filled Seats` of both
sections are then recounted from the schedule.

Grades and period attendance already recorded stay attached to the section they
were recorded in. RosarioSIS behaves the same way when a section is changed by
hand, so this is not a difference — but it is worth knowing before moving a student
part way through a term.

### What stops a move

- The student is not enrolled in this school, or the ID is not a number.
- The course number matches no course, or matches several.
- The course has no section by that name, on either side.
- The student is not in the section they would be moved out of — or is already in
  the one they would be moved to.
- The From and To sections are the same.
- The section they would move to is **full**, or **clashes** with another course
  they hold. Tick _"Move even when the section is full, or clashes with another
  course"_ on the upload screen to let these through; the reason is then shown on
  the row instead of stopping it.

When the same student and course appear on two lines, only the first is applied.

## Master Schedule

It carries the same **Download everything** button as the troubleshoot reports,
which is the way to get past the 1000 row limit RosarioSIS lists impose.

A read-only reference listing every course of the current school year and school
with its sections, showing the fields these imports write: the course number the
next file has to quote, the section short names that were assigned, and the
settings each section was created with. Filter by subject, or show only the
**courses with no section yet** — those are the ones still missing a line in the
sections file. Unlike the other programs it needs only the "Can Use" permission.

It also carries a **Rebuild the section names** button. A section's name is built
from its school period, its meeting days, its marking period, its short name and
its teacher; rename any of those and the stored names drift. The button rebuilds
every section name of the current school year and school from what is stored, and
reports how many it changed. It is safe to run twice: the second run changes
nothing.

## Empty Sections

The sections nobody is scheduled into, so they can be culled or merged. It carries
the same **Download everything** button as the other reports. Raise the
"Students" filter from _None at all_ to _At most 5_ or _At most 10_ to find the
ones just short of viable too, and filter by subject. Clicking a section opens it
in _Scheduling > Courses_.

The student count is **counted from the schedule** (dropped courses left out)
rather than read from the `FILLED_SEATS` counter stored on the section, which can
drift. Both are shown, and the page warns when they disagree — check such a
section before deleting it.

## Schedule Troubleshoot

Three read-only lists for working out why a schedule did not come out right.
Like the Master Schedule, they need only the "Can Use" permission.

**Getting more than 1000 rows.** RosarioSIS lists cut off at 1000 rows, and the
export built into a list is taken *after* that cut, so it cannot reach past row
1000 either. Every report here therefore carries its own **Download everything**
button, which writes the whole result as a CSV regardless of size; the list on
screen is paginated so the later rows can still be reached by eye. Use Download
whenever the row count above the list is over 1000.

The button opens in a new tab, the same way the export button built into every
RosarioSIS list does: an ordinary link would be picked up by the page's AJAX
navigation, which would fetch the CSV and show it as text instead of saving it.

**Student Schedules & Gaps** — the whole picture per student, and the one to hand
to a solver: one row per student per course, in student order, listing the courses
they hold and then the ones they asked for and did not get. Every missing request
carries a `Why` and, in `Options`, each section the course could have gone into:

| Column | Values |
|---|---|
| `Status` | `scheduled`, `missing` |
| `Why` | `no_section` the course has no section &middot; `all_full` every section is full &middot; `clash` every section with room clashes with a course held &middot; `open` a section has room and does not clash |
| `Options` | one entry per section, separated by ` ; `, each reading `section\|period\|days\|marking period\|seats=\|fits=\|clash=` |

An option reads, for example, `Section 1|Period 1|MWF|FY|seats=18|fits=no|clash=LUN001`:
the section has 18 seats free but cannot be taken, because the student already
holds LUN001 in that slot. Several periods or days on one section are joined with
`+`, as are several clashing courses.

Two sections clash when they meet in the same school period, on a day they share,
during marking periods that run at the same time — compared on the marking period
dates, so a term clashes with the full year and with the quarters inside it. A
section with no day set is treated as meeting every day, so a clash is never
missed.

**Unfilled Requests** — every Course Request the student holds no scheduled
course for, with the reason on the row: the course has no section at all, every
section is full, or seats are free (so it is a timetable clash, or the Scheduler
has not run). Each row carries the student, their grade level, the course number,
how many sections the course has and how many seats are free across them.

**Schedules** — one row per student per scheduled course: student, grade level,
course number, course, section and its name, period, days, marking period,
teacher, start and end dates, and whether the row is current or dropped. Dropped
courses are left out unless you ask for them.

## Sections

### 1. Section Defaults (set them once, first)

Sections cannot be imported until these are saved. They are the settings **every**
section gets:

Seats, Meeting Days, Takes Attendance, Calendar, Grading Scale, Affects Class Rank,
Affects Honor Roll.

The other Course Period settings are never asked for:

| Setting | Value |
|---|---|
| Short Name | `Section 1`, `Section 2`... counting on from the sections the course already has |
| Teacher, Period, Marking Period | From the imported section |
| Credits | The credit hours of the course |
| Room | Always empty |
| Gender Restriction | Always None |

### 2. Section Overrides (optional)

An override set is a **named group of courses** plus the settings that replace the
defaults for them -- for example a "Lunch" set holding the lunch courses, overriding
the Grading Scale to _Not Graded_ and turning off Class Rank and Honor Roll.

Each setting has its own "Override" tick box, so a set can deliberately override a
setting **to an empty value** (Not Graded, no attendance, unchecked) -- an unticked
setting simply keeps its default. A course can belong to several sets; they are
applied in sort order, and the last one wins on a setting overridden twice.

### 3. Import Sections

One section per line, with these columns:

| # | Column | Header names accepted | Matched with |
|---|---|---|---|
| 1 | Course Number | `course number`, `course no`, `course code`, `course short name`, `course`, `number`, `code` | The short name of an existing course |
| 2 | Period | `period number`, `period no`, `school period`, `period` | A school period name, short name or ID |
| 3 | Marking Period | `marking period`, `term`, `semester`, `quarter`, `mp`, `length` | A marking period name, short name or ID |
| 4 | Teacher | `teacher id`, `teacher no`, `teacher number`, `staff id`, `teacher` | A teacher ID, username or full name |

Every column is required. Example:

```csv
Course Number,Period,Marking Period,Teacher ID
MAT101,1,Full Year,1001
MAT101,2,Full Year,1001
SCI201,3,Semester 1,1002
```

The review screen lists every section with the short name it will get, its credits,
and the full set of settings it will be created with -- naming the override sets that
changed anything.

Periods, marking periods and teachers are matched by name first, then by ID: `1`
is the period whose short name is `01` (leading zeros are ignored), not the period
with ID 1. A name shared by two records (two teachers called the same) is refused:
use the ID or username instead.

A row is **not** created when the course number matches no course (or matches
several), when the period, marking period or teacher is not found or is ambiguous,
when the same line appears twice in the file, when the course already has a section
with the same period, marking period and teacher, or when its settings have no
Meeting Days or name a calendar, grading scale or attendance category that is not in
this school year (typically right after a rollover: save the Section Defaults and
override sets again).

Marking periods offered are Full Year and its children, like _Scheduling > Courses_.

## Action hooks

The module fires 3 action hooks, see the [`functions/Actions.php`](https://gitlab.com/francoisjacquet/rosariosis/-/blob/mobile/functions/Actions.php)
file of RosarioSIS:

- `Course_Express/Import.php|create_course_subject`, with `$_REQUEST['subject_id']`
  set to the new Course Subject.
- `Course_Express/Import.php|create_course`, with `$_REQUEST['subject_id']` and
  `$_REQUEST['course_id']` set to the new Course.
- `Course_Express/Sections.php|create_course_period`, with `$_REQUEST['course_id']`
  and `$_REQUEST['course_period_id']` set to the new Course Period.

The `Scheduling/Courses.php|*` hooks are deliberately **not** fired: add-ons listening
to those expect the Courses program context.

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
