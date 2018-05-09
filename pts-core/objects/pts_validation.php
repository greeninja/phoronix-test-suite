<?php

/*
	Phoronix Test Suite
	URLs: http://www.phoronix.com, http://www.phoronix-test-suite.com/
	Copyright (C) 2009 - 2018, Phoronix Media
	Copyright (C) 2009 - 2018, Michael Larabel

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

class pts_validation
{
	public static function process_libxml_errors()
	{
		$error_queue = array();
		$errors = libxml_get_errors();

		foreach($errors as $i => &$error)
		{
			if(isset($error_queue[$error->line]))
			{
				// There's already been an error reported for this line
				unset($errors[$i]);
			}

			switch($error->code)
			{
				case 1840: // Not in enumeration
				case 1839: // Not in pattern
				case 1871: // Missing / invalid element
				case 1833: // Below the minInclusive value
					echo PHP_EOL . $error->message;
					echo 'Line ' . $error->line . ': ' . $error->file . PHP_EOL;
					$error_queue[$error->line] = true;
					unset($errors[$i]);
					break;
			}
		}

		if(count($errors) > 0 && PTS_IS_CLIENT)
		{
			// DEBUG
			print_r($errors);
		}

		libxml_clear_errors();
	}
	public static function test_profile_permitted_files()
	{
		$allowed_files = array('downloads.xml', 'test-definition.xml', 'results-definition.xml', 'install.sh', 'support-check.sh', 'pre.sh', 'post.sh', 'interim.sh', 'post-cache-share.sh');

		foreach(pts_types::operating_systems() as $os)
		{
			$os = strtolower($os[0]);
			$allowed_files[] = 'support-check_' . $os . '.sh';
			$allowed_files[] = 'install_' . $os . '.sh';
			$allowed_files[] = 'pre_' . $os . '.sh';
			$allowed_files[] = 'post_' . $os . '.sh';
			$allowed_files[] = 'interim_' . $os . '.sh';
		}

		return $allowed_files;
	}
	public static function check_xml_tags(&$obj, &$tags_to_check, &$append_missing_to)
	{
		foreach($tags_to_check as $tag_check)
		{
			$to_check = $obj->xml_parser->getXMLValue($tag_check[0]);

			if(empty($to_check))
			{
				$append_missing_to[] = $tag_check;
			}
		}
	}
	public static function print_issue($type, $problems_r)
	{
		foreach($problems_r as $error)
		{
			list($target, $description) = $error;

			echo PHP_EOL . $type . ': ' . $description . PHP_EOL;

			if(!empty($target))
			{
				echo 'TARGET: ' . $target . PHP_EOL;
			}
		}
	}
	public static function validate_test_suite(&$test_suite)
	{
		// Validate the XML against the XSD Schemas
		libxml_clear_errors();

		// First rewrite the main XML file to ensure it is properly formatted, elements are ordered according to the schema, etc...
		$valid = $test_suite->validate();

		if($valid == false)
		{
			echo PHP_EOL . 'Errors occurred parsing the main XML.' . PHP_EOL;
			pts_validation::process_libxml_errors();
			return false;
		}
		else
		{
			echo PHP_EOL . 'Test Suite XML Is Valid.' . PHP_EOL;
		}

		return true;
	}
	public static function validate_test_profile(&$test_profile)
	{

		if($test_profile->get_file_location() == null)
		{
			echo PHP_EOL . 'ERROR: The file location of the XML test profile source could not be determined.' . PHP_EOL;
			return false;
		}

		// Validate the XML against the XSD Schemas
		libxml_clear_errors();

		// Now re-create the pts_test_profile object around the rewritten XML
		$test_profile = new pts_test_profile($test_profile->get_identifier());
		$valid = $test_profile->validate();

		if($valid == false)
		{
			echo PHP_EOL . 'Errors occurred parsing the main XML.' . PHP_EOL;
			pts_validation::process_libxml_errors();
			return false;
		}

		// Rewrite the main XML file to ensure it is properly formatted, elements are ordered according to the schema, etc...
		$test_profile_writer = new pts_test_profile_writer();
		$test_profile_writer->rebuild_test_profile($test_profile);
		$test_profile_writer->save_xml($test_profile->get_file_location());

		// Now re-create the pts_test_profile object around the rewritten XML
		$test_profile = new pts_test_profile($test_profile->get_identifier());
		$valid = $test_profile->validate();

		if($valid == false)
		{
			echo PHP_EOL . 'Errors occurred parsing the main XML.' . PHP_EOL;
			pts_validation::process_libxml_errors();
			return false;
		}
		else
		{
			echo PHP_EOL . 'Test Profile XML Is Valid.' . PHP_EOL;
		}

		// Validate the downloads file
		$download_xml_file = $test_profile->get_file_download_spec();

		if(empty($download_xml_file) == false)
		{
			$writer = new pts_test_profile_downloads_writer();
			$writer->rebuild_download_file($test_profile);
			$writer->save_xml($download_xml_file);

			$dom = new DOMDocument();
			$dom->load($download_xml_file);
			$valid = $dom->schemaValidate(pts_openbenchmarking::openbenchmarking_standards_path() . 'schemas/test-profile-downloads.xsd');

			if($valid == false)
			{
				echo PHP_EOL . 'Errors occurred parsing the downloads XML.' . PHP_EOL;
				pts_validation::process_libxml_errors();
				return false;
			}
			else
			{
				echo PHP_EOL . 'Test Downloads XML Is Valid.' . PHP_EOL;
			}


			// Validate the individual download files
			echo PHP_EOL . 'Testing File Download URLs.' . PHP_EOL;
			$files_missing = 0;
			$file_count = 0;

			foreach($test_profile->get_downloads() as $download)
			{
				foreach($download->get_download_url_array() as $url)
				{
					$stream_context = pts_network::stream_context_create();
					stream_context_set_params($stream_context, array('notification' => 'pts_stream_status_callback'));
					$file_pointer = fopen($url, 'r', false, $stream_context);

					if($file_pointer == false)
					{
						echo 'File Missing: ' . $download->get_filename() . ' / ' . $url . PHP_EOL;
						$files_missing++;
					}
					else
					{
						fclose($file_pointer);
					}
					$file_count++;
				}
			}

			if($files_missing > 0) // && $file_count == $files_missing
			{
				return false;
			}
		}


		// Validate the parser file
		$parser_file = $test_profile->get_file_parser_spec();

		if(empty($parser_file) == false)
		{
			$writer = self::rebuild_result_parser_file($parser_file);
			$writer->saveXMLFile($parser_file);

			$dom = new DOMDocument();
			$dom->load($parser_file);
			$valid = $dom->schemaValidate(pts_openbenchmarking::openbenchmarking_standards_path() . 'schemas/results-parser.xsd');

			if($valid == false)
			{
				echo PHP_EOL . 'Errors occurred parsing the results parser XML.' . PHP_EOL;
				pts_validation::process_libxml_errors();
				return false;
			}
			else
			{
				echo PHP_EOL . 'Test Results Parser XML Is Valid.' . PHP_EOL;
			}
		}

		// Make sure no extra files are in there
		$allowed_files = pts_validation::test_profile_permitted_files();

		foreach(pts_file_io::glob($test_profile->get_resource_dir() . '*') as $tp_file)
		{
			if(!is_file($tp_file) || !in_array(basename($tp_file), $allowed_files))
			{
				echo PHP_EOL . basename($tp_file) . ' is not allowed in the test package.' . PHP_EOL;
				return false;
			}
		}

		return true;
	}
	public static function rebuild_result_parser_file($xml_file)
	{
		$xml_writer = new nye_XmlWriter();
		$xml_parser = new nye_XmlReader($xml_file);
		$result_template = $xml_parser->getXMLArrayValues('PhoronixTestSuite/ResultsParser/OutputTemplate');
		$result_match_test_arguments = $xml_parser->getXMLArrayValues('PhoronixTestSuite/ResultsParser/MatchToTestArguments');
		$result_key = $xml_parser->getXMLArrayValues('PhoronixTestSuite/ResultsParser/ResultKey');
		$result_line_hint = $xml_parser->getXMLArrayValues('PhoronixTestSuite/ResultsParser/LineHint');
		$result_line_before_hint = $xml_parser->getXMLArrayValues('PhoronixTestSuite/ResultsParser/LineBeforeHint');
		$result_line_after_hint = $xml_parser->getXMLArrayValues('PhoronixTestSuite/ResultsParser/LineAfterHint');
		$result_before_string = $xml_parser->getXMLArrayValues('PhoronixTestSuite/ResultsParser/ResultBeforeString');
		$result_after_string = $xml_parser->getXMLArrayValues('PhoronixTestSuite/ResultsParser/ResultAfterString');
		$strip_from_result = $xml_parser->getXMLArrayValues('PhoronixTestSuite/ResultsParser/StripFromResult');
		$strip_result_postfix = $xml_parser->getXMLArrayValues('PhoronixTestSuite/ResultsParser/StripResultPostfix');
		$multi_match = $xml_parser->getXMLArrayValues('PhoronixTestSuite/ResultsParser/MultiMatch');
		$chars_to_space = $xml_parser->getXMLArrayValues('PhoronixTestSuite/ResultsParser/TurnCharsToSpace');
		$file_format = $xml_parser->getXMLArrayValues('PhoronixTestSuite/ResultsParser/FileFormat');
		$result_divide_by = $xml_parser->getXMLArrayValues('PhoronixTestSuite/ResultsParser/DivideResultBy');
		$result_multiply_by = $xml_parser->getXMLArrayValues('PhoronixTestSuite/ResultsParser/MultiplyResultBy');
		$result_scale = $xml_parser->getXMLArrayValues('PhoronixTestSuite/ResultsParser/ResultScale');
		$result_proportion = $xml_parser->getXMLArrayValues('PhoronixTestSuite/ResultsParser/ResultProportion');
		$result_precision = $xml_parser->getXMLArrayValues('PhoronixTestSuite/ResultsParser/ResultPrecision');
		$result_args_desc = $xml_parser->getXMLArrayValues('PhoronixTestSuite/ResultsParser/ArgumentsDescription');
		$result_append_args_desc = $xml_parser->getXMLArrayValues('PhoronixTestSuite/ResultsParser/AppendToArgumentsDescription');
		$DeleteOutputBefore = $xml_parser->getXMLArrayValues('PhoronixTestSuite/ResultsParser/DeleteOutputBefore');
		$DeleteOutputAfter = $xml_parser->getXMLArrayValues('PhoronixTestSuite/ResultsParser/DeleteOutputAfter');

		foreach(array_keys($result_template) as $i)
		{
			$xml_writer->addXmlNode('PhoronixTestSuite/ResultsParser/OutputTemplate', $result_template[$i]);
			$xml_writer->addXmlNodeWNE('PhoronixTestSuite/ResultsParser/MatchToTestArguments', $result_match_test_arguments[$i]);
			$xml_writer->addXmlNodeWNE('PhoronixTestSuite/ResultsParser/ResultKey', $result_key[$i]);
			$xml_writer->addXmlNodeWNE('PhoronixTestSuite/ResultsParser/LineHint', $result_line_hint[$i]);
			$xml_writer->addXmlNodeWNE('PhoronixTestSuite/ResultsParser/LineBeforeHint', $result_line_before_hint[$i]);
			$xml_writer->addXmlNodeWNE('PhoronixTestSuite/ResultsParser/LineAfterHint', $result_line_after_hint[$i]);
			$xml_writer->addXmlNodeWNE('PhoronixTestSuite/ResultsParser/ResultBeforeString', $result_before_string[$i]);
			$xml_writer->addXmlNodeWNE('PhoronixTestSuite/ResultsParser/ResultAfterString', $result_after_string[$i]);
			$xml_writer->addXmlNodeWNE('PhoronixTestSuite/ResultsParser/StripFromResult', $strip_from_result[$i]);
			$xml_writer->addXmlNodeWNE('PhoronixTestSuite/ResultsParser/StripResultPostfix', $strip_result_postfix[$i]);
			$xml_writer->addXmlNodeWNE('PhoronixTestSuite/ResultsParser/MultiMatch', $multi_match[$i]);
			$xml_writer->addXmlNodeWNE('PhoronixTestSuite/ResultsParser/DivideResultBy', $result_divide_by[$i]);
			$xml_writer->addXmlNodeWNE('PhoronixTestSuite/ResultsParser/MultiplyResultBy', $result_multiply_by[$i]);
			$xml_writer->addXmlNodeWNE('PhoronixTestSuite/ResultsParser/ResultScale', $result_scale[$i]);
			$xml_writer->addXmlNodeWNE('PhoronixTestSuite/ResultsParser/ResultProportion', $result_proportion[$i]);
			$xml_writer->addXmlNodeWNE('PhoronixTestSuite/ResultsParser/ResultPrecision', $result_precision[$i]);
			$xml_writer->addXmlNodeWNE('PhoronixTestSuite/ResultsParser/ArgumentsDescription', $result_args_desc[$i]);
			$xml_writer->addXmlNodeWNE('PhoronixTestSuite/ResultsParser/AppendToArgumentsDescription', $result_append_args_desc[$i]);
			$xml_writer->addXmlNodeWNE('PhoronixTestSuite/ResultsParser/FileFormat', $file_format[$i]);
			$xml_writer->addXmlNodeWNE('PhoronixTestSuite/ResultsParser/TurnCharsToSpace', $chars_to_space[$i]);
			$xml_writer->addXmlNodeWNE('PhoronixTestSuite/ResultsParser/DeleteOutputBefore', $DeleteOutputBefore[$i]);
			$xml_writer->addXmlNodeWNE('PhoronixTestSuite/ResultsParser/DeleteOutputAfter', $DeleteOutputAfter[$i]);
		}

		$result_iqc_source_file = $xml_parser->getXMLArrayValues('PhoronixTestSuite/ImageParser/SourceImage');
		$result_match_test_arguments = $xml_parser->getXMLArrayValues('PhoronixTestSuite/ImageParser/MatchToTestArguments');
		$result_iqc_image_x = $xml_parser->getXMLArrayValues('PhoronixTestSuite/ImageParser/ImageX');
		$result_iqc_image_y = $xml_parser->getXMLArrayValues('PhoronixTestSuite/ImageParser/ImageY');
		$result_iqc_image_width = $xml_parser->getXMLArrayValues('PhoronixTestSuite/ImageParser/ImageWidth');
		$result_iqc_image_height = $xml_parser->getXMLArrayValues('PhoronixTestSuite/ImageParser/ImageHeight');

		foreach(array_keys($result_iqc_source_file) as $i)
		{
			$xml_writer->addXmlNode('PhoronixTestSuite/ImageParser/SourceImage', $result_iqc_source_file[$i]);
			$xml_writer->addXmlNodeWNE('PhoronixTestSuite/ImageParser/MatchToTestArguments', $result_match_test_arguments[$i]);
			$xml_writer->addXmlNodeWNE('PhoronixTestSuite/ImageParser/ImageX', $result_iqc_image_x[$i]);
			$xml_writer->addXmlNodeWNE('PhoronixTestSuite/ImageParser/ImageY', $result_iqc_image_y[$i]);
			$xml_writer->addXmlNodeWNE('PhoronixTestSuite/ImageParser/ImageWidth', $result_iqc_image_width[$i]);
			$xml_writer->addXmlNodeWNE('PhoronixTestSuite/ImageParser/ImageHeight', $result_iqc_image_height[$i]);
		}

		$monitor_sensor = $xml_parser->getXMLArrayValues('PhoronixTestSuite/SystemMonitor/Sensor');
		$monitor_frequency = $xml_parser->getXMLArrayValues('PhoronixTestSuite/SystemMonitor/PollingFrequency');
		$monitor_report_as = $xml_parser->getXMLArrayValues('PhoronixTestSuite/SystemMonitor/Report');

		foreach(array_keys($monitor_sensor) as $i)
		{
			$xml_writer->addXmlNode('PhoronixTestSuite/SystemMonitor/Sensor', $monitor_sensor[$i]);
			$xml_writer->addXmlNodeWNE('PhoronixTestSuite/SystemMonitor/PollingFrequency', $monitor_frequency[$i]);
			$xml_writer->addXmlNodeWNE('PhoronixTestSuite/SystemMonitor/Report', $monitor_report_as[$i]);
		}

		$extra_data_id = $xml_parser->getXMLArrayValues('PhoronixTestSuite/ExtraData/Identifier');

		foreach(array_keys($extra_data_id) as $i)
		{
			$xml_writer->addXmlNode('PhoronixTestSuite/ExtraData/Identifier', $extra_data_id[$i]);
		}

		return $xml_writer;
	}
	public static function process_xsd_types()
	{
		$doc = new DOMDocument();
		$xsd_file = pts_openbenchmarking::openbenchmarking_standards_path() . 'schemas/types.xsd';
		if(is_file($xsd_file))
		{
			$doc->loadXML(file_get_contents($xsd_file));
		}
		$xpath = new DOMXPath($doc);
		$xpath->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');

		$types = array();
		foreach($xpath->evaluate('/xs:schema/xs:simpleType') as $e)
		{
			$name = $e->getAttribute('name');
			$type = $e->getElementsByTagName('restriction')->item(0)->getAttribute('base');
			switch($type)
			{
				case 'xs:integer':
					$type = 'INT';
					break;
				case 'xs:string':
					$type = 'STRING';
					break;
			}
			if($e->getElementsByTagName('restriction')->item(0)->getElementsByTagName('minLength')->length > 0)
			{
				$min_length = $e->getElementsByTagName('restriction')->item(0)->getElementsByTagName('minLength')->item(0)->getAttribute('value');
			}
			else
			{
				$min_length = -1;
			}
			if($e->getElementsByTagName('restriction')->item(0)->getElementsByTagName('maxLength')->length > 0)
			{
				$max_length = $e->getElementsByTagName('restriction')->item(0)->getElementsByTagName('maxLength')->item(0)->getAttribute('value');
			}
			else
			{
				$max_length = -1;
			}
			if($e->getElementsByTagName('restriction')->item(0)->getElementsByTagName('minInclusive')->length > 0)
			{
				$min_value = $e->getElementsByTagName('restriction')->item(0)->getElementsByTagName('minInclusive')->item(0)->getAttribute('value');
			}
			else
			{
				$min_value = -1;
			}
			if($e->getElementsByTagName('restriction')->item(0)->getElementsByTagName('maxInclusive')->length > 0)
			{
				$max_value = $e->getElementsByTagName('restriction')->item(0)->getElementsByTagName('maxInclusive')->item(0)->getAttribute('value');
			}
			else
			{
				$max_value = -1;
			}

			$enums = array();
			for($i = 0; $i < $e->getElementsByTagName('restriction')->item(0)->getElementsByTagName('enumeration')->length; $i++)
			{
				$enums[] = $e->getElementsByTagName('restriction')->item(0)->getElementsByTagName('enumeration')->item($i)->getAttribute('value');
			}


			$types[$name] = new pts_input_type_restrictions($name, $type, $min_length, $max_length, $min_value, $max_value, $enums);
		}
		return $types;
	}
	public static function process_xsd_display_chart($xsd_file, $obj = null, $types = null)
	{
		$nodes = self::generate_xsd_element_objects($xsd_file, $obj, $types);
		self::xsd_display_cli_from_objects($nodes);
	}
	public static function xsd_to_cli_creator($xsd_file, &$new_object, $types = null)
	{
		$nodes = self::generate_xsd_element_objects($xsd_file, null, $types);
		self::xsd_nodes_to_cli_prompts($nodes, $new_object);
	}
	public static function xsd_nodes_to_cli_prompts($nodes, &$new_object)
	{
		foreach($nodes as $path => $node)
		{
			if($node->get_documentation() == null)
			{
				continue;
			}

			if(in_array('UNCOMMON', $node->get_flags_array()))
			{
				continue;
			}

			echo pts_client::cli_just_bold($node->get_name());

			/*
			if($node->get_value() != null)
			{
				echo ': ' . pts_client::cli_colored_text($node->get_value(), 'cyan');
			}
			*/

			echo PHP_EOL;
			$enums = array();
			$min_value = -1;
			$max_value = -1;
			if($node->get_input_type_restrictions() != null)
			{
				$type = $node->get_input_type_restrictions();
				$enums = $type->get_enums();
				if(!empty($enums))
				{
					echo pts_client::cli_colored_text('Possible Values: ', 'gray', true) . implode(', ', $enums) . PHP_EOL;
				}
				$min_value = $type->get_min_value();
				if($min_value > -1)
				{
					echo pts_client::cli_colored_text('Minimum Value: ', 'gray', true) . $min_value . PHP_EOL;
				}
				$max_value = $type->get_max_value();
				if($max_value > 0)
				{
					echo pts_client::cli_colored_text('Maximum Value: ', 'gray', true) . $max_value . PHP_EOL;
				}
			}
			/*if($node->get_api() != null)
			{
				echo pts_client::cli_colored_text('API: ', 'gray', true) . $node->get_api()[0] . '->' . $node->get_api()[1] . '()' . PHP_EOL;
			}*/
			if($node->get_documentation() != null)
			{
				echo $node->get_documentation() . PHP_EOL;
			}
			if($node->get_default_value() != null)
			{
				echo pts_client::cli_colored_text('Default Value: ', 'gray', true) . $node->get_default_value() . PHP_EOL;
			}

			$do_require = in_array('TEST_REQUIRES', $node->get_flags_array());
			if(!empty($enums))
			{
				$input = pts_user_io::prompt_text_menu('Select from the supported options', $enums, false, false, null);
			}
			else
			{
				do
				{
					$input_passes = true;
					$input = pts_user_io::prompt_user_input($path, !($do_require && $node->get_default_value() == null), false);

					if($do_require && $min_value > 0 && strlen($input) < $min_value)
					{
						echo 'Minimum length of ' . $min_value . ' is required.';
						$input_passes = false;
					}
					if($do_require && $max_value > 0 && strlen($input) > $max_value)
					{
						echo 'Maximum length of ' . $max_value . ' is supported.';
						$input_passes = false;
					}

				}
				while(!$input_passes);

				if(empty($input) && $node->get_default_value() != null)
				{
					$input = $node->get_default_value();
				}
			}

			$new_object->addXmlNodeWNE($path, trim($input));

			echo PHP_EOL;
		}
	}
	protected static function generate_xsd_element_objects($xsd_file, $obj = null, $types = null)
	{
		$doc = new DOMDocument();
		if(is_file($xsd_file))
		{
			$doc->loadXML(file_get_contents($xsd_file));
		}
		$xpath = new DOMXPath($doc);
		$xpath->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');

		$nodes = array();
		$ev = $xpath->evaluate('/xs:schema/xs:element');
		foreach($ev as $e)
		{
			self::xsd_elements_to_objects($nodes, $obj, $xpath, $e, $types, '');
		}

		return $nodes;
	}
	public static function xsd_elements_to_objects(&$append_to_array, $o, $xpath, $el, $types, $path)
	{
		static $unbounded;

		if($el->getElementsByTagName('*')->length > 0 && $el->getElementsByTagName('*')->item(0)->nodeName == 'xs:annotation' && $el->getElementsByTagName('*')->item(0)->getElementsByTagName('documentation')->length > 0)
		{
			$name = $el->getAttribute('name');
			$value = null;
			$get_api = null;
			$set_api = null;
			$default_value = null;
			$flags = null;
			$nodes_to_match = array('set' => 'set_api', 'get' => 'get_api', 'default' => 'default_value', 'flags' => 'flags');
			$cnodes = $el->getElementsByTagName('*');
			for($i = 0; $i < $cnodes->length; $i++)
			{
				if(isset($nodes_to_match[$cnodes->item($i)->nodeName]))
				{
					${$nodes_to_match[$cnodes->item($i)->nodeName]} = $cnodes->item($i)->nodeValue;
				}
			}

			if($get_api != null && (is_callable(array($o, $get_api)) || (is_array($o) && isset($o[$get_api]))))
			{
				if(is_object($o))
				{
					$class = get_class($o);
					$val = call_user_func(array($o, $get_api));

					if(is_object($val))
					{
						$o = $val;
						$val = null;
					}
				}
				else if(is_array($o))
				{
					$class = null;
					$val = $o[$get_api];
				}

				if($el->getAttribute('maxOccurs') == 'unbounded')
				{
					$o = $val;
					$val = null;
				}
				else if(is_array($val))
				{
					$val = '{ ' . implode(', ', call_user_func(array($o, $get_api))) . ' }';
				}
				else if($val === true)
				{
					$val = 'TRUE';
				}
				else if($val === false)
				{
					$val = 'FALSE';
				}

				if(!empty($val))
				{
					$value = $val;
				}
			}

			$input_type_restrictions = null;
			if($el->getAttribute('type') != null)
			{
				$type = $el->getAttribute('type');
				if(isset($types[$type]))
				{
					$types[$type]->set_required($el->getAttribute('minOccurs') > 0);
					$input_type_restrictions = $types[$type];
				}
			}
			if(is_array($unbounded))
			{
				foreach($unbounded as $ub_check)
				{
					if(strpos($path, $ub_check) !== false)
					{
						$flags .= ' UNBOUNDED';
						break;
					}
				}
			}
			$api = null;
			if(!empty($get_api) && !empty($class))
			{
				$api = array($class, $get_api);
			}
			$documentation = trim($el->getElementsByTagName('annotation')->item('0')->getElementsByTagName('documentation')->item(0)->nodeValue);
			$append_to_array[$path . '/' . $name] = new pts_element_node($name, $value, $input_type_restrictions, $api, $documentation, $set_api, $default_value, $flags);
		}
		else
		{
			$name = $el->getAttribute('name');
			$append_to_array[$path . '/' . $name] = new pts_element_node($name);
		}

		if($el->getAttribute('maxOccurs') == 'unbounded')
		{
			$unbounded[$path . '/' . $name] =  $path . '/' . $name;
		}

		$els = $xpath->evaluate('xs:complexType/xs:sequence/xs:element', $el);
		if(is_array($o) && !empty($o))
		{
			$path .= (!empty($path) ? '/' : '') . $name;
			foreach($o as $j)
			{
				foreach($els as $e)
				{
					self:: xsd_elements_to_objects($append_to_array, $j, $xpath, $e, $types, $path);
				}
			}
		}
		else
		{
			$path .= (!empty($path) ? '/' : '') . $name;
			foreach($els as $e)
			{
				self:: xsd_elements_to_objects($append_to_array, $o, $xpath, $e, $types, $path);
			}
		}
	}
	public static function xsd_display_cli_from_objects($nodes)
	{
		foreach($nodes as $path => $node)
		{
			$depth = count(explode('/', $path)) - 1;
			if($node->get_documentation() == null)
			{
				echo str_repeat('     ', $depth) . pts_client::cli_colored_text($node->get_name(), 'yellow', true);
			}
			else
				echo str_repeat('     ', $depth) . pts_client::cli_just_bold($node->get_name());

			if($node->get_value() != null)
			{
				echo ': ' . pts_client::cli_colored_text($node->get_value(), 'cyan');
			}
			echo PHP_EOL;
			if($node->get_input_type_restrictions() != null)
			{
				$type = $node->get_input_type_restrictions();
				$enums = $type->get_enums();
				if(!empty($enums))
				{
					echo str_repeat('     ', $depth) . pts_client::cli_colored_text('Possible Values: ', 'gray', true) . implode(', ', $enums) . PHP_EOL;
				}
				$min_value = $type->get_min_value();
				if($min_value > -1)
				{
					echo str_repeat('     ', $depth) . pts_client::cli_colored_text('Minimum Value: ', 'gray', true) . $min_value . PHP_EOL;
				}
				$max_value = $type->get_max_value();
				if($max_value > 0)
				{
					echo str_repeat('     ', $depth) . pts_client::cli_colored_text('Maximum Value: ', 'gray', true) . $max_value . PHP_EOL;
				}
			}
			if($node->get_api() != null)
			{
				echo str_repeat('     ', $depth) . pts_client::cli_colored_text('Get API: ', 'gray', true) . $node->get_api()[0] . '->' . $node->get_api()[1] . '()' . PHP_EOL;
			}
			if($node->get_api_setter() != null)
			{
				echo str_repeat('     ', $depth) . pts_client::cli_colored_text('Set API: ', 'gray', true) . $node->get_api_setter() . '()' . PHP_EOL;
			}
			if($node->get_default_value() != null)
			{
				echo str_repeat('     ', $depth) . pts_client::cli_colored_text('Default Value: ', 'gray', true) . $node->get_default_value() . PHP_EOL;
			}
			if($node->get_documentation() != null)
			{
				echo str_repeat('     ', $depth) .  $node->get_documentation() . PHP_EOL;
			}
			echo PHP_EOL;
		}
	}
}

?>
