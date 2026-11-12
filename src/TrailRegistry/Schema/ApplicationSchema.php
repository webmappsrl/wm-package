<?php

namespace Wm\WmPackage\TrailRegistry\Schema;

use Wm\WmPackage\TrailRegistry\Rules\DifficultyMatchesActivities;

/**
 * Lo schema dei campi del modulo di domanda di accatastamento (campi 29-65
 * del format), nell'ordine della numerazione. E' la fonte unica da cui
 * derivano la validazione, l'elenco dei campi obbligatori e i Field di Nova.
 *
 * I metodi non sono statici perche' lo shard possa ridefinire lo schema:
 * registra la propria sottoclasse nel container
 * (`$this->app->bind(ApplicationSchema::class, MiaSchema::class)`) e chi
 * consuma lo schema lo risolve con `app(ApplicationSchema::class)`, mai con
 * `new`. Una sottoclasse ridefinisce `fields()`, `itemRules()` o `version()`:
 * gli altri metodi derivano da quelli.
 */
class ApplicationSchema
{
    /**
     * Le attivita' ammesse. Non si validano contro `taxonomy_activities`,
     * che contiene anche identifier che attivita' non sono.
     *
     * @var list<string>
     */
    protected const ACTIVITIES = [
        'trekking',
        'hiking',
        'mtb',
        'horse',
        'accessible-trails',
        'nordic-walking',
        'educational-trails',
    ];

    /**
     * Chiave, dentro `api_data`, che porta la versione dello schema con cui il
     * payload e' stato accettato. Il prefisso `_` la distingue da un campo del
     * modulo. Chi legge un payload per sapere con quali regole e' stato
     * accettato la prende da qui, accanto a `version()` che la produce.
     */
    public const VERSION_KEY = '_schema_version';

    /**
     * La versione dello schema. Si alza a mano quando cambia cio' che la
     * consegna deve contenere o come si valida: il payload accettato porta
     * con se' la versione che lo ha accettato.
     */
    public function version(): string
    {
        return '1';
    }

    /**
     * Le attivita' ammesse dallo schema. E' un metodo e non l'accesso diretto
     * alla costante perche' chi la consuma passa dal binding dello shard.
     *
     * @return list<string>
     */
    public function activities(): array
    {
        return static::ACTIVITIES;
    }

    /**
     * I 27 campi nell'ordine del format; il numero in coda a ogni riga e' il
     * suo numero di campo. Le select dichiarate senza `values` sono quelle il
     * cui elenco non e' fissato per la Resource Nova: il campo e' gia' una
     * select e i valori si aggiungono quando arrivano. `last_survey_date` si
     * valida come data ma si mostra come `text`: un formato di data
     * inatteso non deve impedire di leggere cosa e' arrivato.
     *
     * @return array<int, ApplicationField>
     */
    public function fields(): array
    {
        return [
            new ApplicationField('from', 'text', __('Start point'), null, true, ['required', 'string'], null), // 29
            new ApplicationField('to', 'text', __('End point'), null, true, ['required', 'string'], null), // 30
            new ApplicationField('intermediate_stop', 'text', __('Intermediate stop'), null, false, ['nullable', 'string'], null), // 31
            new ApplicationField('distance', 'number', __('Declared distance'), null, true, ['required', 'numeric'], null), // 32
            new ApplicationField('ascent', 'number', __('Declared ascent'), null, false, ['nullable', 'numeric'], null), // 33
            new ApplicationField('duration_forward', 'number', __('Declared duration forward'), null, true, ['required', 'integer'], null), // 34
            new ApplicationField('duration_backward', 'number', __('Declared duration backward'), null, true, ['required', 'integer'], null), // 35
            new ApplicationField('activities', 'json', __('Proposed activities'), null, true, ['required', 'array', 'min:1'], null), // 36
            new ApplicationField('difficulty', 'json', __('Proposed difficulty'), null, false, ['nullable', 'array', new DifficultyMatchesActivities], null), // 37
            new ApplicationField('existing_signage', 'select', __('Existing signage'), [
                ['value' => 'good_condition', 'label' => ['it' => "c'è ed è in buono stato", 'en' => 'present and in good condition']],
                ['value' => 'dilapidated', 'label' => ['it' => "c'è ed è fatiscente", 'en' => 'present but dilapidated']],
                ['value' => 'absent', 'label' => ['it' => "non c'è", 'en' => 'absent']],
            ], true, ['required', 'in:good_condition,dilapidated,absent'], null), // 38
            new ApplicationField('overlapping_routes', 'text', __('Overlapping routes'), null, false, ['nullable', 'string'], null), // 39
            new ApplicationField('proposed_managing_municipalities', 'json', __('Proposed managing municipalities'), null, true, ['required', 'array', 'min:1'], null), // 42
            new ApplicationField('municipality_email', 'email', __('Municipality email'), null, true, ['required', 'email'], null), // 43
            new ApplicationField('municipality_email_alt', 'email', __('Alternative municipality email'), null, false, ['nullable', 'email'], null), // 44
            new ApplicationField('themes', 'json', __('Themes'), null, false, ['nullable', 'array'], null), // 45
            new ApplicationField('soggetto_gestore', 'select', __('Manager'), [
                ['value' => 'applicant', 'label' => ['it' => 'Il richiedente', 'en' => 'The applicant']],
                ['value' => 'municipalities', 'label' => ['it' => 'Comuni territorialmente competenti selezionati', 'en' => 'Selected territorially competent municipalities']],
                ['value' => 'third_party', 'label' => ['it' => 'Altro soggetto terzo', 'en' => 'Other third party']],
                ['value' => 'forestas', 'label' => ['it' => 'Forestas', 'en' => 'Forestas']],
            ], true, ['required', 'in:applicant,municipalities,third_party,forestas'], null), // 47
            new ApplicationField('soggetto_gestore_other', 'text', __('Other manager'), null, false, ['required_if:soggetto_gestore,third_party', 'nullable', 'string'], null), // 48
            new ApplicationField('soggetto_rilevatore', 'select', __('Surveyor'), [
                ['value' => 'applicant', 'label' => ['it' => 'Il richiedente', 'en' => 'The applicant']],
                ['value' => 'other_surveyor', 'label' => ['it' => 'Altro soggetto rilevatore', 'en' => 'Other surveyor']],
            ], true, ['required', 'in:applicant,other_surveyor'], null), // 49
            new ApplicationField('soggetto_rilevatore_other', 'text', __('Other surveyor'), null, false, ['required_if:soggetto_rilevatore,other_surveyor', 'nullable', 'string'], null), // 50
            new ApplicationField('soggetto_rilevatore_email', 'email', __('Surveyor email'), null, false, ['required_if:soggetto_rilevatore,other_surveyor', 'nullable', 'email'], null), // 51
            new ApplicationField('soggetto_manutentore', 'select', __('Maintainer'), [
                ['value' => 'applicant', 'label' => ['it' => 'Il richiedente (se soggetto giuridico)', 'en' => 'The applicant (if a legal entity)']],
                ['value' => 'municipalities', 'label' => ['it' => 'Comuni territorialmente competenti selezionati', 'en' => 'Selected territorially competent municipalities']],
                ['value' => 'other_legal_entity', 'label' => ['it' => 'Altro soggetto giuridico', 'en' => 'Other legal entity']],
                ['value' => 'forestas', 'label' => ['it' => 'Forestas', 'en' => 'Forestas']],
            ], true, ['required', 'in:applicant,municipalities,other_legal_entity,forestas'], null), // 53
            new ApplicationField('soggetto_manutentore_other', 'json', __('Other maintainer'), null, false, ['required_if:soggetto_manutentore,other_legal_entity', 'nullable', 'array'], 'text'), // 54
            new ApplicationField('soggetto_manutentore_cf', 'json', __('Other maintainer tax code'), null, false, ['required_if:soggetto_manutentore,other_legal_entity', 'nullable', 'array'], null), // 55
            new ApplicationField('soggetto_custode_presente', 'boolean', __('Keeper exists'), null, true, ['required', 'boolean'], null), // 56
            new ApplicationField('soggetto_custode', 'text', __('Keeper'), null, false, ['required_if:soggetto_custode_presente,true', 'nullable', 'string'], null), // 58
            new ApplicationField('last_survey_date', 'date', __('Last survey date'), null, true, ['required', 'date', 'before_or_equal:today'], 'text'), // 64
            new ApplicationField('maintenance_frequency_months', 'number', __('Maintenance frequency (months)'), null, true, ['required', 'integer', 'min:1'], null), // 65
        ];
    }

    /**
     * Le regole degli elementi dei campi array, con la chiave `campo.*` che
     * Laravel si aspetta.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function itemRules(): array
    {
        return [
            'activities.*' => ['string', 'in:'.implode(',', static::ACTIVITIES)],
            'proposed_managing_municipalities.*' => ['string'],
            'soggetto_manutentore_other.*' => ['string'],
            'soggetto_manutentore_cf.*' => ['string'],
        ];
    }

    /**
     * Le regole Laravel di ogni campo, con la chiave del campo e, per gli
     * array, anche quella dei loro elementi (`campo.*`).
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [];
        foreach ($this->fields() as $field) {
            $rules[$field->name] = $field->rules;
        }

        return $rules + $this->itemRules();
    }

    /**
     * I campi che la consegna deve contenere sempre. Non ci sono quelli la cui
     * obbligatorieta' dipende dal valore di un altro campo: la dichiara la
     * loro regola `required_if`.
     *
     * @return list<string>
     */
    public function requiredFieldNames(): array
    {
        $names = [];
        foreach ($this->fields() as $field) {
            if ($field->required) {
                $names[] = $field->name;
            }
        }

        return $names;
    }

    /**
     * Lo schema nella forma che `PropertiesPanel::jsonForm()` consuma, nell'
     * ordine del format.
     *
     * @return array<int, array<string, mixed>>
     */
    /**
     * I valori ammessi di un campo a scelta, ciascuno con la sua etichetta,
     * pronti per la documentazione dell'API.
     *
     * Nel payload viaggia solo il valore, stabile e senza ambiguita'; chi
     * integra deve pero' poterlo ritrovare fra le voci del format, che sono
     * cio' che ha discusso. L'etichetta e' quella dello schema, la stessa che
     * Nova mostra all'operatore: scriverla a mano anche nella documentazione
     * vorrebbe dire tenerne due copie allineate a mano.
     *
     * L'etichetta e' nella lingua corrente, con l'italiano come riserva,
     * perche' e' la lingua del format.
     *
     * Restituisce null per un campo che non ha un elenco di valori.
     */
    public function valuesDescription(string $name): ?string
    {
        $field = collect($this->fields())->first(fn (ApplicationField $f) => $f->name === $name);

        if ($field === null || $field->values === null || $field->values === []) {
            return null;
        }

        $locale = app()->getLocale();

        $items = array_map(function (array $option) use ($locale) {
            $label = $option['label'][$locale] ?? $option['label']['it'] ?? (string) $option['value'];

            return sprintf('`%s` — *%s*', $option['value'], $label);
        }, $field->values);

        return __('Allowed values').': '.implode('; ', $items).'.';
    }

    public function toNovaSchema(): array
    {
        return array_map(fn (ApplicationField $field) => $field->toArray(), $this->fields());
    }
}
