<?php

use Laravel\Nova\Fields\KeyValue;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Nova\EcTrack as EcTrackResource;

// related_url non ha una colonna: come su EcPoi vive dentro `properties`, e a scriverlo è il campo
// Nova `properties->related_url`. Il test della Resource prova che il campo esiste e punta lì.
it('espone nella Resource Nova delle tracce un KeyValue su properties->related_url', function () {
    $resource = new EcTrackResource(new EcTrack);

    $fields = collect($resource->getInfoTabFields())
        ->filter(fn ($field) => $field instanceof KeyValue && $field->attribute === 'properties->related_url');

    expect($fields)->toHaveCount(1);
});

it('conserva in properties una mappa etichetta => url (andata e ritorno del JSON)', function () {
    $track = EcTrack::factory()->createQuietly();
    $track->properties = array_merge($track->properties, [
        'related_url' => ['Sito del Parco' => 'https://www.parcotepilora.it'],
    ]);
    $track->saveQuietly();

    expect($track->fresh()->properties['related_url'])
        ->toBe(['Sito del Parco' => 'https://www.parcotepilora.it']);
});
