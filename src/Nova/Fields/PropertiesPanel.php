<?php

namespace Wm\WmPackage\Nova\Fields;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Kongulov\NovaTabTranslatable\NovaTabTranslatable;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\Color;
use Laravel\Nova\Fields\Date;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\Email;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Password;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Panel;

class PropertiesPanel extends Panel
{
    protected array $formSchema;

    protected ?string $modelKey;

    protected ?object $model;

    protected ?string $columnName;

    protected ?string $jsonAttribute;

    /**
     * Create a new properties panel instance.
     *
     * @param  string  $name  The panel name
     * @param  string|null  $modelKey  The model key for schema lookup
     */
    public function __construct(string $name = 'Properties', ?string $modelKey = null)
    {
        $this->modelKey = $modelKey;
        $this->model = null; // Verrà impostato dinamicamente
        $this->columnName = 'properties'; // Default
        $this->jsonAttribute = $modelKey; // Usa modelKey come attributo JSON

        parent::__construct($name, []);
    }

    /**
     * Determine if fields should be editable based on editable parameter
     *
     * @param  bool|callable|null  $editable  Editable configuration
     * @param  object|null  $model  The model instance for callback evaluation
     * @param  \Illuminate\Http\Request|null  $request  The request instance for callback evaluation
     */
    protected static function determineEditability($editable, ?object $model = null, $request = null): bool
    {
        if (is_callable($editable)) {
            return call_user_func($editable, $model, $request);
        }

        if (is_bool($editable)) {
            return $editable;
        }

        // Default: not editable if not specified
        return false;
    }

    /**
     * Create a properties panel with model context
     *
     * @param  string  $name  Panel name
     * @param  string  $path  The full path: 'column' or 'column->attribute->subattribute'
     * @param  object|null  $model  Model instance
     * @param  bool|callable|null  $editable  Whether fields should be editable (true/false) or callback function
     */
    public static function makeWithModel(string $name = 'Properties', string $path = 'properties', ?object $model = null, $editable = null): Panel
    {
        // Genera i campi usando jsonForm
        $fields = [];
        if ($model && $path) {
            // Analizza il path per separare column name e attribute path
            $pathSegments = explode('->', $path);
            $columnName = array_shift($pathSegments); // Primo segmento è sempre il nome della colonna
            $attributePath = implode('->', $pathSegments); // Il resto è il path dell'attributo

            // Recupera lo schema per vedere se c'è un label da usare
            if ($model && $columnName && Schema::hasColumn($model->getTable(), $columnName)) {
                $column = $model->$columnName ?? '';
                if (is_array($column) && $attributePath) {
                    // Naviga nel path per trovare i dati del form
                    $currentData = $column;
                    $pathArray = explode('->', $attributePath);
                    foreach ($pathArray as $segment) {
                        if (isset($currentData[$segment])) {
                            $currentData = $currentData[$segment];
                        } else {
                            $currentData = [];
                            break;
                        }
                    }

                    // Prova a recuperare lo schema SOLO se siamo nel contesto di un form
                    // Controlla se l'attributePath contiene 'form' e se ci sono dati del form
                    if (str_contains($attributePath, 'form') && ! empty($currentData)) {
                        $formSchema = static::getFormSchemaFromAcquisitionForms($model, $column, $attributePath);

                        // Se lo schema ha un label, aggiornalo al nome del panel
                        if ($formSchema && isset($formSchema['label'])) {
                            $schemaLabel = static::resolveMultilingualLabel($formSchema['label']);
                            $name = $name.' - '.$schemaLabel; // Usa direttamente il label dello schema invece di concatenare
                        }
                    }
                }
            }

            // Crea un'istanza temporanea per usare i metodi non statici
            $tempPanel = new static($name);
            $fields = $tempPanel->jsonForm($model, $columnName, $attributePath ?: '', null, $editable);
        }

        // Crea un Panel standard di Nova con i campi generati
        return new Panel($name, $fields);
    }

    /**
     * Create JSON form fields from column data and schema
     *
     * @param  object  $model  The model instance
     * @param  string  $columnName  The JSON column name
     * @param  string|null  $attribute  The attribute within the JSON (supports nested paths with ->)
     * @param  array|null  $formSchema  Optional form schema
     * @param  bool|callable|null  $editable  Whether fields should be editable
     * @return array Array of Nova fields
     */
    public function jsonForm(object $model, string $columnName, ?string $attribute, ?array $formSchema = null, $editable = null): array
    {
        $fields = [];

        // Determina se i campi devono essere editabili
        $isEditable = static::determineEditability($editable, $model, request());

        // Gestione speciale per Layer model - carica sempre il layer schema
        if (class_basename($model) === 'Layer') {
            $layerSchema = config('layer-schema');
            if ($layerSchema && isset($layerSchema[$columnName])) {
                $formSchema = $layerSchema[$columnName];
            }
        }

        if ($columnName && Schema::hasColumn($model->getTable(), $columnName)) {
            // Fetch the JSON data from the column
            $column = $model->$columnName ?? '';
            if (! is_array($column)) {
                $formData = json_decode($column, true) ?? [];
            } else {
                $formData = $column;
            }

            // Se l'attributo è 'form', recupera automaticamente il formSchema da app->acquisitionForms
            if ($attribute === 'form' && is_null($formSchema)) {
                $formSchema = static::getFormSchemaFromAcquisitionForms($model, $formData, $attribute);
            }

            // Se è specificato un attributo, naviga attraverso il path (supporta path annidati con ->)
            if ($attribute) {
                $attributePath = explode('->', $attribute);
                $currentData = $formData;

                foreach ($attributePath as $pathSegment) {
                    if (isset($currentData[$pathSegment])) {
                        $currentData = $currentData[$pathSegment];
                    } else {
                        // Se il path non esiste, ritorna array vuoto
                        $currentData = [];
                        break;
                    }
                }

                $formData = $currentData;
            }

            if (is_null($formSchema) || empty($formSchema)) {
                // If no form schema is provided, use the form data directly
                $values = $this->getFormIdOptions($model);
                if(count($values) > 0) {
                $formIdSchema = [
                    'name' => 'id',
                    'type' => 'select',
                    'required' => true,
                    'values' => $this->getFormIdOptions($model),
                    'label' => [
                        'it' => 'Form ID',
                        'en' => 'Form ID',
                    ],
                  ];
                  $novaField = $this->createFieldFromSchema($formIdSchema, $columnName, $attribute, $isEditable);
                  if ($novaField) {
                      $fields[] = $novaField;
                  }
                }
                foreach ($formData as $key => $value) {
                    $fieldType = 'text'; // Default

                    if (is_array($value)) {
                        // Se è un array, visualizzalo come JSON
                        $fieldType = 'json';
                        $value = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    } elseif (is_string($value) && static::isJsonString($value)) {
                        // Se è una stringa JSON, visualizzala come JSON
                        $fieldType = 'json';
                        // Formatta il JSON per una migliore visualizzazione
                        $decoded = json_decode($value, true);
                        if ($decoded !== null) {
                            $value = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                        }
                    } elseif (is_numeric($value)) {
                        $fieldType = 'number';
                    }

                    // Create a dummy schema based on existing form data
                    $fieldSchema = [
                        'name' => $key,
                        'type' => $fieldType,
                        'value' => $value,
                    ];
                    $novaField = $this->createFieldFromSchema($fieldSchema, $columnName, $attribute, $isEditable);
                    if ($novaField) {
                        $fields[] = $novaField;
                    }
                }
            } else {
                $fieldsArray =[];
                // Initialize the fields with data from the JSON column
                // Se formSchema ha una chiave 'fields', usala, altrimenti usa direttamente formSchema
                if(isset($formSchema['id']) ) {
                    $fieldsArray[] = [
                    'name' => 'id',
                    'type' => 'select',
                    'required' => true,
                    'values' => $this->getFormIdOptions($model),
                    'label' => [
                        'it' => 'Form ID',
                        'en' => 'Form ID',
                    ],
                  ];
                }
                $fieldsArray = array_merge($fieldsArray, isset($formSchema['fields']) ? $formSchema['fields'] : $formSchema);

                foreach ($fieldsArray as $fieldSchema) {
                    $fieldName = $fieldSchema['name'] ?? null;
                    $value = isset($fieldName) ? ($formData[$fieldName] ?? $fieldSchema['value'] ?? null) : null;
                    $fieldSchema['value'] = $value; // Set the value from form data or default
                    $novaField = $this->createFieldFromSchema($fieldSchema, $columnName, $attribute, $isEditable);
                    if ($novaField) {
                        $fields[] = $novaField;
                    }
                }
            }
        } elseif ($formSchema) {
            // Use the provided form schema
            // Se formSchema ha una chiave 'fields', usala, altrimenti usa direttamente formSchema
            $fieldsArray = isset($formSchema['fields']) ? $formSchema['fields'] : $formSchema;

            foreach ($fieldsArray as $fieldSchema) {
                $novaField = static::createFieldFromSchema($fieldSchema, $columnName, $attribute, $isEditable);
                if ($novaField) {
                    $fields[] = $novaField;
                }
            }
        } else {
            throw new \Exception('Either form JSON column name or form schema must be provided. Please check your database or provide a form schema.');
        }

        return $fields;
    }

    /**
     * Create a Nova field based on the field schema.
     *
     * @param  array  $fieldSchema  The field schema
     * @param  string|null  $columnName  The column name
     * @param  string|null  $attribute  The attribute within JSON
     * @param  bool  $isEditable  Whether the field should be editable
     * @return Field|null The created Nova field
     */
    protected function createFieldFromSchema(array $fieldSchema, $columnName = null, $attribute = null, bool $isEditable = false): ?Field
    {
        $key = $fieldSchema['name'] ?? null;
        $value = $fieldSchema['value'] ?? null;
        $fieldType = $fieldSchema['type'] ?? 'text';

        // Gestione label multilingua
        $rawLabel = $fieldSchema['label'] ?? ucwords(str_replace('_', ' ', $key));
        $label = static::resolveMultilingualLabel($rawLabel);

        $rules = [];

        // Se è specificato un attributo, costruiamo il path JSON corretto (supporta path annidati)
        $jsonPath = $attribute ? "$columnName->$attribute->$key" : "$columnName->$key";

        // Se columnName è null (schema only case), usa direttamente il key
        if (! $columnName) {
            $jsonPath = $key;
        }

        if (isset($fieldSchema['rules'])) {
            foreach ($fieldSchema['rules'] as $rule) {
                if ($rule['name'] === 'required') {
                    $rules[] = 'required';
                } elseif ($rule['name'] === 'email') {
                    $rules[] = 'email';
                } elseif ($rule['name'] === 'minLength' && isset($rule['value'])) {
                    $rules[] = 'min:'.$rule['value'];
                }
            }
        }

        $field = null;

        // Controlla se il campo è translatable
        $isTranslatable = isset($fieldSchema['translatable']) && $fieldSchema['translatable'] === true;

        // Controllo se è un campo color (basato su fieldSchema['field'])
        $fieldFieldType = $fieldSchema['field'] ?? null;

        // Se il field è specificato come 'color', modifica il tipo
        if ($fieldFieldType === 'color') {
            $fieldType = 'color';
        }

        // Crea il campo Nova appropriato basato sul tipo dello schema
        switch ($fieldType) {
            case 'number':
            case 'integer':
                $field = Number::make($label, $jsonPath);
                break;

            case 'email':
                $field = Email::make($label, $jsonPath);
                break;

            case 'password':
                $field = Password::make($label, $jsonPath);
                break;

            case 'textarea':
                if ($isTranslatable) {
                    // Se il campo è translatable, usa NovaTabTranslatable
                    $field = NovaTabTranslatable::make([
                        Textarea::make($label, $jsonPath),
                    ]);
                } else {
                    // Altrimenti usa un campo Textarea normale
                    $field = Textarea::make($label, $jsonPath)
                        ->displayUsing(function ($value) {
                            return $value;
                        });
                }
                break;

            case 'date':
                $field = Date::make($label, $jsonPath);
                break;

            case 'datetime':
                $field = DateTime::make($label, $jsonPath);
                break;

            case 'boolean':
            case 'checkbox':
                $field = Boolean::make($label, $jsonPath);
                break;

            case 'select':
                $field = Select::make($label, $jsonPath);

                // Se ci sono options nello schema, aggiungile
                if (isset($fieldSchema['values']) && is_array($fieldSchema['values'])) {
                    $options = [];
                    foreach ($fieldSchema['values'] as $option) {
                        $optionValue = $option['value'] ?? $option;
                        $optionLabel = isset($option['label'])
                            ? static::resolveMultilingualLabel($option['label'])
                            : $optionValue;
                        $options[$optionValue] = $optionLabel;
                    }
                    $field->options($options);
                }

                // Per i campi select, assicuriamoci che il valore sia visualizzato correttamente
                $field->displayUsing(function ($value) use ($fieldSchema) {
                    if (isset($fieldSchema['values']) && is_array($fieldSchema['values'])) {
                        foreach ($fieldSchema['values'] as $option) {
                            $optionValue = $option['value'] ?? $option;
                            if ($optionValue == $value) {
                                return isset($option['label'])
                                    ? static::resolveMultilingualLabel($option['label'])
                                    : $optionValue;
                            }
                        }
                    }

                    return $value;
                });
                break;

            case 'json':
                $field = Code::make($label, $jsonPath)
                    ->json();
                break;

            case 'color':
                $field = Color::make($label, $jsonPath);
                break;

            case 'text':
            default:
                if ($isTranslatable) {
                    // Se il campo è translatable, usa NovaTabTranslatable
                    $field = NovaTabTranslatable::make([
                        Text::make($label, $jsonPath),
                    ]);
                } else {
                    // Altrimenti usa un campo Text normale
                    $field = Text::make($label, $jsonPath)
                        ->displayUsing(function ($value) {
                            // Se il valore è un array, convertilo in JSON per la visualizzazione
                            if (is_array($value)) {
                                return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                            }

                            return $value;
                        });
                }
                break;
        }

        // Applicazione centralizzata di rules e hideFromIndex
        $field->rules($rules)->hideFromIndex();

        // Gestione isEditable centralizzata - nasconde i campi dalle viste edit se non editabili
        if ($field && ! $isEditable) {
            $field->hideWhenUpdating();
        }

        return $field;
    }

    /**
     * Recupera il formSchema da app->acquisitionForms basato su form->id
     *
     * @param  object  $model  The model instance
     * @param  array  $fullFormData  I dati completi del JSON
     * @param  string  $attribute  L'attributo (dovrebbe essere 'form')
     * @return array|null Il schema del form o null se non trovato
     */
    protected static function getFormSchemaFromAcquisitionForms(object $model, array $fullFormData, string $attribute): ?array
    {
        // Verifica che ci sia l'id del form
        if (! isset($fullFormData[$attribute]['id'])) {
            return null;
        }

        $formId = $fullFormData[$attribute]['id'];

        // Usa il modello app associato all'UGC
        if (! $model->app) {
            return null;
        }

        try {
            // Ottieni il form specifico dal modello App
            $acquisitionForm = $model->app->acquisitionForms($formId);

            if (! $acquisitionForm) {
                return null;
            }

            // Se il risultato è un array con 'fields', restituisci fields
            // Se è già un array di fields, restituisci così com'è
            if (is_array($acquisitionForm)) {
                return $acquisitionForm;
            }

            return null;
        } catch (\Exception $e) {
            // Log dell'errore per debug
            Log::error('Errore recupero acquisitionForms: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Risolve un label che può essere stringa o oggetto multilingua
     *
     * @param  string|array  $label  Il label da risolvere
     * @return string Il label risolto nella lingua corrente
     */
    protected static function resolveMultilingualLabel($label): string
    {
        // Se è una stringa, ritornala così com'è
        if (is_string($label)) {
            return $label;
        }

        // Se è un array/oggetto con chiavi di lingua
        if (is_array($label)) {
            $currentLocale = app()->getLocale();

            // Prova prima con la lingua corrente
            if (isset($label[$currentLocale])) {
                return $label[$currentLocale];
            }

            // Fallback su italiano
            if (isset($label['it'])) {
                return $label['it'];
            }

            // Fallback su inglese
            if (isset($label['en'])) {
                return $label['en'];
            }

            // Prendi il primo valore disponibile
            return array_values($label)[0] ?? 'Unknown';
        }

        // Fallback se non è né stringa né array
        return 'Unknown';
    }

    /**
     * Controlla se una stringa è un JSON valido
     *
     * @param  string  $string  La stringa da controllare
     * @return bool True se è un JSON valido
     */
    protected static function isJsonString(string $string): bool
    {
        if (empty($string)) {
            return false;
        }

        // Deve iniziare con { o [
        $trimmed = trim($string);
        if (! str_starts_with($trimmed, '{') && ! str_starts_with($trimmed, '[')) {
            return false;
        }

        json_decode($string);

        return json_last_error() === JSON_ERROR_NONE;
    }

    public function getFormIdOptions(object $model): array
    {
        $app = $model->app;
        // Se non c'è un'app associata, restituisci un array vuoto
        if (! $app) {
            return [];
        }

        // Ottieni tutti i form di acquisizione dall'app associata
        $forms = $app->acquisitionForms();

        if (! $forms) {
            return [];
        }

        $options = [];
        foreach ($forms as $form) {
            if (isset($form['id'])) {
                // Usa il label multilingua se disponibile, altrimenti fallback su id
                $label = $form['label'] ?? [
                    'it' => $form['id'],
                    'en' => $form['id'],
                ];
                $options[] = [
                    'value' => $form['id'],
                    'label' => $label,
                ];
            }
        }

        return $options;
    }
}
