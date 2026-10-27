# Sim Date audit — RosarioSIS v12.9.3

Audit of every place RosarioSIS v12.9.3 reads the current date or time, done
before any Sim Date code was written. Source: the upstream tarball the
[Dockerfile](../Dockerfile) downloads (`ROSARIOSIS_VERSION=v12.9.3`).

Searched (excluding third-party code in `classes/` and `locale/`):

- PHP: `DBDate(`, `date(`, `time()`, `mktime(`, `strtotime(`, `new DateTime`,
  `date_create`, `DateTime::createFromFormat`, `getdate(`, `gmdate(`
- SQL inside PHP strings: `CURRENT_DATE`, `CURRENT_TIMESTAMP`, `NOW()`, `LOCALTIMESTAMP`
- JS: `new Date()`, `Date.now()` in `assets/js/` and inline in PHP

## Classifications

| Class | Meaning | What we do |
|---|---|---|
| **A** | School "today" | Use the sim date |
| **B** | Real event timestamp | Leave on the real clock |
| **C** | Unclear | Owner decision needed; a default is implemented (see [Category C decisions](#category-c-decisions)) |
| **N** | Not a clock read: formats or does arithmetic on a date it was given | Nothing to do |

**Status** column: `DBDate` = already goes through `DBDate()`, so the one change
to `DBDate()` covers it. `patched` = edited in
[patches/rosariosis/sim-date.patch](../patches/rosariosis/sim-date.patch).
`pinned real` = goes through `DBDate()` in stock but is really a class B
use, so the patch switches it to the real date. `module` = handled in the
Sim_Date module (no core edit). `—` = left alone.

## 1. The core "today" function

| File:line | Snippet | Class | Status | Reason |
|---|---|---|---|---|
| functions/Date.php:25 | `return date( 'Y-m-d' );` in `DBDate()` | A | patched | The single "today" function. 219 call sites go through it: attendance date default, `GetCurrentMP( 'QTR', DBDate() )` on login/school change (Side.php:112,124,321, Search.fnc.php:67), take-attendance edit window, gradebook assignment defaults, report card / progress report print dates (ReportCards.fnc.php:984, ProgressReports.php:128), enrollment checks in GetStaffList, Rollover, PasswordReset, etc. |

## 2. `DBDate()` callers that are really class B

These go through `DBDate()` in stock, so after the `DBDate()` change they would
silently start using the sim date. Each one compares against a real timestamp.

| File:line | Snippet | Class | Status | Reason |
|---|---|---|---|---|
| modules/School_Setup/includes/Dashboard.inc.php:60 | `AND CREATED_AT <='" . DBDate() . ' 23:59:59'` | B | pinned real | Login count over `access_log.CREATED_AT` (real). With a past sim date it would show 0 logins. |
| modules/School_Setup/AccessLog.php:23 | `RequestedDate( 'end', DBDate() )` | B | pinned real | Access log end date; log rows are real timestamps. |
| plugins/Content_Security_Policy/functions.php:27,30,39 | `$cron_day = DBDate();` … `DBDate() <= $cron_day` | B | pinned real | Daily CRON. Moving the sim date back would stop it running; forward would run it early. |
| plugins/Content_Security_Policy/SaveReport.php:164 | `CREATED_AT>='" . DBDate() . " 00:00:00'` | B | pinned real | CSP reports created today (real). |
| modules/Food_Service/MenuReports.php:9 | `RequestedDate( 'end', DBDate() )` | C→B | pinned real | Report over `food_service_transactions.TIMESTAMP`, which is a real `CURRENT_TIMESTAMP`. See C1. |
| modules/Food_Service/TransactionsReport.php:7 | `RequestedDate( 'end', DBDate(), 'set' )` | C→B | pinned real | Same. See C1. |
| modules/Food_Service/Statements.php:7 | `RequestedDate( 'end', DBDate(), 'set' )` | C→B | pinned real | Same. See C1. |
| modules/Food_Service/BalanceReport.php:3 | `RequestedDate( 'date', DBDate(), 'set' )` | C→B | pinned real | Balance as of a date, over real transaction timestamps. See C1. |
| modules/Discipline/includes/PortalAlerts.fnc.php:40,48 | `ENTRY_DATE BETWEEN '$last_login_date' AND '" . DBDate()` | C | DBDate | Mixes real last login with school dates. See C4. |
| modules/Student_Billing/includes/PortalAlerts.fnc.php:40 | `ASSIGNED_DATE BETWEEN '$last_login_date' AND '" . DBDate()` | C | DBDate | Same. See C4. |
| plugins/Moodle/Scheduling/Courses.php:446, plugins/Moodle/School_Setup/Rollover.php:188 | `$timestart = strtotime( DBDate() );` | C | DBDate | Course start pushed to an external Moodle. See C6. |

All other `DBDate()` callers (≈200) are school dates (A) and need nothing more.

## 3. PHP direct clock reads

| File:line | Snippet | Class | Status | Reason |
|---|---|---|---|---|
| functions/Date.php:411-412 | `$begin = date( 'Y' ) - 80; $end = date( 'Y' ) + 5;` | A | patched | Year dropdown range of every date picker; centred on the school year. |
| functions/Date.php:172, 486-496 | `strtotime( $time )`, `date( 'Y', $timestamp )` | N | — | Formats/splits a given date. |
| database.inc.php:666 | `echo date( 'm/d/Y H:i:s' );` | B | — | DB error report timestamp. |
| functions/ErrorMessage.fnc.php:179 | `'Date: ' . date( 'Y-m-d H:i:s' )` | B | — | Error email timestamp. |
| functions/PHPCompatibility/strftime_compat.php:35-58 | `$timestamp = time();` / `new \DateTime` when none given | B | — | Only reached with no timestamp; callers are the Preferences date-format examples (modules/Users/Preferences.php:439-448), which just preview the format. |
| Side.php:401 | `strftime_compat( '%A ' . Preferences( 'DATE' ) )` (no timestamp) | A | patched | Left menu "today" under the user name. Now `strftime_compat( …, DBDate() )`, so it shows the sim date. SimDate.js redraws the menu top when "today" changes. |
| Warehouse.php:197-200 | `new DateTime( '', $date_time_zone )`, `gmdate( 'H:i', … )` | B | — | Computes the time zone offset for MySQL. |
| ProgramFunctions/FileUpload.fnc.php:758 | `date( 'Y-m-d_His' )` | B | — | Unique upload file name. |
| ProgramFunctions/HackingLog.fnc.php:30,36 | `time()` | B | — | Rate limiting. |
| ProgramFunctions/StudentsUsersInfo.fnc.php:621 | `$datetime2 = date_create( 'now' );` | A | patched | **Student age** ("%Y Years %m Months %d Days"). |
| modules/Accounting/includes/DailyTotals.php:10, DailyTransactions.php:10 | `RequestedDate( 'start', date( 'Y-m' ) . '-01' )` | A | patched | Report starts on the 1st of the current month; entries are school-dated (default `DBDate()`). |
| modules/Accounting/includes/Dashboard.inc.php:95,97 | `$accounting_RET[date( 'Y-m' )]` | A | patched | "This month" totals over school-dated entries. |
| modules/Accounting/includes/Dashboard.inc.php:130 | `strtotime( $year_month . '-28' )` | N | — | Formats a given month. |
| modules/Attendance/AddAbsences.php:9,14 | `$_REQUEST['month'] = date( 'm' );` / `date( 'Y' )` | A | patched | Calendar opens on the current month. |
| modules/Attendance/AddAbsences.php:321 | `elseif ( date( 'Y-m-d' ) === $this_date )` | A | patched | "Today" highlight. |
| modules/Attendance/AddAbsences.php:277-281 | `mktime( … $_REQUEST['month'] … )` | N | — | Given month. |
| modules/Attendance/FixDailyAttendance.php:39-56 | `mktime(…$_REQUEST…)`, `date( 'Y-m-d', $i )` | N | — | Given range. |
| modules/Attendance/includes/DailySummary.php:16 | `RequestedDate( 'start', date( 'Y-m' ) . '-01' )` | A | patched | Report default month. |
| modules/Attendance/includes/DailySummary.php:305,370 | `str_replace( date( 'Y' ), '', $school_date )` | A | patched | Hides "this year" from column dates; cosmetic, but should match the school year. |
| modules/Attendance/includes/StudentSummary.php:8 | `RequestedDate( 'start', date( 'Y-m' ) . '-01' )` | A | patched | Report default month. |
| modules/Attendance/Percent.php:32 | `RequestedDate( 'start', date( 'Y-m' ) . '-01', 'set' )` | A | patched | Report default month. |
| modules/Attendance/TakeAttendance.php:208,210 | `strtotime( $date ) <= $time + …` | N | — | `$time = strtotime( DBDate() )` (line 201), already sim. |
| modules/Discipline/CategoryBreakdown.php:16, CategoryBreakdownTime.php:16, StudentFieldBreakdown.php:16 | `$min_date = date( 'Y-m' ) . '-01';` | A | patched | Referral reports default month; referral dates are school dates. |
| modules/Discipline/CategoryBreakdownTime.php:147,195,253,299,341,612 | `date( 'Y-m', strtotime( '+1 month', … ) )` | N | — | Month iteration. |
| modules/Discipline/includes/Dashboard.inc.php:58 | `=== date( 'Y-m' )` | A | patched | "This month" referrals. |
| modules/Discipline/includes/Dashboard.inc.php:74 | `strtotime( … . '-28' )` | N | — | |
| modules/Eligibility/EnterEligibility.php:29,43,45 | `$today = date( 'w' ) …`, `time() - (…)*86400` | A | patched | Current eligibility week. |
| modules/Eligibility/EnterEligibility.php:304-305 | `date( 'Gi' ) < ( $START_HOUR . $START_MINUTE )` | C | — | Entry window time of day. See C2. |
| modules/Eligibility/Student.php:70,72 | `$today = date( 'w' ) …`, `$start = time() - …` | A | patched | Current eligibility week. |
| modules/Eligibility/Student.php:78-108 | `date( 'Y-m-d', $start_time )` | N | — | Derived from `$start`. |
| modules/Eligibility/StudentList.php:12,14,22 | `date( 'w' )`, `time()`, `$end_date = date( 'Y-m-d' );` | A | patched | Current eligibility week. |
| modules/Eligibility/StudentList.php:20,28,30,56 | `date( 'Y-m-d', $start_time … )` | N | — | |
| modules/Eligibility/TeacherCompletion.php:17,19,27 | `date( 'w' )`, `time()`, `$end_date = date( 'Y-m-d' );` | A | patched | Current eligibility week. |
| modules/Eligibility/TeacherCompletion.php:25,33,35,86-87 | `date( 'Y-m-d', $start_time … )` | N | — | |
| modules/Food_Service/DailyMenus.php:7,12 | `$_REQUEST['month'] = date( 'm' );` / `date( 'Y' )` | A | patched | Menu calendar opens on the current month. |
| modules/Food_Service/DailyMenus.php:22-351 | `mktime(…$_REQUEST…)`, `date( 'Y-m-d', $time )` | N | — | |
| modules/Food_Service/includes/Dashboard.inc.php:70,73,75,77 | `$meals_RET[date( 'Y-m-d' )]` | C→B | — | Keyed on `food_service_transactions.TIMESTAMP` (real). See C1. |
| modules/Food_Service/MenuReports.php:6, Statements.php:4, TransactionsReport.php:4 | `RequestedDate( 'start', date( 'Y-m' ) . '-01' …)` | C→B | — | Ranges over real transaction timestamps. See C1. |
| modules/Food_Service/TakeMenuCounts.php:76, TeacherCompletion.php:7 | `date( 'D', strtotime( $date ) )` | N | — | `$date` defaults to `DBDate()`. |
| modules/Grades/includes/HonorRoll.fnc.php:71,245 | `date( 'F j, Y', strtotime( END_DATE ) )` | N | — | |
| modules/Grades/includes/StudentAssignments.fnc.php:133 | `'date' => date( 'Y-m-d H:i:s' )` | C | — | Assignment submission time. See C5. |
| modules/misc/Portal.php:46,50 | `if ( date( 'H' ) < 12 )` | B | — | "Good morning/afternoon" — time of day; there is no sim time. |
| modules/misc/Portal.php:192-956 | `date(pn.CREATED_AT)` (SQL) | N | — | SQL cast of a column. |
| modules/Scheduling/AddDrop.php:7 | `RequestedDate( 'start', date( 'Y-m' ) . '-01' )` | A | patched | Add/drop report default month. |
| modules/Scheduling/Schedule.php:497 | `'&time=' . time()` | B | — | Cache buster. |
| modules/School_Setup/AccessLog.php:20 | `date( 'Y-m-d', time() - 60 * 60 * 24 )` | B | — | Access log (real). |
| modules/School_Setup/AccessLog.php:46 | `strtotime( '1 year ago' )` | B | — | Log purge. |
| modules/School_Setup/Calendar.php:13,21 | `$_REQUEST['month'] = date( 'm' );` / `date( 'Y' )` | A | patched | **Calendar opens on the current month.** |
| modules/School_Setup/Calendar.php:784 | `$start_date_default = date( 'Y-m-01' );` | A | patched | Calendar list view default. |
| modules/School_Setup/Calendar.php:790 | `date( 'Y-m-t', strtotime( $start_date_default ) )` | N | — | Derived. |
| modules/School_Setup/Calendar.php:365-387,539-541,1169 | `mktime(…$_REQUEST…)`, `date( 'w', … )` | N | — | |
| modules/School_Setup/includes/CalendarDay.inc.php:94, DayToNumber.inc.php:18, Rollover.fnc.php:264, MarkingPeriods.php:170-250 | `date( 'w', strtotime( $date ) )`, `date_create( $value )` | N | — | |
| modules/School_Setup/includes/Dashboard.inc.php:49 | `date( 'Y-m-d', time() - 60 * 60 * 24 )` | B | — | Login count (real). |
| modules/Students/includes/AddDrop.php:12, AddDropBreakdownTime.php:27 | `date( 'Y-m' ) . '-01'` | A | patched | Enrollment report default month. |
| modules/Students/includes/AddDropBreakdownTime.php:51 | month iteration | N | — | |
| modules/Students/includes/Comments.inc.php:25 | `'date' => date( 'Y-m-d G:i:s' )` | B | — | Comment created timestamp. |
| modules/Student_Billing/includes/DailyTotals.php:10, DailyTransactions.php:10 | `date( 'Y-m' ) . '-01'` | A | patched | Fees/payments are school-dated (default `DBDate()`). |
| modules/Student_Billing/includes/Dashboard.inc.php:78,80 | `[date( 'Y-m' )]` | A | patched | "This month" fees/payments. |
| modules/Student_Billing/includes/Dashboard.inc.php:103 | `strtotime( … '-28' )` | N | — | |
| plugins/Content_Security_Policy/config.inc.php:24 | `strtotime( '1 year ago' )` | B | — | Report purge. |
| plugins/Content_Security_Policy/config.inc.php:123 | `date( 'Y-m' ) . '-01'` | B | — | CSP reports (real). |
| plugins/Moodle/** (Assignments.php:71, Courses.php:341-647, MassSchedule.php:43, Schedule.php:101, Calendar.php:60, Rollover.php:88) | `strtotime( $columns['DUE_DATE'] )` etc. | N | — | Given dates. |

## 4. SQL inside PHP strings

`CURRENT_DATE` replacements become `'" . DBDate() . "'` (Rosario's own idiom,
used in 100+ places in stock), or `CAST('" . DBDate() . "' AS DATE)` where the
value takes part in interval arithmetic. `DBDate()` only ever returns a
validated `YYYY-MM-DD`, so no further escaping is needed.

| File:line | Snippet | Class | Status | Reason |
|---|---|---|---|---|
| index.php:139-140,156 | `CURRENT_DATE>=se.START_DATE AND (CURRENT_DATE<=se.END_DATE …)` | A | patched | **Student login enrollment check.** Without this a student enrolled from the sim first day could not log in before that real date. |
| index.php:326,332 | `SET LAST_LOGIN=CURRENT_TIMESTAMP` | B | — | Login history. |
| ProgramFunctions/FirstLogin.fnc.php:36,43 | `LAST_LOGIN=CURRENT_TIMESTAMP` | B | — | |
| PasswordReset.php:111,126,360,389,396 | `LAST_LOGIN > CURRENT_TIMESTAMP`, `CURRENT_TIMESTAMP + INTERVAL '2 hour'` | B | — | Reset token expiry. |
| ProgramFunctions/SendNotification.fnc.php:49-50 | `CURRENT_DATE>=START_DATE …` | A | patched | "Enrolled as of today" → account activation notice. |
| modules/Attendance/includes/Dashboard.inc.php:56 | `ad.SCHOOL_DATE<=CURRENT_DATE` | A | patched | Attendance to date. |
| modules/Eligibility/EnterEligibility.php:85-86,102-103 | `CURRENT_DATE>=ga.ASSIGNED_DATE`, `>=ga.DUE_DATE` | A | patched | Assignments due so far. |
| modules/Eligibility/includes/Dashboard.inc.php:49-50,69-70 | `START_DATE<=CURRENT_DATE AND END_DATE>=CURRENT_DATE` | A | patched | Current eligibility period. |
| modules/Food_Service/Students/Accounts.php:187, Students/Statements.php:47, Students/Reminders.php:153 | `START_DATE<=CURRENT_DATE AND (END_DATE IS NULL OR CURRENT_DATE<=END_DATE)` | A | patched | Enrollment check. |
| modules/Food_Service/Students/Reminders.php:41 | `SCHOOL_DATE>CURRENT_DATE` | A | patched | School days left in the year. |
| modules/Food_Service/Students/Reminders.php:55,57 | `SCHOOL_DATE BETWEEN (CURRENT_DATE - INTERVAL '14 DAY') …` | A | patched | School days in the last 2 weeks. |
| modules/Food_Service/Students/Reminders.php:48,50 | `fst.TIMESTAMP BETWEEN (CURRENT_DATE - INTERVAL …)` | C→B | — | Real transaction timestamps. See C1. |
| modules/Food_Service/Students/ServeMenus.php:25, Users/ServeMenus.php:20, Students/Transactions.php:43, Users/Transactions.php:34 | `CURRENT_TIMESTAMP` in INSERT | B | — | Transaction created timestamp. |
| modules/Food_Service/Students/ServeMenus.php:163-164, Users/ServeMenus.php:129-130, Students/Transactions.php:119-120, Users/Transactions.php:103-104 | `fst.TIMESTAMP BETWEEN CURRENT_DATE AND (CURRENT_DATE + INTERVAL '1 DAY')` | C→B | — | "Today's transactions" over real timestamps. See C1. |
| modules/Grades/AnomalousGrades.php:85 | `CURRENT_DATE>=ga.ASSIGNED_DATE … CURRENT_DATE>(SELECT END_DATE …)` | A | patched | Gradebook: which assignments are due. |
| modules/Grades/Grades.php:89-91,308-310 | same | A | patched | **Gradebook** due / assigned. |
| modules/Grades/includes/FinalGrades.inc.php:571-573 | same | A | patched | Final grade calculation. |
| modules/Grades/includes/StudentAssignments.fnc.php:472,626 | `CURRENT_DATE>=ga.ASSIGNED_DATE` | A | patched | Assignments visible to students. |
| modules/Grades/InputFinalGrades.php:997 | `POST_START_DATE<=CURRENT_DATE` | A | patched | Grade posting window. |
| modules/Grades/ProgressReports.php:73,82,174 | same assigned/due logic | A | patched | Progress reports. |
| modules/Grades/StudentGrades.php:128,139,146-150,393-397,428-431 | `CURRENT_DATE>=ss.START_DATE`, assigned/due logic | A | patched | Student grades. |
| modules/misc/Portal.php:196-197,227-228,470-471,497-498,743-744,770-771,933-934,959-960 | `pn.START_DATE<=CURRENT_DATE …` | A | patched | **Portal notes & polls currently shown.** |
| modules/misc/Portal.php:250-251,522-523,795-796,983-984 | `SCHOOL_DATE BETWEEN CURRENT_DATE AND (CURRENT_DATE + INTERVAL '11 DAY')` | A | patched | **Portal "upcoming events".** |
| modules/misc/Portal.php:561-562,839-842,1024-1027 | `a.DUE_DATE>=CURRENT_DATE`, `s.START_DATE<=CURRENT_DATE` | A | patched | **Portal "upcoming assignments".** |
| modules/misc/Portal.php:746,798 | `se.START_DATE<=CURRENT_DATE AND (se.END_DATE>=CURRENT_DATE …)` | A | patched | Parent portal: schools of the parent's currently enrolled children. |
| modules/misc/Portal.php:192,466,739,929 | `CURRENT_DATE` in commented-out queries | — | — | Dead code. |
| modules/Scheduling/Courses.php:876-877,1698-1699,1801-1802 | `START_DATE<=CURRENT_DATE AND (END_DATE IS NULL OR END_DATE>=CURRENT_DATE)` | A | patched | Currently scheduled students. |
| modules/Scheduling/includes/calcSeats0.fnc.php:37,40, ScheduleReport.php:480,483 | `(CURRENT_DATE>'…END_DATE')`, `'CURRENT_DATE'` | A | patched | Seats filled as of today. |
| modules/Scheduling/includes/ClassSearchWidget.fnc.php:216 | `CURRENT_DATE>=ss.START_DATE …` | A | patched | Currently scheduled. |
| modules/School_Setup/Calendar.php:1129,1135 | `a.ASSIGNED_DATE<=CURRENT_DATE` | A | patched | Assignments shown on the calendar. |
| modules/School_Setup/PortalNotes.php:160, PortalPolls.php:203 | `END_DATE<CURRENT_DATE THEN 'Y' … AS EXPIRED` | A | patched | Expired notes/polls. |
| modules/Students/includes/Dashboard.inc.php:52-53,81 | `CURRENT_DATE>=se.START_DATE …` | A | patched | **Currently enrolled** count. |
| modules/Students/Student.php:278-279 | `CURRENT_DATE>=START_DATE AND (CURRENT_DATE<=END_DATE …)` | A | patched | **Currently enrolled** check. |
| plugins/Content_Security_Policy/functions.php:25 | `VALUES (0, 'CONTENT_SECURITY_POLICY_CRON_DAY', CURRENT_DATE)` | B | — | CRON bookkeeping. |
| `DEFAULT CURRENT_TIMESTAMP`, `CREATED_AT`/`UPDATED_AT` in rosariosis.sql and module install.sql files | | B | — | Created/updated timestamps. |

## 5. JavaScript

| File:line | Snippet | Class | Status | Reason |
|---|---|---|---|---|
| assets/js/jscalendar/calendar.js:600 | `(el.navtype == 0) ? new Date() : …` | A | module | Date picker "Today" button. |
| assets/js/jscalendar/calendar.js:722 | `this.date = this.dateStr ? … : new Date();` | A | module | Date picker default date. |
| assets/js/jscalendar/calendar.js:1051 | `var today = new Date();` in `_init` | A | module | Date picker "today" highlight. |
| assets/js/jscalendar/calendar.js:1461 | `var today = new Date();` in `parseDate` | A | module | Default year when parsing. |
| assets/js/jscalendar/calendar-setup.js:209 | `cal.setDate(new Date());` | A | module | Date picker opens on today when the field is empty. |
| assets/js/jscalendar/calendar.js (other `new Date(x)`) | `new Date(el.caldate)` etc. | N | — | Given dates. |
| assets/js/plugins.min.js | bundle of the above + Chart.js, colorbox, marked, DOMPurify | — | module | The jscalendar copy inside the bundle is what runs; patched at runtime (see [sim-date.md](sim-date.md#client-side)). Others don't use "today". |
| assets/js/warehouse.js, inline PHP | — | — | — | No `new Date()` / `Date.now()` hits. |

The JS calendar is the only client-side "today". The server-side month
calendars (School Setup > Calendar, Add Absences, Daily Menus) are rendered in
PHP and covered in §3.

## Other "today" paths checked

| Path | Finding |
|---|---|
| School year on login | `$_SESSION['UserSyear'] = Config( 'SYEAR' )` (index.php:305, User.fnc.php:35) — comes from `$DefaultSyear`/rollover, never from the date. See C3. |
| Current marking period | `GetCurrentMP( 'QTR', DBDate(), false )` in Side.php:112,124,321 and Search.fnc.php:67 — covered by `DBDate()`. |
| Take attendance for today | `RequestedDate( 'date', DBDate(), 'set' )` (TakeAttendance.php:21) — covered. |
| Report card / progress report print date | `ProperDate( DBDate() )` — covered. |
| Postgres functions/triggers in rosariosis.sql | No `CURRENT_DATE` / `NOW()` uses. |

## Category C decisions

Each needs a decision from the owner. The default below is what is implemented;
each is easy to flip.

| # | Item | Default implemented | Why / how to flip |
|---|---|---|---|
| C1 | **Food Service ledger.** Transactions are stamped with the real `CURRENT_TIMESTAMP` at insert, but "today's transactions", the meals dashboard and the reports filter by date. | Ledger stays real: today's-transaction filters, the meals dashboard and the transaction reports' default ranges use the real date. School-calendar and enrollment parts of Food Service use the sim date. | Mixing the two (real stamps, sim filter) makes "today's transactions" empty. To move the whole ledger onto the sim date, the INSERTs' `CURRENT_TIMESTAMP` would have to become sim date + real time — that conflicts with requirement 6, so it needs an owner call. |
| C2 | Eligibility entry window time of day (EnterEligibility.php:304-305). | Weekday from the sim date, hour from the real clock. | There is no sim time of day. |
| C3 | School year selection. | Unchanged: the school year is whatever `ROSARIOSIS_YEAR`/rollover says. The control page shows a warning when the sim date falls outside the current school year's dates. | Deriving the year from the date would break rollover and logins. To move years, roll over as usual. |
| C4 | Portal alerts "new since your last login" (Discipline, Student Billing). | Range is real last-login date → sim today. | If the sim date is behind the real last login, no alerts show. A fix would mean storing a sim-date last login per user. |
| C5 | Assignment submission time (StudentAssignments.fnc.php:133). | Real. | It is an event timestamp, but teachers may compare it with the (sim) due date. Could be stamped sim date + real time. |
| C6 | Moodle plugin course start (`strtotime( DBDate() )`). | Sim (stock path through `DBDate()`). | Moodle is a real external system; not used in this deployment. |
