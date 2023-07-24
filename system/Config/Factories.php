<?php

/**
 * This file is part of CodeIgniter 4 framework.
 *
 * (c) CodeIgniter Foundation <admin@codeigniter.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace CodeIgniter\Config;

use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\Model;
use Config\Services;
use InvalidArgumentException;

/**
 * Factories for creating instances.
 *
 * Factories allow dynamic loading of components by their path
 * and name. The "shared instance" implementation provides a
 * large performance boost and helps keep code clean of lengthy
 * instantiation checks.
 *
 * @method static BaseConfig|null config(...$arguments)
 * @method static Model|null      models(string $name, array $options = [], ?ConnectionInterface &$conn = null)
 */
class Factories
{
    /**
     * Store of component-specific options, usually
     * from CodeIgniter\Config\Factory.
     *
     * @var array<string, array<string, bool|string|null>>
     */
    protected static $options = [];

    /**
     * Explicit options for the Config
     * component to prevent logic loops.
     *
     * @var array<string, bool|string|null>
     */
    private static array $configOptions = [
        'component'  => 'config',
        'path'       => 'Config',
        'instanceOf' => null,
        'getShared'  => true,
        'preferApp'  => true,
    ];

    /**
     * Mapping of classnames (with or without namespace) to
     * their instances.
     *
     * [component => [name => FQCN]]
     *
     * @var array<string, array<string, string>>
     * @phpstan-var array<string, array<string, class-string>>
     */
    protected static $basenames = [];

    /**
     * Store for instances of any component that
     * has been requested as "shared".
     * A multi-dimensional array with components as
     * keys to the array of name-indexed instances.
     *
     * [component => [FQCN => instance]]
     *
     * @var array<string, array<string, object>>
     * @phpstan-var  array<string, array<class-string, object>>
     */
    protected static $instances = [];

    /**
     * Define the class to load. You can *override* the concrete class.
     *
     * @param string $component Lowercase, plural component name
     * @param string $name      Classname. The first parameter of Factories magic method
     * @param string $classname FQCN to load
     * @phpstan-param class-string $classname FQCN to load
     */
    public static function define(string $component, string $name, string $classname): void
    {
        if (isset(self::$basenames[$component][$name])) {
            if (self::$basenames[$component][$name] === $classname) {
                return;
            }

            throw new InvalidArgumentException(
                'Already defined in Factories: ' . $component . ' ' . $name . ' -> ' . self::$basenames[$component][$name]
            );
        }

        if (! class_exists($classname)) {
            throw new InvalidArgumentException('No such class: ' . $classname);
        }

        // Force a configuration to exist for this component.
        // Otherwise, getOptions() will reset the component.
        self::getOptions($component);

        self::$basenames[$component][$name] = $classname;
    }

    /**
     * Loads instances based on the method component name. Either
     * creates a new instance or returns an existing shared instance.
     *
     * @return object|null
     */
    public static function __callStatic(string $component, array $arguments)
    {
        // First argument is the name, second is options
        $name    = trim(array_shift($arguments), '\\ ');
        $options = array_shift($arguments) ?? [];

        // Determine the component-specific options
        $options = array_merge(self::getOptions(strtolower($component)), $options);

        if (! $options['getShared']) {
            if (isset(self::$basenames[$component][$name])) {
                $class = self::$basenames[$component][$name];

                return new $class(...$arguments);
            }

            if ($class = self::locateClass($options, $name)) {
                return new $class(...$arguments);
            }

            return null;
        }

        // Check for an existing instance
        if (isset(self::$basenames[$options['component']][$name])) {
            $class = self::$basenames[$options['component']][$name];

            // Need to verify if the shared instance matches the request
            if (self::verifyInstanceOf($options, $class)) {
                if (isset(self::$instances[$options['component']][$class])) {
                    return self::$instances[$options['component']][$class];
                }
                self::$instances[$options['component']][$class] = new $class(...$arguments);

                return self::$instances[$options['component']][$class];

            }
        }

        // Check for an existing Config instance with basename.
        if (self::isConfig($options['component'])) {
            $basename = self::getBasename($name);

            if (isset(self::$basenames[$options['component']][$basename])) {
                $class = self::$basenames[$options['component']][$basename];

                // Need to verify if the shared instance matches the request
                if (self::verifyInstanceOf($options, $class)) {
                    if (isset(self::$instances[$options['component']][$class])) {
                        return self::$instances[$options['component']][$class];
                    }
                    self::$instances[$options['component']][$class] = new $class(...$arguments);

                    return self::$instances[$options['component']][$class];

                }
            }
        }

        // Try to locate the class
        if (! $class = self::locateClass($options, $name)) {
            return null;
        }

        self::$instances[$options['component']][$class] = new $class(...$arguments);
        self::$basenames[$options['component']][$name]  = $class;

        // If a short classname is specified, also register FQCN to share the instance.
        if (! isset(self::$basenames[$options['component']][$class])) {
            self::$basenames[$options['component']][$class] = $class;
        }

        return self::$instances[$options['component']][$class];
    }

    /**
     * Is the component Config?
     *
     * @param string $component Lowercase, plural component name
     */
    private static function isConfig(string $component): bool
    {
        return $component === 'config';
    }

    /**
     * Finds a component class
     *
     * @param array  $options The array of component-specific directives
     * @param string $name    Class name, namespace optional
     */
    protected static function locateClass(array $options, string $name): ?string
    {
        // Check for low-hanging fruit
        if (
            class_exists($name, false)
            && self::verifyPreferApp($options, $name)
            && self::verifyInstanceOf($options, $name)
        ) {
            return $name;
        }

        // Determine the relative class names we need
        $basename = self::getBasename($name);
        $appname  = self::isConfig($options['component'])
            ? 'Config\\' . $basename
            : rtrim(APP_NAMESPACE, '\\') . '\\' . $options['path'] . '\\' . $basename;

        // If an App version was requested then see if it verifies
        if (
            // preferApp is used only for no namespace class or Config class.
            (strpos($name, '\\') === false || self::isConfig($options['component']))
            && $options['preferApp'] && class_exists($appname)
            && self::verifyInstanceOf($options, $name)
        ) {
            return $appname;
        }

        // If we have ruled out an App version and the class exists then try it
        if (class_exists($name) && self::verifyInstanceOf($options, $name)) {
            return $name;
        }

        // Have to do this the hard way...
        $locator = Services::locator();

        // Check if the class was namespaced
        if (strpos($name, '\\') !== false) {
            if (! $file = $locator->locateFile($name, $options['path'])) {
                return null;
            }
            $files = [$file];
        }
        // No namespace? Search for it
        // Check all namespaces, prioritizing App and modules
        elseif (! $files = $locator->search($options['path'] . DIRECTORY_SEPARATOR . $name)) {
            return null;
        }

        // Check all files for a valid class
        foreach ($files as $file) {
            $class = $locator->getClassname($file);

            if ($class && self::verifyInstanceOf($options, $class)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Verifies that a class & config satisfy the "preferApp" option
     *
     * @param array  $options The array of component-specific directives
     * @param string $name    Class name, namespace optional
     */
    protected static function verifyPreferApp(array $options, string $name): bool
    {
        // Anything without that restriction passes
        if (! $options['preferApp']) {
            return true;
        }

        // Special case for Config since its App namespace is actually \Config
        if (self::isConfig($options['component'])) {
            return strpos($name, 'Config') === 0;
        }

        return strpos($name, APP_NAMESPACE) === 0;
    }

    /**
     * Verifies that a class & config satisfy the "instanceOf" option
     *
     * @param array  $options The array of component-specific directives
     * @param string $name    Class name, namespace optional
     */
    protected static function verifyInstanceOf(array $options, string $name): bool
    {
        // Anything without that restriction passes
        if (! $options['instanceOf']) {
            return true;
        }

        return is_a($name, $options['instanceOf'], true);
    }

    /**
     * Returns the component-specific configuration
     *
     * @param string $component Lowercase, plural component name
     *
     * @return array<string, bool|string|null>
     *
     * @internal For testing only
     */
    public static function getOptions(string $component): array
    {
        $component = strtolower($component);

        // Check for a stored version
        if (isset(self::$options[$component])) {
            return self::$options[$component];
        }

        $values = self::isConfig($component)
            // Handle Config as a special case to prevent logic loops
            ? self::$configOptions
            // Load values from the best Factory configuration (will include Registrars)
            : config(Factory::class)->{$component} ?? [];

        // The setOptions() reset the component. So getOptions() may reset
        // the component.
        return self::setOptions($component, $values);
    }

    /**
     * Normalizes, stores, and returns the configuration for a specific component
     *
     * @param string $component Lowercase, plural component name
     * @param array  $values    option values
     *
     * @return array<string, bool|string|null> The result after applying defaults and normalization
     */
    public static function setOptions(string $component, array $values): array
    {
        // Allow the config to replace the component name, to support "aliases"
        $values['component'] = strtolower($values['component'] ?? $component);

        // Reset this component so instances can be rediscovered with the updated config
        self::reset($values['component']);

        // If no path was available then use the component
        $values['path'] = trim($values['path'] ?? ucfirst($values['component']), '\\ ');

        // Add defaults for any missing values
        $values = array_merge(Factory::$default, $values);

        // Store the result to the supplied name and potential alias
        self::$options[$component]           = $values;
        self::$options[$values['component']] = $values;

        return $values;
    }

    /**
     * Resets the static arrays, optionally just for one component
     *
     * @param string|null $component Lowercase, plural component name
     */
    public static function reset(?string $component = null)
    {
        if ($component) {
            unset(
                static::$options[$component],
                static::$basenames[$component],
                static::$instances[$component]
            );

            return;
        }

        static::$options   = [];
        static::$basenames = [];
        static::$instances = [];
    }

    /**
     * Helper method for injecting mock instances
     *
     * @param string $component Lowercase, plural component name
     * @param string $name      The name of the instance
     *
     * @internal For testing only
     */
    public static function injectMock(string $component, string $name, object $instance)
    {
        // Force a configuration to exist for this component
        $component = strtolower($component);
        self::getOptions($component);

        $class    = get_class($instance);
        $basename = self::getBasename($name);

        self::$instances[$component][$class] = $instance;
        self::$basenames[$component][$name]  = $class;

        if (self::isConfig($component)) {
            self::$basenames[$component][$basename] = $class;
        }
    }

    /**
     * Gets a basename from a class name, namespaced or not.
     *
     * @internal For testing only
     */
    public static function getBasename(string $name): string
    {
        // Determine the basename
        if ($basename = strrchr($name, '\\')) {
            return substr($basename, 1);
        }

        return $name;
    }
}
