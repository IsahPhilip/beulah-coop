<?php
/**
 * Environment Configuration Loader
 * Loads .env file and provides helper functions to access environment variables
 */

class EnvLoader
{
    private static array $variables = [];
    private static bool $loaded = false;

    /**
     * Load environment variables from .env file
     */
    public static function load(string $path = ''): void
    {
        if (self::$loaded) {
            return;
        }

        if (empty($path)) {
            $path = dirname(__DIR__) . '/.env';
        }

        if (!file_exists($path)) {
            // Check for .env in project root
            $altPath = dirname(__DIR__, 2) . '/.env';
            if (file_exists($altPath)) {
                $path = $altPath;
            } else {
                // Silently fail if no .env file exists
                return;
            }
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            // Skip comments
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            // Parse key=value
            if (strpos($line, '=') === false) {
                continue;
            }

            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Remove quotes
            $value = self::parseValue($value);

            // Store in array
            self::$variables[$key] = $value;

            // Set as environment variable if not already set
            if (!isset($_ENV[$key]) && !isset($_SERVER[$key])) {
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
                putenv("$key=$value");
            }
        }

        self::$loaded = true;
    }

    /**
     * Parse value and remove surrounding quotes
     */
    private static function parseValue(string $value): string
    {
        // Remove surrounding quotes
        if ((strlen($value) > 1 && $value[0] === '"' && $value[strlen($value) - 1] === '"') ||
            (strlen($value) > 1 && $value[0] === "'" && $value[strlen($value) - 1] === "'")) {
            $value = substr($value, 1, -1);
        }

        // Handle common boolean and null values
        switch (strtolower($value)) {
            case 'true':
            case '(true)':
                return '1';
            case 'false':
            case '(false)':
                return '';
            case 'empty':
            case '(empty)':
                return '';
            case 'null':
            case '(null)':
                return '';
        }

        return $value;
    }

    /**
     * Get an environment variable
     *
     * @param string $key The environment variable key
     * @param mixed $default Default value if key doesn't exist
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        self::load();

        // Check $_ENV first, then our loaded variables, then $_SERVER
        if (isset($_ENV[$key])) {
            return $_ENV[$key];
        }

        if (isset(self::$variables[$key])) {
            return self::$variables[$key];
        }

        if (isset($_SERVER[$key])) {
            return $_SERVER[$key];
        }

        return $default;
    }

    /**
     * Check if an environment variable exists
     */
    public static function has(string $key): bool
    {
        self::load();
        return isset($_ENV[$key]) || isset(self::$variables[$key]) || isset($_SERVER[$key]);
    }

    /**
     * Get a boolean environment variable
     */
    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default ? '1' : '0');
        return (bool) $value;
    }

    /**
     * Get an integer environment variable
     */
    public static function getInt(string $key, int $default = 0): int
    {
        return (int) self::get($key, $default);
    }

    /**
     * Get a float environment variable
     */
    public static function getFloat(string $key, float $default = 0.0): float
    {
        return (float) self::get($key, $default);
    }

    /**
     * Get all loaded environment variables
     */
    public static function all(): array
    {
        self::load();
        return self::$variables;
    }
}

// Helper function for easier access
if (!function_exists('env')) {
    function env(string $key, $default = null)
    {
        return EnvLoader::get($key, $default);
    }
}

// Auto-load the environment
EnvLoader::load();