<?php

namespace Wm\WmPackage\Nova\Fields;

use Kongulov\NovaTabTranslatable\NovaTabTranslatable;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\Color;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Fields\URL;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;

class PropertiesPanel extends Panel
{
    protected array $formSchema;

    protected ?string $modelKey;

    /**
     * Create a new properties panel instance.
     *
     * @param  string  $name  The panel name
     * @param  string|null  $modelKey  The model key for schema lookup
     */
    public function __construct(string $name = 'Properties', ?string $modelKey = null)
    {
        $this->modelKey = $modelKey;
        $this->loadSchemaFromConfig();

        parent::__construct($name, $this->buildFields());
    }

    /**
     * Build the fields array for the panel
     *
     * @return array The array of field objects
     *
     * @throws \Exception If Nova is not installed
     */
    protected function buildFields(): array
    {
        $this->ensureNovaIsInstalled();

        [$translatableFields, $regularFields] = $this->categorizeFields();

        $fields = $regularFields;

        if (! empty($translatableFields)) {
            $fields[] = $this->createTranslatableFieldGroup($translatableFields);
        }

        return $fields;
    }

    /**
     * Categorize fields into translatable and regular fields
     *
     * @return array An array containing translatable and regular fields
     */
    protected function categorizeFields(): array
    {
        $translatableFields = [];
        $regularFields = [];

        foreach ($this->formSchema as $fieldName => $fieldSchema) {
            $field = $this->createBaseField($fieldName, $fieldSchema, 'properties');

            if ($fieldSchema['translatable'] ?? false) {
                $translatableFields[] = $field;
            } else {
                $regularFields[] = $field;
            }
        }

        return [$translatableFields, $regularFields];
    }

    /**
     * Create a translatable field group
     *
     * @param  array  $translatableFields  The translatable fields
     * @return NovaTabTranslatable The translatable field group
     */
    protected function createTranslatableFieldGroup(array $translatableFields): NovaTabTranslatable
    {
        return NovaTabTranslatable::make($translatableFields)
            ->hideFromIndex();
    }

    /**
     * Load schema from configuration file
     *
     * @throws \Exception If no schema configuration is found
     */
    protected function loadSchemaFromConfig(): void
    {
        $this->formSchema = config("wm-form-schema.{$this->modelKey}", []);

        // Se non c'è uno schema configurato, cerchiamo di inferirlo dalle proprietà del modello
        if (empty($this->formSchema) && request()->resourceId) {
            $model = $this->findModel(request()->resourceId);
            if ($model && isset($model->properties) && is_array($model->properties)) {
                $this->inferSchemaFromProperties($model->properties);
            }
        }
    }

    /**
     * Find the model instance by ID
     *
     * @param  int  $id  The model ID
     * @return mixed|null The model instance or null
     */
    protected function findModel(int $id)
    {
        return  NovaRequest::createFrom(request())->findModelQuery()->first();
    }

    /**
     * Infer schema from model properties
     *
     * @param  array  $properties  The properties array
     */
    protected function inferSchemaFromProperties(array $properties): void
    {
        foreach ($properties as $key => $value) {
            if (is_array($value)) {
                $this->inferSchemaForNestedArray($key, $value);
            } else {
                $this->formSchema[$key] = $this->inferFieldSchema($key, $value);
            }
        }
    }

    /**
     * Infer schema for nested array properties
     *
     * @param  string  $parentKey  The parent key
     * @param  array  $nestedArray  The nested array
     */
    protected function inferSchemaForNestedArray(string $parentKey, array $nestedArray): void
    {
        foreach ($nestedArray as $key => $value) {
            $fullKey = $parentKey . '.' . $key;
            if (is_array($value)) {
                $this->inferSchemaForNestedArray($fullKey, $value);
            } else {
                $this->formSchema[$fullKey] = $this->inferFieldSchema($fullKey, $value);
            }
        }
    }

    /**
     * Infer field schema based on value type
     *
     * @param  string  $key  The field key
     * @param  mixed  $value  The field value
     * @return array The inferred field schema
     */
    protected function inferFieldSchema(string $key, $value): array
    {
        $label = ucwords(str_replace(['_', '.'], ' ', $key));
        $schema = [
            'label' => $label,
            'rules' => ['nullable'],
            'readonly' => true,
        ];

        if (is_bool($value)) {
            $schema['type'] = 'boolean';
        } elseif (is_numeric($value) || (is_string($value) && is_numeric($value))) {
            $schema['type'] = 'number';
        } elseif (is_string($value) && $this->isUrl($value)) {
            $schema['type'] = 'url';
        } elseif (is_string($value) && $this->isHexColor($value)) {
            $schema['type'] = 'color';
        } elseif (is_string($value) && $this->isUuid($value)) {
            $schema['type'] = 'uuid';
        } elseif (is_string($value) && strlen($value) > 100) {
            $schema['type'] = 'textarea';
        } elseif (is_string($value) && $this->isJson($value)) {
            $schema['type'] = 'json';
        } else {
            $schema['type'] = 'text';
        }

        return $schema;
    }

    /**
     * Check if a string is a valid URL
     *
     * @param  string  $string  The string to check
     * @return bool True if the string is a valid URL
     */
    protected function isUrl(string $string): bool
    {
        return filter_var($string, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Check if a string is a valid hexadecimal color
     *
     * @param  string  $string  The string to check
     * @return bool True if the string is a valid hex color
     */
    protected function isHexColor(string $string): bool
    {
        return preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $string) === 1;
    }

    /**
     * Check if a string is a valid UUID
     *
     * @param  string  $string  The string to check
     * @return bool True if the string is a valid UUID
     */
    protected function isUuid(string $string): bool
    {
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        return preg_match($pattern, $string) === 1 || $string === 'uuid';
    }

    /**
     * Check if a string is valid JSON
     *
     * @param  string  $string  The string to check
     * @return bool True if the string is valid JSON
     */
    protected function isJson(string $string): bool
    {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Build Nova fields from configuration schema
     *
     * @param  string  $key  The field key
     * @param  array  $fieldSchema  The field schema configuration
     * @param  string  $columnName  The column name
     * @return Field The created Nova field
     */
    protected function createBaseField(string $key, array $fieldSchema, string $columnName): Field
    {
        $fieldType = $fieldSchema['type'] ?? 'text';
        $label = $fieldSchema['label'] ?? $key;
        $rules = $fieldSchema['rules'] ?? [];
        $fieldPath = "$columnName->$key";

        $field = $this->createFieldByType($fieldType, $label, $fieldPath);

        return $this->applyFieldAttributes($field, $fieldSchema, $rules);
    }

    /**
     * Create a field based on its type
     *
     * @param  string  $fieldType  The field type
     * @param  string  $label  The field label
     * @param  string  $fieldPath  The field path
     * @return Field The created field
     */
    protected function createFieldByType(string $fieldType, string $label, string $fieldPath): Field
    {
        return match ($fieldType) {
            'number' => Number::make($label, $fieldPath),
            'boolean' => Boolean::make($label, $fieldPath),
            'textarea' => Textarea::make($label, $fieldPath),
            'json' => Code::make($label, $fieldPath)->json(),
            'url' => URL::make($label, $fieldPath),
            'color' => Color::make($label, $fieldPath),
            'uuid' => Text::make($label, $fieldPath)->withMeta(['extraAttributes' => ['class' => 'font-mono']]),
            default => Text::make($label, $fieldPath),
        };
    }

    /**
     * Apply common field attributes
     *
     * @param  Field  $field  The field to modify
     * @param  array  $fieldSchema  The field schema
     * @param  array  $rules  The validation rules
     * @return Field The modified field
     */
    protected function applyFieldAttributes(Field $field, array $fieldSchema, array $rules): Field
    {
        $field->rules($rules)->hideFromIndex();

        if (isset($fieldSchema['help'])) {
            $field->help($fieldSchema['help']);
        }

        if (isset($fieldSchema['placeholder'])) {
            $field->placeholder($fieldSchema['placeholder']);
        }

        if ($fieldSchema['readonly'] ?? false) {
            $field->readonly();
        }

        return $field;
    }

    /**
     * Ensure Nova is installed
     *
     * @throws \Exception If Nova is not installed
     */
    protected function ensureNovaIsInstalled(): void
    {
        if (! class_exists('Laravel\Nova\Fields\Field')) {
            throw new \Exception('Laravel Nova is not installed. Please install Laravel Nova to use this feature.');
        }
    }
}
