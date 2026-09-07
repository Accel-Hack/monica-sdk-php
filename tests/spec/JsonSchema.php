<?php

declare(strict_types=1);

namespace Monica\Tests\Spec;

use InvalidArgumentException;
use RuntimeException;

/**
 * Dependency-free validator for the JSON Schema draft 2020-12 subset that
 * spec/event-schema.json actually uses.
 *
 * The SDK ships with no schema library and must run on PHP 7.4, so the
 * keywords below are implemented by hand. An unsupported keyword is a hard
 * error rather than a silent pass: a contract test that quietly stops
 * checking is worse than no contract test.
 */
final class JsonSchema
{
    private const SUPPORTED = [
        '$schema', '$id', '$defs', '$ref', 'title',
        'type', 'enum', 'const', 'not', 'oneOf',
        'required', 'properties', 'additionalProperties',
        'items', 'minItems', 'maxItems',
        'minLength', 'maxLength', 'pattern', 'format',
        'minimum',
    ];

    /** @var object */
    private $root;

    /**
     * @param object $schema decoded with json_decode($json, false)
     */
    public function __construct(object $schema)
    {
        $this->root = $schema;
    }

    public static function fromFile(string $path): self
    {
        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException('cannot read schema: ' . $path);
        }
        $schema = json_decode($json, false);
        if (!is_object($schema)) {
            throw new RuntimeException('schema is not a JSON object: ' . $path);
        }

        return new self($schema);
    }

    /**
     * @param mixed $value decoded with json_decode($json, false)
     * @return list<string> empty when $value satisfies the schema
     */
    public function validate($value): array
    {
        return $this->check($value, $this->root, '$');
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function check($value, object $schema, string $path): array
    {
        foreach (get_object_vars($schema) as $keyword => $ignored) {
            if (!in_array($keyword, self::SUPPORTED, true)) {
                throw new InvalidArgumentException(
                    'unsupported schema keyword "' . $keyword . '" at ' . $path
                    . '; extend tests/spec/JsonSchema.php before relying on this run'
                );
            }
        }

        if (isset($schema->{'$ref'})) {
            return $this->check($value, $this->resolve((string) $schema->{'$ref'}), $path);
        }

        $errors = [];
        if (isset($schema->type) && !self::matchesType($value, (string) $schema->type)) {
            return [$path . ': expected ' . $schema->type . ', got ' . self::describe($value)];
        }
        if (property_exists($schema, 'const') && !self::same($value, $schema->const)) {
            $errors[] = $path . ': expected const ' . self::encode($schema->const);
        }
        if (isset($schema->enum) && !self::inList($value, $schema->enum)) {
            $errors[] = $path . ': ' . self::encode($value) . ' is not one of ' . self::encode($schema->enum);
        }
        if (isset($schema->not) && $this->check($value, $schema->not, $path) === []) {
            $errors[] = $path . ': must not match the "not" schema';
        }
        if (isset($schema->oneOf)) {
            $matched = [];
            $branchErrors = [];
            foreach ($schema->oneOf as $index => $branch) {
                $branchResult = $this->check($value, $branch, $path);
                if ($branchResult === []) {
                    $matched[] = $index;
                } else {
                    $branchErrors[] = '#' . $index . ' ' . implode('; ', $branchResult);
                }
            }
            if (count($matched) !== 1) {
                $errors[] = $path . ': matched ' . count($matched) . ' of ' . count($schema->oneOf)
                    . ' oneOf branches (' . implode(' | ', $branchErrors) . ')';
            }
        }

        if (is_object($value)) {
            $errors = array_merge($errors, $this->checkObject($value, $schema, $path));
        } elseif (is_array($value)) {
            $errors = array_merge($errors, $this->checkArray($value, $schema, $path));
        } elseif (is_string($value)) {
            $errors = array_merge($errors, self::checkString($value, $schema, $path));
        } elseif (is_int($value) || is_float($value)) {
            if (isset($schema->minimum) && $value < $schema->minimum) {
                $errors[] = $path . ': ' . $value . ' is below minimum ' . $schema->minimum;
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function checkObject(object $value, object $schema, string $path): array
    {
        $errors = [];
        $properties = isset($schema->properties) ? get_object_vars($schema->properties) : [];
        foreach ($schema->required ?? [] as $required) {
            if (!property_exists($value, (string) $required)) {
                $errors[] = $path . ': missing required property "' . $required . '"';
            }
        }
        foreach (get_object_vars($value) as $key => $child) {
            if (isset($properties[$key])) {
                $errors = array_merge($errors, $this->check($child, $properties[$key], $path . '.' . $key));
                continue;
            }
            $additional = $schema->additionalProperties ?? true;
            if ($additional === false) {
                $errors[] = $path . ': additional property "' . $key . '" is not allowed';
            } elseif (is_object($additional)) {
                $errors = array_merge($errors, $this->check($child, $additional, $path . '.' . $key));
            }
        }

        return $errors;
    }

    /**
     * @param array<int, mixed> $value
     * @return list<string>
     */
    private function checkArray(array $value, object $schema, string $path): array
    {
        $errors = [];
        if (isset($schema->minItems) && count($value) < $schema->minItems) {
            $errors[] = $path . ': has ' . count($value) . ' items, minimum is ' . $schema->minItems;
        }
        if (isset($schema->maxItems) && count($value) > $schema->maxItems) {
            $errors[] = $path . ': has ' . count($value) . ' items, maximum is ' . $schema->maxItems;
        }
        if (isset($schema->items)) {
            foreach ($value as $index => $item) {
                $errors = array_merge($errors, $this->check($item, $schema->items, $path . '[' . $index . ']'));
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private static function checkString(string $value, object $schema, string $path): array
    {
        $errors = [];
        $length = self::codePointLength($value);
        if (isset($schema->minLength) && $length < $schema->minLength) {
            $errors[] = $path . ': length ' . $length . ' is below minLength ' . $schema->minLength;
        }
        if (isset($schema->maxLength) && $length > $schema->maxLength) {
            $errors[] = $path . ': length ' . $length . ' exceeds maxLength ' . $schema->maxLength;
        }
        if (isset($schema->pattern) && preg_match(self::toPcre((string) $schema->pattern), $value) !== 1) {
            $errors[] = $path . ': ' . self::encode($value) . ' does not match ' . $schema->pattern;
        }
        if (isset($schema->format) && !self::matchesFormat($value, (string) $schema->format)) {
            $errors[] = $path . ': ' . self::encode($value) . ' is not a valid ' . $schema->format;
        }

        return $errors;
    }

    private function resolve(string $reference): object
    {
        if (strpos($reference, '#/$defs/') !== 0) {
            throw new InvalidArgumentException('unsupported $ref: ' . $reference);
        }
        $name = substr($reference, strlen('#/$defs/'));
        $defs = $this->root->{'$defs'} ?? null;
        if (!is_object($defs) || !isset($defs->$name) || !is_object($defs->$name)) {
            throw new InvalidArgumentException('unknown $ref: ' . $reference);
        }

        return $defs->$name;
    }

    /**
     * @return list<string>
     */
    public function definitionNames(): array
    {
        $defs = $this->root->{'$defs'} ?? null;

        return is_object($defs) ? array_keys(get_object_vars($defs)) : [];
    }

    /**
     * @return mixed
     */
    public function pointer(string $pointer)
    {
        $current = $this->root;
        foreach (array_filter(explode('/', $pointer), 'strlen') as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
            if (is_object($current) && property_exists($current, $segment)) {
                $current = $current->$segment;
                continue;
            }
            if (is_array($current) && array_key_exists((int) $segment, $current)) {
                $current = $current[(int) $segment];
                continue;
            }

            return null;
        }

        return $current;
    }

    /**
     * @param mixed $value
     */
    private static function matchesType($value, string $type): bool
    {
        switch ($type) {
            case 'object':
                return is_object($value);
            case 'array':
                return is_array($value);
            case 'string':
                return is_string($value);
            case 'integer':
                // 2.0 is an integer in JSON Schema; json_decode keeps it a float.
                return is_int($value) || (is_float($value) && floor($value) === $value);
            case 'number':
                return is_int($value) || is_float($value);
            case 'boolean':
                return is_bool($value);
            case 'null':
                return $value === null;
            default:
                throw new InvalidArgumentException('unsupported type: ' . $type);
        }
    }

    private static function matchesFormat(string $value, string $format): bool
    {
        switch ($format) {
            case 'date-time':
                // RFC 3339, which is what every MONICA SDK emits.
                return preg_match(
                    '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/',
                    $value
                ) === 1;
            case 'uuid':
                return preg_match(
                    '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/',
                    $value
                ) === 1;
            default:
                throw new InvalidArgumentException('unsupported format: ' . $format);
        }
    }

    /**
     * ECMA-262 patterns in the schema are unanchored, which is also PCRE's
     * default. Only the delimiter needs escaping.
     */
    private static function toPcre(string $pattern): string
    {
        return '~' . str_replace('~', '\\~', $pattern) . '~u';
    }

    private static function codePointLength(string $value): int
    {
        $length = preg_match_all('/./us', $value);

        return $length === false ? strlen($value) : $length;
    }

    /**
     * @param mixed $a
     * @param mixed $b
     */
    private static function same($a, $b): bool
    {
        return self::encode($a) === self::encode($b);
    }

    /**
     * @param mixed $value
     * @param array<int, mixed> $list
     */
    private static function inList($value, array $list): bool
    {
        foreach ($list as $candidate) {
            if (self::same($value, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $value
     */
    private static function describe($value): string
    {
        if (is_object($value)) {
            return 'object';
        }
        if (is_array($value)) {
            return 'array';
        }
        if ($value === null) {
            return 'null';
        }

        return gettype($value) . ' ' . self::encode($value);
    }

    /**
     * @param mixed $value
     */
    private static function encode($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? '<unencodable>' : $json;
    }
}
