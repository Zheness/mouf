<?php
/*
 * This file is part of the Mouf core package.
*
* (c) 2012-2013 David Negrier <david@mouf-php.com>
*
* For the full copyright and license information, please view the LICENSE.txt
* file that was distributed with this source code.
*/
namespace Mouf;

use Mouf\Composer\ComposerService;

use Mouf\Reflection\MoufReflectionProxy;
use Mouf\Reflection\MoufReflectionClass;
use Mouf\Reflection\ReflectionClassManagerInterface;

/**
 * This class is managing object instanciation in the Mouf framework.
 * This is a dependency injection container (DIC).
 * Use it to retrieve instances declared using the Mouf UI, or to create/edit the instances.
 *
 */
class MoufContainer {
	const DECLARE_ON_EXIST_EXCEPTION = 'exception';
	const DECLARE_ON_EXIST_KEEP_INCOMING_LINKS = 'keepincominglinks';
	const DECLARE_ON_EXIST_KEEP_ALL = 'keepall';

	/**
	 * The name of the file containing the configuration.
	 * 
	 * @var string
	 */
	private $configFile;
	
	/**
	 * The object in charge of fetching class descriptor instances. Only used in edition mode.
	 * 
	 * @var ReflectionClassManagerInterface
	 */
	private $reflectionClassManager;
	
	/**
	 * The array of component instances managed by mouf.
	 * The objects in this array have already been instanciated.
	 * The key is the name of the instance, the value is the object.
	 *
	 * @var array<string, object>
	 */
	private $objectInstances = array();

	/**
	 * The array of component instances that have been declared.
	 * This array contains the definition that will be used to create the instances.
	 *
	 * $declaredInstance["instanceName"] = $instanceDefinitionArray;
	 *
	 * $instanceDefinitionArray["class"] = "string"
	 * $instanceDefinitionArray["fieldProperties"] = array("propertyName" => $property);
	 * $instanceDefinitionArray["setterProperties"] = array("setterName" => $property);
	 * $instanceDefinitionArray["fieldBinds"] = array("propertyName" => "instanceName");
	 * $instanceDefinitionArray["setterBinds"] = array("setterName" => "instanceName");
	 * $instanceDefinitionArray["comment"] = "string"
	 * $instanceDefinitionArray["weak"] = true|false (if true, object can be garbage collected if not referenced)
	 * $instanceDefinitionArray["anonymous"] = true|false (if true, object name should not be displayed. Object becomes "weak")
	 * $instanceDefinitionArray["external"] = true|false
	 *
	 * $property["type"] = "string|config|request|session";
	 * $property["value"] = $value;
	 * $property['metadata'] = array($key=>$value)
	 *
	 * @var array<string, array>
	 */
	private $declaredInstances = array();

	/**
	 * Constructs a container.
	 * 
	 * @param ReflectionClassManagerInterface $reflectionClassManager The object in charge of fetching class descriptor instances. Only used in edition mode.
	 */
	public function __construct(ReflectionClassManagerInterface $reflectionClassManager) {
		$this->reflectionClassManager = $reflectionClassManager;
	}
	
	/**
	 * Loads the config file passed in parameter.
	 * This will unload any other config file loaded before.
	 * 
	 * @param string $fileName
	 */
	public function load($fileName) {
		$this->objectInstances = array();
		$this->declaredInstances = require $fileName;
		$this->configFile = $fileName;
	}
	
	/**
	 * Sets the reflection class manager, after instanciation.
	 * (Useful for mouf hidden mode, where instanciation is done before
	 * we know the real mode).
	 * 
	 * @param ReflectionClassManagerInterface $reflectionClassManager
	 */
	public function setReflectionClassManager(ReflectionClassManagerInterface $reflectionClassManager) {
		$this->reflectionClassManager = $reflectionClassManager;
	}
	
	/**
	 * Returns the instance of the specified object.
	 *
	 * @param string $instanceName
	 * @return object
	 */
	public function get($instanceName) {
		if (!isset($this->objectInstances[$instanceName]) || $this->objectInstances[$instanceName] == null) {
			$this->instantiateComponent($instanceName);
		}
		return $this->objectInstances[$instanceName];
	}
	
	/**
	 * Returns true if the instance name passed in parameter is defined in Mouf.
	 *
	 * @param string $instanceName
	 */
	public function has($instanceName) {
		return isset($this->declaredInstances[$instanceName]);
	}

	/**
	 * Returns the list of all instances of objects in Mouf.
	 * Objects are not instanciated. Instead, a list containing the name of the instance in the key
	 * and the name of the class in the value is returned.
	 *
	 * @return array<string, string>
	 */
	public function getInstancesList() {
		$arr = array();
		foreach ($this->declaredInstances as $instanceName=>$classDesc) {
			$arr[$instanceName] = $classDesc['class'];
		}
		return $arr;
	}

	/**
	 * Sets at once all the instances of all the components.
	 * This is used internally to load the state of Mouf very quickly.
	 * Do not use directly.
	 *
	 * @param array $definition A huge array defining all the declared instances definitions.
	 */
	public function addComponentInstances(array $definition) {
		$this->declaredInstances = array_merge($this->declaredInstances, $definition);
	}

	/**
	 * Declares a new component. Low-level function. Unless you are worried by performances, you should use the createInstance function instead.
	 *
	 * @param string $instanceName
	 * @param string $className
	 * @param boolean $external Whether the component is external or not. Defaults to false.
	 * @param int $mode Depending on the mode, the behaviour will be different if an instance with the same name already exists.
	 * @param bool $weak If the object is weak, it will be destroyed if it is no longer referenced.
	 */
	public function declareComponent($instanceName, $className, $external = false, $mode = self::DECLARE_ON_EXIST_EXCEPTION, $weak = false) {
		if (isset($this->declaredInstances[$instanceName])) {
			if ($mode == self::DECLARE_ON_EXIST_EXCEPTION) {
				throw new MoufException("Unable to create Mouf instance named '".$instanceName."'. An instance with this name already exists.");
			} elseif ($mode == self::DECLARE_ON_EXIST_KEEP_INCOMING_LINKS) {
				$this->declaredInstances[$instanceName]["fieldProperties"] = array();
				$this->declaredInstances[$instanceName]["setterProperties"] = array();
				$this->declaredInstances[$instanceName]["fieldBinds"] = array();
				$this->declaredInstances[$instanceName]["setterBinds"] = array();
				$this->declaredInstances[$instanceName]["weak"] = $weak;
				$this->declaredInstances[$instanceName]["comment"] = "";
			} elseif ($mode == self::DECLARE_ON_EXIST_KEEP_ALL) {
				// Do nothing
			}
		}
		
		if (strpos($className, '\\' === 0)) {
			$className = substr($className, 1);
		}
		
		$this->declaredInstances[$instanceName]["class"] = $className;
		$this->declaredInstances[$instanceName]["external"] = $external;
	}

	/**
	 * Removes an instance.
	 * Sets to null any property linking to that component or remove the property from any array it might belong to.
	 *
	 * @param string $instanceName
	 */
	public function removeInstance($instanceName) {
		unset($this->instanceDescriptors[$instanceName]);
		unset($this->declaredInstances[$instanceName]);
		if (isset($this->instanceDescriptors[$instanceName])) {
			unset($this->instanceDescriptors[$instanceName]);
		}
		
		foreach ($this->declaredInstances as $declaredInstanceName=>$declaredInstance) {
			if (isset($declaredInstance["constructor"])) {
				foreach ($declaredInstance["constructor"] as $index=>$propWrapper) {
					if ($propWrapper['parametertype'] == 'object') {
						$properties = $propWrapper['value'];
						if (is_array($properties)) {
							// If this is an array of properties
							$keys_matching = array_keys($properties, $instanceName);
							if (!empty($keys_matching)) {
								foreach ($keys_matching as $key) {
									unset($properties[$key]);
								}
								$this->setParameterViaConstructor($declaredInstanceName, $index, $properties, 'object');
							}
						} else {
							// If this is a simple property
							if ($properties == $instanceName) {
								$this->setParameterViaConstructor($declaredInstanceName, $index, null, 'object');
							}
						}
					}
				}
			}
		}
		
		foreach ($this->declaredInstances as $declaredInstanceName=>$declaredInstance) {
			if (isset($declaredInstance["fieldBinds"])) {
				foreach ($declaredInstance["fieldBinds"] as $paramName=>$properties) {
					if (is_array($properties)) {
						// If this is an array of properties
						$keys_matching = array_keys($properties, $instanceName);
						if (!empty($keys_matching)) {
							foreach ($keys_matching as $key) {
								unset($properties[$key]);
							}
							$this->bindComponents($declaredInstanceName, $paramName, $properties);
						}
					} else {
						// If this is a simple property
						if ($properties == $instanceName) {
							$this->bindComponent($declaredInstanceName, $paramName, null);
						}
					}
				}
			}
		}

		foreach ($this->declaredInstances as $declaredInstanceName=>$declaredInstance) {
			if (isset($declaredInstance["setterBinds"])) {
				foreach ($declaredInstance["setterBinds"] as $setterName=>$properties) {
					if (is_array($properties)) {
						// If this is an array of properties
						$keys_matching = array_keys($properties, $instanceName);
						if (!empty($keys_matching)) {
							foreach ($keys_matching as $key) {
								unset($properties[$key]);
							}
							$this->bindComponentsViaSetter($declaredInstanceName, $setterName, $properties);
						}
					} else {
						// If this is a simple property
						if ($properties == $instanceName) {
							$this->bindComponentViaSetter($declaredInstanceName, $setterName, null);
						}
					}
				}
			}
		}
	}

	/**
	 * Renames an instance.
	 * All properties are redirected to the new instance accordingly.
	 *
	 * @param string $instanceName Old name
	 * @param string $newInstanceName New name
	 */
	public function renameInstance($instanceName, $newInstanceName) {
		if ($instanceName == $newInstanceName) {
			return;
		}

		if (isset($this->declaredInstances[$newInstanceName])) {
			throw new MoufException("Unable to rename instance '$instanceName' to '$newInstanceName': Instance '$newInstanceName' already exists.");
		}

		if (isset($this->declaredInstances[$instanceName]['external']) && $this->declaredInstances[$instanceName]['external'] == true) {
			throw new MoufException("Unable to rename instance '$instanceName' into '$newInstanceName': Instance '$instanceName' is declared externally.");
		}

		$this->declaredInstances[$newInstanceName] = $this->declaredInstances[$instanceName];
		unset($this->declaredInstances[$instanceName]);
		
		foreach ($this->declaredInstances as $declaredInstanceName=>$declaredInstance) {
			if (isset($declaredInstance["constructor"])) {
				foreach ($declaredInstance["constructor"] as $index=>$propWrapper) {
					if ($propWrapper['parametertype'] == 'object') {
						$properties = $propWrapper['value'];
						if (is_array($properties)) {
							// If this is an array of properties
							$keys_matching = array_keys($properties, $instanceName);
							if (!empty($keys_matching)) {
								foreach ($keys_matching as $key) {
									$properties[$key] = $newInstanceName;
								}
								$this->setParameterViaConstructor($declaredInstanceName, $index, $properties, 'object');
							}
						} else {
							// If this is a simple property
							if ($properties == $instanceName) {
								$this->setParameterViaConstructor($declaredInstanceName, $index, $newInstanceName, 'object');
							}
						}
					}
				}
			}
		}
		
		
		foreach ($this->declaredInstances as $declaredInstanceName=>$declaredInstance) {
			if (isset($declaredInstance["fieldBinds"])) {
				foreach ($declaredInstance["fieldBinds"] as $paramName=>$properties) {
					if (is_array($properties)) {
						// If this is an array of properties
						$keys_matching = array_keys($properties, $instanceName);
						if (!empty($keys_matching)) {
							foreach ($keys_matching as $key) {
								$properties[$key] = $newInstanceName;
							}
							$this->bindComponents($declaredInstanceName, $paramName, $properties);
						}
					} else {
						// If this is a simple property
						if ($properties == $instanceName) {
							$this->bindComponent($declaredInstanceName, $paramName, $newInstanceName);
						}
					}
				}
			}
		}

		foreach ($this->declaredInstances as $declaredInstanceName=>$declaredInstance) {
			if (isset($declaredInstance["setterBinds"])) {
				foreach ($declaredInstance["setterBinds"] as $setterName=>$properties) {
					if (is_array($properties)) {
						// If this is an array of properties
						$keys_matching = array_keys($properties, $instanceName);
						if (!empty($keys_matching)) {
							foreach ($keys_matching as $key) {
								$properties[$key] = $newInstanceName;
							}
							$this->bindComponentsViaSetter($declaredInstanceName, $setterName, $properties);
						}
					} else {
						// If this is a simple property
						if ($properties == $instanceName) {
							$this->bindComponentViaSetter($declaredInstanceName, $setterName, $newInstanceName);
						}
					}
				}
			}
		}
		
		if (isset($this->instanceDescriptors[$instanceName])) {
			$this->instanceDescriptors[$newInstanceName] = $this->instanceDescriptors[$instanceName];
			unset($this->instanceDescriptors[$instanceName]);
		}
	}

	/**
	 * Return the type of the instance.
	 *
	 * @param string $instanceName The instance name
	 * @return string The class name of the instance
	 */
	public function getInstanceType($instanceName) {
		return $this->declaredInstances[$instanceName]['class'];
	}

	/**
	 * Instantiate the object (and any object needed on the way)
	 *
	 */
	private function instantiateComponent($instanceName) {
		if (!isset($this->declaredInstances[$instanceName])) {
			throw new MoufInstanceNotFoundException("The object instance '".$instanceName."' is not defined.", 1, $instanceName);
		}
		try {
			$instanceDefinition = $this->declaredInstances[$instanceName];
	
			$className = $instanceDefinition["class"];
	
			if (isset($instanceDefinition['constructor'])) {
				$constructorParametersArray = $instanceDefinition['constructor'];
				
				$classDescriptor = new \ReflectionClass($className);
				
				$constructorParameters = array();
				foreach ($constructorParametersArray as $constructorParameterDefinition) {
					$value = $constructorParameterDefinition["value"];
					switch ($constructorParameterDefinition['parametertype']) {
						case "primitive":
							switch ($constructorParameterDefinition["type"]) {
								case "string":
									$constructorParameters[] = $value;
									break;
								case "request":
									$constructorParameters[] = $_REQUEST[$value];
									break;
								case "session":
									$constructorParameters[] = $_SESSION[$value];
									break;
								case "config":
									$constructorParameters[] = constant($value);
									break;
								default:
									throw new MoufException("Invalid type '".$constructorParameterDefinition["type"]."' for object instance '$instanceName'.");
							}
							break;
						case "object":
							if (is_array($value)) {
								$tmpArray = array();
								foreach ($value as $keyInstanceName=>$valueInstanceName) {
									if ($valueInstanceName !== null) {
										$tmpArray[$keyInstanceName] = $this->get($valueInstanceName);
									} else {
										$tmpArray[$keyInstanceName] = null;
									}
								}
								$constructorParameters[] = $tmpArray;
							} else {
								if ($value !== null) {
									$constructorParameters[] = $this->get($value);
								} else {
									$constructorParameters[] = null;
								}
							}
							break;
						default:	
							throw new MoufException("Unknown parameter type ".$constructorParameterDefinition['parametertype']." for parameter in constructor of instance '".$instanceName."'");
					}
				}
				$object = $classDescriptor->newInstanceArgs($constructorParameters);
			} else {
				$object = new $className();
			}
			$this->objectInstances[$instanceName] = $object;
			if (isset($instanceDefinition["fieldProperties"])) {
				foreach ($instanceDefinition["fieldProperties"] as $key=>$valueDef) {
					switch ($valueDef["type"]) {
						case "string":
							$object->$key = $valueDef["value"];
							break;
						case "request":
							$object->$key = $_REQUEST[$valueDef["value"]];
							break;
						case "session":
							$object->$key = $_SESSION[$valueDef["value"]];
							break;
						case "config":
							$object->$key = constant($valueDef["value"]);
							break;
						default:
							throw new MoufException("Invalid type '".$valueDef["type"]."' for object instance '$instanceName'.");
					}
				}
			}
	
			if (isset($instanceDefinition["setterProperties"])) {
				foreach ($instanceDefinition["setterProperties"] as $key=>$valueDef) {
					//$object->$key($valueDef["value"]);
					switch ($valueDef["type"]) {
						case "string":
							$object->$key($valueDef["value"]);
							break;
						case "request":
							$object->$key($_REQUEST[$valueDef["value"]]);
							break;
						case "session":
							$object->$key($_SESSION[$valueDef["value"]]);
							break;
						case "config":
							$object->$key(constant($valueDef["value"]));
							break;
						default:
							throw new MoufException("Invalid type '".$valueDef["type"]."' for object instance '$instanceName'.");
					}
				}
			}
	
			if (isset($instanceDefinition["fieldBinds"])) {
				foreach ($instanceDefinition["fieldBinds"] as $key=>$value) {
					if (is_array($value)) {
						$tmpArray = array();
						foreach ($value as $keyInstanceName=>$valueInstanceName) {
							if ($valueInstanceName !== null) {
								$tmpArray[$keyInstanceName] = $this->get($valueInstanceName);
							} else {
								$tmpArray[$keyInstanceName] = null;
							}
						}
						$object->$key = $tmpArray;
					} else {
						$object->$key = $this->get($value);
					}
				}
			}
	
			if (isset($instanceDefinition["setterBinds"])) {
				foreach ($instanceDefinition["setterBinds"] as $key=>$value) {
					if (is_array($value)) {
						$tmpArray = array();
						foreach ($value as $keyInstanceName=>$valueInstanceName) {
							if ($valueInstanceName !== null) {
								$tmpArray[$keyInstanceName] = $this->get($valueInstanceName);
							} else {
								$tmpArray[$keyInstanceName] = null;
							}
						}
						$object->$key($tmpArray);
					} else {
						$object->$key($this->get($value));
					}
				}
			}
		} catch (MoufInstanceNotFoundException $e) {
			throw new MoufInstanceNotFoundException("The object instance '".$instanceName."' could not be created because it depends on an object in error (".$e->getMissingInstanceName().")", 2, $instanceName, $e);
		}
		return $object;
	}

	/**
	 * Binds a parameter to the instance.
	 * Low-level function. Unless you are worried by performances, you should use the createInstance function instead.
	 *
	 * @param string $instanceName
	 * @param string $paramName
	 * @param string $paramValue
	 * @param string $type Can be one of "string|config|request|session"
	 * @param array $metadata An array containing metadata
	 */
	public function setParameter($instanceName, $paramName, $paramValue, $type = "string", array $metadata = array()) {
		if ($type != "string" && $type != "config" && $type != "request" && $type != "session") {
			throw new MoufException("Invalid type. Must be one of: string|config|request|session. Value passed: '".$type."'");
		}

		$this->declaredInstances[$instanceName]["fieldProperties"][$paramName]["value"] = $paramValue;
		$this->declaredInstances[$instanceName]["fieldProperties"][$paramName]["type"] = $type;
		$this->declaredInstances[$instanceName]["fieldProperties"][$paramName]["metadata"] = $metadata;
	}

	/**
	 * Binds a parameter to the instance using a setter.
	 *
	 * @param string $instanceName
	 * @param string $setterName
	 * @param string $paramValue
	 * @param string $type Can be one of "string|config|request|session"
	 * @param array $metadata An array containing metadata
	 */
	public function setParameterViaSetter($instanceName, $setterName, $paramValue, $type = "string", array $metadata = array()) {
		if ($type != "string" && $type != "config" && $type != "request" && $type != "session") {
			throw new MoufException("Invalid type. Must be one of: string|config|request|session");
		}

		$this->declaredInstances[$instanceName]["setterProperties"][$setterName]["value"] = $paramValue;
		$this->declaredInstances[$instanceName]["setterProperties"][$setterName]["type"] = $type;
		$this->declaredInstances[$instanceName]["setterProperties"][$setterName]["metadata"] = $metadata;
	}

	/**
	 * Binds a parameter to the instance using a construcotr parameter.
	 *
	 * @param string $instanceName
	 * @param string $index
	 * @param string $paramValue
	 * @param string $parameterType Can be one of "primitive" or "object".
	 * @param string $type Can be one of "string|config|request|session"
	 * @param array $metadata An array containing metadata
	 */
	public function setParameterViaConstructor($instanceName, $index, $paramValue, $parameterType, $type = "string", array $metadata = array()) {
		if ($type != "string" && $type != "config" && $type != "request" && $type != "session") {
			throw new MoufException("Invalid type. Must be one of: string|config|request|session");
		}

		$this->declaredInstances[$instanceName]['constructor'][$index]["value"] = $paramValue;
		$this->declaredInstances[$instanceName]['constructor'][$index]["parametertype"] = $parameterType;
		$this->declaredInstances[$instanceName]['constructor'][$index]["type"] = $type;
		$this->declaredInstances[$instanceName]['constructor'][$index]["metadata"] = $metadata;
		
		// Now, let's make sure that all indexes BEFORE ours are set, and let's order everything by key.
		for ($i=0; $i<$index; $i++) {
			if (!isset($this->declaredInstances[$instanceName]['constructor'][$i])) {
				// If the parameter before does not exist, let's set it to null.
				$this->declaredInstances[$instanceName]['constructor'][$i]["value"] = null;
				$this->declaredInstances[$instanceName]['constructor'][$i]["parametertype"] = "primitive";
				$this->declaredInstances[$instanceName]['constructor'][$i]["type"] = "string";
				$this->declaredInstances[$instanceName]['constructor'][$i]["metadata"] = array();
			}
		}
		ksort($this->declaredInstances[$instanceName]['constructor']);
	}


	/**
	 * Unsets all the parameters (using a property or a setter) for the given instance.
	 *
	 * @param string $instanceName The instance to consider
	 */
	public function unsetAllParameters($instanceName) {
		unset($this->declaredInstances[$instanceName]["fieldProperties"]);
		unset($this->declaredInstances[$instanceName]["setterProperties"]);
	}

	/**
	 * Returns the value for the given parameter.
	 *
	 * @param string $instanceName
	 * @param string $paramName
	 * @return mixed
	 */
	public function getParameter($instanceName, $paramName) {
		// todo: improve this
		if (isset($this->declaredInstances[$instanceName]['fieldProperties'][$paramName]['value'])) {
			return $this->declaredInstances[$instanceName]['fieldProperties'][$paramName]['value'];
		} else {
			return null;
		}
	}
	
	/**
	 * Returns true if the value of the given parameter is set.
	 * False otherwise.
	 * 
	 * @param string $instanceName
	 * @param string $paramName
	 * @return boolean
	 */
	public function isParameterSet($instanceName, $paramName) {
		return isset($this->declaredInstances[$instanceName]['fieldProperties'][$paramName]) || isset($this->declaredInstances[$instanceName]['fieldBinds'][$paramName]);
	}
	
	/**
	 * Completely unset this parameter from the DI container.
	 *
	 * @param string $instanceName
	 * @param string $paramName
	 */
	public function unsetParameter($instanceName, $paramName) {
		unset($this->declaredInstances[$instanceName]['fieldProperties'][$paramName]);
		unset($this->declaredInstances[$instanceName]['fieldBinds'][$paramName]);
	}

	/**
	 * Returns the value for the given parameter that has been set using a setter.
	 *
	 * @param string $instanceName
	 * @param string $setterName
	 * @return mixed
	 */
	public function getParameterForSetter($instanceName, $setterName) {
		// todo: improve this
		if (isset($this->declaredInstances[$instanceName]['setterProperties'][$setterName]['value'])) {
			return $this->declaredInstances[$instanceName]['setterProperties'][$setterName]['value'];
		} else {
			return null;
		}
	}
	
	/**
	 * Returns true if the value of the given setter parameter is set.
	 * False otherwise.
	 *
	 * @param string $instanceName
	 * @param string $setterName
	 * @return boolean
	 */
	public function isParameterSetForSetter($instanceName, $setterName) {
		return isset($this->declaredInstances[$instanceName]['setterProperties'][$setterName]) || isset($this->declaredInstances[$instanceName]['setterBinds'][$setterName]);
	}
	
	/**
	 * Completely unset this setter parameter from the DI container.
	 *
	 * @param string $instanceName
	 * @param string $setterName
	 */
	public function unsetParameterForSetter($instanceName, $setterName) {
		unset($this->declaredInstances[$instanceName]['setterProperties'][$setterName]);
		unset($this->declaredInstances[$instanceName]['setterBinds'][$setterName]);
	}

	/**
	 * Returns the value for the given parameter that has been set using a constructor.
	 *
	 * @param string $instanceName
	 * @param int $index
	 * @return mixed
	 */
	public function getParameterForConstructor($instanceName, $index) {
		if (isset($this->declaredInstances[$instanceName]['constructor'][$index]['value'])) {
			return $this->declaredInstances[$instanceName]['constructor'][$index]['value'];
		} else {
			return null;
		}
	}

	/**
	 * The type of the parameter for a constructor parameter. Can be one of "primitive" or "object".
	 * @param string $instanceName
	 * @param int $index
	 * @return string
	 */
	public function isConstructorParameterObjectOrPrimitive($instanceName, $index) {
		if (isset($this->declaredInstances[$instanceName]['constructor'][$index]['parametertype'])) {
			return $this->declaredInstances[$instanceName]['constructor'][$index]['parametertype'];
		} else {
			return null;
		}
	}

	/**
	 * Returns true if the value of the given constructor parameter is set.
	 * False otherwise.
	 *
	 * @param string $instanceName
	 * @param int $index
	 * @return boolean
	 */
	public function isParameterSetForConstructor($instanceName, $index) {
		return isset($this->declaredInstances[$instanceName]['constructor'][$index]);
	}
	
	/**
	 * Completely unset this constructor parameter from the DI container.
	 *
	 * @param string $instanceName
	 * @param int $index
	 */
	public function unsetParameterForConstructor($instanceName, $index) {
		unset($this->declaredInstances[$instanceName]['constructor'][$index]);
	}
	

	/**
	 * Returns the type for the given parameter (can be one of "string", "config", "session" or "request")
	 *
	 * @param string $instanceName
	 * @param string $paramName
	 * @return string
	 */
	public function getParameterType($instanceName, $paramName) {
		if (isset($this->declaredInstances[$instanceName]['fieldProperties'][$paramName]['type'])) {
			return $this->declaredInstances[$instanceName]['fieldProperties'][$paramName]['type'];
		} else {
			return null;
		}
	}

	/**
	 * Returns the type for the given parameter that has been set using a setter (can be one of "string", "config", "session" or "request")
	 *
	 * @param string $instanceName
	 * @param string $setterName
	 * @return string
	 */
	public function getParameterTypeForSetter($instanceName, $setterName) {
		if (isset($this->declaredInstances[$instanceName]['setterProperties'][$setterName]['type'])) {
			return $this->declaredInstances[$instanceName]['setterProperties'][$setterName]['type'];
		} else {
			return null;
		}
	}

	/**
	 * Returns the type for the given parameter that has been set using a setter (can be one of "string", "config", "session" or "request")
	 *
	 * @param string $instanceName
	 * @param int $index
	 * @return string
	 */
	public function getParameterTypeForConstructor($instanceName, $index) {
		if (isset($this->declaredInstances[$instanceName]['constructor'][$index]['type'])) {
			return $this->declaredInstances[$instanceName]['constructor'][$index]['type'];
		} else {
			return null;
		}
	}

	/**
	 * Sets the type for the given parameter (can be one of "string", "config", "session" or "request")
	 *
	 * @param string $instanceName
	 * @param string $paramName
	 * @param string $type
	 */
	public function setParameterType($instanceName, $paramName, $type) {
		$this->declaredInstances[$instanceName]['fieldProperties'][$paramName]['type'] = $type;
	}

	/**
	 * Sets the type for the given parameter that has been set using a setter (can be one of "string", "config", "session" or "request")
	 *
	 * @param string $instanceName
	 * @param string $setterName
	 * @param string $type
	 */
	public function setParameterTypeForSetter($instanceName, $setterName, $type) {
		$this->declaredInstances[$instanceName]['setterProperties'][$setterName]['type'] = $type;
	}

	/**
	 * Sets the type for the given parameter that has been set using a constructor parameter (can be one of "string", "config", "session" or "request")
	 *
	 * @param string $instanceName
	 * @param int $index
	 * @param string $type
	 */
	public function setParameterTypeForConstructor($instanceName, $index, $type) {
		$this->declaredInstances[$instanceName]['constructor'][$index]['type'] = $type;
	}

	/**
	 * Returns the metadata for the given parameter.
	 * Metadata is an array of key=>value, containing additional info.
	 * For instance, it could contain information on the way to represent a field in the UI, etc...
	 *
	 * @param string $instanceName
	 * @param string $paramName
	 * @return string
	 */
	public function getParameterMetadata($instanceName, $paramName) {
		if (isset($this->declaredInstances[$instanceName]['fieldProperties'][$paramName]['metadata'])) {
			return $this->declaredInstances[$instanceName]['fieldProperties'][$paramName]['metadata'];
		} else {
			return array();
		}
	}

	/**
	 * Returns the metadata for the given parameter that has been set using a setter.
	 * Metadata is an array of key=>value, containing additional info.
	 * For instance, it could contain information on the way to represent a field in the UI, etc...
	 *
	 * @param string $instanceName
	 * @param string $setterName
	 * @return string
	 */
	public function getParameterMetadataForSetter($instanceName, $setterName) {
		if (isset($this->declaredInstances[$instanceName]['setterProperties'][$setterName]['metadata'])) {
			return $this->declaredInstances[$instanceName]['setterProperties'][$setterName]['metadata'];
		} else {
			return array();
		}
	}

	/**
	 * Returns the metadata for the given parameter that has been set using a constructor parameter.
	 * Metadata is an array of key=>value, containing additional info.
	 * For instance, it could contain information on the way to represent a field in the UI, etc...
	 *
	 * @param string $instanceName
	 * @param int $index
	 * @return string
	 */
	public function getParameterMetadataForConstructor($instanceName, $index) {
		if (isset($this->declaredInstances[$instanceName]['constructor'][$index]['metadata'])) {
			return $this->declaredInstances[$instanceName]['constructor'][$index]['metadata'];
		} else {
			return array();
		}
	}






	/**
	 * Returns true if the param is set for the given instance.
	 *
	 * @param string $instanceName
	 * @param string $paramName
	 * @return boolean
	 */
	public function hasParameter($instanceName, $paramName) {
		// todo: improve this
		return isset($this->declaredInstances[$instanceName]['fieldProperties'][$paramName]);
	}

	/**
	 * Returns true if the param is set for the given instance using a setter.
	 *
	 * @param string $instanceName
	 * @param string $setterName
	 * @return boolean
	 */
	public function hasParameterForSetter($instanceName, $setterName) {
		// todo: improve this
		return isset($this->declaredInstances[$instanceName]['setterProperties'][$setterName]);
	}

	/**
	 * Binds another instance to the instance.
	 *
	 * @param string $instanceName
	 * @param string $paramName
	 * @param string $paramValue the name of the instance to bind to.
	 */
	public function bindComponent($instanceName, $paramName, $paramValue) {
		if ($paramValue == null) {
			unset($this->declaredInstances[$instanceName]["fieldBinds"][$paramName]);
		} else {
			$this->declaredInstances[$instanceName]["fieldBinds"][$paramName] = $paramValue;
		}
	}

	/**
	 * Binds another instance to the instance via a setter.
	 *
	 * @param string $instanceName
	 * @param string $setterName
	 * @param string $paramValue the name of the instance to bind to.
	 */
	public function bindComponentViaSetter($instanceName, $setterName, $paramValue) {
		if ($paramValue == null) {
			unset($this->declaredInstances[$instanceName]["setterBinds"][$setterName]);
		} else {
			$this->declaredInstances[$instanceName]["setterBinds"][$setterName] = $paramValue;
		}
	}

	/**
	 * Binds an array of instance to the instance.
	 *
	 * @param string $instanceName
	 * @param string $paramName
	 * @param array $paramValue an array of names of instance to bind to.
	 */
	public function bindComponents($instanceName, $paramName, $paramValue) {
		if ($paramValue == null) {
			unset($this->declaredInstances[$instanceName]["fieldBinds"][$paramName]);
		} else {
			$this->declaredInstances[$instanceName]["fieldBinds"][$paramName] = $paramValue;
		}
	}

	/**
	 * Binds an array of instance to the instance via a setter.
	 *
	 * @param string $instanceName
	 * @param string $setterName
	 * @param array $paramValue an array of names of instance to bind to.
	 */
	public function bindComponentsViaSetter($instanceName, $setterName, $paramValue) {
		if ($paramValue == null) {
			unset($this->declaredInstances[$instanceName]["setterBinds"][$setterName]);
		} else {
			$this->declaredInstances[$instanceName]["setterBinds"][$setterName] = $paramValue;
		}
	}

	/**
	 * This function saves the container configuration and generates a static class file to access instances (TODO: remove/change/optimize this).
	 * The configuration is save in the file it was loaded from, unless another file name is passed in 
	 * parameter.
	 * 
	 * @param string $fileName (optionnal): the file name of the configuration.
	 * @param string $staticClassName (optionnal): the name of the class generated.
	 * @param string $staticClassDirectory (optionnal): the directory of the static class file, relative to the directory of this file (if the class has a namespace, this directory should not contain the namespace part). It should not end with a /.
	 * @throws MoufException
	 */
	public function write($filename = null, $staticClassName = null, $staticClassDirectory = null) {
		if ($filename == null) {
			$filename = $this->configFile;
		}
		
		if ((file_exists($filename) && !is_writable($filename)) || (!file_exists($filename) && !is_writable(dirname($filename)))) {
			$dirname = realpath(dirname($filename));
			$filename = basename($filename);
			throw new MoufException("Error, unable to write file ".$dirname."/".$filename);
		}
		
		if ($staticClassName) {
			$staticClassFileName = $staticClassDirectory.'/'.str_replace('\\', '/', $staticClassName).'.php';
			if ((file_exists(__DIR__."/".$staticClassFileName) && !is_writable(__DIR__."/".$staticClassFileName)) || (!file_exists(__DIR__."/".$staticClassFileName) && !is_writable(dirname(__DIR__."/".$staticClassFileName)))) {
				throw new MoufException("Error, unable to write file ".$staticClassFileName);
			}
		}

		// Let's start by garbage collecting weak instances.
		$this->purgeUnreachableWeakInstances();

		$fp = fopen($filename, "w");
		fwrite($fp, "<?php\n");
		fwrite($fp, "/**\n");
		fwrite($fp, " * This is a file automatically generated by the Mouf framework.\n");
		fwrite($fp, " * Unless you know what you are doing, do not modify it, as it could be overwritten.\n");
		fwrite($fp, " */\n");

		// Declare all components in one instruction
		$internalDeclaredInstances = array();
		foreach ($this->declaredInstances as $name=>$declaredInstance) {
			if (!isset($declaredInstance["external"]) || !$declaredInstance["external"]) {
				$internalDeclaredInstances[$name] = $declaredInstance;
			}
		}

		// Sort all instances by key. This way, new instances are not added at the end of the array,
		// and this reduces the number of conflicts when working in team with a version control system.
		ksort($internalDeclaredInstances);

		fwrite($fp, "return ".var_export($internalDeclaredInstances, true).";\n");
		fwrite($fp, "\n");

		fclose($fp);
		
		if ($staticClassFileName) {
			$fp2 = fopen(__DIR__."/".$staticClassFileName, "w");

			fwrite($fp2, "/**
 * This is the base class of the Manage Object User Friendly or Modular object user framework (MOUF) framework.
 * This object can be used to get the objects manage by MOUF.
 *
 */
use Mouf\MoufManager;
					
 class ".$staticClassName." {
 ");
			$getters = array();
			foreach ($this->declaredInstances as $name=>$classDesc) {
				if (!isset($classDesc['class'])) {
					throw new MoufException("No class for instance $name");
				}
				if (isset($classDesc['anonymous']) && $classDesc['anonymous']) {
					continue;
				}
				$className = $classDesc['class'];
				$getter = self::generateGetterString($name);
				if (isset($getters[strtolower($getter)])){
					$i = 0;
					while (isset($getters[strtolower($getter."_$i")])) {
						$i++;
					}
					$getter = $getter."_$i";
				}
				$getters[strtolower($getter)] = true;
				fwrite($fp2, "	/**\n");
				fwrite($fp2, "	 * @return $className\n");
				fwrite($fp2, "	 */\n");
				fwrite($fp2, "	 public static function ".$getter."() {\n");
				fwrite($fp2, "	 	return MoufManager::getMoufManager()->get(".var_export($name,true).");\n");
				fwrite($fp2, "	 }\n\n");
			}
			fwrite($fp2, "}\n");
				
			fclose($fp2);
		}
		
		
	}

	/**
	 * Generate the string for the getter by uppercasing the first character and prepending "get".
	 *
	 * @param string $instanceName
	 * @return string
	 */
	private function generateGetterString($instanceName) {
		$modInstance = str_replace(" ", "", $instanceName);
		$modInstance = str_replace("\n", "", $modInstance);
		$modInstance = str_replace("-", "", $modInstance);
		$modInstance = str_replace(".", "_", $modInstance);
		// Let's remove anything that is not an authorized character:
		$modInstance = preg_replace("/[^A-Za-z0-9_]/", "", $modInstance);


		return "get".strtoupper(substr($modInstance,0,1)).substr($modInstance,1);
	}

	/**
	 * Return all instances names whose instance type is (or extends or inherits) the provided $instanceType.
	 * Note: this will silently ignore any instance whose class cannot be found.
	 * Note: can only be used if an autoloader for the classes is available (so if we are in the
	 * scope of the application).
	 *
	 * @param string $instanceType
	 * @return array<string>
	 */
	public function findInstances($instanceType) {
		
		$instancesArray = array();

		$reflectionInstanceType = new \ReflectionClass($instanceType);
		$isInterface = $reflectionInstanceType->isInterface();

		foreach ($this->declaredInstances as $instanceName=>$classDesc) {
			$className = $classDesc['class'];
			
			// Silently ignore any non existing class.
			if (!class_exists($className)) {
				continue;
			}
			
			$reflectionClass = new \ReflectionClass($className);
			if ($isInterface) {
				if ($reflectionClass->implementsInterface($instanceType)) {
					$instancesArray[] = $instanceName;
				}
			} else {
				if ($reflectionClass->isSubclassOf($instanceType) || $reflectionClass->getName() == $instanceType) {
					$instancesArray[] = $instanceName;
				}
			}
		}
		return $instancesArray;
	}

	/**
	 * Returns the name(s) of the component bound to instance $instanceName on property $propertyName.
	 *
	 * @param string $instanceName
	 * @param string $propertyName
	 * @return string or array<string> if there are many components.
	 */
	public function getBoundComponentsOnProperty($instanceName, $propertyName) {
		if (isset($this->declaredInstances[$instanceName]) && isset($this->declaredInstances[$instanceName]['fieldBinds']) && isset($this->declaredInstances[$instanceName]['fieldBinds'][$propertyName])) {
			return $this->declaredInstances[$instanceName]['fieldBinds'][$propertyName];
		}
		else
			return null;
	}

	/**
	 * Returns the name(s) of the component bound to instance $instanceName on setter $setterName.
	 *
	 * @param string $instanceName
	 * @param string $setterName
	 * @return string or array<string> if there are many components.
	 */
	public function getBoundComponentsOnSetter($instanceName, $setterName) {
		if (isset($this->declaredInstances[$instanceName]) && isset($this->declaredInstances[$instanceName]['setterBinds']) && isset($this->declaredInstances[$instanceName]['setterBinds'][$setterName]))
			return $this->declaredInstances[$instanceName]['setterBinds'][$setterName];
		else
			return null;
	}

	/**
	 * Returns the list of all components bound to that component.
	 *
	 * @param string $instanceName
	 * @return array<string, comp(s)> where comp(s) is a string or an array<string> if there are many components for that property. The key of the array is the name of the property.
	 */
	public function getBoundComponents($instanceName) {
		$binds = array();
		if (isset($this->declaredInstances[$instanceName]) && isset($this->declaredInstances[$instanceName]['fieldBinds'])) {
			$binds = $this->declaredInstances[$instanceName]['fieldBinds'];
		}
		if (isset($this->declaredInstances[$instanceName]) && isset($this->declaredInstances[$instanceName]['setterBinds'])) {
			foreach ($this->declaredInstances[$instanceName]['setterBinds'] as $setter=>$bind) {
				$binds[MoufPropertyDescriptor::getPropertyNameFromSetterName($setter)] = $bind;
			}
		}
		return $binds;
	}

	/**
	 * Returns the list of instances that are pointing to this instance through one of their properties.
	 *
	 * @param string $instanceName
	 * @return array<string, string> The instances pointing to the passed instance are returned in key and in the value
	 */
	public function getOwnerComponents($instanceName) {
		$instancesList = array();

		foreach ($this->declaredInstances as $scannedInstance=>$instanceDesc) {
			if (isset($instanceDesc['fieldBinds'])) {
				foreach ($instanceDesc['fieldBinds'] as $declaredBindProperty) {
					if (is_array($declaredBindProperty)) {
						if (array_search($instanceName, $declaredBindProperty) !== false) {
							$instancesList[$scannedInstance] = $scannedInstance;
							break;
						}
					} elseif ($declaredBindProperty == $instanceName) {
						$instancesList[$scannedInstance] = $scannedInstance;
					}
				}
			}
		}

		foreach ($this->declaredInstances as $scannedInstance=>$instanceDesc) {
			if (isset($instanceDesc['setterBinds'])) {
				foreach ($instanceDesc['setterBinds'] as $declaredBindProperty) {
					if (is_array($declaredBindProperty)) {
						if (array_search($instanceName, $declaredBindProperty) !== false) {
							$instancesList[$scannedInstance] = $scannedInstance;
							break;
						}
					} elseif ($declaredBindProperty == $instanceName) {
						$instancesList[$scannedInstance] = $scannedInstance;
					}
				}
			}
		}
		
		foreach ($this->declaredInstances as $scannedInstance=>$instanceDesc) {
			if (isset($instanceDesc['constructor'])) {
				foreach ($instanceDesc['constructor'] as $declaredConstructorProperty) {
					if ($declaredConstructorProperty['parametertype']=='object') {
						$value = $declaredConstructorProperty['value'];
						if (is_array($value)) {
							if (array_search($instanceName, $value) !== false) {
								$instancesList[$scannedInstance] = $scannedInstance;
								break;
							}
						} elseif ($value == $instanceName) {
							$instancesList[$scannedInstance] = $scannedInstance;
						}
					}
				}
			}
		}

		return $instancesList;

	}

	/**
	 * Returns the name of a Mouf instance from the object.
	 * Note: this can be pretty slow as all instances are searched.
	 * FALSE is returned if nothing is found.
	 *
	 * @param object $instance
	 * @return string|false The name of the instance.
	 */
	public function findInstanceName($instance) {
		return array_search($instance, $this->objectInstances);
	}

	/**
	 * Duplicates an instance.
	 *
	 * @param string $srcInstanceName The name of the source instance.
	 * @param string $destInstanceName The name of the new instance.
	 */
	public function duplicateInstance($srcInstanceName, $destInstanceName) {
		if (!isset($this->declaredInstances[$srcInstanceName])) {
			throw new MoufException("Error while duplicating instance: unable to find source instance ".$srcInstanceName);
		}
		if (isset($this->declaredInstances[$destInstanceName])) {
			throw new MoufException("Error while duplicating instance: the dest instance already exists: ".$destInstanceName);
		}
		$this->declaredInstances[$destInstanceName] = $this->declaredInstances[$srcInstanceName];
	}

	/**
	 * This function will delete any weak instance that would not be referred anymore.
	 * This is used to garbage-collect any unused weak instances.
	 * 
	 * This is public only for test purposes
	 */
	public function purgeUnreachableWeakInstances() {
		foreach ($this->declaredInstances as $key=>$instance) {
			if (!isset($instance['weak']) || $instance['weak'] == false) {
				$this->walkForGarbageCollection($key);
			}
		}

		// At this point any instance with the "noGarbageCollect" attribute should be kept. Others should be eliminated.
		$keptInstances = array();
		foreach ($this->declaredInstances as $key=>$instance) {
			if (isset($instance['noGarbageCollect']) && $instance['noGarbageCollect'] == true) {
				// Let's clear the flag
				unset($this->declaredInstances[$key]['noGarbageCollect']);
			} else {
				// Let's delete the weak instance
				unset($this->declaredInstances[$key]);
			}
		}


	}

	/**
	 * Recursive function that mark this instance as NOT garbage collectable and go through referred nodes.
	 *
	 * @param string $instanceName
	 */
	public function walkForGarbageCollection($instanceName) {
		$instance = &$this->declaredInstances[$instanceName];
		if (isset($instance['noGarbageCollect']) && $instance['noGarbageCollect'] == true) {
			// No need to go through already visited nodes.
			return;
		}

		$instance['noGarbageCollect'] = true;

		$declaredInstances = &$this->declaredInstances;
		$moufManager = $this;
		if (isset($instance['constructor'])) {
			foreach ($instance['constructor'] as $argument) {
				if ($argument["parametertype"] == "object") {
					$value = $argument["value"];
					if(is_array($value)) {
						array_walk_recursive($value, function($singleValue) use (&$declaredInstances, $moufManager) {
							if ($singleValue != null) {
								$moufManager->walkForGarbageCollection($singleValue);
							}
						});
						/*foreach ($value as $singleValue) {
							if ($singleValue != null) {
								$this->walkForGarbageCollection($this->declaredInstances[$singleValue]);
							}
						}*/
					}
					else {
						if ($value != null) {
							$this->walkForGarbageCollection($value);
						}
					}
				}

			}
		}
		if (isset($instance['fieldBinds'])) {
			foreach ($instance['fieldBinds'] as $prop) {
				if(is_array($prop)) {
					array_walk_recursive($prop, function($singleProp) use (&$declaredInstances, $moufManager) {
						if ($singleProp != null) {
							$moufManager->walkForGarbageCollection($singleProp);
						}
					});
							
					/*foreach ($prop as $singleProp) {
						if ($singleProp != null) {
							$this->walkForGarbageCollection($this->declaredInstances[$singleProp]);
						}
					}*/
				}
				else {
					$this->walkForGarbageCollection($prop);
				}
			}
		}
		if (isset($instance['setterBinds'])) {
			foreach ($instance['setterBinds'] as $prop) {
				if(is_array($prop)) {
					array_walk_recursive($prop, function($singleProp) use (&$declaredInstances, $moufManager) {
						if ($singleProp != null) {
							$moufManager->walkForGarbageCollection($singleProp);
						}
					});
					/*foreach ($prop as $singleProp) {
						if ($singleProp != null) {
							$this->walkForGarbageCollection($this->declaredInstances[$singleProp]);
						}
					}*/
					
				}
				else {
					$this->walkForGarbageCollection($prop);
				}
			}
		}
	}

	/**
	 * Returns true if the instance is week
	 *
	 * @param string $instanceName
	 * @return bool
	 */
	public function isInstanceWeak($instanceName) {
		if (isset($this->declaredInstances[$instanceName]['weak'])) {
			return $this->declaredInstances[$instanceName]['weak'];
		} else {
			return false;
		}
	}

	/**
	 * Decides whether an instance should be weak or not.
	 * @param string $instanceName
	 * @param bool $weak
	 */
	public function setInstanceWeakness($instanceName, $weak) {
		$this->declaredInstances[$instanceName]['weak'] = $weak;
	}


	/**
	 * Returns true if the instance is anonymous
	 *
	 * @param string $instanceName
	 * @return bool
	 */
	public function isInstanceAnonymous($instanceName) {
		if (isset($this->declaredInstances[$instanceName]['anonymous'])) {
			return $this->declaredInstances[$instanceName]['anonymous'];
		} else {
			return false;
		}
	}

	/**
	 * Decides whether an instance is anonymous or not.
	 * @param string $instanceName
	 * @param bool $anonymous
	 */
	public function setInstanceAnonymousness($instanceName, $anonymous) {
		if ($anonymous) {
			$this->declaredInstances[$instanceName]['anonymous'] = true;
			// An anonymous object must be weak.
			$this->declaredInstances[$instanceName]['weak'] = true;
		} else {
			unset($this->declaredInstances[$instanceName]['anonymous']);
		}
	}

	/**
	 * Returns an "anonymous" name for an instance.
	 * "anonymous" names start with "__anonymous__" and is followed by a number.
	 * This function will return a name that is not already used.
	 *
	 * @return string
	 */
	public function getFreeAnonymousName() {

		$i=0;
		do {
			$anonName = "__anonymous__".$i;
			if (!isset($this->declaredInstances[$anonName])) {
				break;
			}
			$i++;
		} while (true);

		return $anonName;
	}

	/**
	 * An array of instanciated MoufInstanceDescriptor objects.
	 * These descriptors are created by getInstanceDescriptor or createInstance function.
	 *
	 * @var array<string, MoufInstanceDescriptor>
	 */
	private $instanceDescriptors;

	/**
	 * Returns an object describing the instance whose name is $name.
	 *
	 * @param string $name
	 * @return MoufInstanceDescriptor
	 */
	public function getInstanceDescriptor($name) {
		if (isset($this->instanceDescriptors[$name])) {
			return $this->instanceDescriptors[$name];
		} elseif (isset($this->declaredInstances[$name])) {
			$this->instanceDescriptors[$name] = new MoufInstanceDescriptor($this, $name);
			return $this->instanceDescriptors[$name];
		} else {
			throw new MoufException("Instance '".$name."' does not exist.");
		}
	}

	/**
	 * Creates a new instance and returns the instance descriptor.
	 * @param string $className The name of the class of the instance.
	 * @param int $mode Depending on the mode, the behaviour will be different if an instance with the same name already exists.
	 * @return MoufInstanceDescriptor
	 */
	public function createInstance($className, $mode = self::DECLARE_ON_EXIST_EXCEPTION) {
		$className = ltrim($className, "\\");
		$name = $this->getFreeAnonymousName();
		$this->declareComponent($name, $className, false, $mode);
		$this->setInstanceAnonymousness($name, true);
		return $this->getInstanceDescriptor($name);
	}

	/**
	 * Returns the class in charge of managing the list of class descriptors.
	 * 
	 * @return \Mouf\Reflection\ReflectionClassManagerInterface
	 */
	public function getReflectionClassManager() {
		return $this->reflectionClassManager;
	}
}
?>