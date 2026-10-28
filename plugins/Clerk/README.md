Clerk plugin
============

Syncs RosarioSIS users to [Clerk](https://clerk.com) and adds **Sign in with Clerk** to the RosarioSIS login page.

**RosarioSIS is the source of truth.** The plugin only ever writes to Clerk:

| RosarioSIS | Clerk |
| --- | --- |
| User / student with a username, active | User created, or username, first & last name, email and `public_metadata.rosario` brought back in line with RosarioSIS |
| Profile _No Access_, student not enrolled today, user deleted | Banned (setting), unbanned when active again |
| Profile not selected for sync | Left alone |
| Clerk user not created by the plugin (other external ID) | Never touched |

- **Username**: the RosarioSIS username, as is.
- **Default password**: first name + last name + month of birth, lowercase, letters and digits only. _John O'Neil_ born in March: `johnoneil03`. Month format (`03`, `3`, `march`, `mar`) and case are settings. It is only set when the user is created in Clerk; users without a birthdate are created without a password.
- **Password changes** made in RosarioSIS (user edited by an admin, or _My Preferences > Password_) are sent to Clerk (setting).
- **Users synced**: users (staff table) of the default school year, and students, for the profiles you pick: Administrator, Teacher, Parent, Student.

## How users are linked

Each Clerk user gets an external ID: `rosario-staff-<STAFF_ID>` or `rosario-student-<STUDENT_ID>`, and public metadata:

```json
{ "rosario": { "type": "staff", "id": 12, "profile": "teacher" } }
```

A Clerk user that already exists with the same username and no external ID is adopted (its own password is kept). After Rollover, users get a new `STAFF_ID`; the sync moves the Clerk user to it by username instead of banning it.

SSO maps the Clerk user to RosarioSIS **by external ID only**, never by username or email: users may be allowed to change those in Clerk.

## Setup

### 1. Clerk Dashboard

1. **User & authentication**: enable **Username**, and **Password**. Email is optional: the plugin sends it when RosarioSIS has one (user email, or the student email field set in _School > Configuration_). If your instance requires an email address, users without one cannot be created.
2. **API keys**: copy the **Secret key**.
3. **Domains**: copy the **Frontend API URL**, for example `https://clerk.example.com`.
4. **OAuth applications** (only for Sign in with Clerk): add an application, with scopes `openid profile email`. Redirect URI: `https://<rosariosis-domain>/index.php?clerk_sso=callback` (shown in the plugin configuration). Copy the **Client ID** and **Client Secret**.

### 2. Environment variables (Dokploy > Environment)

| Variable | |
| --- | --- |
| `CLERK_SECRET_KEY` | Backend API secret key (`sk_live_...`). Required. |
| `CLERK_FRONTEND_API` | Frontend API URL. Required for SSO. |
| `CLERK_OAUTH_CLIENT_ID` | OAuth application Client ID. Required for SSO. |
| `CLERK_OAUTH_CLIENT_SECRET` | OAuth application Client Secret. Required for SSO. |
| `CLERK_OAUTH_REDIRECT_URI` | Optional, overrides the redirect URI if the detected one is wrong (proxy). |

Dokploy writes them to a `.env` file next to `docker-compose.yml`, which loads it into the web container with `env_file`. Do not also list them under `environment:` in the compose file: a `${VAR}` substitution that comes out empty there overrides the `env_file` value. Secrets are never stored in the database.

If the configuration page shows them missing, it also lists every variable name containing "clerk" that PHP can see, and whether `ROSARIO_REST_API_SECRET` has a value in PHP. **"set, but empty"** means the container has the variable with an empty value: something is overriding it, usually an `environment:` entry in the compose file.

Fallback: define them as constants at the end of `config.inc.php` (on the volume), for example `define( 'CLERK_SECRET_KEY', 'sk_live_...' );`. Environment variables win when both are set.

### 3. RosarioSIS

1. _School > Configuration > Plugins_: activate **Clerk**, then click **Configuration**.
2. Check the environment variables are detected, click **Test Connection**.
3. Pick the profiles to sync and the birthdate fields (students: _Birthdate_; users: any date field in _Users > User Fields_, none by default).
4. Click **Preview**: it lists what the sync would do, changing nothing. Clerk validation errors (a username Clerk refuses, for example) only show up on the real sync.
5. Click **Sync Now**. Errors are in the **Sync Log** below.

After that, users are synced when they are saved in RosarioSIS (setting). Run a full sync on a schedule to catch the rest: enrollment ending, imports, Rollover, edits made outside the user screens:

```bash
# Host crontab or Dokploy scheduled task, every night at 2:00.
0 2 * * * docker compose -f /path/to/docker-compose.yml exec -T web php plugins/Clerk/sync.php
```

`--dry-run` previews. Exit code: `0` OK, `1` some users failed (see the log), `2` could not sync.

## Sign in with Clerk

A **Sign in with Clerk** link is added under the login form (the text is a setting). The user signs in on Clerk, with whatever methods your Clerk instance offers (password, email code, Google...), and is logged in to RosarioSIS as the linked user, if that user is active: same checks as the password login (profile not _No Access_, student enrolled today). The login is written to the Access Log.

Logging out of RosarioSIS does not sign the user out of Clerk.

## Security notes

- The default password is guessable by anyone who knows the user's name and birth month. Clerk's password checks (length, breached passwords) are skipped for it. Ask users to change it, or rely on other Clerk sign-in methods.
- Passwords sent from RosarioSIS also skip Clerk's checks: RosarioSIS applies its own password strength rules.
- Clerk usernames must follow Clerk's rules (by default letters, digits, `-` and `_`). RosarioSIS usernames that break them (`john.smith`) are reported as errors and not created: rename them in RosarioSIS, or relax the rules in Clerk.

## Files

- `functions.php`: login link, SSO requests, automatic sync hooks.
- `config.inc.php`: configuration page: status, settings, sync, reset passwords, log.
- `sync.php`: command line sync.
- `includes/Api.fnc.php`: Clerk Backend API & HTTP client.
- `includes/Sync.fnc.php`: sync logic, default password.
- `includes/SSO.fnc.php`: OAuth 2.0 authorization code flow with PKCE, RosarioSIS login.
- `install.sql`, `install_mysql.sql`: settings, `clerk_sync_log` table (changes & errors, 90 days).

## Requirements

RosarioSIS 12.9+, PHP 7.4+ with the curl extension (included in the Docker image).
