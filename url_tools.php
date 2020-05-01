<?php declare(strict_types=1);

/**
 * Copyright (c) 2007-2020, Jos de Ruijter <jos@dutnie.nl>
 */

/**
 * Class handling URL validation. Returns null if a URL fails the syntax check.
 * Returns an array with the URLs components on success. Missing components are
 * represented by an empty string, a missing port component by (int) 0.
 *
 * For reference: RFC 3986, RFC 1034 section 3.5, RFC 1123 section 2.1
 *
 * Additional notes:
 *  - The scheme should either be http or https. For URLs without a scheme, http
 *    is assumed.
 *  - User part in authority is not recognized and will not validate.
 *  - IPv6 addresses will not validate.
 *  - Square brackets must be percent encoded.
 */
class url_tools
{
	private static string $regexp_callback = '';
	private static string $regexp_complete = '';

	private function __construct()
	{
		/**
		 * This is a static class and should not be instantiated.
		 */
	}

	/**
	 * Validate a given URL.
	 */
	public static function get_components(string $url): ?array
	{
		/**
		 * Assemble the regular expression if not already done so.
		 */
		if (self::$regexp_complete === '') {
			$domain = '(?<domain>[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*)';
			$tld = '(?<tld>[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)';
			$fqdn = '(?<fqdn>'.$domain.'\.'.$tld.')\.?';
			$ipv4address = '(?<ipv4address>(25[0-5]|(2[0-4]|1[0-9]|[1-9])?[0-9])(\.(25[0-5]|(2[0-4]|1[0-9]|[1-9])?[0-9])){3})';
			$port = '(?<port>(6553[0-5]|(655[0-2]|(65[0-4]|(6[0-4]|[1-5][0-9]|[1-9])[0-9]|[1-9])[0-9]|[1-9])?[0-9]))';
			$authority = '(?<authority>('.$ipv4address.'|'.$fqdn.')(:'.$port.')?)';
			$unreserved = '[a-z0-9_.~-]';
			$pct_encoded = '%[0-9a-f]{2}';
			$sub_delims = '[!$&\'()*+,;=]';
			$pchar = '('.$unreserved.'|'.$pct_encoded.'|'.$sub_delims.'|[:@])';
			$fragment = '(?<fragment>(#('.$pchar.'|[\/?])*)?)';
			$path = '(?<path>(\/\/?('.$pchar.'+\/?)*)?)';
			$query = '(?<query>(\?('.$pchar.'|[\/?])*)?)';
			$scheme = '((?<scheme>https?):\/\/)';
			self::$regexp_callback = '/^'.$scheme.'?'.$authority.'/i';
			self::$regexp_complete = '/^(?<url>'.$scheme.'?'.$authority.$path.$query.$fragment.')$/i';
		}

		/**
		 * Convert scheme and authority to lower case.
		 */
		$url = preg_replace_callback(self::$regexp_callback, function (array $matches): string {
			return strtolower($matches[0]);
		}, $url);

		/**
		 * Validate the URL.
		 */
		if (!preg_match(self::$regexp_complete, $url, $matches, PREG_UNMATCHED_AS_NULL)) {
			return null;
		}

		/**
		 * The TLD may not consist of all digits.
		 */
		if (!is_null($matches['tld']) && preg_match('/^\d+$/', $matches['tld'])) {
			return null;
		}

		/**
		 * The FQDN (excluding trailing dot) may not exceed 253 characters.
		 */
		if (!is_null($matches['fqdn']) && strlen($matches['fqdn']) > 253) {
			return null;
		}

		/**
		 * If the URL has no scheme, http is assumed.
		 */
		if (is_null($matches['scheme'])) {
			$matches['scheme'] = 'http';
			$matches['url'] = 'http://'.$matches['url'];
		}

		/**
		 * Create an array with all the components of the URL.
		 */
		$components = ['url', 'scheme', 'authority', 'ipv4address', 'fqdn', 'domain', 'tld', 'path', 'query', 'fragment'];

		foreach ($components as $component) {
			if (is_null($matches[$component])) {
				/**
				 * Nonexistent components are returned as an empty string.
				 */
				$url_components[$component] = '';
			} else {
				$url_components[$component] = $matches[$component];
			}
		}

		/**
		 * The port component should be of type integer. 0 means no port.
		 */
		if (is_null($matches['port'])) {
			$url_components['port'] = 0;
		} else {
			$url_components['port'] = (int) $matches['port'];
		}

		return $url_components;
	}
}
