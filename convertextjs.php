<?php
// Possible parameters types:
// String
// Aray
// String/Array
// Function
// Object
// Number
// Mixed
// Boolean
// HTMLElement
// HTMLElement/String
// HTML/Ext.Element
// Object/String
// String/Object/Function
// Number/Mixed
// ...

class ExtJSConverter
{
	private $_state,
			$_cur_class,
			$_classes			= array(),
			$_sorted_classes	= array(),
			$_top_classes		= array();

	const	DEBUGLEVEL = 10;
	
	const	START				= 0x01,
			IN_CLASS			= 0x02,
			IN_PROPERTIES		= 0x03,
			IN_METHODS			= 0x04;

	const PREFIX				= 'jq_';
	
	static private $RESERVED 	= array
	(
		'this', 'clone'
	);
			
	function __construct()
	{
		$this->run();
	}	
	
	private function run()
	{
		$topdir = 'docs/extjs';
		$d = opendir($topdir);
		while($fn = readdir($d))
		{
			$fqn = $topdir . '/' . $fn;
			if(!is_dir($fqn))
				continue;
			if($fn == '..')
				continue;
			$this->process_dir($fqn);
		}
		closedir($d);
		
		$this->inherit();
		
		// Let's write our PHP stub
		$header = <<<EOB
<?php
require 'libs/extphp/json.php';

// This static class contains the current code generated when creating EXT components
class JsWriter
{	
	static \$_output = '', \$_json = null;
			
	static function reset()
	{
		self::\$_output = '';	
	}
	
	static function write(\$txt)
	{
		self::\$_output .= \$txt;
	}
	static function get()
	{
		return self::\$_output;
	}
	static function JSON(\$phpstruct)
	{
		if(!self::\$_json)
			self::\$_json = new Services_JSON();
		return self::\$_json->encode(\$phpstruct);
	}
}

// Whatever is passed to this class' constructor will be executed
// when the web page is ready
class JsReady
{
	function __construct(\$body)
	{
		\$prefix = "Ext.onReady(function()\\n{\\n";
		\$suffix = "\\n});\\n";
		print \$prefix . \$body . \$suffix;
	}	
}

// Wrap Javascript code in a function
class JsFunction
{
	var \$_args, \$_body;
	function __construct(\$args, \$body)
	{
		\$this->_args = \$args;
		\$this->_body = \$body;
	}
	
	function __toString() { return "function(\$this->_args) { \$this->_body }"; }
}

// Anything assigned to an object of this class will be left untouched
// by our JSON translator
class JsLitteral
{
	var \$_body;
	function __construct(\$body)
	{
		\$this->_body = \$body;
	}
	
	function __toString() { return \$this->_body; }	
}

// Use this object to declare Javascript variables since by default
// variables assignments are done in PHP only
class JsVariable
{
	var \$_var_name, \$_value, \$_declared = false;
	
	function __call(\$funct, \$args)
	{
		if(gettype(\$args)=='array')
		{
			\$comma = '';
			\$new_args = '';
			foreach(\$args as \$arg)
			{
				if(gettype(\$arg)=='array')
					\$arg = JsWriter::JSON(\$arg);
				\$new_args .= \$comma.\$arg;
				\$comma = ',';
			}
			\$args = &\$new_args;
		}
		JsWriter::write(\$this->_var_name.".\$funct(\$args);");
	}
	
	function __construct(\$var_name, \$value=null)
	{
		\$this->_var_name = \$var_name;
		if(null != \$value)
			\$this->assign(\$value);
	}

	function assign(\$value)
	{
		\$this->_value = \$value;
		if(!\$this->_declared)
			JsWriter::write("var ".\$this->_var_name." = \$value;\\n");	
		else				
			JsWriter::write(\$this->_var_name." = \$value;\\n");	
		\$this->_declared = true;
	}
	
	function value() { return \$this->_value; }	
	function name()  {  return new JsLitteral(\$this->_var_name); }
}

// All Ext_...._Config classes are this class' children
class ConfigTemplate
{
	var \$_tupple = array();
	
	function associate(\$var_name, \$value) { \$this->_tupple[\$var_name] = \$value; return \$this; }
	function __toString() { return JsWriter::JSON(\$this->_tupple); }
}

// All Ext_... (except _Config) classes are this class' children
class ClassTemplate
{
	var \$_out;
	
	function __toString() { return \$this->_out; }
}

EOB;
		$f = fopen('libs/extphp/main.php', 'w+');			
		fputs($f, $header);
		fputs($f, '?>');
		fclose($f);		

		foreach($this->_sorted_classes as $class_name => $class_object)
		{
			$f = fopen("libs/extphp/$class_name.php", 'w+');		
			fputs($f, "<?php\n");
			$this->write_stub($f, $class_name, $class_object);
			fputs($f, '?>');
			fclose($f);		
		}
	}
	
	private function process_dir($dir_name)
	{
		$this->log(1, "Processing directory $dir_name");
		$sub_dirs = array();
		$d = opendir($dir_name);
		while($fn = readdir($d))
		{			
			if($fn[0] == '.')
				continue;
			if(is_dir($dir_name . '/' . $fn))
			{
				$sub_dirs[] = $dir_name . '/' . $fn;
			}
			$this->process_file($dir_name, $fn);
		}
		closedir($d);
		foreach($sub_dirs as $sub_dir)
		{
			$this->process_dir($sub_dir);
		}
	}
	
	private function process_file($dir_name, $fn)
	{
		$this->log(2, "    File: $fn");
		$f = fopen($dir_name . '/' . $fn, "r");
		$this->_state = self::START;
		while($l = fgets($f))
		{
			$this->process_line($l);
		}
		fclose($f);
	}
	
	// Simple State Machine: Depending on where we are in the current document,
	// we are getting more information on the current class.
	private function process_line($line)
	{
		switch($this->_state)
		{
			case self::START:
				if(preg_match('/<h1>Class[ \t]+(.+?)<\/h1>/', $line, $matches))
				{
					$this->log(2, "        Class ".$matches[1]);
					$this->_cur_class = new ClassDef($matches[1]);
					$this->_state = self::IN_CLASS;
				}
				break;
			case self::IN_CLASS:
				if(preg_match('/Extends:.*<a ext:cls=\"(.+?)\"/', $line, $matches))
				{
					$this->log(2, "        :: Extends ".$matches[1]);
					$this->_cur_class->set_parent_class_name($matches[1]);
				}
				else if(preg_match('/<h2>(Config Options|Public Properties)<\/h2>/', $line, $matches))		
				{
					$this->_state = self::IN_PROPERTIES;					
				}		
				break;
			case self::IN_PROPERTIES:
				// TODO Differentiate between:
				// - Config Options
				// - Public Properties
				if(preg_match('/<b>(.+)<\/b>[ \t]*:[ \t]*([A-Za-z0-9\.\/]+?)[ \t]*<div class=\"mdesc\">/', $line, $matches))
				{
					$this->log(3, "            Property: ".$matches[1]." (".$matches[2].")");
					// TODO Handle default
					$class_name_parts = explode('.', $this->_cur_class->get_name());					
					$property_name = &$matches[1];
					$static_property = false;
					if(false !== strpos($property_name, '.'))
					{
						$property_name_parts = explode('.', $property_name);
						$c_c_n_p = count($class_name_parts);
						$c_p_n_p = count($property_name_parts);
						$offset = ($class_name_parts[0]=='Ext' ? 1 : 0);
						for($i=0;$i<$c_c_n_p-$offset; $i++)
						{
							if($class_name_parts[$i+$offset] == $property_name_parts[0])
							{
								$offset += $i;
								break;
							}
						}
						if($c_c_n_p-$offset < $c_p_n_p)
						{
							$static_property = true;
							for($i=0; $i<$c_c_n_p-$offset; $i++)
							{
								if($class_name_parts[$i+$offset] != $property_name_parts[$i])
								{
									$static_property = false;
									break;
								}
							}
						}				
					}
					$this->_cur_class->set_property($property_name, '', $static_property, $matches[2]);
				}
				else if(preg_match('/<h2>Public Methods<\/h2>/', $line, $matches))		
				{
					$this->_state = self::IN_METHODS;					
				}		
				break;
			case self::IN_METHODS:
				if(preg_match('/<b>(.+)<\/b>(.+)[ \t]*<div class=\"mdesc\">/', $line, $matches))
				{
					// Extract parameters
					$raw_parameters_array = explode(',', $matches[2]);
					$method_name = $matches[1];
					$this->log(3, "            Method: ".$matches[1]);
					$args = array();
					foreach($raw_parameters_array as $raw_parameter)
					{
						if(preg_match("/(\[*)<code>([A-Za-z0-9\.\/]+)[ \t]+(.+)<\/code>/", $raw_parameter, $matches))
						{
							$this->log(4, "                ".$matches[2].": ".$matches[1].$matches[3]);
							if(false !== strpos($matches[3], 'etc.'))
							{
								// TODO Handle variable number of arguments							
							}
							else
							{
								if(!ctype_alnum($matches[3][0]))
								{
									$arg_type = $this->normalize_arg_type($matches[3]);
									$matches[3] = $matches[2];
									$matches[2] = $arg_type;
								}
								if(false !== strpos($matches[3], '.'))
								{
									// FIXME This assumes that we are only using internal consts...tssk tssk
									// On 2nd thought: ORLY?
									list(, $arg_name) = explode('.', $matches[3]);
									$matches[3] = 'const_'.$arg_name;
								}
								// Reserved keywords
								if(in_array($matches[3], self::$RESERVED))
									$matches[3] = self::PREFIX.$matches[3];
								$args[$matches[3]] = new ArgDef($matches[3], $matches[2], ($matches[1]=='['));		
							}
						}
					}
					$class_name_parts = explode('.', $this->_cur_class->get_name());
					// Build constructor comparison object and compare
					$constructor = false;
					$method_name_parts = explode('.', $method_name);
					$c_c_n_p = count($class_name_parts);
					$c_m_n_p = count($method_name_parts);
					if($c_m_n_p <= $c_c_n_p)
					{
						$constructor = true;
						for($i=1; $i<=$c_m_n_p; $i++)
						{
							if($class_name_parts[$c_c_n_p-$i] != $method_name_parts[$c_m_n_p-$i])
							{
								$constructor = false;
								break;
							}
						}
					}
					if($constructor)
					{
						$this->_cur_class->set_constructor_args($args);
					}
					else
					{
						$static_method = false;
						$offset = ($class_name_parts[0]=='Ext' ? 1 : 0);
						for($i=0;$i<$c_c_n_p-$offset; $i++)
						{
							if($class_name_parts[$i+$offset] == $method_name_parts[0])
							{
								$offset += $i;
								break;
							}
						}
						if($c_c_n_p-$offset < $c_m_n_p)
						{
							$static_method = true;
							for($i=0; $i<$c_c_n_p-$offset; $i++)
							{
								if($class_name_parts[$i+$offset] != $method_name_parts[$i])
								{
									$static_method = false;
									break;
								}
							}
						}				

						if($c_m_n_p>1)
						{
							if($class_name_parts[$c_c_n_p-1] == $method_name_parts[0])
								$static_method = true;
						}
						if(!$static_method) $static_method = $this->is_special_case_static($method_name);
						$this->_cur_class->set_method($method_name, $args, $static_method);
					}
				}
				else if(preg_match('/<h2>Public Events<\/h2>/', $line, $matches))		
				{
					// TODO Hmm this is dirty.
					$this->_classes[$this->_cur_class->get_name()] = $this->_cur_class;
					$this->_cur_class = null;
					$this->_state = self::START;					
				}		
				break;
		}
	}
	
	// Sadly some stuff is not properly documented and I have to make up for this here
	private function is_special_case_static($method_name)
	{
		if($method_name=='create')
			return true;
		return false;
	}
	
	private function normalize_class_name($class_name)
	{
		return preg_replace('/\.([A-Za-z])/', '_$1', $class_name);
	}
	
	private function normalize_arg_type($arg_type)
	{
		$ret = '';
		for($i=0; $i<strlen($arg_type); $i++)
		{
			if(ctype_alnum($arg_type[$i]))
				$ret .= $arg_type[$i];
		}
		return $ret;
	}

	// A very dumb method to re-order all classes so that
	// parent classes are declared before their children...(!)
	private function inherit()
	{
		$this->log(1, '...Computing inheritance(s?)');
		foreach($this->_classes as $class_name => $class_def)
		{
			$this->log(2,"For Class $class_name");
			$parent_name = $class_def->get_parent_class_name();
			if(!empty($parent_name))
			{
				$this->log(2, "    parent = ".$parent_name);
				$parent_def = &$this->_classes[$parent_name];
				$parent_def->_children[] = $class_def;
			}
			else
			{
				$this->log(2, "    no parent");
				$_top_classes[] = $class_def;
			}
		}
		
		foreach($_top_classes as $top_class)
		{
			$this->traverse_class_hierarchy($top_class);
		}		
	}
	
	// Recursively add children to sorted class structure.
	private function traverse_class_hierarchy(&$cur_class)
	{
		$this->_sorted_classes[$cur_class->get_name()] = $cur_class;
		foreach($cur_class->_children as $child)
		{
			$this->traverse_class_hierarchy($child);
		}						
	}
	
	// This is where we spit the wrapper PHP code.
	private function write_stub($f, $class_name, $class_object)
	{
		// CLASS
		if(false === strpos($class_name ,'.'))
		{
			// Some classes totally conflict with PHP...
			$this->log(1, "Warning: Not creating class $class_name due to short name.");
			return;
		}
		$this->log(2,"JS->PHP $class_name");
		
		$out = 'class ' . $this->normalize_class_name($class_name).'_Config';

		$p = $this->normalize_class_name($class_object->get_parent_class_name());
		if(!empty($p))
		{
			$out .= " extends {$p}_Config";
		}
		else
		{
			$out .= " extends ConfigTemplate";
		}
		$out .= "\n{\n";

		// CLASS/INSTANCE VARIABLES
		$js = '';
		$prop_sep = '';
		foreach($class_object->get_properties() as $property_name => $property_object)
		{
			if($property_object->is_static())
			{
				$property_name_parts = explode('.', $property_name);
				$php_property_name = $this->fix_reserved($property_name_parts[count($property_name_parts)-1]);
//				$out .= "\tstatic \$$php_property_name = '" . $this->escape_js($property_object->get_value()) . "';\n";
				$prop_sep = "\n";
				$out .= "\tstatic function $php_property_name(\$val) { \$this->_tupple['$property_name'] = \$val; return self; }\n";
//				$out .= "\tstatic function get_$php_property_name() { return \$this->_tupple['$property_name']; }\n";
			}
			else
			{
				$php_property_name = $this->fix_reserved($property_name);			
//				$out .= "\tvar \$$php_property_name = '" . $this->escape_js($property_object->get_value()) . "';\n";
				$prop_sep = "\n";
				$out .= "\tfunction $php_property_name(\$val) { \$this->_tupple['$property_name'] = \$val; return \$this; }\n";
//				$out .= "\tfunction get_$php_property_name() { return \$this->_tupple['$property_name']; }\n";
			}
			$prop_sep = "\n";
		}

		$out .= "}\n\n";

		$out .= 'class ' . $this->normalize_class_name($class_name);

		$p = $this->normalize_class_name($class_object->get_parent_class_name());
		if(!empty($p))
		{
			$out .= " extends $p";
		}
		else
		{
			$out .= " extends ClassTemplate";
		}
		$out .= "\n{\n\tvar \$_out;\n";

		// CONSTRUCTOR
		$js = '';
		$out .= "$prop_sep\tfunction __construct(";
		$js  .= "new $class_name(";
		$postout = '';
		$still_array = array();
		foreach($class_object->get_constructor_args() as $name => $arg_def)
		{
			$type = strtolower($arg_def->get_type());
			$postout .= "\t\tif(gettype(\$$name)=='array')\n\t\t\t\$$name = JsWriter::JSON(\$$name);\n";
			$optional = $arg_def->is_optional();			
			$out .= "$comma\$$name";
			if($optional)
				$out .= "=null";
			if(strtolower($type)=='string')
				$js  .= "$comma\"\$$name\"";
			else
				$js  .= "$comma\$$name";
			$comma = ', ';
		}
		$out .= ")\n\t{\n" . $postout;
		$js  .= ")\n";
		$out .= $this->add_silent_js($js);
		$out .= "\t}\n\n";
		
		// METHODS
		foreach($class_object->get_methods() as $method_name => $method_object)
		{
			$js = '';
			if($method_object->is_static())
			{
				$method_name_parts = explode('.', $method_name);
				$out .= "\tstatic function ".$this->fix_reserved($method_name_parts[count($method_name_parts)-1])."(";		
				$js  .= "$class_name.$method_name(";
			}
			else
			{
				$out .= "\tfunction ".$this->fix_reserved("$method_name")."(";
				$js  .= "$method_name(";
			}
			$postout = '';
			$comma = '';
			foreach($method_object->get_args() as $name => $arg_def)
			{
				$type = strtolower($arg_def->get_type());
				$postout .= "\t\tif(gettype(\$$name)=='array')\n\t\t\t\$$name = JsWriter::JSON(\$$name);\n";			
				$optional = $arg_def->is_optional();
				$out .= "$comma\$$name";
				if($optional)
					$out .= "= null";
				if($type=='string')
					$js  .= "$comma\"\$$name\"";
				else
					$js  .= "$comma\$$name";
				$comma = ', ';
			}
			$out .= ")\n\t{\n" . $postout;
			$js  .= ")";
			foreach($method_object->get_args() as $name => $arg_def)
			{
				$type = strtolower($arg_def->get_type());
				if($type=='array')
					$out .= "\t\t\$$name=JsWriter::JSON(\$$name);\n"; 				
			}
			$out .= $this->add_get_js($js);
			$out .= "\t}\n\n";
		}
		
		//
		$out .= "\tfunction jsrender() { JsWriter::write(\$this->_out); }\n}\n";
		fputs($f, $out);		
	}
	
	// Do no break code with runaway quotes
	function escape_js($txt)
	{
		return str_replace("'", "\\'", $txt);
	}
	
	private function add_silent_js($js)
	{
		return "\t\t\$this->_out =<<<EOB\n$js\nEOB;\n";
	}

	private function add_get_js($js)
	{
		return "\t\t\$out =<<<EOB\n$js;\nEOB;\n\t\tJsWriter::write(\$out);\n";
	}
	
	// Reserved keywords are prefixed to avoid conflict
	private function fix_reserved($name)
	{
		if(in_array($name, self::$RESERVED))
			return self::PREFIX.$name;
		else
			return $name;		
	}
		
	private function log($level, $txt, $matches = null)
	{
		if($level > self::DEBUGLEVEL)
			return;
		print "$txt\n";
		if($matches)
			print_r($matches);
	}
	
	private function panic($txt)
	{
		die("\nPANIC: $txt\n");
	}
}

class ClassDef
{
	private	$_name,
			$_uid, // Reserved for future use
			$_parent_class_name,
			$_constructor_args = array(),
			$_properties = array(),
			$_methods = array();
			
	public	$_children = array();
	
	static private $_uid_runner = 1;
	
	function __construct($name)
	{
		$this->_uid = sprintf('extjs%u', self::$_uid_runner++);
		$this->_name = trim($name);
	}
	
	function get_name()
	{
		return $this->_name;
	}

	function get_uid()
	{
		return $this->_uid;	
	}
	
	function set_parent_class_name($parent_class_name)
	{
		$this->_parent_class_name = trim($parent_class_name);
	}
	
	function get_parent_class_name()
	{
		return $this->_parent_class_name;
	}

	function set_constructor_args($constructor_args)
	{
		$this->_constructor_args = $constructor_args;
	}

	function get_constructor_args()
	{
		return $this->_constructor_args;
	}
	
	function set_property($name, $value, $static_property, $type)
	{
		$this->_properties[$name] = new PropertyDef($name, $value, $static_property, $type);
	}
	
	function get_properties()
	{
		return $this->_properties;
	}
		
	function merge_properties($other_class_def)
	{
		$this->_properties = array_merge($this->_properties, $other_class_def->get_properties());
	}

	function set_method($name, $args, $static_method)
	{
		$this->_methods[$name] = new MethodDef($name, $args, $static_method);
	}
	
	function get_methods()
	{
		return $this->_methods;
	}

	function merge_methods($other_class_def)
	{
		$this->_methods = array_merge($this->_methods, $other_class_def->get_methods());
	}
}

class PropertyDef
{
	private	$_name,
			$_value,
			$_type,
			$_static;

	function __construct($name, $value, $static = false, $type='unknown')
	{
		$this->_name = trim($name);
		$this->_value = trim($value);
		$this->_type = trim($type);
		$this->_static = $static;
	}
	
	function get_name()
	{
		return $this->_name;
	}
	
	function get_value()
	{
		return $this->_value;
	}

	function is_static()
	{
		return $this->_static;
	}
}

class MethodDef
{
	private	$_name,
			$_args,
			$_static;
	
	function __construct($name, $args, $static = false)
	{
		$this->_name = trim($name);
		$this->_args = $args;
		$this->_static = $static;
	}
	
	function get_name()
	{
		return $this->_name;
	}
	
	function get_args()
	{
		return $this->_args;
	}
	
	function is_static()
	{
		return $this->_static;
	}
}

class ArgDef
{
	private	$_name,
			$_type,
			$_optional;
			
	function __construct($name, $type, $optional)
	{
		$this->_name		= trim($name);
		$this->_type 		= trim($type);
		$this->_optional	= $optional;
	}
	
	function get_name()
	{
		return $this->_name;
	}

	function get_type()
	{
		return $this->_type;
	}

	function is_optional()
	{
		return $this->_optional;
	}
}

new EXTJSConverter();
?>
