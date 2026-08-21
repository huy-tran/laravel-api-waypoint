<?php

declare(strict_types=1);

use Hygo\ApiWaypoint\Tests\TestCase;

/**
 * Every guard failure is a 404 with Laravel's standard body. A 403 would confirm
 * the route exists, which is the one fact worth hiding.
 */
dataset('waypoint routes', [
    'schema' => ['GET', '/_api-waypoint'],
    'manifest' => ['GET', '/_api-waypoint/manifest'],
    'references' => ['GET', '/_api-waypoint/references/customers/uuid'],
    'scenarios index' => ['GET', '/_api-waypoint/scenarios'],
    'scenarios store' => ['POST', '/_api-waypoint/scenarios'],
    'tokens' => ['POST', '/_api-waypoint/tokens'],
]);

it('404s with no secret at all', function (string $method, string $uri): void {
    $this->json($method, $uri)
        ->assertNotFound()
        ->assertExactJson(['message' => 'Not Found.']);
})->with('waypoint routes');

it('404s with a wrong secret of the same length', function (string $method, string $uri): void {
    $wrong = str_repeat('x', strlen(TestCase::SECRET));

    expect(strlen($wrong))->toBe(strlen(TestCase::SECRET));

    $this->withHeaders($this->secretHeader($wrong))
        ->json($method, $uri)
        ->assertNotFound()
        ->assertExactJson(['message' => 'Not Found.']);
})->with('waypoint routes');

it('404s with a secret differing only in length', function (string $method, string $uri): void {
    $this->withHeaders($this->secretHeader(TestCase::SECRET.'x'))
        ->json($method, $uri)
        ->assertNotFound();

    $this->withHeaders($this->secretHeader(substr(TestCase::SECRET, 0, -1)))
        ->json($method, $uri)
        ->assertNotFound();
})->with('waypoint routes');

it('404s for an empty secret header', function (string $method, string $uri): void {
    $this->withHeaders($this->secretHeader(''))
        ->json($method, $uri)
        ->assertNotFound();
})->with('waypoint routes');

it('never answers a guard failure with 403', function (string $method, string $uri): void {
    $response = $this->withHeaders($this->secretHeader('nope'))->json($method, $uri);

    expect($response->getStatusCode())->toBe(404)
        ->and($response->getStatusCode())->not->toBe(403);
})->with('waypoint routes');

it('accepts the correct secret', function (): void {
    $this->withHeaders($this->secretHeader())
        ->getJson('/_api-waypoint')
        ->assertOk();
});
