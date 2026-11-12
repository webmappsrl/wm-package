<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\GeometryComputationService;

// Rotte GPX e KML di tracce e POI (oc:8953). Le geometrie sono scritte a mano, con quote fissate:
// quelle casuali delle factory possono valere 0.00, e `EcPoiFactory` mette la latitudine fino a
// ±180, che PostGIS riporta nel range in silenzio.
//
// Funzioni e costanti hanno il prefisso `ecDownload` / `EC_DOWNLOAD_`: Pest carica tutti i file di
// test nello stesso processo, e un nome generico ripetuto altrove sarebbe un errore fatale.

const EC_DOWNLOAD_GPX_NS = 'http://www.topografix.com/GPX/1/1';
const EC_DOWNLOAD_KML_NS = 'http://www.opengis.net/kml/2.2';
const EC_DOWNLOAD_SPECIAL_NAME = 'Via Don Giordi/Via della Musica – 100% & co';

function ecDownloadName(string $name): array
{
    return ['it' => $name, 'en' => $name];
}

function ecDownloadTrack(string $name = 'Sentiero di prova'): EcTrack
{
    App::first() ?? App::factory()->createQuietly();

    return EcTrack::factory()->createQuietly([
        'name' => ecDownloadName($name),
        'osmid' => null,
        // Due linee; il secondo punto della prima ha Z = 0, cioè quota mancante.
        'geometry' => DB::raw("ST_GeomFromText('MULTILINESTRING Z ((10 43 100, 10.001 43.001 0, 10.002 43.002 120.5), (10.01 43.01 200, 10.011 43.011 210))', 4326)"),
    ]);
}

function ecDownloadPoi(float $ele = 350, string $name = 'Fonte di prova', float $lon = 9.5): EcPoi
{
    App::first() ?? App::factory()->createQuietly();

    return EcPoi::factory()->createQuietly([
        'name' => ecDownloadName($name),
        'osmid' => null,
        'geometry' => DB::raw("ST_GeomFromText('POINT Z ({$lon} 40.1 {$ele})', 4326)"),
    ]);
}

function ecDownloadLoadXml(string $content): DOMDocument
{
    $dom = new DOMDocument;
    expect($dom->loadXML($content))->toBeTrue();

    return $dom;
}

function ecDownloadXpath(DOMDocument $dom): DOMXPath
{
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('g', EC_DOWNLOAD_GPX_NS);
    $xpath->registerNamespace('k', EC_DOWNLOAD_KML_NS);

    return $xpath;
}

function ecDownloadValidatesGpx(DOMDocument $dom): bool
{
    return $dom->schemaValidate(__DIR__.'/../../fixtures/gpx/gpx.xsd');
}

dataset('gpx track routes', [
    'download' => ['/api/ec/track/download/%d.gpx'],
    'view' => ['/api/ec/track/%d.gpx'],
]);

dataset('kml track routes', [
    'download' => ['/api/ec/track/download/%d.kml'],
    'view' => ['/api/ec/track/%d.kml'],
]);

dataset('gpx poi routes', [
    'download' => ['/api/ec/poi/download/%d.gpx'],
    'view' => ['/api/ec/poi/%d.gpx'],
]);

dataset('kml poi routes', [
    'download' => ['/api/ec/poi/download/%d.kml'],
    'view' => ['/api/ec/poi/%d.kml'],
]);

dataset('all gpx and kml routes', [
    'track gpx download' => ['track', '/api/ec/track/download/%d.gpx'],
    'track gpx view' => ['track', '/api/ec/track/%d.gpx'],
    'track kml download' => ['track', '/api/ec/track/download/%d.kml'],
    'track kml view' => ['track', '/api/ec/track/%d.kml'],
    'poi gpx download' => ['poi', '/api/ec/poi/download/%d.gpx'],
    'poi gpx view' => ['poi', '/api/ec/poi/%d.gpx'],
    'poi kml download' => ['poi', '/api/ec/poi/download/%d.kml'],
    'poi kml view' => ['poi', '/api/ec/poi/%d.kml'],
]);

dataset('download routes', [
    'track gpx' => ['track', '/api/ec/track/download/%d.gpx'],
    'track kml' => ['track', '/api/ec/track/download/%d.kml'],
    'poi gpx' => ['poi', '/api/ec/poi/download/%d.gpx'],
    'poi kml' => ['poi', '/api/ec/poi/download/%d.kml'],
]);

it('returns a complete GPX for a multi-line track', function (string $route) {
    $track = ecDownloadTrack();

    $response = $this->get(sprintf($route, $track->id));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('application/gpx+xml');

    $dom = ecDownloadLoadXml($response->getContent());
    $xpath = ecDownloadXpath($dom);

    expect($dom->documentElement->localName)->toBe('gpx')
        ->and($dom->documentElement->namespaceURI)->toBe(EC_DOWNLOAD_GPX_NS)
        ->and($dom->documentElement->getAttribute('version'))->toBe('1.1')
        ->and($xpath->query('/g:gpx/g:trk')->length)->toBe(1)
        ->and($xpath->evaluate('string(/g:gpx/g:trk/g:name)'))->toBe('Sentiero di prova')
        ->and($xpath->query('/g:gpx/g:trk/g:trkseg')->length)->toBe(2)
        ->and($xpath->query('/g:gpx/g:trk/g:trkseg[1]/g:trkpt')->length)->toBe(3)
        ->and($xpath->query('/g:gpx/g:trk/g:trkseg[2]/g:trkpt')->length)->toBe(2);

    $first = $xpath->query('/g:gpx/g:trk/g:trkseg[1]/g:trkpt[1]')->item(0);
    expect($first->getAttribute('lat'))->toBe('43')
        ->and($first->getAttribute('lon'))->toBe('10')
        ->and($xpath->evaluate('string(g:ele)', $first))->toBe('100');

    // Z = 0 vale come quota mancante: niente <ele>.
    expect($xpath->query('/g:gpx/g:trk/g:trkseg[1]/g:trkpt[2]/g:ele')->length)->toBe(0)
        ->and($xpath->evaluate('string(/g:gpx/g:trk/g:trkseg[1]/g:trkpt[3]/g:ele)'))->toBe('120.5')
        ->and($xpath->query('//g:trkpt/g:ele')->length)->toBe(4)
        ->and(ecDownloadValidatesGpx($dom))->toBeTrue();
})->with('gpx track routes');

it('returns a complete GPX waypoint for a POI', function (string $route) {
    $poi = ecDownloadPoi();

    $response = $this->get(sprintf($route, $poi->id));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('application/gpx+xml');

    $dom = ecDownloadLoadXml($response->getContent());
    $xpath = ecDownloadXpath($dom);
    $wpt = $xpath->query('/g:gpx/g:wpt')->item(0);

    expect($wpt)->not->toBeNull()
        ->and($wpt->getAttribute('lat'))->toBe('40.1')
        ->and($wpt->getAttribute('lon'))->toBe('9.5')
        ->and($xpath->evaluate('string(g:ele)', $wpt))->toBe('350')
        ->and($xpath->evaluate('string(g:name)', $wpt))->toBe('Fonte di prova')
        // Lo schema GPX 1.1 impone <ele> prima di <name>.
        ->and($wpt->firstElementChild->localName)->toBe('ele')
        ->and(ecDownloadValidatesGpx($dom))->toBeTrue();
})->with('gpx poi routes');

it('omits the elevation of a POI at zero, also after rounding', function (float $ele) {
    $poi = ecDownloadPoi($ele);

    $gpx = ecDownloadLoadXml($this->get("/api/ec/poi/download/{$poi->id}.gpx")->assertOk()->getContent());
    $kml = ecDownloadLoadXml($this->get("/api/ec/poi/download/{$poi->id}.kml")->assertOk()->getContent());

    expect(ecDownloadXpath($gpx)->query('/g:gpx/g:wpt/g:ele')->length)->toBe(0)
        ->and(ecDownloadXpath($gpx)->query('/g:gpx/g:wpt/g:name')->length)->toBe(1)
        ->and(ecDownloadValidatesGpx($gpx))->toBeTrue()
        ->and(trim(ecDownloadXpath($kml)->evaluate('string(/k:kml/k:Document/k:Placemark/k:Point/k:coordinates)')))->toBe('9.5,40.1');
})->with([
    'zero' => [0],
    // Arrotondata a 2 decimali vale 0: va trattata come quota mancante.
    'near zero' => [0.004],
]);

it('returns a complete KML for a multi-line track', function (string $route) {
    $track = ecDownloadTrack();

    $response = $this->get(sprintf($route, $track->id));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('application/vnd.google-earth.kml+xml');

    $dom = ecDownloadLoadXml($response->getContent());
    $xpath = ecDownloadXpath($dom);

    expect($dom->documentElement->localName)->toBe('kml')
        ->and($dom->documentElement->namespaceURI)->toBe(EC_DOWNLOAD_KML_NS)
        ->and($xpath->evaluate('string(/k:kml/k:Document/k:name)'))->toBe('Sentiero di prova')
        ->and($xpath->evaluate('string(/k:kml/k:Document/k:Placemark/k:name)'))->toBe('Sentiero di prova')
        ->and($xpath->query('/k:kml/k:Document/k:Placemark/k:MultiGeometry/k:LineString')->length)->toBe(2);

    $lineCoordinates = fn (int $index) => preg_split('/\s+/', trim($xpath->evaluate(
        "string(/k:kml/k:Document/k:Placemark/k:MultiGeometry/k:LineString[{$index}]/k:coordinates)"
    )));

    // Il punto con Z = 0 ha solo lon e lat.
    expect($lineCoordinates(1))->toBe(['10,43,100', '10.001,43.001', '10.002,43.002,120.5'])
        ->and($lineCoordinates(2))->toBe(['10.01,43.01,200', '10.011,43.011,210']);
})->with('kml track routes');

it('returns a complete KML point for a POI', function (string $route) {
    $poi = ecDownloadPoi();

    $response = $this->get(sprintf($route, $poi->id));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('application/vnd.google-earth.kml+xml');

    $dom = ecDownloadLoadXml($response->getContent());
    $xpath = ecDownloadXpath($dom);

    expect($xpath->evaluate('string(/k:kml/k:Document/k:Placemark/k:name)'))->toBe('Fonte di prova')
        ->and(trim($xpath->evaluate('string(/k:kml/k:Document/k:Placemark/k:Point/k:coordinates)')))->toBe('9.5,40.1,350');
})->with('kml poi routes');

it('never writes numbers in exponential notation', function (string $route) {
    // In PHP (string) 0.00001 vale «1.0E-5», che xsd:decimal non accetta.
    $poi = ecDownloadPoi(350, 'Fonte di prova', 0.00001);

    $content = $this->get(sprintf($route, $poi->id))->assertOk()->getContent();

    expect($content)->not->toMatch('/\d[eE][+-]?\d/')
        ->and($content)->toContain('0.00001');
})->with([
    'gpx' => ['/api/ec/poi/download/%d.gpx'],
    'kml' => ['/api/ec/poi/download/%d.kml'],
]);

it('encodes special characters of the name in the XML', function () {
    $track = ecDownloadTrack(EC_DOWNLOAD_SPECIAL_NAME);

    $gpx = ecDownloadLoadXml($this->get("/api/ec/track/{$track->id}.gpx")->assertOk()->getContent());
    $kml = ecDownloadLoadXml($this->get("/api/ec/track/{$track->id}.kml")->assertOk()->getContent());

    expect(ecDownloadXpath($gpx)->evaluate('string(/g:gpx/g:trk/g:name)'))->toBe(EC_DOWNLOAD_SPECIAL_NAME)
        ->and(ecDownloadXpath($kml)->evaluate('string(/k:kml/k:Document/k:name)'))->toBe(EC_DOWNLOAD_SPECIAL_NAME);
});

it('drops control characters from the name, which XML does not allow', function () {
    $track = ecDownloadTrack("Sentiero\x0B di\x01 prova");

    $gpx = ecDownloadLoadXml($this->get("/api/ec/track/{$track->id}.gpx")->assertOk()->getContent());

    expect(ecDownloadXpath($gpx)->evaluate('string(/g:gpx/g:trk/g:name)'))->toBe('Sentiero di prova');
});

it('builds an ASCII and a UTF-8 file name on download routes', function (string $type, string $route) {
    $model = $type === 'track' ? ecDownloadTrack(EC_DOWNLOAD_SPECIAL_NAME) : ecDownloadPoi(350, EC_DOWNLOAD_SPECIAL_NAME);
    $extension = pathinfo($route, PATHINFO_EXTENSION);

    $disposition = $this->get(sprintf($route, $model->id))->assertOk()->headers->get('Content-Disposition');

    expect($disposition)->toStartWith('attachment;');

    preg_match('/filename="([^"]*)"/', $disposition, $ascii);
    preg_match("/filename\\*=utf-8''([^;]*)/i", $disposition, $utf8);

    expect($ascii[1])->toEndWith(".{$extension}")
        ->and($ascii[1])->toMatch('/^[\x20-\x7E]+$/')
        ->and($ascii[1])->not->toContain('/')
        ->and($ascii[1])->not->toContain('\\')
        ->and($ascii[1])->not->toContain('%')
        ->and(rawurldecode($utf8[1]))->toBe("Via Don Giordi-Via della Musica – 100% & co.{$extension}");
})->with('download routes');

it('builds the file name also for the GeoJSON download', function (string $type) {
    $model = $type === 'track' ? ecDownloadTrack(EC_DOWNLOAD_SPECIAL_NAME) : ecDownloadPoi(350, EC_DOWNLOAD_SPECIAL_NAME);

    $disposition = $this->get("/api/ec/{$type}/download/{$model->id}.geojson")->assertOk()->headers->get('Content-Disposition');

    preg_match("/filename\\*=utf-8''([^;]*)/i", $disposition, $utf8);

    expect($disposition)->toStartWith('attachment;')
        ->and(rawurldecode($utf8[1]))->toBe('Via Don Giordi-Via della Musica – 100% & co.geojson');
})->with(['track', 'poi']);

it('uses the id as ASCII file name when the name cannot be transliterated', function () {
    $track = ecDownloadTrack('日本');

    $disposition = $this->get("/api/ec/track/download/{$track->id}.gpx")->assertOk()->headers->get('Content-Disposition');

    // makeDisposition() mette le virgolette solo se il nome ne ha bisogno.
    expect($disposition)->toMatch("/filename=\"?{$track->id}\\.gpx\"?;/")
        ->and($disposition)->toContain("filename*=utf-8''".rawurlencode('日本').'.gpx');
});

it('does not send Content-Disposition on view routes', function (string $type, string $route) {
    $model = $type === 'track' ? ecDownloadTrack() : ecDownloadPoi();

    $response = $this->get(sprintf($route, $model->id));

    $response->assertOk();
    expect($response->headers->has('Content-Disposition'))->toBeFalse();
})->with([
    'track gpx' => ['track', '/api/ec/track/%d.gpx'],
    'track kml' => ['track', '/api/ec/track/%d.kml'],
    'poi gpx' => ['poi', '/api/ec/poi/%d.gpx'],
    'poi kml' => ['poi', '/api/ec/poi/%d.kml'],
]);

it('names the file after the id when the name is empty', function (string $type, string $route) {
    $model = $type === 'track' ? ecDownloadTrack('') : ecDownloadPoi(350, '');
    $extension = pathinfo($route, PATHINFO_EXTENSION);

    $disposition = $this->get(sprintf($route, $model->id))->assertOk()->headers->get('Content-Disposition');

    expect($disposition)->toContain("filename=\"{$model->id}.{$extension}\"");
})->with('download routes');

it('answers 404 when the content has no geometry', function (string $type, string $route) {
    $model = $type === 'track' ? ecDownloadTrack() : ecDownloadPoi();

    // La colonna geometry non è nullable: il null si simula sul service, e si chiama la rotta
    // perché il 404 deve funzionare anche nel controller.
    $service = Mockery::mock(GeometryComputationService::class)->makePartial();
    $service->shouldReceive('getModelGeometryAsGeojson')->andReturn(null);
    app()->instance(GeometryComputationService::class, $service);

    $response = $this->get(sprintf($route, $model->id));

    $response->assertStatus(404);
    expect($response->json())->toBe(['code' => 404, 'error' => 'Geometry not found']);
})->with('all gpx and kml routes');

it('answers 404 for a missing id', function (string $type, string $route) {
    $this->get(sprintf($route, 999999999))->assertStatus(404);
})->with('all gpx and kml routes');

it('keeps the GeoJSON download working', function (string $type) {
    $model = $type === 'track' ? ecDownloadTrack() : ecDownloadPoi();

    $response = $this->get("/api/ec/{$type}/download/{$model->id}.geojson");

    $response->assertOk();
    expect($response->json('type'))->toBe('Feature')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment');
})->with(['track', 'poi']);
