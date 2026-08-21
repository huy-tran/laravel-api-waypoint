<?php

declare(strict_types=1);

use Hygo\ApiWaypoint\Compiler\Faker\StrategyVocabulary;
use Hygo\ApiWaypoint\Compiler\SchemaCompiler;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Validates the compiled document against the meta-schema of the contract itself.
 *
 * This is what keeps two independently built codebases honest: the package and
 * the Central App both have something objective to test against, rather than each
 * testing its own reading of a prose document.
 */
const META_SCHEMA = __DIR__.'/../../resources/schema/api-waypoint-1.0.json';

function validateAgainstContract(array $document): array
{
    $validator = new Validator;
    $validator->setMaxErrors(50);

    $result = $validator->validate(
        json_decode((string) json_encode($document), false, 512, JSON_THROW_ON_ERROR),
        (string) file_get_contents(META_SCHEMA),
    );

    if ($result->isValid()) {
        return [];
    }

    $formatted = (new ErrorFormatter)->format($result->error(), true);

    return array_map(
        static fn (string $path, array $messages): string => $path.': '.implode('; ', $messages),
        array_keys($formatted),
        $formatted,
    );
}

it('ships a meta-schema that is itself valid JSON', function (): void {
    expect(META_SCHEMA)->toBeReadableFile();

    $decoded = json_decode((string) file_get_contents(META_SCHEMA), true);

    expect($decoded)->toBeArray()
        ->and($decoded['$id'])->toContain('api-waypoint-1.0');
});

it('compiles a document that validates against the contract', function (): void {
    $errors = validateAgainstContract(app(SchemaCompiler::class)->compile()->toArray());

    expect($errors)->toBe([], "Document does not conform:\n".implode("\n", $errors));
});

it('serves a document over HTTP that validates against the contract', function (): void {
    $response = $this->withHeaders($this->secretHeader())
        ->getJson('/_api-waypoint')
        ->assertOk();

    $errors = validateAgainstContract($response->json());

    expect($errors)->toBe([], "Response does not conform:\n".implode("\n", $errors));
});

it('rejects a document claiming to come from production', function (): void {
    $document = app(SchemaCompiler::class)->compile()->toArray();
    $document['application']['environment'] = 'production';

    // The meta-schema encodes the safety rule too: a waypoint document from
    // production is invalid by definition.
    expect(validateAgainstContract($document))->not->toBe([]);
});

it('rejects an unresolvable hint with no reason', function (): void {
    $document = app(SchemaCompiler::class)->compile()->toArray();
    $document['components']['data_objects']['Orders.CreateOrderData']['properties']['notes']['x-faker'] = [
        'strategy' => 'unresolvable',
    ];

    // Saying a field cannot be generated is the feature. Saying it without saying
    // why is not, so the contract requires the reason.
    expect(validateAgainstContract($document))->not->toBe([]);
});

it('rejects a strategy outside the closed vocabulary', function (): void {
    $document = app(SchemaCompiler::class)->compile()->toArray();
    $document['components']['data_objects']['Orders.CreateOrderData']['properties']['notes']['x-faker'] = [
        'strategy' => 'faker.lorem.sentence',
    ];

    expect(validateAgainstContract($document))->not->toBe([]);
});

it('emits only strategies the vocabulary knows', function (): void {
    $document = app(SchemaCompiler::class)->compile()->toArray();

    $strategies = [];

    array_walk_recursive($document, static function ($value, $key) use (&$strategies): void {
        if ($key === 'strategy') {
            $strategies[] = $value;
        }
    });

    expect($strategies)->not->toBeEmpty();

    foreach (array_unique($strategies) as $strategy) {
        expect(StrategyVocabulary::knows($strategy))
            ->toBeTrue("[{$strategy}] is not in the closed vocabulary");
    }
});

it('serves a manifest whose hashes agree with the document', function (): void {
    $document = $this->withHeaders($this->secretHeader())->getJson('/_api-waypoint')->json();
    $manifest = $this->withHeaders($this->secretHeader())->getJson('/_api-waypoint/manifest')->json();

    expect($manifest['schema_hash'])->toBe($document['schema_hash'])
        ->and($manifest['schema_format_version'])->toBe($document['schema_format_version']);

    foreach ($document['endpoints'] as $endpoint) {
        expect($manifest['endpoints'][$endpoint['id']])->toBe($endpoint['hash']);
    }

    foreach ($document['components']['data_objects'] as $name => $component) {
        expect($manifest['data_objects'][$name])->toBe($component['x-laravel']['hash']);
    }
});
