<?php

// set the start time
$bm_start_time = microtime(TRUE);

ini_set('memory_limit', '64M');

// estimated disk usage per copy (2GB)
define('BM_SPEC_ARTIFACTS_DIR', getenv('bm_iteration_dir') . '/artifacts');

// rounding precision
define('BM_SPEC_CPU_ROUND_PRECISION', 3);

// default copies runtime parameter
define('BM_SPEC_CPU_DEFAULT_COPIES', 'x64:100%/2GB|100%/1GB');

// default run timeout (72 hours)
define('BM_SPEC_CPU_DEFAULT_TIMEOUT', 60*60*72);

// path where spec cpu should be installed
define('BM_SPEC_CPU_PATH', getenv('bm_param_spec_dir') ? getenv('bm_param_spec_dir') : '/opt/cpu2006');

// path to the spec cpu config directory
define('BM_SPEC_CPU_CONFIG_PATH', BM_SPEC_CPU_PATH . '/config');

// estimated disk usage per copy (2GB)
define('BM_SPEC_ESTIMATED_DISK_USAGE_MB', 2*1024);

// estimated disk usage per copy (2GB)
define('BM_SPEC_RUN_OUTPUT_FILE', getenv('bm_iteration_dir') . '/spec.log');

// estimated disk usage per copy (2GB)
define('BM_SPEC_RUN_SCRIPT', getenv('bm_iteration_dir') . '/specrun');

// debug parameter flag
$bm_debug = getenv('bm_param_debug') && getenv('bm_param_debug') == '1';

// file that is created to designate sse failure when failover_no_sse is set
define('BM_SPEC_SKIP_SSE_FILE', getenv('bm_run_dir') . '/.sse_failover');

// file that is created to designate x64 failure when x64_failover is set
define('BM_SPEC_X64_FAILOVER_FILE', getenv('bm_run_dir') . '/.x64_failover');

// path to the huge pages library for 32-bit execution
define('BM_SPEC_HUGE_PAGES_LIB32', '/usr/lib/libhugetlbfs.so');

// path to the huge pages library for 64-bit execution
define('BM_SPEC_HUGE_PAGES_LIB64', '/usr/lib64/libhugetlbfs.so');

// path to meminfo
define('BM_SPEC_MEMINFO', '/proc/meminfo');

// x64 parameter flag
$bm_x64 = getenv('bm_param_x64') && getenv('bm_param_x64') == '1';
if (getenv('bm_param_x64') == '2') $bm_x64 = $bm_is64bit;

/**
 * returns the current execution time
 * @return float
 */
function bm_exec_time() {
	global $bm_start_time;
	return round(microtime(TRUE) - $bm_start_time, BM_SPEC_CPU_ROUND_PRECISION);
}

/**
 * generates the runscript - returns TRUE on success
 * @param string $runspec the runspec command
 * @return boolean
 */
function bm_generate_runscript($runspec) {
	$generated = FALSE;
	if ($fp = fopen(BM_SPEC_RUN_SCRIPT, 'w')) {
		$generated = TRUE;
		fwrite($fp, "#!/bin/bash\n");
		fwrite($fp, 'export SPEC=' . BM_SPEC_CPU_PATH . "\n");
		fwrite($fp, 'cd ' . BM_SPEC_CPU_PATH . "\n");
		fwrite($fp, "source shrc\n");
		fwrite($fp, "ulimit -s unlimited\n");
		// Huge pages
		if (getenv('bm_param_huge_pages') == '1' && file_exists($huge_pages_lib = file_exists(BM_SPEC_HUGE_PAGES_LIB64) ? BM_SPEC_HUGE_PAGES_LIB64 : BM_SPEC_HUGE_PAGES_LIB32)) {
			// check if huge pages are available
			if (preg_match('/([0-9]+)$/msU', shell_exec('cat ' . BM_SPEC_MEMINFO . ' | grep HugePages_Free'), $m) && $m[1] > 0) {
				bm_log_msg('Huge pages will be enabled because ' . $m[1] . ' are available', basename(__FILE__), __LINE__);
				fwrite($fp, "export HUGETLB_MORECORE=yes\n");
				fwrite($fp, "export LD_PRELOAD=${huge_pages_lib}\n");
			}
			else bm_log_msg('Huge pages will not be enabled because there are none free as determined from cat /proc/meminfo | grep HugePages_Free', basename(__FILE__), __LINE__);
		}
		fwrite($fp, "$runspec &>" . BM_SPEC_RUN_OUTPUT_FILE . "\n");
		fwrite($fp, "echo $?\n");
		fclose($fp);
		exec("chmod 755 " . BM_SPEC_RUN_SCRIPT);
	}
	return $generated;
}

/**
 * returns the benchmarks that should be run
 * @return array
 */
function bm_get_benchmarks() {
	global $bm_benchmarks;
	global $bm_debug;
	if (!is_array($bm_benchmarks)) {
		$bm_benchmarks = array();
		$benchmarks_ini = bm_string_to_hash(file_get_contents(dirname(dirname(__FILE__)) . '/config/spec-benchmarks.ini'));
		if ($bm_debug) {
			bm_log_msg('Evaluating validity of benchmarking from benchmark ini:', basename(__FILE__), __LINE__);
			print_r(array_keys($benchmarks_ini));
		}
		foreach(explode(' ', getenv('bm_param_benchmark') ? getenv('bm_param_benchmark') : 'all') as $benchmark) {
			$benchmark = trim($benchmark);
			$found = FALSE;
			foreach(array_keys($benchmarks_ini) as $b) {
				if ($benchmark == $b || preg_match("/[0-9]+\.${benchmark}\$/", $b) || preg_match("/${benchmark}\./", $b)) {
					$found = TRUE;
					$pieces = explode('.', $b);
					$benchmark = $pieces[0];
					break;
				}
			}
			if ($found) $bm_benchmarks[] = $benchmark;
			else bm_log_msg("$benchmark is not a valid SPEC CPU benchmark", basename(__FILE__), __LINE__, TRUE);
		}
		if ($bm_debug) {
			bm_log_msg('Returning runspec benchmarks:', basename(__FILE__), __LINE__);
			print_r($bm_benchmarks);
		}
	}
	return $bm_benchmarks;
}

/**
 * returns the benchmarks that should be run
 * @return string
 */
function bm_get_config() {
	global $bm_config;
	if (!$bm_config) {
		$config = getenv('bm_param_config') ? getenv('bm_param_config') : 'default.cfg';
		if (file_exists(BM_SPEC_CPU_CONFIG_PATH . '/' . basename($config)) || 
		    @copy($config, BM_SPEC_CPU_CONFIG_PATH . '/' . basename($config))) {
			$bm_config = basename($config);
			bm_log_msg("Config $bm_config will be used (from $config)", basename(__FILE__), __LINE__);
		}
		else bm_log_msg("$config is not valid or cannot be copied to the SPEC CPU config directory", basename(__FILE__), __LINE__, TRUE);
	}
	return $bm_config;
}

/**
 * returns the benchmarks that should be run
 * @return string
 */
function bm_get_iterations() {
	return getenv('bm_param_iterations') && is_numeric(getenv('bm_param_iterations')) && getenv('bm_param_iterations') > 0 && getenv('bm_param_iterations') != 3 ? getenv('bm_param_iterations') : NULL;
}

/**
 * returns the number of copies to include in the run (based on the copies run
 * parameter) - applies only to rate runs
 * @return int
 */
function bm_get_num_copies() {
	global $bm_copies;
	if ($bm_copies) return $bm_copies;	
	global $bm_x64;
	
	$copies = NULL;
	$working = array();
	$param = getenv('bm_param_copies') ? getenv('bm_param_copies') : BM_SPEC_CPU_DEFAULT_COPIES;
	foreach(explode('|', $param) as $param) {
		$x64 = FALSE;
		if (preg_match('/^x64:(.*)$/', $param, $m)) {
			$x64 = TRUE;
			$param = $m[1];
		}
		$pieces = explode('/', trim($param));
		$highest = FALSE;
		if (substr($pieces[0], 0, 1) == '+') {
			$highest = TRUE;
			$pieces[0] = str_replace('+', '', $pieces[0]);
		}
		$working[$x64] = array('highest' => $highest, 'param' => $param, 'pieces' => $pieces);
	}
	// determine whether or use 32 or 64 bit parameter value
	if (count($keys = array_keys($working)) > 1) $key = $bm_x64 ? 1 : 0;
	else $key = $keys[0];
	$highest = $working[$key]['highest'];
	$param = $working[$key]['param'];
	$pieces = $working[$key]['pieces'];
	
	bm_log_msg("Calculating number of copies to run using copies parameter $param [highest=$highest]", basename(__FILE__), __LINE__);
	
	foreach($pieces as $c) {
		$c = trim($c);
		$pcopies = NULL;
		// cpu core relative
		if (preg_match('/^([0-9\.]+)%$/', $c, $m) && getenv('bm_cpu_count')) {
			$pcopies = round(getenv('bm_cpu_count') * $m[1] * .01);
			bm_log_msg("Calculated CPU relative count $pcopies from $c", basename(__FILE__), __LINE__);
		}
		// memory relative
		else if (preg_match('/^([0-9\.]+)([GMBgmb]+)$/', $c, $m) && getenv('bm_memory_total')) {
			$pcopies = round(getenv('bm_memory_total')/($m[1]*1024*(strtolower(trim($m[2])) == 'gb' ? 1024 : 1)));
			bm_log_msg("Calculated memory relative count $pcopies from $c", basename(__FILE__), __LINE__);
		}
		// fixed
		else if (preg_match('/^([0-9]+)$/', $c, $m)) {
			$pcopies = $m[1];
			bm_log_msg("Found fixed count $pcopies from $c", basename(__FILE__), __LINE__);
		}
		
		if ($pcopies !== NULL && $pcopies < 1) $pcopies = 1;
		if ($pcopies && (!$copies || ($highest && $pcopies > $copies) || (!$highest && $pcopies < $copies))) {
			$copies = $pcopies;
			bm_log_msg("Adjusting copies to $pcopies", basename(__FILE__), __LINE__);
		}
	}
	$copies = $copies ? $copies : 1;
	
	// max copies
	if (is_numeric($max_copies = getenv('bm_param_max_copies')) && $copies > $max_copies) {
		bm_log_msg("Reducing copies from $copies to $max_copies due to max_copies constraint", basename(__FILE__), __LINE__);
		$copies = $max_copies;
	}
	
	bm_log_msg("Returning copies=$copies", basename(__FILE__), __LINE__);
	$bm_copies = $copies;
	return $bm_copies;
}

/**
 * returns an hash indexed by file type where the value is an array of all of
 * the files that were generated associated with that type
 * @return array
 */
function bm_get_output_files() {
	global $bm_debug;
	$output_files = array();
	if (preg_match_all('/format: (.*) -> (.*)$/msU', file_get_contents(BM_SPEC_RUN_OUTPUT_FILE), $m)) {
		if ($bm_debug) {
			bm_log_msg('Evaluating the following output files:', basename(__FILE__), __LINE__);
			print_r($m[1]);
		}
		foreach($m[1] as $i => $key) {
			$key = strtolower(trim($key));
			foreach(explode(',', $m[2][$i]) as $file) {
				if (($file = trim($file)) && file_exists($file)) {
					if (!isset($output_files[$key])) $output_files[$key] = array();
					$output_files[$key][] = trim($file);
				}
			}
		}
		if ($bm_debug) {
			bm_log_msg('Validated the following output files:', basename(__FILE__), __LINE__);
			print_r($output_files);
		}
	}
	else bm_log_msg('No output files found in ' . BM_SPEC_RUN_OUTPUT_FILE, basename(__FILE__), __LINE__, TRUE);
	return $output_files;
}

/**
 * returns an array representing the desired output formats
 * @return array
 */
function bm_get_output_formats() {
	global $bm_output_formats;
	if (!is_array($bm_output_formats)) {
		bm_log_msg('Determining output formats', basename(__FILE__), __LINE__);
		$bm_output_formats = array();
		$formats_ini = bm_string_to_hash(file_get_contents(dirname(dirname(__FILE__)) . '/config/output-formats.properties'));
		foreach(explode(',', getenv('bm_param_output_format') ? getenv('bm_param_output_format') : 'default') as $format) {
			$format = trim($format);
			if (isset($formats_ini[$format]) && !in_array($format, $bm_output_formats)) $bm_output_formats[] = $format;
			else bm_log_msg("$format is not a valid output format", basename(__FILE__), __LINE__, TRUE);
		}
		bm_log_msg('Returning output formats ' . implode(',', $bm_output_formats), basename(__FILE__), __LINE__);
	}
	return $bm_output_formats;
}

/**
 * returns a hash representing the macros that should be set using the runspec
 * --define argument - the possible macros are defined in the README under the
 * config runtime parameter documentation
 * @return array
 */
function bm_get_runspec_macros() {
	global $bm_debug;
	global $bm_runspec_macros;
	
	if (!is_array($bm_runspec_macros)) {
		bm_log_msg('Generating runspec macros', basename(__FILE__), __LINE__);
		$bm_runspec_macros = array();
		foreach(array('cpu_cache', 'cpu_count', 'cpu_family', 'cpu_model', 'cpu_name', 'cpu_speed', 
		              'cpu_vendor', 'compute_service_id', 'external_id', 'instance_id', 
		              'ip_or_hostname', 'is32bit', 'is64bit', 'iteration_num', 'mean_anyway', 'meta_hw_avail', 
		              'meta_hw_fpu', 'meta_hw_ncpuorder', 'meta_hw_nthreadspercore', 'meta_hw_other',
		              'meta_hw_ocache', 'meta_hw_pcache', 'meta_hw_tcache', 'meta_license_num',
		              'meta_notes_base', 'meta_notes_comp', 'meta_notes_comp', 'meta_notes',
		              'meta_notes_os', 'meta_notes_part', 'meta_notes_peak', 'meta_notes_plat',
		              'meta_notes_port', 'meta_notes_submit', 'meta_sw_avail', 'meta_sw_other',
		              'meta_tester', 'label', 'location', 'memory_free', 'memory_total', 'numa',
		              'os', 'os_version', 'provider_id', 'region', 'run_id', 'run_name', 
		              'storage_config', 'subregion', 'test_id', 'x64') as $macro) {
			
			// numa macro - determine if supported
			if ($macro == 'numa') $bm_runspec_macros['numa'] = (trim(exec('numactl --show; echo $?')) . '') === '0' ? TRUE : FALSE;
			// no_numa param
			if ($bm_runspec_macros['numa'] && getenv('bm_param_no_numa') == '1') $bm_runspec_macros['numa'] = FALSE;
			// note parameters (up to 5)
			else if (preg_match('/^meta_notes/', $macro)) {
				for($i=1; $i<=5; $i++) {
					if ($v = getenv($m = "${macro}_${i}")) $bm_runspec_macros[$m] = $v;
				}
			}
			// other macros - look for values in params or environment variable
			else if (($v = getenv("bm_param_${macro}")) || ($v = getenv("bm_${macro}"))) {
				if ($macro == 'is32bit' || $macro == 'is64bit' || $macro == 'mean_anyway') $v = $v ? TRUE : FALSE;
				else if ($macro == 'x64') $v = $v == 2 ? (getenv('bm_is64bit') ? TRUE : FALSE) : ($v ? TRUE : FALSE);
				$bm_runspec_macros[$macro] = $v;
			}
		}
		// SSE
		if ($sse = bm_get_sse()) $bm_runspec_macros['sse'] = $sse;
		// Additional macros (define_* parameters)
		foreach(bm_string_to_hash(shell_exec('env')) as $key => $val) {
			if (preg_match('/^bm_param_define_(.*)$/', $key, $m)) $bm_runspec_macros[$m[1]] = trim($val) ? trim($val) : TRUE;
		}
	}
	if ($bm_debug) {
		bm_log_msg('Returning runspec macros:', basename(__FILE__), __LINE__);
		print_r($bm_runspec_macros);
	}
	if (file_exists(BM_SPEC_SKIP_SSE_FILE) && isset($bm_runspec_macros['sse'])) unset($bm_runspec_macros['sse']);
	if (file_exists(BM_SPEC_X64_FAILOVER_FILE)) $bm_runspec_macros['x64'] = !$bm_runspec_macros['x64'];
	
	return $bm_runspec_macros;
}

/**
 * returns the SSE flag ot use for this run
 * @return string
 */
function bm_get_sse() {
	// skip sse
	if (getenv('bm_param_failover_no_sse') && file_exists(BM_SPEC_SKIP_SSE_FILE)) {
		bm_log_msg('Return sse=null because skip SSE check file exists: ' . BM_SPEC_SKIP_SSE_FILE, basename(__FILE__), __LINE__);
		return NULL;
	}
	
	$sse = getenv('bm_param_sse') ? getenv('bm_param_sse') : 'optimal';
	if ($sse == 'optimal') $sse = strtoupper(trim(shell_exec('determine_sse')));
	$sse_flags = array('SSE2', 'SSE3', 'SSSE3', 'SSE4.1', 'SSE4.2', 'AVX');
	if (!in_array($sse, $sse_flags)) $sse = NULL;
	else if ($sse) $sse_pos = array_search($sse, $sse_flags);
	
	$min_sse = getenv('bm_param_sse_min') ? array_search(getenv('bm_param_sse_min'), $sse_flags) : FALSE;
	$max_sse = getenv('bm_param_sse_max') ? array_search(getenv('bm_param_sse_max'), $sse_flags) : FALSE;
	
	if ($min_sse !== FALSE && $sse && $sse_pos < $min_sse) {
		bm_log_msg("SSE $sse does not meet minimum SSE " . getenv('bm_param_sse_min') . ". SSE will not be used", basename(__FILE__), __LINE__);
		$sse = NULL;
	}
	if ($max_sse !== FALSE && $sse && $sse_pos > $max_sse) {
		bm_log_msg("SSE $sse is greater than maximum SSE " . getenv('bm_param_sse_max') . ". Max SSE will be used instead", basename(__FILE__), __LINE__);
		$sse = getenv('bm_param_sse_max');
	}
	
	return $sse;
}

/**
 * this function outputs a log message
 * @param string $msg the message to output
 * @param string $source the source of the message
 * @param int $line an optional line number
 * @param boolean $error is this an error message
 * @param string $source1 secondary source
 * @param int $line1 secondary line number
 * @return void
 */
$bm_error_level = error_reporting();
function bm_log_msg($msg, $source=NULL, $line=NULL, $error=FALSE, $source1=NULL, $line1=NULL) {
	global $bm_debug;
	if ($bm_debug || $error) {
		global $bm_error_level;
		$source = basename($source);
		if ($source1) $source1 = basename($source1);
		$exec_time = bm_exec_time();
		// avoid timezone errors
		error_reporting(E_ALL ^ E_NOTICE ^ E_WARNING);
		$timestamp = date('m/d/Y H:i:s T');
		error_reporting($bm_error_level);
		printf("%-24s %-12s %-12s %s\n", $timestamp, bm_exec_time() . 's', 
					 $source ? str_replace('.php', '', $source) . ($line ? ':' . $line : '') : '', 
					 ($error ? 'ERROR - ' : '') . $msg . 
					 ($source1 ? ' [' . str_replace('.php', '', $source1) . ($line1 ? ":$line1" : '') . ']' : ''));
	}
}

/**
 * this function parses key/value pairs in the string $blob. the return value
 * is a hash the corresponding key/value pairs. empty lines, or lines 
 * beginning with ; or # are ignored. for lines without an = character, the 
 * entire line will be the key and the value will be TRUE
 * @param string $blob the string to parse
 * @param boolean $ini if true, the parsing will be segmented where sections 
 * that begin with a bracket enclosed string define the segments. for example,
 * if the function encountered a line [globals], all of the key value pairs 
 * following that line will be placed into a 'global' sub-hash in the return 
 * value (until the next section is encountered)
 * @param array $excludeKeys array of regular expressions representing keys
 * that should not be included in the return hash
 * @param array $includeKeys array of regular expressions representing keys
 * that should be included in the return hash
 */
function bm_string_to_hash($blob, $ini=FALSE, $excludeKeys=NULL, $includeKeys=NULL) {
	$hash = array();
	$iniSection = NULL;
	foreach(explode("\n", $blob) as $line) {
		$line = trim($line);
		$firstChar = $line ? substr($line, 0, 1) : NULL;
		if ($firstChar && $firstChar != ';' && $firstChar != '#') {
			// ini section
			if ($ini && preg_match('/^\[(.*)\]$/', $line, $m)) $iniSection = $m[1];
			else {
				if ($split = strpos($line, '=')) {
					$key = substr($line, 0, $split);
					$value = substr($line, $split + 1);
				}
				else {
					$key = $line;
					$value = TRUE;
				}
				if (is_array($excludeKeys)) {
					foreach($excludeKeys as $regex) if (preg_match($regex, $key)) $key = NULL;
				}
				if (is_array($includeKeys)) {
					$found = FALSE;
					foreach($includeKeys as $regex) if (preg_match($regex, $key)) $found = TRUE;
					if (!$found) $key = NULL;
				}
				if ($key) {
					if ($ini && $iniSection) {
						if (!isset($hash[$iniSection])) $hash[$iniSection] = array();
						$hash[$iniSection][$key] = $value;
					}
					else $hash[$key] = $value;
				}
			}
		}
	}
	return $hash;
}

/**
 * validates that there is sufficient disk space for this run
 * @return boolean
 */
function bm_validate_disk_space() {
	if (!($valid = getenv('bm_param_validate_disk_space') == '1' ? FALSE : TRUE)) {
		if (($dir = BM_SPEC_CPU_PATH . '/benchspec') && ($copies = bm_get_num_copies())) {
			bm_log_msg('Validating disk space', basename(__FILE__), __LINE__);
			$freespace = bm_get_dir_freespace($dir);
			$required = $copies * BM_SPEC_ESTIMATED_DISK_USAGE_MB;
			if ($valid = $freespace >= $required) bm_log_msg("Required disk space ${required}MB is available in ${dir} (${freespace}MB is available)", basename(__FILE__), __LINE__);
			else bm_log_msg("Required disk space ${required}MB is not available in ${dir} (${freespace}MB is available)", basename(__FILE__), __LINE__, TRUE);
		}
		else bm_log_msg("Insufficient data available to validate disk space - dir=${dir} copies=${copies}", basename(__FILE__), __LINE__, TRUE);
	}
	else bm_log_msg('Skipping disk space validation because param validate_disk_space=0', basename(__FILE__), __LINE__);
	
	return $valid;
}

/**
 * returns disk statistics as an array of hashes indexed by mount point where
 * each hash contains the following keys:
 *   filesystem: the device for the mount point
 *   free:       the amount of space free (MB)
 *   mount:      the mount point (same as the key)
 *   size:       the total size (MB)
 *   used:       the amount of space used (MB)
 *   used_perc:  the percentage of space used (e.g. 79%)
 * if $dir is specified, only a single hash will be returned
 * @param string $dir an optional directory to return stats for. if specified, 
 * the return value will be a single hash corresponding with the mountpoint 
 * for this directory. for example, if $dir == '/home', this method will first
 * check for and return a separate mountpoint for /home, and if there is no 
 * specific mountpoint for /home, return the stats for the root volume. More 
 * specified directories can also be specified like '/var/lib'
 * @param string $dfm dump from the 'df -m' command. if not provided, it will 
 * be executed on this host
 * @access public
 * @return hash
 */
function bm_get_dir_freespace($dir) {
	$stats = array();
	$dfm = shell_exec('df -m');
	foreach(explode("\n", $dfm) as $line) {
		if ($last && preg_match('/^\s+[0-9]+/', $line)) $line = $last . ' ' . $line;
		if (preg_match('/(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)/', $line, $m) && is_numeric($m[2]) && is_numeric($m[4]) && is_numeric($m[4])) {
			$stats[$m[6]] = array('filesystem' => $m[1], 'free' => $m[4], 'mount' => $m[6], 'size' => $m[3] + $m[4], 'used' => $m[3], 'used_perc' => $m[5]);
		}
		else if (substr($line, 0, 1) == '/') $last = $line;
		else $last = NULL;
	}
	if ($dir) {
		$dmount = '/';
		foreach(array_keys($stats) as $mountpoint) {
			if (strpos($dir, $mountpoint) === 0 && strlen($mountpoint) > strlen($dmount)) $dmount = $mountpoint;
		}
		$stats = $stats[$dmount];
	}
	return $stats ? $stats['free'] : NULL;
}

/**
 * Used to convert a CSV file into a two dimensional array
 * @param string $file the CSV file to convert
 * @return array
 */
function bm_parse_csv($file) {
	if (file_exists($file) && ($fp = fopen($file, 'r'))) {
		$csv = array();
		// 0 start column/line
		// 1 start column
		// 2 parsing non-string
		// 3 parsing string
		// 4 parsing string in break
		$state = 0;
		$buff = '';
		$row = array();
		$sdelim = '"';
		while(!feof($fp)) {
		  $char = fgetc($fp);
		  if ($state < 3 && ($char == ',' || $char == "\n" || feof($fp))) {
		    if ($char != ',' && $char != "\n") { $buff .= $char; }
		    $row[] = $buff;
		    $state = feof($fp) || $char == "\n" ? 0 : 1;
		    if ($state == 0 && count($row) > 1) {
					$csv[] = $row;
		      $row = array();
		    }
		    $buff = '';
		    continue;
		  }
		  else if ($char == $sdelim && $state <= 1) {
		    $state = 3;
		    continue;
		  }
		  else if ($char == $sdelim && $state == 3) {
		    $state = fgetc($fp) == $sdelim ? 4 : 2;
		    fseek($fp, -1, SEEK_CUR);
		    continue;
		  }
		  else if ($char == '\\' && $state == 3) {
		    $state = 4;
		  }
		  else if ($state == 4) {
		    $state = 3;
		  }
		  else if ($state <= 1) {
		    $state = 2;
		  }
		  $buff .= $char;
		}
		fclose($fp);
	}
	return $csv;
}

// set default time zone if necessary
if (!ini_get('date.timezone')) ini_set('date.timezone', ($tz = trim(shell_exec('date +%Z'))) ? $tz : 'UTC');
?>