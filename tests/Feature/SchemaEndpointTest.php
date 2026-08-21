<?php

declare(strict_types=1);

use Hygo\ApiWaypoint\Compiler\Support\SchemaDocument;
use Hygo\ApiWaypoint\Support\SchemaRepository;
use Illuminate\Support\Facades\Cache;

it('serves the full document with the contract headers', function (): void {
    $response = $this->withHeaders($this->secretHeader())
        ->getJson('/_api-waypoint')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-Api-Waypoint-Format', SchemaDocument::FORMAT);

    expect($response->headers->get('ETag'))->toBe('"'.$response->json('schema_hash').'"');
});

it('answers a matching If-None-Match with 304 and no body', function (): void {
    $etag = $this->withHeaders($this->secretHeader())
        ->getJson('/_api-waypoint')
        ->headers->get('ETag');

    $response = $this->withHeaders($this->secretHeader() + ['If-None-Match' => $etag])
        ->get('/_api-waypoint')
        ->assertStatus(304);

    expect($response->getContent())->toBe('')
        ->and($response->headers->get('ETag'))->toBe($etag);
});

it('tolerates a weak validator and a list of candidates', function (): void {
    $etag = $this->withHeaders($this->secretHeader())
        ->getJson('/_api-waypoint')
        ->headers->get('ETag');

    $this->withHeaders($this->secretHeader() + ['If-None-Match' => 'W/'.$etag])
        ->get('/_api-waypoint')
        ->assertStatus(304);

    $this->withHeaders($this->secretHeader() + ['If-None-Match' => '"sha256:000000000000", '.$etag])
        ->get('/_api-waypoint')
        ->assertStatus(304);
});

it('serves the document again when the presented ETag does not match', function (): void {
    $this->withHeaders($this->secretHeader() + ['If-None-Match' => '"sha256:000000000000"'])
        ->getJson('/_api-waypoint')
        ->assertOk()
        ->assertJsonPath('schema_format_version', '1.0');
});

it('accepts the format it speaks', function (): void {
    $this->withHeaders($this->secretHeader())
        ->getJson('/_api-waypoint?format=1.0')
        ->assertOk();
});

it('answers an unsupported format with a 409 that says what it does speak', function (): void {
    $response = $this->withHeaders($this->secretHeader())
        ->getJson('/_api-waypoint?format=0.9')
        ->assertStatus(409);

    expect($response->json())->toMatchArray([
        'code' => 'waypoint.format_unsupported',
        'requested' => '0.9',
        'supported' => ['1.0'],
    ])->and($response->json('hint'))->toBeString();
});

it('negotiates the format on the manifest too', function (): void {
    $this->withHeaders($this->secretHeader())
        ->getJson('/_api-waypoint/manifest?format=2.0')
        ->assertStatus(409)
        ->assertJsonPath('code', 'waypoint.format_unsupported');
});

it('never caches the document in a development environment', function (): void {
    // Correctness over speed: the document describes a codebase changing under the
    // developer's hands, and a stale cached copy is worse than a recompile.
    Cache::flush();

    $this->withHeaders($this->secretHeader())->getJson('/_api-waypoint')->assertOk();

    expect(Cache::get(SchemaRepository::CACHE_KEY))->toBeNull();
});

it('compiles once per request and recompiles on the next one', function (): void {
    $repository = app(SchemaRepository::class);

    $first = $repository->document();

    // Within one request the document is memoised, so GET / does not pay for two
    // compiles just to set an ETag.
    expect($repository->document())->toBe($first);

    $this->travel(2)->seconds();

    // A new request gets a new repository, and therefore a fresh compile.
    expect(app(SchemaRepository::class)->fresh()->generatedAt())
        ->not->toBe($first->generatedAt());
});

it('compiles the document once per request, not once per read of it', function (): void {
    $response = $this->withHeaders($this->secretHeader())->getJson('/_api-waypoint')->assertOk();

    // ETag and body come from the same compile, so they cannot disagree.
    expect($response->headers->get('ETag'))->toBe('"'.$response->json('schema_hash').'"');
});

it('serves the manifest', function (): void {
    $response = $this->withHeaders($this->secretHeader())
        ->getJson('/_api-waypoint/manifest')
        ->assertOk()
        ->assertHeader('X-Api-Waypoint-Format', SchemaDocument::FORMAT);

    expect($response->json())->toHaveKeys([
        'schema_format_version', 'generated_at', 'schema_hash', 'application',
        'endpoints', 'data_objects', 'removed_since',
    ])->and($response->json('removed_since'))->toBeNull()
        ->and($response->json('application'))->toBe([
            'key' => 'acme-orders-api',
            'environment' => 'testing',
        ]);
});

it('keeps the manifest small', function (): void {
    $document = strlen((string) $this->withHeaders($this->secretHeader())->get('/_api-waypoint')->getContent());
    $manifest = strlen((string) $this->withHeaders($this->secretHeader())->get('/_api-waypoint/manifest')->getContent());

    expect($manifest)->toBeLessThan($document / 4);
});
