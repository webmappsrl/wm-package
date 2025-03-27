<?php

namespace Wm\WmPackage\Nova\Fields;

use Kongulov\NovaTabTranslatable\NovaTabTranslatable;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
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

        if (empty($this->formSchema)) {
            throw new \Exception("No schema configuration found for model: {$this->modelKey}");
        }
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
