<?php

/**
 * Parse instructions for the discord logfile format.
 *
 * Line         Format                                                  Notes
 * ---------------------------------------------------------------------------------------------------------------------
 * Normal       NICK: MSG                                               Skip empty lines.
 * ---------------------------------------------------------------------------------------------------------------------
 *
 * Notes:
 * - normalize_line() scrubs all lines before passing them on to parse_line().
 * - Given that nicks can't contain ":" the order of the regular expressions below is irrelevant (current order aims for
 *   best performance).
 * - Booooring...
 */
class parser_discord extends parser
{
	/**
	 * Parse a line for various chat data.
	 */
	protected function parse_line($line)
	{
		/**
		 * "Normal" lines.
		 */
		if (preg_match('/^\[(?<time>\d{2}:\d{2}(:\d{2})?)\] (?<nick>.+?#\d{4}): (?<line>.+)$/', $line, $matches)) {
			$this->set_normal($matches['time'], $matches['nick'], $matches['line']);

		/**
		 * Skip everything else.
		 */
		} elseif ($line !== '') {
			output::output('debug', __METHOD__.'(): skipping line '.$this->linenum.': \''.$line.'\'');
		}
	}
}
?>
