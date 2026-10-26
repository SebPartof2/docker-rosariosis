# Sim Date acceptance checks

Run on 2026-10-26 (real date: Monday 2026-10-26, Q2 / S1) against an image
built from this branch (RosarioSIS 12.9.3, PHP 8.3, PostgreSQL 15), in a
separate Docker Compose project with its own volumes. Requests were made with
curl as admin, teacher, student and parent, plus a headless browser (Edge, via
the DevTools protocol) for the date picker.

Test data, added to the stock demo database:

- Default calendar: Monday–Friday school days from 2026-06-15 to 2027-06-11,
  minus two holidays: Thu 2026-11-26 and Mon 2027-02-15.
- The demo student's enrollment starts **2027-01-04**, so student login
  depends on "today".
- A Physics course period (teacher "teacher"), the student scheduled in it, and
  an assignment "Lab Report" (Q3, assigned 2027-01-25, due 2027-02-05).

Stock school year 2026: FY 2026-06-13 → 2027-06-12; Q1 from 06-13, Q2 from
09-14, Q3 from 2027-01-01, Q4 from 2027-03-15; S2 from 2027-01-01.

| # | Check | Result |
|---|---|---|
| 1 | No sim date: behaves like stock | **Pass.** Page shows "Off (using real date)", Q2 / S1, school day yes. Calendar opens on 2026-10, attendance date defaults to 2026-10-26, the admin's marking period is Q2. The student cannot log in ("Your account has not yet been activated"): enrollment starts in 2027, as in stock. |
| 2 | Sim date = first day of school (2026-06-15) | **Pass.** Q1 / S1 / Full Year, school day yes. Calendar opens on 2026-06. Attendance defaults to 2026-06-15. The admin's current marking period moved to Q1 on the next page load. School year stays 2026 (comes from `ROSARIOSIS_YEAR`, see C3 in the audit). |
| 3 | Holiday / weekend | **Pass.** Thu 2026-11-26 (holiday) and Sat 2026-11-28 both show "School Day: No (Weekend or holiday)". Teacher Take Attendance on the holiday Mon 2027-02-15 gives stock's "You cannot take attendance for this period on this day"; on 2027-02-16 the roster loads and is editable. |
| 4 | Next school day | **Pass.** Wed 11-25 → Fri 11-27 (skips the holiday). Fri 11-27 → Mon 11-30 (skips the weekend). Fri 2027-02-12 → Tue 02-16 (skips the weekend and the Monday holiday). Fri 2027-06-11 (last day) → "There is no school day left after this date on the calendar." +1 day 2026-12-31 → 2027-01-01, then −1 day → 2026-12-31. |
| 5 | Second semester (2027-02-01) | **Pass.** Q3 / S2. Admin and teacher marking periods are Q3. The teacher gradebook shows "Lab Report" under Quarter 3. The student can now log in, and their portal lists "Lab Report" as upcoming. Back at 2026-12-01 the student is locked out again. Report card printing was not run end to end (needs grades and the PDF renderer); its date is `ProperDate( DBDate() )`, the function every other check here exercises. |
| 6 | Login, logout, sessions; real timestamps | **Pass.** With the sim date at 2027-02-16, logins work and logout ends the session. `access_log.CREATED_AT`, `staff.LAST_LOGIN`, `students.LAST_LOGIN` and `sim_date_log.CREATED_AT` all show the real time, 2026-10-26 14:15 Anchorage time (22:15 UTC). |
| 7 | Restart / redeploy | **Pass.** After `docker compose restart web`, and after recreating both the web and db containers, the sim date was still 2027-02-16. |
| 8 | Non-admin opens the page by URL | **Pass.** Teacher, student and parent: GET and POST (trying to set 2030-01-01) are refused by Modules.php, which logs "HACKING ATTEMPT" (6 entries) and redirects to the portal. The sim date was unchanged. Before the module was activated, an admin was refused too. |
| 9 | Clear | **Pass.** "The sim date is off: using the real date." Everything is identical to check 1. The clear is logged (2027-02-16 → off). |

## Additional checks

| Check | Result |
|---|---|
| Patch applies to pristine 12.9.3 | `patch --fuzz=0` applies all 56 files, both locally and in the Docker build. |
| PHP lint | All 56 patched files and the module files pass `php -l`. |
| Every patched page, sim date set (2027-02-01) | 60 page loads across admin, teacher, student and parent (portals and dashboards, attendance, discipline, eligibility, food service, grades, scheduling incl. seats and class lists, calendar month and list, portal notes and polls, billing, students, access log). No DB errors, no PHP warnings or fatals in the container log. Student Summary, Balance Report and Anomalous Grades are not in the stock admin profile; Anomalous Grades ran as teacher. |
| Invalid date | The date selects can't post a bad date (Rosario clamps Feb 30 to Feb 28). A corrupted stored value (`2026-13-45; DROP`) is ignored: real date used, never reaches SQL. |
| Out-of-year warning | Sim date 2026-02-28 shows "Today (February 28 2026) is outside the 2026-2027 school year…". |
| Date picker (headless Edge) | Sim date on: `window.SIM_DATE` = 2027-02-01, the picker's today highlight is 2027-02-01, its **Today** button picks 2027-02-01, `new Date()` outside the picker is still 2026-10-26, and `Date` is restored. Off: the picker uses the browser date, as in stock. The first run found a bug (jscalendar keeps data on the `Date` constructor); fixed in SimDate.js and re-tested in a rebuilt image. |
| Time zone | `config.inc.php` without `$Timezone`: the first start adds it, the second start leaves it alone (1 copy), and PHP reads `America/Anchorage`. Rows written by Rosario are in Anchorage time. |

## Found, not fixed (stock RosarioSIS)

The jscalendar date picker reads the field as `new Date('YYYY-MM-DD')`, which
JavaScript treats as UTC midnight. In any browser time zone west of UTC
(all of the Americas, including Anchorage) the picker opens on the
**previous day**. This happens with or without a sim date. The browser test
above pins the browser to Europe/Berlin to test around it.
