Sim Date module for RosarioSIS
==============================

Hidden admin page that sets the school's in-world "today" (the **sim date**).

This module is only the control page and the date picker patch. The sim date
itself is applied by a core patch (`patches/rosariosis/sim-date.patch`) that
the Dockerfile applies at build time.

Full documentation: [docs/sim-date.md](../../docs/sim-date.md).

Page: `Modules.php?modname=Sim_Date/SimDate.php` (Administrators only, in no menu).
