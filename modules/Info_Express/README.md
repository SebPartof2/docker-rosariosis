Info Express module
===================

This module is an add-on for [RosarioSIS](https://www.rosariosis.org)

## Description

Update Student Info in bulk, from a CSV.

Upload a CSV, tell what each column holds, review the changes student by
student, then apply them. One column must hold the Student ID; every other
column can be mapped to **any** student field, including the custom Student
Fields of every category.

Only the students enrolled in the **current school year** and the **current
school** are updated. A student of another school is reported as not found.

## Content

Info Express

- Update Student Info
- Reactivate Students
- Enrollment Dates

## Install

1. Copy the `Info_Express` folder inside the `modules` folder of RosarioSIS.
2. Activate the module from the _School > Configuration > Modules_ menu.

Activating the module runs `install.sql` (or `install_mysql.sql` on MySQL),
which gives all three programs to the Administrator profiles. Individual permissions
can then be adjusted from _Users > Profiles_ or _Users > Permissions_. All
three require the "Can Edit" permission because they modify records.

> `install.sql` runs only the **first** time the module is activated.
> Deactivating and reactivating it does not run the file again — if you are
> adding **Reactivate Students** or **Enrollment Dates** to an installation
> that already had this module, grant their permissions by hand from
> _Users > Profiles_.

## The 4 steps

1. **Student Info File** — upload a `.csv`, `.tsv` or `.txt` file, or paste the
   rows. The delimiter (comma, semicolon, tabulation or pipe) is detected from
   the first line; UTF-8 and ISO-8859-1 (Excel) are both accepted, with or
   without a byte order mark. Values can be quoted with `"`.
2. **Columns** — every column of the file is listed with its name, its first
   values, and a pull-down of every student field, grouped by category. The
   mapping is pre-filled by matching the header names against the field names and
   IDs; the match ignores case, punctuation and camel case, so `StudentID`,
   `student_id`, `SID` and `Student No.` all find the identifier. When nothing is
   recognized, the screen says so and you map the columns by hand.
   Two options sit above the list: whether the first row holds the column names
   (the row itself is shown, so you can see what the tick box skips), and whether
   an empty cell empties the field (off by default: empty cells are left alone).
3. **Changes** — every row, with the student it matched and the exact
   `old → new` value of each field that would change. Rows that cannot be
   applied say why. Tick **Skip** on any student you do not want to update.
4. **Done** — the students are updated, and everything that was skipped is
   listed with its reason.

## Fields

| Group | Fields |
|---|---|
| Identifier | Student ID (**required**, one column) |
| Student | First Name, Middle Name, Last Name, Suffix, Username |
| Enrollment | Grade Level, Calendar, Next School, Enrollment Code |
| _each Student Field category_ | every field of that category |

Every Student Field type is supported except **Files**, which cannot be set
from a CSV. Enrollment fields are written to the student's **last enrollment
record** of the current school year and school, like _Students > Group Assign
Student Info_ does.

Not covered: the student password (never settable in bulk from a plain-text
file), addresses and contacts (they live in their own tables, with several
addresses and people per student), and the student photo.

## Values accepted

| Field type | Accepted in the CSV |
|---|---|
| Text, Long Text | Any text. Long Text goes through the MarkDown sanitizer. |
| Number | `12.5` or `12,5`. Rounded to 2 decimals. |
| Date | Anything PHP understands: `2011-04-18`, `18 April 2011`, `04/18/2011`. |
| Checkbox | `Y`, `Yes`, `1`, `true`, `x`, `checked` / `N`, `No`, `0`, `false`. |
| Pull-Down, Export Pull-Down | One of the field's options, ignoring case and accents. |
| Auto Pull-Down | Any text: the options of this type are free. |
| Select Multiple | Options separated by `\|`, for example `Chess\|Drama`. |
| Grade Level, Calendar, Next School, Enrollment Code | Its name, its short name, or its ID. |

A value that matches none of the above stops that **row** (not the whole file):
it is listed on the review screen with the reason, and nothing is written for
that student.

## Rules

- A required field cannot be emptied, even with "Empty cells empty the field"
  on.
- A username must not already belong to another student or to a user, and must
  not appear twice in the file.
- When the same Student ID appears twice, only the first row is applied.
- An out of range date rolls over, the same way it does everywhere else in
  RosarioSIS: `2011-02-31` becomes `2011-03-03`. The review screen shows the
  date that will actually be stored.
- A maximum of 2000 rows and 100 columns is read per file.
- Whether the first row holds the column names is guessed, not assumed: a row naming
  at least one field is a header row; otherwise a row holding a number is data, and a
  row of plain text is a header row whose names could not be matched. The tick box on
  the mapping screen always overrides the guess.

## Reactivate Students

Makes every student of the current school year and school **active** again, in
one pass. Use it after a rollover or a bad import has left students showing as
inactive.

A student is active when their enrollment record covers today — the same test
the student search and _Students > Student Info_ use:

    START_DATE IS NOT NULL
    AND today >= START_DATE
    AND ( END_DATE IS NULL OR today <= END_DATE )

So there are three ways to be inactive, and each has its own fix:

| Why they are inactive | What the page changes |
| --- | --- |
| They were dropped — an end date in the past | Clears the end date, and the drop code with it |
| They have not started — a start date still to come | Moves the start date back to today |
| Their record has no start date at all | Sets the start date to today |

The page lists every inactive student with their dates, their drop code and the
one change they would get. Tick **Skip** on anyone you want left alone — a
student you deliberately enrolled for next term, say — then press _Make them
Active_. Students who are already active are never listed and never touched.

Nothing else on the record moves: the grade level, the calendar, the enrollment
code and the school stay as they are. A student holding **more than one**
enrollment record for the year is fixed on their latest one; a student with any
record covering today is already active and is not listed at all.

**No account activation email is sent.** Enrolling a single student from their
Student Info page mails them their portal login; doing that for a whole list at
once would not be wanted. Students who need that email should be enrolled from
_Students > Student Info_ instead.

## Enrollment Dates

Moves the day a student was enrolled, and the day they joined each of their
classes, to a date you pick — for the whole school at once, or for a list of
Student IDs.

Useful after a rollover or an import that landed everybody on the wrong day:
setting one date puts the enrollment records and the schedule records back in
step, without re-enrolling or re-scheduling anybody.

### What it changes

Only **START_DATE**, on two tables:

| Record | Column |
| --- | --- |
| The student's enrollment | `student_enrollment.START_DATE` |
| Each class they hold | `schedule.START_DATE` |

Either can be left out — tick only the one you want on the first screen.

**No end date is ever touched, and nobody is enrolled or dropped.** A student
holding more than one enrollment record for the year is moved on their latest
one, the record that says where they stand now.

### What is left alone

A record whose **end date falls before the new date** is skipped, and the review
screen counts them. Writing a start date past the end date would not be a date
change: RosarioSIS reads a record whose end date has passed as a drop, so the
student would vanish from the active list. Those records are reported, never
written.

A class the student already joined on that date is not rewritten either.

### Attendance

Moving dates **forward** leaves period attendance sitting before the day the
student is now enrolled from. The review screen counts those records so you know
they are there.

**This page never deletes attendance.** RosarioSIS does delete it when you
change one schedule record by hand on _Scheduling > Student Schedule_; doing the
same for a whole school in one click is not a decision this page will make for
you. If you want that attendance gone, remove it from the Attendance module.

### The school year

The first screen shows the dates the school year runs between, and the review
screen warns when the date you picked falls outside them — scheduling,
attendance and the marking periods all sit inside the year, so a date outside it
is usually a slip. It is a warning, not a refusal: you can still go ahead.

## Action hooks

The module fires these action hooks, see the [`functions/Actions.php`](https://gitlab.com/francoisjacquet/rosariosis/-/blob/mobile/functions/Actions.php)
file of RosarioSIS:

- `Info_Express/Import.php|update_student`, once per updated student, with
  `$_REQUEST['student_id']` set.
- `Info_Express/Reactivate.php|reactivate`, once per reactivated student.
- `Info_Express/EnrollmentDates.php|move_dates`, once per student whose dates
  were moved.

The `Students/Student.php|update_student` hook is deliberately **not** fired:
add-ons listening to it expect the Student Info program context.

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
