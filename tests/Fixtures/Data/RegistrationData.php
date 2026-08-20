<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Tests\Fixtures\Data;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Exercises "confirmed", which implies a sibling the Data class never declares
 * but the endpoint rejects the payload without.
 */
#[MapInputName(SnakeCaseMapper::class)]
class RegistrationData extends Data
{
    public function __construct(
        public string $emailAddress,
        public string $password,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'email_address' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ];
    }
}
