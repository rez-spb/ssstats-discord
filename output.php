<?php

/**
 * Copyright (c) 2010-2016, Jos de Ruijter <jos@dutnie.nl>
 */

/**
 * Class for handling output messages.
 */
class output
{
	private static $outputbits = 1;
	private static $prevmessage = '';

	private function __construct()
	{
		/**
		 * This is a static class and should not be instantiated.
		 */
	}

	/**
	 * Output a given message to the console.
	 */
	public static function output($type, $message)
	{
		$datetime = date('M d H:i:s');

		if (substr($datetime, 4, 1) === '0') {
			$datetime = substr_replace($datetime, ' ', 4, 1);
		}

		/**
		 * Critical messages will always display and are followed by the termination of
		 * the program.
		 */
		if ($type === 'critical') {
			exit($datetime.' [C] '.$message."\n");
		}

		/**
		 * Avoid repeating the same message multiple times in a row, e.g. repeated lines
		 * and errors related to mode changes.
		 */
		if ($message === self::$prevmessage) {
			return;
		}

		if ($type === 'notice') {
			if (self::$outputbits & 1) {
				echo $datetime.' [ ] '.$message."\n";
			}
		} elseif ($type === 'debug') {
			if (self::$outputbits & 2) {
				echo $datetime.' [D] '.$message."\n";
			}
		}

		/**
		 * Remember the last message displayed.
		 */
		self::$prevmessage = $message;
	}

	/**
	 * Set the amount of bits corresponding to the type(s) of output messages
	 * displayed. By default all but debug messages will be displayed. This can be
	 * changed in the configuration file.
	 *
	 *  0  Critical events (will always display)
	 *  1  Notices
	 *  2  Debug messages
	 */
	public static function set_outputbits($outputbits)
	{
		self::$outputbits = $outputbits;
	}
}
