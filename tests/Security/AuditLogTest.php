<?php

declare(strict_types=1);

use Hygo\ApiWaypoint\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

uses(RefreshDatabase::class);

/**
 * One audit line per waypoint request. Cheap, and it makes "who seeded 400
 * orders" answerable.
 */
function captureAuditLog(): ArrayObject
{
    // An ArrayObject rather than an array, so the collector is shared by reference
    // with the closure the logger calls.
    $captured = new ArrayObject;

    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('info')
        ->andReturnUsing(static function (string $message, array $context) use ($captured): void {
            $captured->append([$message, $context]);
        });
    $logger->shouldIgnoreMissing();

    Log::shouldReceive('channel')->andReturn($logger);

    return $captured;
}

it('writes one line per request, with the route and the status', function (): void {
    $captured = captureAuditLog();

    $this->withHeaders($this->secretHeader())->getJson('/_api-waypoint')->assertOk();

    expect($captured->count())->toBe(1)
        ->and($captured[0][0])->toBe('api-waypoint')
        ->and($captured[0][1])->toMatchArray([
            'route' => 'api-waypoint.schema',
            'method' => 'GET',
            'status' => 200,
        ]);
});

it('identifies the actor by a fingerprint, never by the secret itself', function (): void {
    $captured = captureAuditLog();

    $this->withHeaders($this->secretHeader())->getJson('/_api-waypoint')->assertOk();

    $actor = $captured[0][1]['actor'];

    expect($actor)->toHaveLength(8)
        ->and($actor)->toBe(substr(hash('sha256', TestCase::SECRET), 0, 8))
        ->and(json_encode($captured->getArrayCopy()))->not->toContain(TestCase::SECRET);
});

it('records the scenario name on a scenario run', function (): void {
    $captured = captureAuditLog();

    $this->withHeaders($this->secretHeader())
        ->postJson('/_api-waypoint/scenarios', ['scenario' => 'paid_order'])
        ->assertCreated();

    expect($captured[0][1]['scenario'])->toBe('paid_order');
});

it('records the role on a token mint', function (): void {
    $captured = captureAuditLog();

    $this->withHeaders($this->secretHeader())
        ->postJson('/_api-waypoint/tokens', ['role' => 'admin'])
        ->assertOk();

    expect($captured[0][1]['role'])->toBe('admin');
});

it('records the table and column on a reference lookup', function (): void {
    $captured = captureAuditLog();

    $this->withHeaders($this->secretHeader())
        ->getJson('/_api-waypoint/references/customers/uuid')
        ->assertOk();

    expect($captured[0][1])->toMatchArray(['table' => 'customers', 'column' => 'uuid']);
});

it('writes no line for a request the secret guard rejected', function (): void {
    $captured = captureAuditLog();

    // The guard runs first, so a probe cannot fill the log with noise.
    $this->withHeaders($this->secretHeader('wrong'))->getJson('/_api-waypoint')->assertNotFound();

    expect($captured->count())->toBe(0);
});
