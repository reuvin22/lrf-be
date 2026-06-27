/**
 * LRF — automatic salary close
 *
 * Paste this into the same Apps Script project as Code.gs (Extensions ▸ Apps
 * Script ▸ add a file, e.g. SalaryClose.gs), set BACKEND_URL below, then run
 * installSalaryTrigger() ONCE to schedule it.
 *
 * How it works:
 *   • A daily time-driven trigger runs runSalaryClose().
 *   • It reads `closing_day` from the SystemSettings tab.
 *   • On the closing day only, it calls the backend POST /api/v1/salary/close,
 *     which computes each employee's salary with the PHP SalaryCalculator (rates
 *     from the Settings sheet) and writes the "Salary Record" tab.
 *
 * The maths lives in the backend (single source of truth); this script is just
 * the clock that detects the date and triggers it. See SALARY_CALCULATION note.
 */

// ── CONFIG ───────────────────────────────────────────────────────────────────
// Base URL of the deployed backend (no trailing slash needed). Example:
//   var BACKEND_URL = 'https://lrf-api.example.com';
var BACKEND_URL = 'https://CHANGE-ME.example.com';

/**
 * Daily trigger entry point. Does nothing except on the closing day.
 */
function runSalaryClose() {
  var ss       = SpreadsheetApp.getActiveSpreadsheet();
  var settings = readSystemSettings_(ss);
  var closingDay = Number(settings['closing_day'] || 10);

  var today = new Date();
  if (!isClosingDay_(closingDay, today)) {
    return; // not the closing day — skip
  }

  var url = BACKEND_URL.replace(/\/+$/, '') + '/api/v1/salary/close';

  var res = UrlFetchApp.fetch(url, {
    method: 'post',
    contentType: 'application/json',
    payload: JSON.stringify({}), // backend defaults to the previous calendar month
    muteHttpExceptions: true
  });

  Logger.log('salary/close → HTTP ' + res.getResponseCode() + ' : ' + res.getContentText());
}

/**
 * True when `today` is the closing day. If closing_day exceeds the month's
 * length (e.g. 31 in February), it fires on the last day of the month instead.
 */
function isClosingDay_(closingDay, today) {
  var d = today.getDate();
  if (d === closingDay) return true;

  var lastDay = new Date(today.getFullYear(), today.getMonth() + 1, 0).getDate();
  return closingDay > lastDay && d === lastDay;
}

/**
 * Read the SystemSettings tab (key/value) into a map.
 * Columns: A system_settings_id | B key | C value | D description.
 */
function readSystemSettings_(ss) {
  var sheet = ss.getSheetByName('SystemSettings');
  var map = {};
  if (!sheet) return map;

  var lastRow = sheet.getLastRow();
  if (lastRow < 2) return map;

  var rows = sheet.getRange(2, 1, lastRow - 1, 3).getValues();
  for (var i = 0; i < rows.length; i++) {
    var key = rows[i][1];
    if (key) map[String(key)] = rows[i][2];
  }
  return map;
}

/**
 * Run ONCE from the editor to schedule the daily check. Re-running replaces the
 * existing trigger (no duplicates). Authorise external requests + triggers when
 * prompted.
 */
function installSalaryTrigger() {
  var triggers = ScriptApp.getProjectTriggers();
  for (var i = 0; i < triggers.length; i++) {
    if (triggers[i].getHandlerFunction() === 'runSalaryClose') {
      ScriptApp.deleteTrigger(triggers[i]);
    }
  }

  ScriptApp.newTrigger('runSalaryClose')
    .timeBased()
    .everyDays(1)
    .atHour(2) // ~02:00 in the script's timezone
    .create();
}

/**
 * Manual helper: run the close now (ignores the date check) for testing.
 */
function runSalaryCloseNow() {
  var url = BACKEND_URL.replace(/\/+$/, '') + '/api/v1/salary/close';
  var res = UrlFetchApp.fetch(url, {
    method: 'post',
    contentType: 'application/json',
    payload: JSON.stringify({}),
    muteHttpExceptions: true
  });
  Logger.log('salary/close (manual) → HTTP ' + res.getResponseCode() + ' : ' + res.getContentText());
}
