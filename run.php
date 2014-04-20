#!/usr/bin/php -q
<?php
/**
 * this script runs a spec cpu test iteration. It utilizes the following exit 
 * status codes
 *   0 Iteration successful
 *   1 SPEC_CPU is not installed
 *   2 SPEC CPU config directory is not writable
 *   3 No valid benchmarks specified
 *   4 Unable to determine copies
 *   5 Invalid SPEC configuration
 *   6 Unable to determien output formats
 *   7 Invalid size parameter
 *   8 Invalid tune parameter
 *   9 Insufficient free disk space
 *   10 runspec exited with a non-zero status code
 *   11 run did not generate a valid CSV results file
 *   12 unknown error
 */

require_once('lib/util.php');

// set timeout
ini_set('max_execution_time', is_numeric(getenv('bm_run_timeout')) && getenv('bm_run_timeout') > 0 ? getenv('bm_run_timeout') : BM_SPEC_CPU_DEFAULT_TIMEOUT);

// exit status code
$bm_status = 0;

if (!is_dir(BM_SPEC_CPU_CONFIG_PATH)) {
	bm_log_msg('SPEC_CPU is not installed in ' . BM_SPEC_CPU_PATH, basename(__FILE__), __LINE__, TRUE);
	$bm_status = 1;
}
else if (!is_writable(BM_SPEC_CPU_CONFIG_PATH)) {
	bm_log_msg("SPEC_CPU config directory " . BM_SPEC_CPU_CONFIG_PATH . " is not writable by the running user", basename(__FILE__), __LINE__, TRUE);
	$bm_status = 2;
}
else if (!count($spec_benchmarks = bm_get_benchmarks())) {
	bm_log_msg("No valid benchmarks were defined", basename(__FILE__), __LINE__, TRUE);
	$bm_status = 3;
}
else if (!($spec_copies = bm_get_num_copies())) {
	bm_log_msg('Unable to determine the number of copies to run', basename(__FILE__), __LINE__, TRUE);
	$bm_status = 4;
}
else if (!($spec_config = bm_get_config())) {
	bm_log_msg('Unable to get SPEC CPU configuration', basename(__FILE__), __LINE__, TRUE);
	$bm_status = 5;
}
else if (!($spec_output_formats = bm_get_output_formats())) {
	bm_log_msg('Unable to determine output formats', basename(__FILE__), __LINE__, TRUE);
	$bm_status = 6;
}
else if (getenv('bm_param_size') && !in_array(getenv('bm_param_size'), array('test', 'train', 'ref'))) {
	bm_log_msg(getenv('bm_param_size') . ' is not a valid spec size parameter (valid values are: test, train or ref)', basename(__FILE__), __LINE__, TRUE);
	$bm_status = 7;
}
else if (getenv('bm_param_tune') && !in_array(getenv('bm_param_tune'), array('base', 'peak', 'all'))) {
	bm_log_msg(getenv('bm_param_tune') . ' is not a valid spec tune parameter (valid values are: base, peak or all)', basename(__FILE__), __LINE__, TRUE);
	$bm_status = 8;
}
else if (!bm_validate_disk_space()) {
	bm_log_msg('Insufficient free disk space in ' . BM_SPEC_CPU_PATH, basename(__FILE__), __LINE__, TRUE);
	$bm_status = 9;
}

if (!$bm_status) {
	bm_log_msg('run validation is successful, starting run', basename(__FILE__), __LINE__);
	// determine runspec arguments
	// $spec_copies, $spec_config
  // if ($spec_copies == 1) $spec_copies = NULL;
	if ($spec_config == 'default.cfg') $spec_config = NULL;
	$spec_benchmarks = implode(' ', $spec_benchmarks);
	$spec_defines = bm_get_runspec_macros();
	if (!in_array('csv', $spec_output_formats) && !in_array('all', $spec_output_formats)) $spec_output_formats[] = 'csv';
	$spec_output_format = implode(',', $spec_output_formats);
	$spec_comment = getenv('bm_param_comment') ? getenv('bm_param_comment') : NULL;
	$spec_delay = is_numeric(getenv('bm_param_delay')) && getenv('bm_param_delay') > 0 ? getenv('bm_param_delay') : NULL;
	$spec_flagsurl = getenv('bm_param_flagsurl') ? getenv('bm_param_flagsurl') : NULL;
	$spec_ignore_errors = getenv('bm_param_ignore_errors') == '1';
	$spec_iterations = bm_get_iterations();
	$spec_nobuild = getenv('bm_param_nobuild') !== '0';
	$spec_rate = getenv('bm_param_rate') !== '0';
	$spec_reportable = getenv('bm_param_reportable') == '1';
	$spec_review = getenv('bm_param_review') == '1';
	$spec_size = getenv('bm_param_size') && getenv('bm_param_size') != 'ref' ? getenv('bm_param_size') : NULL;
	$spec_tune = getenv('bm_param_tune') ? getenv('bm_param_tune') : 'base';
	
	// construct runspec command
	$runspec = "runspec" . ($spec_reportable ? ' --reportable' : ' --noreportable');
	if ($spec_config) $runspec .= " --config=${spec_config}";
	if ($spec_output_format) $runspec .= ' --output_format="' . $spec_output_format . '"';
	if ($spec_comment) $runspec .= ' --comment="' . $spec_comment . '"';
	if ($spec_delay) $runspec .= " --delay=${spec_delay}";
	if ($spec_flagsurl) $runspec .= ' --flagsurl="' . $spec_flagsurl . '"';
	if ($spec_ignore_errors) $runspec .= " --ignore_errors";
	if ($spec_iterations) $runspec .= " --iterations=${spec_iterations}";
	if ($spec_nobuild) $runspec .= " --nobuild";
	if ($spec_rate) $runspec .= " --rate " . $spec_copies;
	if ($spec_review) $runspec .= " --review";
	if ($spec_size) $runspec .= " --size=${spec_size}";
	if ($spec_tune) $runspec .= " --tune=${spec_tune}";
	
	$util_patched = preg_match('/"\'\.\$macros->{\$macro}\.\'"\'/msU', file_get_contents(BM_SPEC_CPU_PATH . '/bin/util.pl'));
	$sse_param = NULL;
	foreach($spec_defines as $key => $val) {
		if (is_bool($val) && !$val) continue;
		bm_log_msg("Adding custom macro ${key}=${val} (" . gettype($val) . ')', basename(__FILE__), __LINE__);
		
		// skip macros with spaces, dashes or periods if bin/util.pl has not been patched
		if ($key != 'sse' && !$util_patched && $val && (strpos($val, ' ') || strpos($val, '-') || strpos($val, '.'))) continue;
		
		$quote = '"';
		$runspec .= ($p = ' --define ' . $key . (!is_bool($val) && $val != '1' ? "=${quote}${val}${quote}" : ''));
		if ($key == 'sse') $sse_param = $p;
	}
	if ($spec_rate) $runspec .= " --define rate=${spec_copies}";
	$runspec .= " ${spec_benchmarks}";
	
	// invoke using numa
	if ($spec_defines['numa']) $runspec = 'numactl --interleave=all ' . $runspec;
	
	while(TRUE) {
		bm_log_msg("Invoking runspec " . BM_SPEC_RUN_SCRIPT . " using $runspec", basename(__FILE__), __LINE__);
	
		if (($generated = bm_generate_runscript($runspec)) && ($rstatus = exec(BM_SPEC_RUN_SCRIPT))) {
			$bm_status = 10;
			bm_log_msg("runspec exited with a non-zero status code $rstatus", basename(__FILE__), __LINE__, TRUE);
		}
		else if (!$generated) {
			$bm_status = 12;
			bm_log_msg('Unknown error running runscript', basename(__FILE__), __LINE__, TRUE);
		}
		else if (!($output_files = bm_get_output_files()) || !isset($output_files['csv'][0]) || !bm_parse_csv($output_files['csv'][0])) {
			$bm_status = 11;
			bm_log_msg('run did not generate a valid CSV results file', basename(__FILE__), __LINE__, TRUE);
		}
		else bm_log_msg("runspec completed successfully", basename(__FILE__), __LINE__);
		
		if ($bm_status != 10 || !$sse_param || getenv('bm_param_failover_no_sse') != '1') {
			if ($bm_status == 10 && getenv('bm_param_x64_failover') == '1' && !file_exists(BM_SPEC_X64_FAILOVER_FILE)) {
				bm_log_msg("runspec failed with x64=" . $spec_defines['x64'] . " - re-attempting with x64=" . !$spec_defines['x64'], basename(__FILE__), __LINE__);
				exec('touch ' . BM_SPEC_X64_FAILOVER_FILE);
				// either add or remove --define x64 (based on the initial state)
				$runspec = str_replace($spec_defines['x64'] ? ' --define x64' : 'runspec ', $spec_defines['x64'] ? '' : 'runspec --define x64 ', $runspec);
			}
			else break;
		}
		// re-attempt execution without sse parameter
		else {
			bm_log_msg("runspec failed with $sse_param - re-attempting without sse flag", basename(__FILE__), __LINE__);
			exec('touch ' . BM_SPEC_SKIP_SSE_FILE);
			$runspec = str_replace($sse_param, '', $runspec);
			$sse_param = NULL;
		}
	}
}
else bm_log_msg("run validation failed with status code $bm_status", basename(__FILE__), __LINE__, TRUE);

exit($bm_status);
?>