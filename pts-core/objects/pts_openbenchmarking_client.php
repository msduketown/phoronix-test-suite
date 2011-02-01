<?php

/*
	Phoronix Test Suite
	URLs: http://www.phoronix.com, http://www.phoronix-test-suite.com/
	Copyright (C) 2010 - 2011, Phoronix Media
	Copyright (C) 2010 - 2011, Michael Larabel

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

class pts_openbenchmarking_client
{
	private static $openbenchmarking_account = false;

	public static function upload_test_result(&$object)
	{
		if($object instanceof pts_test_run_manager)
		{
			$result_file = new pts_result_file($object->get_file_name());
			$local_file_name = $object->get_file_name();
			$results_identifier = $object->get_results_identifier();
		}
		else if($object instanceof pts_result_file)
		{
			$result_file = &$object;
			$local_file_name = $result_file->get_identifier();
			$results_identifier = null;
		}

		// Validate the XML
		if($result_file->xml_parser->validate() == false)
		{
			echo "\nErrors occurred parsing the result file XML.\n";
			return false;
		}

		// Ensure the results can be shared
		if(self::result_upload_supported($result_file) == false)
		{
			return false;
		}

		$composite_xml = $result_file->xml_parser->getXML();
		$system_log_location = PTS_SAVE_RESULTS_PATH . $result_file->get_identifier() . '/system-logs/';

		if(pts_config::read_bool_config(P_OPTION_ALWAYS_UPLOAD_SYSTEM_LOGS, 'FALSE'))
		{
			$upload_system_logs = P_OPTION_ALWAYS_UPLOAD_SYSTEM_LOGS;
		}
		else if(is_dir($system_log_location))
		{
			$upload_system_logs = pts_user_io::prompt_bool_input('Would you like to attach the system logs (lspci, dmesg, lsusb, etc) to the test result', true, 'UPLOAD_SYSTEM_LOGS');
		}

		$system_logs = null;
		$system_logs_hash = null;
		if($upload_system_logs && is_dir($system_log_location))
		{
			$is_valid_log = true;
			$finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;

			foreach(pts_file_io::glob($system_log_location . '*') as $log_dir)
			{
				if($is_valid_log == false || !is_dir($log_dir))
				{
					$is_valid_log = false;
					break;
				}

				foreach(pts_file_io::glob($log_dir . '/*') as $log_file)
				{
					if(!is_file($log_file))
					{
						$is_valid_log = false;
						break;
					}

					if($finfo && substr(finfo_file($finfo, $log_file), 0, 5) != 'text/')
					{
						$is_valid_log = false;
						break;
					}
				}
			}

			if($is_valid_log)
			{
				$system_logs_zip = pts_client::create_temporary_file();
				pts_compression::zip_archive_create($system_logs_zip, $system_log_location);

				if(filesize($system_logs_zip) < 102400)
				{
					// If it's about 100kb, probably too big
					$system_logs = base64_encode(file_get_contents($system_logs_zip));
					$system_logs_hash = sha1($system_logs);
				}

				unlink($system_logs_zip);
			}
		}

		$to_post = array(
			'composite_xml' => base64_encode($composite_xml),
			'composite_xml_hash' => sha1($composite_xml),
			'local_file_name' => $local_file_name,
			'this_results_identifier' => $results_identifier,
			'system_logs_zip' => $system_logs,
			'system_logs_hash' => $system_logs_hash
			);

		$json_response = pts_openbenchmarking::make_openbenchmarking_request('upload_test_result', $to_post);
		$json_response = json_decode($json_response, true);

		if(!is_array($json_response))
		{
			echo "\nERROR: Unhandled Exception\n";
			return false;
		}

		if(isset($json_response['openbenchmarking']['upload']['error']))
		{
			echo "\nERROR: " . $json_response['openbenchmarking']['upload']['error'] . "\n";
		}
		if(isset($json_response['openbenchmarking']['upload']['url']))
		{
			echo "\nResults Uploaded To: " . $json_response['openbenchmarking']['upload']['url'] . "\n";
			pts_module_manager::module_process("__event_openbenchmarking_upload", $json_response);
		}
		//$json['openbenchmarking']['upload']['id']

		return isset($json_response['openbenchmarking']['upload']['url']) ? $json_response['openbenchmarking']['upload']['url'] : false;
	}
	public static function init_account($openbenchmarking)
	{
		if(isset($openbenchmarking['user_name']) && isset($openbenchmarking['communication_id']) && isset($openbenchmarking['sav']))
		{
			if(IS_FIRST_RUN_TODAY)
			{
				// Might as well make sure OpenBenchmarking.org account has the latest system info
				// But don't do it everytime to preserve bandwidth
				$openbenchmarking['s_s'] = base64_encode(phodevi::system_software(true));
				$openbenchmarking['s_h'] = base64_encode(phodevi::system_hardware(true));
			}

			$return_state = pts_openbenchmarking::make_openbenchmarking_request('account_verify', $openbenchmarking);
			$json = json_decode($return_state, true);

			if(isset($json['openbenchmarking']['account']['valid']))
			{
				// The account is valid
				self::$openbenchmarking_account = $openbenchmarking;
			}
		}
	}
	public static function make_openbenchmarking_request($request, $post = array())
	{
		$url = pts_openbenchmarking::openbenchmarking_host() . 'f/client.php';
		$to_post = array_merge(array(
			'r' => $request,
			'client_version' => PTS_CORE_VERSION,
			'gsid' => PTS_GSID
			), $post);

		if(is_array(self::$openbenchmarking_account))
		{
			$to_post = array_merge($to_post, self::$openbenchmarking_account);
		}

		return pts_network::http_upload_via_post($url, $to_post);
	}
	protected static function result_upload_supported(&$result_file)
	{
		foreach($result_file->get_result_objects() as $result_object)
		{
			$test_profile = new pts_test_profile($result_object->test_profile->get_identifier());

			if($test_profile->allow_results_sharing() == false)
			{
				echo PHP_EOL . $result_object->test_profile->get_identifier() . " does not allow test results to be uploaded.\n\n";
				return false;
			}
		}

		return true;
	}
	public static function refresh_repository_lists($repos = null, $force_refresh = false)
	{
		if($repos == null)
		{
			if(!defined('HAS_REFRESHED_OBO_LIST') && $force_refresh == false)
			{
				define('HAS_REFRESHED_OBO_LIST', true);
			}
			else
			{
				return true;
			}

			$repos = self::linked_repositories();
		}

		foreach($repos as $repo_name)
		{
			pts_file_io::mkdir(PTS_OPENBENCHMARKING_SCRATCH_PATH . $repo_name);

			if($repo_name == 'local')
			{
				// Local is a special case, not actually a real repository
				continue;
			}

			$index_file = PTS_OPENBENCHMARKING_SCRATCH_PATH . $repo_name . '.index';

			if(is_file($index_file))
			{
				$repo_index = json_decode(file_get_contents($index_file), true);
				$generated_time = $repo_index['main']['generated'];

				// TODO: time zone differences causes this not to be exact if not on server time
				// Refreshing the indexes once every few days should be suffice
				if($generated_time > (time() - (86400 * 3)) && $force_refresh == false)
				{
					// The index is new enough
					continue;
				}
			}

			$server_index = pts_openbenchmarking::make_openbenchmarking_request('repo_index', array('repo' => $repo_name));

			if(json_decode($server_index) != false)
			{
				file_put_contents($index_file, $server_index);
			}
		}
	}
	public static function download_test_profile($qualified_identifier, $hash_check = null)
	{
		if(is_file(PTS_TEST_PROFILE_PATH . $qualified_identifier . '/test-definition.xml'))
		{
			return true;
		}

		$file = PTS_OPENBENCHMARKING_SCRATCH_PATH . $qualified_identifier . '.zip';

		if(!is_file($file))
		{
			$test_profile = pts_openbenchmarking::make_openbenchmarking_request('download_test', array('i' => $qualified_identifier));

			if($hash_check == null || $hash_check = sha1($test_profile))
			{
				// save it
				file_put_contents($file, $test_profile);
				$hash_check = null;
			}
		}

		if(!is_file(PTS_TEST_PROFILE_PATH . $qualified_identifier . '/test-definition.xml') && ($hash_check == null || sha1_file($file) == $hash_check))
		{
			// extract it
			pts_file_io::mkdir(PTS_TEST_PROFILE_PATH . dirname($qualified_identifier));
			pts_file_io::mkdir(PTS_TEST_PROFILE_PATH . $qualified_identifier);
			return pts_compression::zip_archive_extract($file, PTS_TEST_PROFILE_PATH . $qualified_identifier);
		}

		return false;
	}
	public static function download_test_suite($qualified_identifier, $hash_check = null)
	{
		if(is_file(PTS_TEST_SUITE_PATH . $qualified_identifier . '/suite-definition.xml'))
		{
			return true;
		}

		$file = PTS_OPENBENCHMARKING_SCRATCH_PATH . $qualified_identifier . '.zip';

		if(!is_file($file))
		{
			$test_profile = pts_openbenchmarking::make_openbenchmarking_request('download_suite', array('i' => $qualified_identifier));

			if($hash_check == null || $hash_check = sha1($test_profile))
			{
				// save it
				file_put_contents($file, $test_profile);
				$hash_check = null;
			}
		}

		if(!is_file(PTS_TEST_SUITE_PATH . $qualified_identifier . '/suite-definition.xml') && ($hash_check == null || sha1_file($file) == $hash_check))
		{
			// extract it
			pts_file_io::mkdir(PTS_TEST_SUITE_PATH . dirname($qualified_identifier));
			pts_file_io::mkdir(PTS_TEST_SUITE_PATH . $qualified_identifier);
			return pts_compression::zip_archive_extract($file, PTS_TEST_SUITE_PATH . $qualified_identifier);
		}

		return false;
	}
	public static function evaluate_string_to_qualifier($supplied, $bind_version = true)
	{
		$qualified = false;
		$repos = self::linked_repositories();

		if(($c = strpos($supplied, '/')) !== false)
		{
			// A repository was explicitly defined
			$c_repo = substr($supplied, 0, $c);
			$test = substr($supplied, ($c + 1));

			// If it's in the linked repo list it should have refreshed when starting client
			if(!in_array($c_repo, $repos))
			{
				// Pull in this repository's index
				$repos = array($c_repo);
				pts_openbenchmarking_client::refresh_repository_lists($repos);
			}
		}
		else
		{
			// If it's in the linked repo list it should have refreshed when starting client
			$test = $supplied;
		}

		if(($c = strrpos($test, '-')) !== false)
		{
			$version = substr($test, ($c + 1));
			$version_length = strlen($version);

			// TODO: functionalize this and read against types.xsd
			if($version_length >= 5 && $version_length <= 8 && strlen(pts_strings::keep_in_string($version, (pts_strings::CHAR_NUMERIC | pts_strings::CHAR_DECIMAL))) == $version_length)
			{
				$test = substr($test, 0, $c);
			}
			else
			{
				$version = null;
			}
		}
		else
		{
			$version = null;
		}

		foreach($repos as $repo)
		{
			if($repo == 'local')
			{
				if(is_file(PTS_TEST_PROFILE_PATH . $repo . '/' . $test . '/test-definition.xml'))
				{
					return $repo . '/' . $test; // ($bind_version ? '-' . $version : null)
				}
				else if(is_file(PTS_TEST_SUITE_PATH . $repo . '/' . $test . '/suite-definition.xml'))
				{
					return $repo . '/' . $test; // ($bind_version ? '-' . $version : null)
				}
			}

			$repo_index = pts_openbenchmarking::read_repository_index($repo);

			if(is_array($repo_index) && isset($repo_index['tests'][$test]))
			{
				// The test profile at least exists

				// Looking for a particular test profile version?
				if($version != null)
				{
					if(in_array($version, $repo_index['tests'][$test]['versions']))
					{
						pts_openbenchmarking_client::download_test_profile("$repo/$test-$version", $repo_index['tests'][$test]['package_hash']);
						return $repo . '/' . $test . ($bind_version ? '-' . $version : null);
					}
				}
				else
				{
					// Assume to use the latest version
					$version = array_shift($repo_index['tests'][$test]['versions']);
					pts_openbenchmarking_client::download_test_profile("$repo/$test-$version", $repo_index['tests'][$test]['package_hash']);
					return $repo . '/' . $test . ($bind_version ? '-' . $version : null);
				}
			}
			if(is_array($repo_index) && isset($repo_index['suites'][$test]))
			{
				// The test profile at least exists

				// Looking for a particular test profile version?
				if($version != null)
				{
					if(in_array($version, $repo_index['suites'][$test]['versions']))
					{
						pts_openbenchmarking_client::download_test_suite("$repo/$test-$version", $repo_index['suites'][$test]['package_hash']);
						return $repo . '/' . $test . ($bind_version ? '-' . $version : null);
					}
				}
				else
				{
					// Assume to use the latest version
					$version = array_shift($repo_index['suites'][$test]['versions']);
					pts_openbenchmarking_client::download_test_suite("$repo/$test-$version", $repo_index['suites'][$test]['package_hash']);
					return $repo . '/' . $test . ($bind_version ? '-' . $version : null);
				}
			}
		}

		return false;
	}
	public static function available_tests()
	{
		$available_tests = array();

		foreach(self::linked_repositories() as $repo)
		{
			$repo_index = pts_openbenchmarking::read_repository_index($repo);

			if(isset($repo_index['tests']) && is_array($repo_index['tests']))
			{
				foreach(array_keys($repo_index['tests']) as $identifier)
				{
					array_push($available_tests, $repo . '/' . $identifier);
				}
			}
		}

		return $available_tests;
	}
	public static function available_suites()
	{
		$available_suites = array();

		foreach(self::linked_repositories() as $repo)
		{
			$repo_index = pts_openbenchmarking::read_repository_index($repo);

			if(isset($repo_index['suites']) && is_array($repo_index['suites']))
			{
				foreach(array_keys($repo_index['suites']) as $identifier)
				{
					array_push($available_suites, $repo . '/' . $identifier);
				}
			}
		}

		return $available_suites;
	}
	public static function user_name()
	{
		return isset(self::$openbenchmarking_account['user_name']) ? self::$openbenchmarking_account : null;
	}
	public static function upload_usage_data($task, $data)
	{
		switch($task)
		{
			case 'test_complete':
				list($test_result, $time_elapsed) = $data;
				$upload_data = array('test_identifier' => $test_result->test_profile->get_identifier(), 'test_version' => $test_result->test_profile->get_test_profile_version(), 'elapsed_time' => $time_elapsed);
				pts_network::http_upload_via_post(pts_openbenchmarking::openbenchmarking_host() . 'extern/statistics/report-test-completion.php', $upload_data);
				break;
		}
	}
	public static function upload_hwsw_data($to_report)
	{
		foreach($to_report as $component => &$value)
		{
			if(empty($value))
			{
				unset($to_report[$component]);
				continue;
			}

			$value = $component . '=' . $value;
		}

		$upload_data = array('report_hwsw' => implode(';', $to_report), 'gsid' => PTS_GSID);
		pts_network::http_upload_via_post(pts_openbenchmarking::openbenchmarking_host() . 'extern/statistics/report-installed-hardware-software.php', $upload_data);
	}
	public static function upload_pci_data($to_report)
	{
		if(!is_array($to_report))
		{
			return false;
		}

		$to_report = base64_encode(serialize($to_report));

		$upload_data = array('report_pci_data' => $to_report, 'gsid' => PTS_GSID);
		pts_network::http_upload_via_post(pts_openbenchmarking::openbenchmarking_host() . 'extern/statistics/report-pci-data.php', $upload_data);
	}
	public static function upload_usb_data($to_report)
	{
		if(!is_array($to_report))
		{
			return false;
		}

		$to_report = base64_encode(serialize($to_report));

		$upload_data = array('report_usb_data' => $to_report, 'gsid' => PTS_GSID);
		pts_network::http_upload_via_post(pts_openbenchmarking::openbenchmarking_host() . 'extern/statistics/report-usb-data.php', $upload_data);
	}
	public static function request_gsid()
	{
		$upload_data = array(
			'client_version' => PTS_VERSION,
			'client_os' => phodevi::read_property('system', 'vendor-identifier')
			);
		$gsid = pts_network::http_upload_via_post(pts_openbenchmarking::openbenchmarking_host() . 'extern/request-gsid.php', $upload_data);

		return pts_openbenchmarking::is_valid_gsid_format($gsid) ? $gsid : false;
	}
	public static function linked_repositories()
	{
		return array('local', 'pts');
	}
}

?>
