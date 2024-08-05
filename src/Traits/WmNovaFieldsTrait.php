<?php

namespace App\Traits;

use Illuminate\Support\Facades\Schema;

trait WmNovaFieldsTrait
{
    /**
     * Generate Nova fields based on a JSON schema or a provnameed schema array.
     *
     * @param string|null $name The name of the column where the form JSON is stored. Default is null.
     * @param array|null $formSchema The optional schema for fields.
     * @return array
     * @throws \Exception
     */
    public function jsonForm(string $columnName, array $formSchema = null)
    {
        // Ensure Laravel Nova is installed
        $this->ensureNovaIsInstalled();

        $fields = [];

        if ($columnName && Schema::hasColumn($this->getTable(), $columnName)) {
            // Fetch the JSON data from the column
            $column = $this->$columnName ?? '';
            if (!is_array($column)) {
                $formData = json_decode($column, true) ?? [];
            } else {
                $formData = $column;
            }
            if (is_null($formSchema) || empty($formSchema)) {
                // If no form schema is provided, use the form data directly
                foreach ($formData as $key => $value) {
                    // Create a dummy schema based on existing form data
                    $fieldSchema = [
                        'name' => $key,
                        'type' => is_numeric($value) ? 'number' : 'text',
                        'value' => $value
                    ];
                    $novaField = $this->createFieldFromSchema($fieldSchema, $columnName);
                    if ($novaField) {
                        $fields[] = $novaField;
                    }
                }
            } else {
                // Initialize the fields with data from the JSON column
                foreach ($formSchema as $fieldSchema) {
                    $label = $fieldSchema['label'];
                    $value = $formData[$label] ?? $fieldSchema['value'] ?? null;
                    $fieldSchema['value'] = $value; // Set the value from form data or default
                    $novaField = $this->createFieldFromSchema($fieldSchema, $columnName);
                    if ($novaField) {
                        $fields[] = $novaField;
                    }
                }
            }
        } elseif ($formSchema) {
            // Use the provnameed form schema
            foreach ($formSchema as $fieldSchema) {
                $novaField = $this->createFieldFromSchema($fieldSchema);
                if ($novaField) {
                    $fields[] = $novaField;
                }
            }
        } else {
            throw new \Exception('Either form JSON column name or form schema must be provnameed. Please check your database or
provnamee a form schema.');
        }

        return $fields;
    }

    /**
     * Create a Nova field based on the field schema.
     *
     * @param array $fieldSchema
     * @param string|null $columnName
     * @return \Laravel\Nova\Fields\Field|null
     */
    protected function createFieldFromSchema(array $fieldSchema, $columnName = null)
    {
        // Ensure Laravel Nova is installed
        $this->ensureNovaIsInstalled();

        $key = $fieldSchema['name'] ?? null;
        $value = $fieldSchema['value'] ?? null;
        $fieldType = $fieldSchema['type'] ?? 'text';
        $label = $fieldSchema['label'] ?? ucwords(str_replace('_', ' ', $key));
        $rules = [];
        $formData = is_array($this->$columnName) ? $this->$columnName : json_decode($this->$columnName, true);

        if (isset($fieldSchema['rules'])) {
            foreach ($fieldSchema['rules'] as $rule) {
                if ($rule['name'] === 'required') {
                    $rules[] = 'required';
                } elseif ($rule['name'] === 'email') {
                    $rules[] = 'email';
                } elseif ($rule['name'] === 'minLength' && isset($rule['value'])) {
                    $rules[] = 'min:' . $rule['value'];
                }
            }
        }

        $field = null;


        if ($fieldType === 'number') {
            $field = \Laravel\Nova\Fields\Number::make(__($label), "$columnName->$key")
                ->rules($rules);
        } elseif ($fieldType === 'password') {
            $field = \Laravel\Nova\Fields\Password::make(__($label), "$columnName->$key")
                ->rules($rules);
        } else {
            $field = \Laravel\Nova\Fields\Text::make(__($label), "$columnName->$key")
                ->rules($rules);
        }

        return $field;
    }

    /**
     * Ensure Laravel Nova is installed in the project.
     *
     * @throws \Exception
     */
    protected function ensureNovaIsInstalled()
    {
        if (!class_exists('Laravel\Nova\Fields\Field')) {
            throw new \Exception('Laravel Nova is not installed. Please install Laravel Nova to use this feature.');
        }
    }
}
