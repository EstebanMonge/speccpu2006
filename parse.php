#!/usr/bin/php -q
<?php

require_once('lib/util.php');

/**
 * This script parses runtime results and outputs the resulting key/value pairs
 */
$status = 1;

// disable debug output
$bm_debug = FALSE;

if (file_exists(BM_SPEC_RUN_OUTPUT_FILE) && 
   ($output_files = bm_get_output_files()) && 
   isset($output_files['csv'][0]) && 
   ($csv = bm_parse_csv($output_files['csv'][0]))) {
	
	$status = 0;
	$macros = bm_get_runspec_macros();
	$results = array('benchmarks' => implode(' ', bm_get_benchmarks()), 
	                 'config' => bm_get_config(),
	                 'copies' => NULL,
	                 'flagsurl' => getenv('bm_param_flagsurl') ? getenv('bm_param_flagsurl') : '',
	                 'iterations' => NULL,
	                 'numa' => $macros['numa'],
	                 'output_format' => implode(',', bm_get_output_formats()),
	                 'size' => getenv('bm_param_size') ? getenv('bm_param_size') : 'ref',
	                 'sse' => isset($macros['sse']) ? $macros['sse'] : '',
	                 'tune' => NULL,
	                 'valid' => FALSE,
	                 'x64' => isset($macros['x64']) ? $macros['x64'] : '');
	
	$include_benchmark_metrics = getenv('bm_param_include_benchmark_metrics') == '1' || getenv('bm_param_include_benchmark_metrics') === FALSE;
	$include_ref_times = getenv('bm_param_include_ref_times') == '1';
	$include_run_times = getenv('bm_param_include_run_times') == '1';
	
	// parse all output CSV files
	$has_aggregate = FALSE;
	foreach($output_files['csv'] as $csv_file) {
		if (!($csv = bm_parse_csv($csv_file))) continue;
		
		$in_results_table = FALSE;
		$benchmark_counts = array();
		foreach($csv as $row) {
			if ($in_results_table && count($row) < 10) {
				$in_results_table = FALSE;
			}
			else if (isset($row[1]) && preg_match('/Full Results Table/', $row[1])) {
				$in_results_table = TRUE;
			}
			else if ($include_benchmark_metrics && $in_results_table && preg_match('/^[0-9]+\.[a-zA-Z]+/', $row[0])) {
				if (is_numeric($row[2]) || is_numeric($row[3]) || is_numeric($row[7]) || is_numeric($row[8])) {
					$benchmark = $row[0];
					if (!isset($benchmark_counts[$benchmark])) $benchmark_counts[$benchmark] = 1;
					else $benchmark_counts[$benchmark]++;
					$count = $benchmark_counts[$benchmark];
				
					// number of copies
					if (!isset($results['copies']) && $results['rate'] && (is_numeric($row[1]) || is_numeric($row[6]))) $results['copies'] = $row[1] ? $row[1] : $row[6];
					// reference time (speed runs only)
					else if ($include_ref_times && !$results['rate'] && !isset($results["ref_time_${benchmark}"]) && (is_numeric($row[1]) || is_numeric($row[6]))) $results["ref_time_${benchmark}"] = $row[1] ? $row[1] : $row[6];
					// tune (base, peak or all)
					if (!isset($results['tune'])) $results['tune'] = (is_numeric($row[2]) || is_numeric($row[3])) && (is_numeric($row[7]) || is_numeric($row[8])) ? 'all' : (is_numeric($row[2]) || is_numeric($row[3]) ? 'base' : 'peak');
				
					// base
					if (is_numeric($row[2]) || is_numeric($row[3])) {
						if ($include_run_times && is_numeric($row[2])) $results["base_run_time${count}_${benchmark}"] = $row[2];
						if (is_numeric($row[3])) $results['base_' . ($results['rate'] ? 'rate' : 'ratio') . "${count}_${benchmark}"] = $row[3];
						if (trim($row[4]) == '1') $results["base_selected${count}_${benchmark}"] = '1';
					}
					// peak
					if (is_numeric($row[7]) || is_numeric($row[8])) {
						if ($include_run_times && is_numeric($row[7])) $results["peak_run_time${count}_${benchmark}"] = $row[7];
						if (is_numeric($row[8])) $results['peak_' . ($results['rate'] ? 'rate' : 'ratio') . "${count}_${benchmark}"] = $row[8];
						if (trim($row[9]) == '1') $results["peak_selected${count}_${benchmark}"] = '1';
					}
				}
			}
			else if ($in_results_table && preg_match('/Benchmark/', $row[1])) {
				$results['rate'] = preg_match('/Rate/', $row[3]) || preg_match('/Rate/', $row[4]) ? TRUE : FALSE;
				if (!$results['rate']) unset($results['copies']);
			}
			else if (!$in_results_table && preg_match('/^valid/', $row[0])) {
				$results['valid'] = $row[1] == '1';
			}
			else if (!$in_results_table && ((isset($row[0]) && trim($row[0])) || (isset($row[1]) && trim($row[1]))) && 
			         (preg_match('/^SPECint/', $key = trim($row[0]) ? $row[0] : $row[1]) || 
			          preg_match('/^SPECfp/', $key = trim($row[0]) ? $row[0] : $row[1]))) {
				for($n=1; $n<count($row); $n++) if (is_numeric($row[$n])) {
					$has_aggregate = TRUE;
					$results[$key] = $row[$n];
				}
			}
		}
	}
	// determine number of iterations
	if ($benchmark_counts) {
		$keys = array_keys($benchmark_counts);
		$results['iterations'] = $benchmark_counts[$keys[0]];
	}
	
	if ($spec_output_formats = bm_get_output_formats()) {
		$files = array();
		foreach($spec_output_formats as $format) {
			switch(strtolower(trim($format))) {
				case 'all':
				case 'csv':
					if (isset($output_files['csv'])) $files = array_merge($files, $output_files['csv']);
					if ($format != 'all') break;
				case 'default':
				case 'html':
					if (isset($output_files['html'])) $files = array_merge($files, $output_files['html']);
					if ($format != 'all' && $format != 'default') break;
				case 'config':
					if (isset($output_files['config'])) $files = array_merge($files, $output_files['config']);
					if ($format != 'all') break;
				case 'flags':
					if (isset($output_files['flags'])) $files = array_merge($files, $output_files['flags']);
					if ($format != 'all') break;
				case 'text':
					if (isset($output_files['ascii'])) $files = array_merge($files, $output_files['ascii']);
					if ($format != 'all') break;
				case 'pdf':
					if (isset($output_files['pdf'])) $files = array_merge($files, $output_files['pdf']);
					if ($format != 'all') break;
				case 'postscript':
					if (isset($output_files['postscript'])) $files = array_merge($files, $output_files['postscript']);
					if ($format != 'all') break;
				case 'raw':
					if (isset($output_files['raw'])) $files = array_merge($files, $output_files['raw']);
			}
		}
		// move artifacts to be saved
		exec('mkdir -p ' . BM_SPEC_ARTIFACTS_DIR);
		foreach($files as $file) exec("mv ${file} " . BM_SPEC_ARTIFACTS_DIR . '/');
	}
	if (getenv('bm_iteration_dir') != '.' && getenv('bm_param_purge_output') !== '0') {
		$buffer = file_get_contents(BM_SPEC_RUN_OUTPUT_FILE);
		preg_match_all('/created\s+\((.*)\)/msU', $buffer, $m1);
		preg_match_all('/existing\s+\((.*)\)/msU', $buffer, $m2);
		$purge = array();
		if (is_array($m1[1])) $purge = array_merge($purge, $m1[1]);
		if (is_array($m2[1])) $purge = array_merge($purge, $m2[1]);
		foreach($purge as $run_dir) {
			exec('rm -Rf ' . BM_SPEC_CPU_PATH . "/benchspec/CPU2006/*/run/${run_dir}");
		}
		// delete output files that are not being saved
		foreach($output_files as $files) {
			foreach($files as $file) {
				exec("rm -f ${file}");
				// also remove log file
				if (strpos($file, '.csv')) exec('rm -f ' . str_replace('.csv', '.log', $file));
			}
		}
	}
	
	if (!$results['sse'] || file_exists(BM_SPEC_SKIP_SSE_FILE)) unset($results['sse']);
	foreach($results as $key => $val) {
		// skip null values and 'selected' individual metrics when there is no aggregate
		if ($val === NULL || (!$has_aggregate && preg_match('/_selected[0-9]+_/', $key)) || !trim($val)) continue;
		
		if (is_bool($val)) $val = $val ? '1' : '0';
		print("${key}=${val}\n");
	}
}

exit($status);
?>