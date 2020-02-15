<?php

/**
 * Copyright (c) 2007-2020, Jos de Ruijter <jos@dutnie.nl>
 */

declare(strict_types=1);

/**
 * Class for performing database maintenance.
 */
class maintenance
{
	public function __construct(object $sqlite3)
	{
		$this->main($sqlite3);
	}

	/**
	 * Calculate on which date a user reached certain milestones.
	 */
	private function calculate_milestones(object $sqlite3): void
	{
		$sqlite3->exec('DELETE FROM ruid_milestones') or output::output('critical', basename(__FILE__).':'.__LINE__.', sqlite3 says: '.$sqlite3->lastErrorMsg());
		$query = $sqlite3->query('SELECT ruid_activity_by_day.ruid AS ruid, date, l_total FROM ruid_activity_by_day JOIN uid_details ON ruid_activity_by_day.ruid = uid_details.uid WHERE status NOT IN (3,4) ORDER BY ruid ASC, date ASC') or output::output('critical', basename(__FILE__).':'.__LINE__.', sqlite3 says: '.$sqlite3->lastErrorMsg());

		while ($result = $query->fetchArray(SQLITE3_ASSOC)) {
			if (!isset($l_total[$result['ruid']])) {
				$l_total[$result['ruid']] = $result['l_total'];
				$milestones = [1000, 2500, 5000, 10000, 25000, 50000, 100000, 250000, 500000, 1000000];
				$milestone = array_shift($milestones);
			} else {
				$l_total[$result['ruid']] += $result['l_total'];
			}

			while (!is_null($milestone) && $l_total[$result['ruid']] >= $milestone) {
				$sqlite3->exec('INSERT INTO ruid_milestones (ruid, milestone, date) VALUES ('.$result['ruid'].', '.$milestone.', \''.$result['date'].'\')') or output::output('critical', basename(__FILE__).':'.__LINE__.', sqlite3 says: '.$sqlite3->lastErrorMsg());
				$milestone = array_shift($milestones);
			}
		}
	}

	/**
	 * Create materialized views, which are actual stored copies of virtual tables
	 * (views).
	 */
	private function create_materialized_views(object $sqlite3): void
	{
		/**
		 * Data from the views below (v_ruid_*) will be stored as materialized views
		 * (ruid_*) in the database. The order in which they are processed is important,
		 * as some views depend on materialized views created prior to them.
		 */
		$views = [
			'v_ruid_activity_by_day' => 'ruid_activity_by_day',
			'v_ruid_activity_by_month' => 'ruid_activity_by_month',
			'v_ruid_activity_by_year' => 'ruid_activity_by_year',
			'v_ruid_smileys' => 'ruid_smileys',
			'v_ruid_events' => 'ruid_events',
			'v_ruid_lines' => 'ruid_lines'];

		foreach ($views as $view => $table) {
			$sqlite3->exec('DELETE FROM '.$table) or output::output('critical', basename(__FILE__).':'.__LINE__.', sqlite3 says: '.$sqlite3->lastErrorMsg());
			$sqlite3->exec('INSERT INTO '.$table.' SELECT * FROM '.$view) or output::output('critical', basename(__FILE__).':'.__LINE__.', sqlite3 says: '.$sqlite3->lastErrorMsg());
		}
	}

	/**
	 * The file "tlds-alpha-by-domain.txt" contains all TLDs which are currently
	 * active on the internet. Cross-match this list with the TLDs we have stored
	 * in our database and deactivate those that do not match. We expect the
	 * aforementioned file to be present, readable and up to date.
	 */
	private function deactivate_fqdns(object $sqlite3): void
	{
		if (($rp = realpath('tlds-alpha-by-domain.txt')) === false) {
			output::output('notice', __METHOD__.'(): no such file: \'tlds-alpha-by-domain.txt\', skipping tld validation');
			return;
		}

		if (($fp = fopen($rp, 'rb')) === false) {
			output::output('notice', __METHOD__.'(): failed to open file: \''.$rp.'\', skipping tld validation');
			return;
		}

		while (($line = fgets($fp)) !== false) {
			if (preg_match('/^(?<tld>[^#\s]+)/', $line, $matches)) {
				$tlds_active[] = '\''.strtolower($matches['tld']).'\'';
			}
		}

		fclose($fp);
		$sqlite3->exec('UPDATE fqdns SET active = 1') or output::output('critical', basename(__FILE__).':'.__LINE__.', sqlite3 says: '.$sqlite3->lastErrorMsg());

		if (!empty($tlds_active)) {
			$sqlite3->exec('UPDATE fqdns SET active = 0 WHERE tld NOT IN ('.implode(',', $tlds_active).')') or output::output('critical', basename(__FILE__).':'.__LINE__.', sqlite3 says: '.$sqlite3->lastErrorMsg());
			output::output('debug', __METHOD__.'(): deactivated '.$sqlite3->changes().' fqdn'.($sqlite3->changes() !== 1 ? 's' : ''));
		}
	}

	private function main(object $sqlite3): void
	{
		output::output('notice', __METHOD__.'(): performing database maintenance routines');
		$sqlite3->exec('BEGIN TRANSACTION') or output::output('critical', basename(__FILE__).':'.__LINE__.', sqlite3 says: '.$sqlite3->lastErrorMsg());
		output::output('notice', __METHOD__.'(): (1/4) registering most active aliases');
		$this->register_most_active_aliases($sqlite3);
		output::output('notice', __METHOD__.'(): (2/4) creating materialized views');
		$this->create_materialized_views($sqlite3);
		output::output('notice', __METHOD__.'(): (3/4) calculating milestones');
		$this->calculate_milestones($sqlite3);
		output::output('notice', __METHOD__.'(): (4/4) deactivating invalid fqdns');
		$this->deactivate_fqdns($sqlite3);
		output::output('notice', __METHOD__.'(): committing data');
		$sqlite3->exec('COMMIT') or output::output('critical', basename(__FILE__).':'.__LINE__.', sqlite3 says: '.$sqlite3->lastErrorMsg());
	}

	/**
	 * Make the alias with the most lines the new registered nick for the user or
	 * bot it is linked to.
	 */
	private function register_most_active_aliases(object $sqlite3): void
	{
		$query = $sqlite3->query('SELECT status, csnick, ruid, (SELECT uid_details.uid AS uid FROM uid_details JOIN uid_lines ON uid_details.uid = uid_lines.uid WHERE ruid = t1.ruid ORDER BY l_total DESC, uid ASC LIMIT 1) AS new_ruid FROM uid_details AS t1 WHERE status IN (1,3,4) AND new_ruid IS NOT NULL AND ruid != new_ruid') or output::output('critical', basename(__FILE__).':'.__LINE__.', sqlite3 says: '.$sqlite3->lastErrorMsg());

		while ($result = $query->fetchArray(SQLITE3_ASSOC)) {
			$old_registered_nick = $result['csnick'];

			if (($new_registered_nick = $sqlite3->querySingle('SELECT csnick FROM uid_details WHERE uid = '.$result['new_ruid'])) === false) {
				output::output('critical', basename(__FILE__).':'.__LINE__.', sqlite3 says: '.$sqlite3->lastErrorMsg());
			}

			$sqlite3->exec('UPDATE uid_details SET ruid = '.$result['new_ruid'].', status = '.$result['status'].' WHERE uid = '.$result['new_ruid']) or output::output('critical', basename(__FILE__).':'.__LINE__.', sqlite3 says: '.$sqlite3->lastErrorMsg());
			$sqlite3->exec('UPDATE uid_details SET ruid = '.$result['new_ruid'].', status = 2 WHERE ruid = '.$result['ruid']) or output::output('critical', basename(__FILE__).':'.__LINE__.', sqlite3 says: '.$sqlite3->lastErrorMsg());
			output::output('debug', __METHOD__.'(): \''.$new_registered_nick.'\' new registered nick for \''.$old_registered_nick.'\'');
		}
	}
}
