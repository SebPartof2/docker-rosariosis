/**
 * Sim Date: the JS date picker (jscalendar) uses the sim date as "today":
 * default date, "Today" button & today highlight.
 *
 * window.SIM_DATE holds the sim date (YYYY-MM-DD), or '' when off.
 * Read from the #sim_date hidden input output in every page footer (fresh after
 * each AJAX page), else from the sim-date meta tag in the head.
 * Off: the date picker is left alone (browser date).
 *
 * Only the date picker sees the sim date: window.Date is swapped while one of its
 * functions runs, then restored. Everything else keeps the browser clock.
 *
 * @see docs/sim-date.md
 *
 * @package RosarioSIS
 * @subpackage modules
 */

(function() {
	'use strict';

	var RealDate = window.Date,
		depth = 0;

	var readSimDate = function() {
		var input = document.getElementById('sim_date'),
			meta = document.querySelector('meta[name="sim-date"]'),
			value = input ? input.getAttribute('data-value') : (meta ? meta.getAttribute('content') : '');

		window.SIM_DATE = /^\d{4}-\d{2}-\d{2}$/.test(value) ? value : '';

		return window.SIM_DATE;
	};

	/**
	 * Date constructor whose "now" is the sim date, at the browser's time of day.
	 * Instances are real Date objects.
	 */
	var simDateClass = function(simDate) {
		var ymd = simDate.split('-');

		var SimDate = function() {
			if (!(this instanceof SimDate)) {
				// Date() called as a function returns a string.
				return new SimDate().toString();
			}

			if (arguments.length === 0) {
				var now = new RealDate();

				return new RealDate(+ymd[0], ymd[1] - 1, +ymd[2],
					now.getHours(), now.getMinutes(), now.getSeconds(), now.getMilliseconds());
			}

			var args = [null].concat(Array.prototype.slice.call(arguments));

			return new (Function.prototype.bind.apply(RealDate, args))();
		};

		SimDate.prototype = RealDate.prototype;

		// jscalendar keeps data on the Date constructor (Date._MD, Date.DAY...).
		for (var key in RealDate) {
			if (RealDate.hasOwnProperty(key)) {
				SimDate[key] = RealDate[key];
			}
		}

		SimDate.now = function() {
			return new SimDate().getTime();
		};
		SimDate.parse = RealDate.parse;
		SimDate.UTC = RealDate.UTC;

		return SimDate;
	};

	var withSimDate = function(fn) {
		return function() {
			// Nested call: Date is already swapped.
			var simDate = depth ? '' : readSimDate();

			if (!simDate) {
				return fn.apply(this, arguments);
			}

			window.Date = simDateClass(simDate);
			depth++;

			try {
				return fn.apply(this, arguments);
			} finally {
				depth--;
				window.Date = RealDate;
			}
		};
	};

	var patchCalendar = function() {
		var Cal = window.Calendar,
			name;

		if (typeof Cal !== 'function' || Cal.simDatePatched) {
			return;
		}

		// jscalendar looks these up by name when it runs, so wrapping them here covers every call.
		for (name in Cal.prototype) {
			if (Cal.prototype.hasOwnProperty(name) && typeof Cal.prototype[name] === 'function') {
				Cal.prototype[name] = withSimDate(Cal.prototype[name]);
			}
		}

		for (name in Cal) {
			if (Cal.hasOwnProperty(name) && typeof Cal[name] === 'function' && name !== 'setup') {
				Cal[name] = withSimDate(Cal[name]);
			}
		}

		// Calendar.setup() adds a click handler to the date picker button: wrap it too.
		var setup = Cal.setup;

		Cal.setup = function(params) {
			var result = withSimDate(setup).apply(this, arguments),
				trigger = params && (params.button || params.displayArea || params.dayField),
				event = 'on' + (params && params.eventName ? params.eventName : 'click');

			if (trigger && typeof trigger[event] === 'function') {
				trigger[event] = withSimDate(trigger[event]);
			}

			return result;
		};

		Cal.simDatePatched = true;
	};

	readSimDate();

	// plugins.min.js (jscalendar) is loaded before this script.
	patchCalendar();

	document.addEventListener('DOMContentLoaded', patchCalendar);
})();
