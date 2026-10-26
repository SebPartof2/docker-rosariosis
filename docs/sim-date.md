# Sim Date

RosarioSIS normally takes "today" from the server clock. With Sim Date, "today"
is a date an admin sets: the **sim date**. The school year then runs on an
in-world calendar that moves only when an admin moves it.

- It is a date only. There is no simulated time of day.
- It changes only when an admin changes it, never on its own.
- It is stored in the database, so it survives restarts and redeploys.
- When no sim date is set, RosarioSIS behaves exactly like stock.
- Real events keep the real clock: logins, sessions, access log, password
  resets, cookies, created/updated timestamps, error emails.

## Using it

### First-time setup (once per database)

1. Deploy this branch.
2. Log in as an administrator and go to **School > Configuration > Modules**.
   Activate **Sim_Date**. This runs [install.sql](../modules/Sim_Date/install.sql):
   it creates the `SIM_DATE` setting, the `sim_date_log` table, and gives the
   page to the Administrator profiles.
3. Log out and back in.

### The control page

Open it by URL. It is in no menu:

```
https://<your-host>/Modules.php?modname=Sim_Date/SimDate.php
```

It shows:

- the **real date** and the **sim date** (or "Off (using real date)")
- the current **quarter, semester and full year** for "today"
- whether "today" is a **school day** on the school's default calendar
- a warning when "today" falls outside the current school year

Controls:

| Control | Does |
|---|---|
| **Set Date** + Save | Sets the sim date. |
| **−1 Day / +1 Day** | Moves "today" by one calendar day (from the real date if the sim date is off). |
| **Next School Day** | Jumps to the next day with minutes on the default calendar, skipping weekends and holidays. |
| **Clear** | Turns the sim date off: back to the real date. |

Every change is logged with the real time, the user, and the old and new value.
The last 50 are listed on the page; all of them are in the `sim_date_log` table.

### Who can open it

Only users with an Administrator profile. Modules.php checks the program is
allowed for the user's profile (`profile_exceptions`) before loading it, and
logs anyone else as a hacking attempt; the page also checks the user is an
admin who can edit. Because the page is in no menu, it never appears in
**Users > User Profiles**, so nobody can grant it to another profile by
accident.

Admins whose permissions are set per user (no profile, `staff_exceptions`)
don't get it. To give it to one, insert a `staff_exceptions` row for
`Sim_Date/SimDate.php` with `CAN_USE='Y'` and `CAN_EDIT='Y'`.

### Things to know

- **School year.** The school year is still chosen by `ROSARIOSIS_YEAR` and
  rollover, not by the date. Move the sim date within the school year;
  roll over to start a new one. The page warns when "today" is outside the year.
- **Marking period.** When the sim date moves, each logged-in user is moved to
  the marking period of the new "today" on their next page load.
- **Date pickers** pick up a new sim date on the next page. A page that was
  open before the change still has the old date until it is reloaded or you
  navigate.
- **Deactivating the module** removes the page and the date-picker patch but
  does **not** clear the sim date: the server keeps using it. Clear it first.
- **Food Service** keeps its ledger on the real clock (see C1 in the
  [audit](sim-date-audit.md#category-c-decisions)).
- **Date picker off by one day (stock bug).** In browsers west of UTC the
  RosarioSIS date picker opens on the day *before* the date in the field. This
  is stock RosarioSIS, with or without a sim date; see
  [sim-date-acceptance.md](sim-date-acceptance.md#found-not-fixed-stock-rosariosis).

## How it works

### Storage

`config` table, `TITLE='SIM_DATE'`, `SCHOOL_ID=0` (global), value `YYYY-MM-DD`
or empty. Read and written with RosarioSIS' own `Config()` function. The value
is validated on write (a real calendar date) and again on every read: an invalid
value is ignored and the real date is used, so nothing unvalidated reaches SQL.

### Server side: one function

[patches/rosariosis/sim-date.patch](../patches/rosariosis/sim-date.patch)
edits RosarioSIS core:

- `DBDate()` in `functions/Date.php`, RosarioSIS' "today" function, now
  returns `SimDate()`. About 200 places already use it, among them attendance,
  the current marking period, the gradebook, report card print dates and
  enrollment checks.
- `SimDate()` (same file) returns the sim date, else `date( 'Y-m-d' )`.
  `Config()` caches the config table for the request, so it costs no extra query.
- Code that read the clock directly for a school "today" now uses `DBDate()`:
  SQL `CURRENT_DATE`, `date( 'Y-m' )` report defaults, calendar default months,
  student age, Eligibility weeks. In SQL the date goes in as
  `'" . DBDate() . "'`, or `CAST('" . DBDate() . "' AS DATE)` where the
  value is used in date arithmetic or a `CASE`.
- The other way round: code that used `DBDate()` against **real** timestamps
  (access log, CSP reports and CRON, Food Service transaction reports) now
  uses `date( 'Y-m-d' )`, so it stays real.

Every edit is marked with a `// SIMDATE` comment. The list of every date/time
read in RosarioSIS, with what was done to each, is in
[sim-date-audit.md](sim-date-audit.md).

### Client side

[modules/Sim_Date/functions.php](../modules/Sim_Date/functions.php) outputs
the sim date in a `<meta name="sim-date">` tag in the head and in a hidden
`#sim_date` input in every page footer. The footer is refreshed on every AJAX
page, the head is not.

[modules/Sim_Date/js/SimDate.js](../modules/Sim_Date/js/SimDate.js) sets
`window.SIM_DATE` and wraps the date picker (jscalendar): while one of its
functions runs, `window.Date` is replaced by a constructor whose "now" is the
sim date (at the browser's time of day), then restored. That covers the
picker's default date, its **Today** button and its today highlight. Other
scripts keep the real browser clock. It is a separate file because RosarioSIS'
Content Security Policy blocks inline scripts.

The month calendars (School Setup > Calendars, Attendance > Add Absences, Food
Service > Daily Menus) are drawn by PHP and use `DBDate()`.

### Time zone

`America/Anchorage`, the in-world location, used when the real date is the
fallback. It is set in three places:

- `ROSARIOSIS_TIMEZONE` env var (Dockerfile, docker-compose.yml)
- `$Timezone` in `config.inc.php`, which RosarioSIS uses for PHP and for the
  PostgreSQL session (`SET TIMEZONE`). [bin/init](../bin/init) adds it to an
  existing `config.inc.php` on start, since that file is kept across restarts.
- `date.timezone` in the PHP ini, for anything that runs before `config.inc.php`.

The PostgreSQL server itself is not changed: only Rosario's session time zone is.

## Where things are

| Path | What |
|---|---|
| [patches/rosariosis/sim-date.patch](../patches/rosariosis/sim-date.patch) | Core edits, applied by the Dockerfile at build |
| [modules/Sim_Date/](../modules/Sim_Date/) | Control page, history log, date picker patch |
| [Dockerfile](../Dockerfile) | Applies the patch (`--fuzz=0`, the build fails if it does not apply); time zone ini |
| [bin/init](../bin/init) | Adds `$Timezone` to existing `config.inc.php` |
| [docs/sim-date-audit.md](sim-date-audit.md) | Audit of every date/time read |

This repo builds RosarioSIS from the upstream release tarball rather than from
a fork, so the core edits are kept as a patch file in this repo, on the
`sim-date` branch, and applied during the image build. `bin/init` copies the
patched code over `/var/www/html` on each container start.

## Upgrading RosarioSIS

The Dockerfile applies the patch with `--fuzz=0`, so after a version bump the
build either applies it exactly or fails. It never half-applies.

1. Bump `ROSARIOSIS_VERSION` in the Dockerfile and build. If it builds, check
   the new version for new date reads (step 4) and you're done.
2. If the patch fails, rebuild it against the new version:

   ```bash
   mkdir rosario && cd rosario
   curl -L https://gitlab.com/francoisjacquet/rosariosis/-/archive/vX.Y.Z/rosariosis-vX.Y.Z.tar.gz \
     | tar xz --strip-components=1
   git init -q && git add -A && git commit -qm upstream
   patch -p1 --forward --merge < ../patches/rosariosis/sim-date.patch
   ```

3. Resolve the conflicts (`git diff` shows them; search for `SIMDATE` to see
   every edit). Then regenerate the patch from inside `rosario/`:
   `git diff > ../patches/rosariosis/sim-date.patch`
4. Look for new date reads the new version added, and classify them as in
   the audit:

   ```bash
   grep -rnE "CURRENT_DATE|date\( *'|time\(\)|date_create\( *'now'|new Date\(\)" \
     --include=*.php --include=*.js . | grep -v SIMDATE
   ```

   Also check `DBDate()` callers that compare against real timestamps
   (`CREATED_AT`, `LAST_LOGIN`, `access_log`, `TIMESTAMP`).
5. If jscalendar is changed or replaced, check `SimDate.js` still wraps
   `Calendar` and `Calendar.setup`.
6. Update `sim-date-audit.md` and the `francoisjacquet/rosariosis` version in
   `modules/Sim_Date/composer.json`.

## Checking it

With a sim date set, the quickest checks are:

- the control page shows the expected marking period and school day
- Attendance > Take Attendance opens on the sim date
- School Setup > Calendars opens on the sim date's month, and the date
  picker's Today button picks the sim date
- School Setup > Access Log still shows real login times

Acceptance check results are in [sim-date-acceptance.md](sim-date-acceptance.md).
