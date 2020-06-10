<?php

/**
 * Copyright (c) 2010-2015, Jos de Ruijter <jos@dutnie.nl>
 */

/**
 * Class for creating historical stats.
 */
class history
{
	/**
	 * Default settings for this script, which can be overridden in the
	 * configuration file.
	 */
	private $channel = '';
	private $database = 'sss.db3';
	private $mainpage = './';
	private $maxrows_people_day = 10;
	private $maxrows_people_month = 10;
	private $maxrows_people_timeofday = 10;
	private $maxrows_people_year = 10;
	private $stylesheet = 'sss.css';
	private $timezone = 'UTC';
	private $userstats = false;

	/**
	 * Variables that shouldn't be tampered with.
	 */
	private $activity = [];
	private $cid;
	private $color = [
		'afternoon' => 'day',
		'evening' => 'evening',
		'morning' => 'morning',
		'night' => 'night'];
	private $datetime = [];
	private $l_total = 0;

    public function __construct($cid, $year, $month, $day)
	{
		$this->cid = $cid;
		$this->datetime['month'] = $month;
		$this->datetime['year'] = $year;
		$this->datetime['day'] = $day;

		/**
		 * Load settings from vars.php (contained in $settings[]).
		 */
		if ((include 'vars.php') === false) {
			$this->output(null, 'The configuration file could not be read.');
		}

		/**
		 * $cid is the channel ID used in vars.php and is passed along in the URL so
		 * that channel specific settings can be identified and loaded.
		 */
		if (empty($settings[$this->cid])) {
			$this->output(null, 'This channel has not been configured.');
		}

		foreach ($settings[$this->cid] as $key => $value) {
			$this->$key = $value;
		}

		date_default_timezone_set($this->timezone);

		/**
		 * Open the database connection.
		 */
		try {
			$sqlite3 = new SQLite3($this->database, SQLITE3_OPEN_READONLY);
			$sqlite3->busyTimeout(0);
		} catch (Exception $e) {
			$this->output(null, basename(__FILE__).':'.__LINE__.', sqlite3 says: '.$e->getMessage());
		}

		/**
		 * Set SQLite3 PRAGMAs:
		 *  query_only = ON - Disable all changes to database files.
		 *  temp_store = MEMORY - Temporary tables and indices are kept in memory.
		 */
		$pragmas = [
			'query_only' => 'ON',
			'temp_store' => 'MEMORY'];

		foreach ($pragmas as $key => $value) {
			$sqlite3->exec('PRAGMA '.$key.' = '.$value);
		}

		/**
		 * Make stats!
		 */
		echo $this->make_html($sqlite3);
		$sqlite3->close();
	}

	/**
	 * Calculate how many days ago a given $datetime is.
	 */
	private function daysago($datetime)
	{
		/**
		 * Because the amount of seconds in a day can vary due to DST we have
		 * to round the value of $daysago.
		 */
		$daysago = round((strtotime('today') - strtotime(substr($datetime, 0, 10))) / 86400);

		if ($daysago / 365 >= 1) {
			$daysago = str_replace('.0', '', number_format($daysago / 365, 1));
			$daysago .= ' year'.((float) $daysago > 1 ? 's' : '').' ago';
		} elseif ($daysago / 30.42 >= 1) {
			$daysago = str_replace('.0', '', number_format($daysago / 30.42, 1));
			$daysago .= ' month'.((float) $daysago > 1 ? 's' : '').' ago';
		} elseif ($daysago > 1) {
			$daysago .= ' days ago';
		} elseif ($daysago === (float) 1) {
			$daysago = 'Yesterday';
		} elseif ($daysago === (float) 0) {
			$daysago = 'Today';
		}

		return $daysago;
	}

	private function get_activity($sqlite3)
	{
		$query = $sqlite3->query('SELECT SUBSTR(date, 1, 4) AS year, CAST(SUBSTR(date, 6, 2) AS INTEGER) AS month, CAST(SUBSTR(date, 9, 2) AS INTEGER) AS day, SUM(l_total) AS l_total FROM channel_activity GROUP BY year, month, day ORDER BY date ASC;') or $this->output($sqlite3->lastErrorCode(), basename(__FILE__).':'.__LINE__.', sqlite3 says: '.$sqlite3->lastErrorMsg());

		while ($result = $query->fetchArray(SQLITE3_ASSOC)) {
			$this->activity[$result['year']][$result['month']][$result['day']] = $result['l_total'];

			# month check
			if (!isset($this->activity[$result['year']][0])) {
                $this->activity[$result['year']][0] = 0;
            }
			# day check
			if (!isset($this->activity[$result['year']][$result['month']][0])) {
                # there is no day
                $this->activity[$result['year']][$result['month']][0] = 0;
            }

			$this->activity[$result['year']][$result['month']][0] += $result['l_total'];
			$this->activity[$result['year']][0] += $result['l_total'];
		}
	}

    /**
     * Generate the HTML page.
     * @param $sqlite3
     * @return string
     */
	private function make_html($sqlite3)
	{
		if (($daycount = $sqlite3->querySingle('SELECT COUNT(*) FROM channel_activity')) === false) {
			$this->output($sqlite3->lastErrorCode(), basename(__FILE__).':'.__LINE__.', sqlite3 says: '.$sqlite3->lastErrorMsg());
		}

		/**
		 * All queries from this point forward require a non-empty database.
		 */
		if ($daycount === 0) {
			$this->output('error', 'There is not enough data to create statistics, yet.');
		}

		if (($result = $sqlite3->querySingle('SELECT CAST(MIN(SUBSTR(date, 1, 4)) AS INTEGER) AS year_first, CAST(MAX(SUBSTR(date, 1, 4)) AS INTEGER) AS year_last FROM parse_history', true)) === false) {
			$this->output($sqlite3->lastErrorCode(), basename(__FILE__).':'.__LINE__.', sqlite3 says: '.$sqlite3->lastErrorMsg());
		}

		/**
		 * Date and time variables used throughout the script.
		 */
		$this->datetime['year_first'] = $result['year_first'];
		$this->datetime['year_last'] = $result['year_last'];

		if (!is_null($this->datetime['month'])) {
			$this->datetime['monthname'] = date('F', mktime(0, 0, 0, $this->datetime['month'], 1, $this->datetime['year']));
		}

		$this->get_activity($sqlite3);
		
		/**
		 * HTML Head.
		 */
		$output = '<!DOCTYPE html>'."\n\n"
			. '<html lang="en">'."\n\n"
			. '<head>'."\n"
			. '<meta charset="utf-8">'."\n"
			. '<title>Statistics for '.htmlspecialchars($this->channel).'</title>'."\n"
			. '<link rel="stylesheet" href="'.$this->stylesheet.'">'."\n"
			. '</head>'."\n\n"
			. '<body><div id="container">'."\n"
			. '<h1>Historical page for '.htmlspecialchars($this->channel).'</h1>'
			. '<h2>gathered by Rez</h2>'
			. '<div class="info">&lt;&lt; <a href="'.htmlspecialchars($this->mainpage).'">Main page of '.htmlspecialchars($this->channel).'</a><br/><br/>'
			. (is_null($this->datetime['year']) ? '<span class="note">Select year and/or month in the matrix below.</span>' : 
			   'Displaying statistics for '.(!is_null($this->datetime['day']) ? $this->datetime['day'].' ' : '').(!is_null($this->datetime['month']) ? $this->datetime['monthname'].' '.$this->datetime['year'] : 'the year '.$this->datetime['year']).'.')
			. (!is_null($this->datetime['day']) ? ' Lines this day: '.$this->activity[$this->datetime['year']][$this->datetime['month']][$this->datetime['day']].'.' : '').'</div>'."\n";

		/**
		 * Activity section.
		 */
		
		$output .= '<div class="section">Activity</div>'."\n";
		$output .= $this->make_index();

		/**
		 * Only call make_table_* functions for times in which there was activity.
		 */
		if (
			(!is_null($this->datetime['year']) && array_key_exists($this->datetime['year'], $this->activity)) &&
			(is_null($this->datetime['month']) || array_key_exists($this->datetime['month'], $this->activity[$this->datetime['year']])) &&
			(is_null($this->datetime['day']) || array_key_exists($this->datetime['day'], $this->activity[$this->datetime['year']][$this->datetime['month']]))
			) {
			/**
			 * Set $l_total to the total number of lines in the specific scope.
			 */
			if (is_null($this->datetime['month'])) {
				$this->l_total = $this->activity[$this->datetime['year']][0];
				$type = 'year';
			} elseif (is_null($this->datetime['day'])) {
                $this->l_total = $this->activity[$this->datetime['year']][$this->datetime['month']][0];
                $type = 'month';
            }
			else {
				$this->l_total = $this->activity[$this->datetime['year']][$this->datetime['month']][$this->datetime['day']];
				$type = 'day';
			}

			if ($type === 'month') {
			    # not a typo! Build day history for month
                $output .= $this->make_table_activity($sqlite3, 'day');
            }
            $output .= $this->make_table_activity_distribution_hour($sqlite3, $type);
			$output .= $this->make_table_people($sqlite3, $type);
			$output .= $this->make_table_people_timeofday($sqlite3, $type);
		}

		/**
		 * HTML Foot.
		 */
		$output .= '<div class="info">Statistics created on '.date('r').'.</div>'."\n";
		$output .= '</div></body>'."\n\n".'</html>'."\n";
		return $output;
	}

	private function make_index()
	{
		$tr0 = '<colgroup><col class="pos"><col class="c"><col class="c"><col class="c"><col class="c"><col class="c"><col class="c"><col class="c"><col class="c"><col class="c"><col class="c"><col class="c"><col class="c">';
		$tr1 = '<tr><th colspan="13">History';
		$tr2 = '<tr><td class="pos"><td class="k">Jan<td class="k">Feb<td class="k">Mar<td class="k">Apr<td class="k">May<td class="k">Jun<td class="k">Jul<td class="k">Aug<td class="k">Sep<td class="k">Oct<td class="k">Nov<td class="k">Dec';
		$trx = '';

		for ($year = $this->datetime['year_first']; $year <= $this->datetime['year_last']; $year++) {
			if (array_key_exists($year, $this->activity)) {
				$trx .= '<tr><td class="pos"><a href="history.php?cid='.urlencode($this->cid).'&amp;year='.$year.'">'.$year.'</a>';

				for ($month = 1; $month <= 12; $month++) {
					if (array_key_exists($month, $this->activity[$year])) {
						$trx .= '<td class="v"><a href="history.php?cid='.urlencode($this->cid).'&amp;year='.$year.'&amp;month='.$month.'">'.number_format($this->activity[$year][$month][0]).'</a>';
					} else {
						$trx .= '<td class="v"><span class="grey">n/a</span>';
					}
				}
			} else {
				$trx .= '<tr><td class="pos">'.$year.'<td class="v"><span class="grey">n/a</span><td class="v"><span class="grey">n/a</span><td class="v"><span class="grey">n/a</span><td class="v"><span class="grey">n/a</span><td class="v"><span class="grey">n/a</span><td class="v"><span class="grey">n/a</span><td class="v"><span class="grey">n/a</span><td class="v"><span class="grey">n/a</span><td class="v"><span class="grey">n/a</span><td class="v"><span class="grey">n/a</span><td class="v"><span class="grey">n/a</span><td class="v"><span class="grey">n/a</span>';
			}
		}

		return '<table class="index">'.$tr0.$tr1.$tr2.$trx.'</table>'."\n";
	}

    private function make_table_activity($sqlite3, $type)
    {
        $dates = [];
        $days_in_month = cal_days_in_month(CAL_GREGORIAN, $this->datetime['month'], $this->datetime['year']);
        if ($type === 'day') {
            $class = 'act-day';
            $columns = $days_in_month;
            $head = 'Daily';
            $query = $sqlite3->query('SELECT date, l_total, l_night, l_morning, l_afternoon, l_evening FROM channel_activity WHERE date > \''.date('Y-m-d', mktime(0, 0, 0, $this->datetime['month'], 0, $this->datetime['year'])).'\'') or output::output('critical', basename(__FILE__).':'.__LINE__.', sqlite3 says: '.$sqlite3->lastErrorMsg());

            for ($i = $columns - 1; $i >= 0; $i--) {
                $dates[] = date('Y-m-d', mktime(0, 0, 0, $this->datetime['month'], $days_in_month - $i, $this->datetime['year']));
            }
        } else {
            return false;
        }

        if (($result = $query->fetchArray(SQLITE3_ASSOC)) === false) {
            return false;
        }

        $high_date = '';
        $high_value = 0;
        $query->reset();

        while ($result = $query->fetchArray(SQLITE3_ASSOC)) {
            $l_afternoon[$result['date']] = $result['l_afternoon'];
            $l_evening[$result['date']] = $result['l_evening'];
            $l_morning[$result['date']] = $result['l_morning'];
            $l_night[$result['date']] = $result['l_night'];
            $l_total[$result['date']] = $result['l_total'];

            if ($result['l_total'] > $high_value) {
                $high_date = $result['date'];
                $high_value = $result['l_total'];
            }
        }

        $times = ['evening', 'afternoon', 'morning', 'night'];
        $tr1 = '<tr><th colspan="'.$columns.'">'.$head;
        $tr2 = '<tr class="bars">';
        $tr3 = '<tr class="sub">';

        foreach ($dates as $date) {
            if (!array_key_exists($date, $l_total)) {
                $tr2 .= '<td><span class="grey">n/a</span>';
            } else {
                if ($l_total[$date] >= 999500) {
                    $total = number_format($l_total[$date] / 1000000, 1).'M';
                } elseif ($l_total[$date] >= 10000) {
                    $total = round($l_total[$date] / 1000).'k';
                } else {
                    $total = $l_total[$date];
                }

                $height_int['total'] = (int) round(($l_total[$date] / $high_value) * 100);
                $height = $height_int['total'];

                foreach ($times as $time) {
                    if (${'l_'.$time}[$date] !== 0) {
                        $height_float[$time] = (float) (${'l_'.$time}[$date] / $high_value) * 100;
                        $height_int[$time] = (int) floor($height_float[$time]);
                        $height_remainders[$time] = $height_float[$time] - $height_int[$time];
                        $height -= $height_int[$time];
                    } else {
                        $height_int[$time] = 0;
                    }
                }

                if ($height !== 0) {
                    arsort($height_remainders);

                    foreach ($height_remainders as $time => $remainder) {
                        $height--;
                        $height_int[$time]++;

                        if ($height === 0) {
                            break;
                        }
                    }
                }

                $tr2 .= '<td'.($date === 'estimate' ? ' class="est"' : '').'><ul><li class="num" style="height:'.($height_int['total'] + 14).'px">'.$total;

                foreach ($times as $time) {
                    if ($height_int[$time] !== 0) {
                        if ($time === 'evening') {
                            $height_li = $height_int['night'] + $height_int['morning'] + $height_int['afternoon'] + $height_int['evening'];
                        } elseif ($time === 'afternoon') {
                            $height_li = $height_int['night'] + $height_int['morning'] + $height_int['afternoon'];
                        } elseif ($time === 'morning') {
                            $height_li = $height_int['night'] + $height_int['morning'];
                        } elseif ($time === 'night') {
                            $height_li = $height_int['night'];
                        } else {
                            $height_li = '';
                        }

                        $tr2 .= '<li class="'.$this->color[$time].'" style="height:'.$height_li.'px">';
                    }
                }

                $tr2 .= '</ul>';

                /**
                 * It's important to unset $height_remainders so the next iteration won't try to
                 * work with old values.
                 */
                unset($height_remainders);
            }

            if ($type === 'day') {
                $tr3 .= '<td'.($date === $high_date ? ' class="bold"' : '').'><a href="history.php?cid='.urlencode($this->cid).'&amp;year='.$this->datetime['year'].'&amp;month='.$this->datetime['month'].'&amp;day='.date('j', strtotime($date)).'">'.date('D', strtotime($date)).'<br>'.date('j', strtotime($date)).'</a>';
            }
        }

        return '<table class="'.$class.'">'.$tr1.$tr2.$tr3.'</table>'."\n";
    }

	private function make_table_activity_distribution_hour($sqlite3, $type)
	{
	    $query_hours = array();
        for ($h = 0; $h <= 23; $h++) {
            # only full hours are here
            array_push($query_hours, "SUM(l_".sprintf('%02d', $h).") AS l_".sprintf('%02d', $h));
        }
		$query_hours_common = 'SELECT '.implode(', ', $query_hours).' FROM channel_activity';
		$query_date_part = '';
		
	    $hours_result = [];
	    if ($type === 'day') {
			$query_date_part = ' WHERE date LIKE \''.date('Y-m-d', mktime(0, 0, 0, $this->datetime['month'], $this->datetime['day'], $this->datetime['year'])).'%\'';
            if (($hours_result = $sqlite3->querySingle($query_hours_common.$query_date_part, true)) === false) {
                $this->output($sqlite3->lastErrorCode(), basename(__FILE__).':'.__LINE__.', sqlite3 says: '.$sqlite3->lastErrorMsg());
            }
        } elseif ($type === 'month') {
			$query_date_part = ' WHERE date LIKE \''.date('Y-m', mktime(0, 0, 0, $this->datetime['month'], 1, $this->datetime['year'])).'%\'';
			if (($hours_result = $sqlite3->querySingle($query_hours_common.$query_date_part, true)) === false) {
				$this->output($sqlite3->lastErrorCode(), basename(__FILE__).':'.__LINE__.', sqlite3 says: '.$sqlite3->lastErrorMsg());
			}
		} elseif ($type === 'year') {
			$query_date_part = ' WHERE date LIKE \''.$this->datetime['year'].'%\'';
			if (($hours_result = $sqlite3->querySingle($query_hours_common.$query_date_part, true)) === false) {
				$this->output($sqlite3->lastErrorCode(), basename(__FILE__).':'.__LINE__.', sqlite3 says: '.$sqlite3->lastErrorMsg());
			}
		}
		
		# calculation of max height is a worse thing than calculation of everything else.
        $query_height = array();
        for ($h = 0; $h <= 23; $h++) {
            for ($b = 0; $b <= 5; $b++) {
                array_push($query_height, "SUM(l_".sprintf('%02d', $h)."_".$b.") AS l_".sprintf('%02d', $h)."_".$b);
            }
        }
        if (($height_result = $sqlite3->querySingle('SELECT '.implode(', ', $query_height).' FROM channel_activity'.$query_date_part, true)) === false) {
            $this->output($sqlite3->lastErrorCode(), basename(__FILE__).':'.__LINE__.', sqlite3 says: '.$sqlite3->lastErrorMsg());
        }

		$high_key = '';
		$high_value = 0;

		foreach ($height_result as $key => $value) {
			if ($value > $high_value) {
				$high_key = $key;
				$high_value = $value;
			}
		}

		$tr1 = '<tr><th colspan="144">By hour, %';
		$tr2 = '<tr class="bars">';
		$tr3 = '<tr class="sub">';

		foreach ($hours_result as $key => $value) {
			$hour = (int) preg_replace('/^l_0?/', '', $key);

			if ($value === 0) {
				$tr2 .= '<td colspan="6"><span class="grey">n/a</span>';
			} else {
                # make another query per hour
                $query_bins = array();
                for ($b = 0; $b <= 5; $b++) {
                    array_push($query_bins, "SUM(l_" . sprintf('%02d', $hour) . "_" . $b . ") AS l_" . sprintf('%02d', $hour) . "_" . $b);
                }
                if (($bins_result = $sqlite3->querySingle('SELECT '.implode(', ', $query_bins).' FROM channel_activity'.$query_date_part, true)) === false) {
                    $this->output($sqlite3->lastErrorCode(), basename(__FILE__).':'.__LINE__.', sqlite3 says: '.$sqlite3->lastErrorMsg());
                }
				
                # additional height calculation as max of 6 bins
                $height_max = 0;
                foreach ($bins_result as $b_key => $b_value) {
                    $height = round(($b_value / $high_value) * 100);
                    if ($height > $height_max) {
                        $height_max = $height;
                    }
                }
				
				$percentage = ($value / $this->l_total) * 100;

				if ($percentage >= 9.95) {
					$percentage = round($percentage);
				} else {
					$percentage = number_format($percentage, 1);
				}

                $bin_number = 0;
                foreach ($bins_result as $b_key => $b_value) {
                    $height = round(($b_value / $high_value) * 100);

                    # use height_max for percentage
                    $tr2 .= '<td><ul><li class="num" style="height:'.($height_max + 14).'px">'.($bin_number ? '' : $percentage);

                    if ($height !== (float) 0) {
                        if ($hour >= 0 && $hour <= 5) {
                            $time = 'night';
                        } elseif ($hour >= 6 && $hour <= 11) {
                            $time = 'morning';
                        } elseif ($hour >= 12 && $hour <= 17) {
                            $time = 'afternoon';
                        } elseif ($hour >= 18 && $hour <= 23) {
                            $time = 'evening';
                        }

                        $tr2 .= '<li class="'.$this->color[$time].'" style="height:'.$height.'px" title="'.number_format($b_value).'">';
                    }

                    $tr2 .= '</ul>';
                    $bin_number++;
                }

			}

			$tr3 .= '<td colspan="6"'.($key === $high_key ? ' class="bold"' : '').'>'.$hour.'';
		}

		return '<table class="act">'.$tr1.$tr2.$tr3.'</table>'."\n";
	}

	private function make_table_people($sqlite3, $type)
	{
		/**
		 * Only create the table if there is activity from users other than bots and
		 * excluded users.
		 */
		if ($type === 'day') {
            if (($total = $sqlite3->querySingle('SELECT SUM(l_total) FROM ruid_activity_by_day JOIN uid_details ON ruid_activity_by_day.ruid = uid_details.uid WHERE status NOT IN (3,4) AND date = \''.date('Y-m-d', mktime(0, 0, 0, $this->datetime['month'], $this->datetime['day'], $this->datetime['year'])).'\'')) === false) {
                $this->output($sqlite3->lastErrorCode(), basename(__FILE__).':'.__LINE__.', sqlite3 says: '.$sqlite3->lastErrorMsg());
            }
        } elseif ($type === 'month') {
			if (($total = $sqlite3->querySingle('SELECT SUM(l_total) FROM ruid_activity_by_month JOIN uid_details ON ruid_activity_by_month.ruid = uid_details.uid WHERE status NOT IN (3,4) AND date = \''.date('Y-m', mktime(0, 0, 0, $this->datetime['month'], 1, $this->datetime['year'])).'\'')) === false) {
				$this->output($sqlite3->lastErrorCode(), basename(__FILE__).':'.__LINE__.', sqlite3 says: '.$sqlite3->lastErrorMsg());
			}
		} elseif ($type === 'year') {
			if (($total = $sqlite3->querySingle('SELECT SUM(l_total) FROM ruid_activity_by_year JOIN uid_details ON ruid_activity_by_year.ruid = uid_details.uid WHERE status NOT IN (3,4) AND date = \''.$this->datetime['year'].'\'')) === false) {
				$this->output($sqlite3->lastErrorCode(), basename(__FILE__).':'.__LINE__.', sqlite3 says: '.$sqlite3->lastErrorMsg());
			}
		}

		if (empty($total)) {
			return null;
		}

		if ($type === 'day') {
		    $head = 'Most Talkative People &ndash; '.$this->datetime['day'].' '.$this->datetime['monthname'].' '.$this->datetime['year'];
            $query = $sqlite3->query('SELECT csnick, ruid_activity_by_day.l_total AS l_total, ruid_activity_by_day.l_night AS l_night, ruid_activity_by_day.l_morning AS l_morning, ruid_activity_by_day.l_afternoon AS l_afternoon, ruid_activity_by_day.l_evening AS l_evening, quote, (SELECT MAX(lastseen) FROM uid_details WHERE ruid = ruid_activity_by_day.ruid) AS lastseen FROM ruid_activity_by_day JOIN uid_details ON ruid_activity_by_day.ruid = uid_details.uid JOIN ruid_lines ON ruid_activity_by_day.ruid = ruid_lines.ruid WHERE status NOT IN (3,4) AND date = \''.date('Y-m-d', mktime(0, 0, 0, $this->datetime['month'], $this->datetime['day'], $this->datetime['year'])).'\' ORDER BY l_total DESC, ruid_activity_by_day.ruid ASC LIMIT '.$this->maxrows_people_day) or $this->output($sqlite3->lastErrorCode(), basename(__FILE__).':'.__LINE__.', sqlite3 says: '.$sqlite3->lastErrorMsg());
        } elseif ($type === 'month') {
			$head = 'Most Talkative People &ndash; '.$this->datetime['monthname'].' '.$this->datetime['year'];
			$query = $sqlite3->query('SELECT csnick, ruid_activity_by_month.l_total AS l_total, ruid_activity_by_month.l_night AS l_night, ruid_activity_by_month.l_morning AS l_morning, ruid_activity_by_month.l_afternoon AS l_afternoon, ruid_activity_by_month.l_evening AS l_evening, quote, (SELECT MAX(lastseen) FROM uid_details WHERE ruid = ruid_activity_by_month.ruid) AS lastseen FROM ruid_activity_by_month JOIN uid_details ON ruid_activity_by_month.ruid = uid_details.uid JOIN ruid_lines ON ruid_activity_by_month.ruid = ruid_lines.ruid WHERE status NOT IN (3,4) AND date = \''.date('Y-m', mktime(0, 0, 0, $this->datetime['month'], 1, $this->datetime['year'])).'\' ORDER BY l_total DESC, ruid_activity_by_month.ruid ASC LIMIT '.$this->maxrows_people_month) or $this->output($sqlite3->lastErrorCode(), basename(__FILE__).':'.__LINE__.', sqlite3 says: '.$sqlite3->lastErrorMsg());
		} elseif ($type === 'year') {
			$head = 'Most Talkative People &ndash; '.$this->datetime['year'];
			$query = $sqlite3->query('SELECT csnick, ruid_activity_by_year.l_total AS l_total, ruid_activity_by_year.l_night AS l_night, ruid_activity_by_year.l_morning AS l_morning, ruid_activity_by_year.l_afternoon AS l_afternoon, ruid_activity_by_year.l_evening AS l_evening, quote, (SELECT MAX(lastseen) FROM uid_details WHERE ruid = ruid_activity_by_year.ruid) AS lastseen FROM ruid_activity_by_year JOIN uid_details ON ruid_activity_by_year.ruid = uid_details.uid JOIN ruid_lines ON ruid_activity_by_year.ruid = ruid_lines.ruid WHERE status NOT IN (3,4) AND date = \''.$this->datetime['year'].'\' ORDER BY l_total DESC, ruid_activity_by_year.ruid ASC LIMIT '.$this->maxrows_people_year) or $this->output($sqlite3->lastErrorCode(), basename(__FILE__).':'.__LINE__.', sqlite3 says: '.$sqlite3->lastErrorMsg());
		}

		$i = 0;
		$times = ['night', 'morning', 'afternoon', 'evening'];
		$tr0 = '<colgroup><col class="c1"><col class="c2"><col class="pos"><col class="c3"><col class="c4"><col class="c5"><col class="c6">';
		$tr1 = '<tr><th colspan="7">'.$head;
		$tr2 = '<tr><td class="k1">Percent<td class="k2">Lines<td class="pos"><td class="k3">User<td class="k4">Time<td class="k5">Last Seen<td class="k6">Last Quote';
		$trx = '';

		while ($result = $query->fetchArray(SQLITE3_ASSOC)) {
			$i++;
			$width = 100;
			$width_int = [];

			foreach ($times as $time) {
				if ($result['l_'.$time] !== 0) {
					$width_float[$time] = (float) ($result['l_'.$time] / $result['l_total']) * 100;
					$width_int[$time] = (int) floor($width_float[$time]);
					$width_remainders[$time] = $width_float[$time] - $width_int[$time];
					$width -= $width_int[$time];
				}
			}

			if ($width !== 0) {
				arsort($width_remainders);

				foreach ($width_remainders as $time => $remainder) {
					$width--;
					$width_int[$time]++;

					if ($width === 0) {
						break;
					}
				}
			}

			$when = '';

			foreach ($times as $time) {
				if (!empty($width_int[$time])) {
					$when .= '<li class="'.$this->color[$time].'" style="width:'.$width_int[$time].'px">';
				}
			}

			$trx .= '<tr><td class="v1">'.number_format(($result['l_total'] / $total) * 100, 2).'%<td class="v2">'.number_format($result['l_total']).'<td class="pos">'.$i.'<td class="v3">'.($this->userstats ? '<a href="user.php?cid='.urlencode($this->cid).'&amp;nick='.urlencode($result['csnick']).'">'.htmlspecialchars($result['csnick']).'</a>' : htmlspecialchars($result['csnick'])).'<td class="v4"><ul>'.$when.'</ul><td class="v5">'.$this->daysago($result['lastseen']).'<td class="v6">'.htmlspecialchars($result['quote']);
			unset($width_float, $width_int, $width_remainders);
		}

		return '<table class="ppl">'.$tr0.$tr1.$tr2.$trx.'</table>'."\n";
	}

	private function make_table_people_timeofday($sqlite3, $type)
	{
		/**
		 * Only create the table if there is activity from users other than bots and
		 * excluded users.
		 */
		if ($type === 'day') {
            if (($total = $sqlite3->querySingle('SELECT SUM(l_total) FROM ruid_activity_by_day JOIN uid_details ON ruid_activity_by_day.ruid = uid_details.uid WHERE status NOT IN (3,4) AND date = \''.date('Y-m-d', mktime(0, 0, 0, $this->datetime['month'], $this->datetime['day'], $this->datetime['year'])).'\'')) === false) {
                $this->output($sqlite3->lastErrorCode(), basename(__FILE__).':'.__LINE__.', sqlite3 says: '.$sqlite3->lastErrorMsg());
            }
        } elseif ($type === 'month') {
			if (($total = $sqlite3->querySingle('SELECT SUM(l_total) FROM ruid_activity_by_month JOIN uid_details ON ruid_activity_by_month.ruid = uid_details.uid WHERE status NOT IN (3,4) AND date = \''.date('Y-m', mktime(0, 0, 0, $this->datetime['month'], 1, $this->datetime['year'])).'\'')) === false) {
				$this->output($sqlite3->lastErrorCode(), basename(__FILE__).':'.__LINE__.', sqlite3 says: '.$sqlite3->lastErrorMsg());
			}
		} elseif ($type === 'year') {
			if (($total = $sqlite3->querySingle('SELECT SUM(l_total) FROM ruid_activity_by_year JOIN uid_details ON ruid_activity_by_year.ruid = uid_details.uid WHERE status NOT IN (3,4) AND date = \''.$this->datetime['year'].'\'')) === false) {
				$this->output($sqlite3->lastErrorCode(), basename(__FILE__).':'.__LINE__.', sqlite3 says: '.$sqlite3->lastErrorMsg());
			}
		}

		if (empty($total)) {
			return null;
		}

		$high_value = 0;
		$times = ['night', 'morning', 'afternoon', 'evening'];

		foreach ($times as $time) {
			if ($type === 'day') {
                $query = $sqlite3->query('SELECT csnick, l_'.$time.' FROM ruid_activity_by_day JOIN uid_details ON ruid_activity_by_day.ruid = uid_details.uid WHERE date = \''.date('Y-m-d', mktime(0, 0, 0, $this->datetime['month'], $this->datetime['day'], $this->datetime['year'])).'\' AND status NOT IN (3,4) AND l_'.$time.' != 0 ORDER BY l_'.$time.' DESC, ruid_activity_by_day.ruid ASC LIMIT '.$this->maxrows_people_timeofday) or $this->output($sqlite3->lastErrorCode(), basename(__FILE__).':'.__LINE__.', sqlite3 says: '.$sqlite3->lastErrorMsg());
            } elseif ($type === 'month') {
				$query = $sqlite3->query('SELECT csnick, l_'.$time.' FROM ruid_activity_by_month JOIN uid_details ON ruid_activity_by_month.ruid = uid_details.uid WHERE date = \''.date('Y-m', mktime(0, 0, 0, $this->datetime['month'], 1, $this->datetime['year'])).'\' AND status NOT IN (3,4) AND l_'.$time.' != 0 ORDER BY l_'.$time.' DESC, ruid_activity_by_month.ruid ASC LIMIT '.$this->maxrows_people_timeofday) or $this->output($sqlite3->lastErrorCode(), basename(__FILE__).':'.__LINE__.', sqlite3 says: '.$sqlite3->lastErrorMsg());
			} elseif ($type === 'year') {
				$query = $sqlite3->query('SELECT csnick, l_'.$time.' FROM ruid_activity_by_year JOIN uid_details ON ruid_activity_by_year.ruid = uid_details.uid WHERE date = \''.$this->datetime['year'].'\' AND status NOT IN (3,4) AND l_'.$time.' != 0 ORDER BY l_'.$time.' DESC, ruid_activity_by_year.ruid ASC LIMIT '.$this->maxrows_people_timeofday) or $this->output($sqlite3->lastErrorCode(), basename(__FILE__).':'.__LINE__.', sqlite3 says: '.$sqlite3->lastErrorMsg());
			}

			$i = 0;

			while ($result = $query->fetchArray(SQLITE3_ASSOC)) {
				$i++;
				${$time}[$i]['lines'] = $result['l_'.$time];
				${$time}[$i]['user'] = $result['csnick'];

				if (${$time}[$i]['lines'] > $high_value) {
					$high_value = ${$time}[$i]['lines'];
				}
			}
		}

		$tr0 = '<colgroup><col class="pos"><col class="c"><col class="c"><col class="c"><col class="c">';
		$tr1 = '<tr><th colspan="5">Most Talkative People by Time of Day';
		$tr2 = '<tr><td class="pos"><td class="k">Night (0 - 5)<td class="k">Morning (6 - 11)<td class="k">Afternoon (12 - 17)<td class="k">Evening (18 - 23)';
		$trx = '';

		for ($i = 1; $i <= $this->maxrows_people_timeofday; $i++) {
			if (!isset($night[$i]['lines']) && !isset($morning[$i]['lines']) && !isset($afternoon[$i]['lines']) && !isset($evening[$i]['lines'])) {
				break;
			}

			$trx .= '<tr><td class="pos">'.$i;

			foreach ($times as $time) {
				if (!isset(${$time}[$i]['lines'])) {
					$trx .= '<td class="v">';
				} else {
					$width = round((${$time}[$i]['lines'] / $high_value) * 225);

					if ($width !== (float) 0) {
						$trx .= '<td class="v">'.htmlspecialchars(${$time}[$i]['user']).' - '.number_format(${$time}[$i]['lines']).'<br><div class="'.$this->color[$time].'" style="width:'.$width.'px"></div>';
					} else {
						$trx .= '<td class="v">'.htmlspecialchars(${$time}[$i]['user']).' - '.number_format(${$time}[$i]['lines']);
					}
				}
			}
		}

		return '<table class="ppl-tod">'.$tr0.$tr1.$tr2.$trx.'</table>'."\n";
	}

    /**
     * For compatibility reasons this function has the same name as the original
     * version in the base class and accepts the same arguments. Its functionality
     * is slightly different in that it exits on any type of message passed to it.
     * SQLite3 result code 5 = SQLITE_BUSY, result code 6 = SQLITE_LOCKED.
     * @param $code
     * @param $msg
     */
	private function output($code, $msg)
	{
		if ($code === 5 || $code === 6) {
			$msg = 'Statistics are currently being updated, this may take a minute.';
		}

		exit('<!DOCTYPE html>'."\n\n".'<html lang="en"><head><meta charset="utf-8"><title>seriously?</title><link rel="stylesheet" href="sss.css"></head><body><div id="container"><div class="error">'.htmlspecialchars($msg).'</div></div></body></html>'."\n");
	}
}

/**
 * The channel ID must be set, cannot be empty and cannot be of excessive
 * length.
 */
if (empty($_GET['cid']) || !preg_match('/^\S{1,32}$/', $_GET['cid'])) {
	exit('<!DOCTYPE html>'."\n\n".'<html lang="en"><head><meta charset="utf-8"><title>seriously?</title><link rel="stylesheet" href="sss.css"></head><body><div id="container"><div class="error">Invalid channel ID.</div></div></body></html>'."\n");
}

$cid = $_GET['cid'];

/**
 * Pass along a null value if the year and/or month are not set.
 */
if (isset($_GET['year']) && preg_match('/^[12][0-9]{3}$/', $_GET['year'])) {
	$year = (int) $_GET['year'];

	if (isset($_GET['month']) && preg_match('/^([1-9]|1[0-2])$/', $_GET['month'])) {
		$month = (int) $_GET['month'];

	} else {
		$month = null;
	}
    if (isset($_GET['day']) && preg_match('/^([1-9]|[1-3][0-9])$/', $_GET['day'])) {
        $day = (int) $_GET['day'];
    } else {
        $day = null;
    }
} else {
	$month = null;
	$year = null;
	$day = null;
}

/**
 * Make stats!
 */
$history = new history($cid, $year, $month, $day);
