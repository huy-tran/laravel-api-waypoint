<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Compiler\Faker;

/**
 * The closed x-faker strategy vocabulary and its version.
 *
 * The package never names a generator library method. It emits an abstract
 * strategy and the Central App maps that to whatever it generates with, which is
 * what keeps this package free of any knowledge of the consumer.
 *
 * The compiler's own test suite fails if a resolver emits a strategy absent from
 * STRATEGIES, so this list and the emitted document cannot drift apart.
 */
final class StrategyVocabulary
{
    public const VERSION = '1.0';

    /**
     * Declared by resources/schema/api-waypoint-1.0.json.
     *
     * @var array<int, string>
     */
    public const CORE = [
        'reference',
        'enum',
        'pattern',
        'int',
        'float',
        'boolean',
        'sentence',
        'paragraph',
        'person.firstName',
        'person.lastName',
        'internet.email',
        'phone',
        'date',
        'date_range',
        'uuid',
        'url',
        'collection',
        'key_value_map',
        'alphanumeric',
        'timezone',
        'mirror',
        'distinct_from',
        'unresolvable',
    ];

    /**
     * Concrete members of the contract's "address.*" wildcard, plus the few names
     * the shipped heuristics need that the wildcard does not cover.
     *
     * A Central App built against an earlier vocabulary falls back to generating by
     * JSON Schema type when it meets one of these, per the contract's note that an
     * unknown strategy must degrade rather than throw.
     *
     * @var array<int, string>
     */
    public const EXTENSIONS = [
        'address.street',
        'address.city',
        'address.state',
        'address.postcode',
        'address.country',
        'company.name',
        'slug',
        'latitude',
        'longitude',
    ];

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return array_merge(self::CORE, self::EXTENSIONS);
    }

    public static function knows(string $strategy): bool
    {
        return in_array($strategy, self::all(), true);
    }

    /**
     * Shipped property-name heuristics, level 7 of the FakerHintResolver chain.
     * Australian defaults where a locale choice is unavoidable, since that is the
     * host applications this package was written for.
     *
     * Merged under config('api-waypoint.faker.name_hints'), so an app overrides a
     * single name without restating the map.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function defaultNameHints(): array
    {
        return [
            'email' => ['strategy' => 'internet.email'],
            'email_address' => ['strategy' => 'internet.email'],
            'first_name' => ['strategy' => 'person.firstName'],
            'given_name' => ['strategy' => 'person.firstName'],
            'last_name' => ['strategy' => 'person.lastName'],
            'surname' => ['strategy' => 'person.lastName'],
            'family_name' => ['strategy' => 'person.lastName'],
            'name' => ['strategy' => 'person.lastName'],
            'phone' => ['strategy' => 'phone'],
            'phone_number' => ['strategy' => 'phone'],
            'mobile' => ['strategy' => 'phone'],
            'telephone' => ['strategy' => 'phone'],
            'abn' => ['strategy' => 'pattern', 'pattern' => '###########'],
            'acn' => ['strategy' => 'pattern', 'pattern' => '#########'],
            'postcode' => ['strategy' => 'address.postcode', 'pattern' => '####'],
            'post_code' => ['strategy' => 'address.postcode', 'pattern' => '####'],
            'suburb' => ['strategy' => 'address.city'],
            'city' => ['strategy' => 'address.city'],
            'state' => ['strategy' => 'address.state', 'values' => ['ACT', 'NSW', 'NT', 'QLD', 'SA', 'TAS', 'VIC', 'WA']],
            'country' => ['strategy' => 'address.country', 'values' => ['AU', 'NZ', 'GB', 'US']],
            'company' => ['strategy' => 'company.name'],
            'company_name' => ['strategy' => 'company.name'],
            'business_name' => ['strategy' => 'company.name'],
            'street' => ['strategy' => 'address.street'],
            'address_line_1' => ['strategy' => 'address.street'],
            'url' => ['strategy' => 'url'],
            'website' => ['strategy' => 'url'],
            'slug' => ['strategy' => 'slug'],
            'title' => ['strategy' => 'sentence', 'max' => 60],
            'description' => ['strategy' => 'paragraph'],
            'notes' => ['strategy' => 'sentence'],
            'comment' => ['strategy' => 'sentence'],
            // Identifier-shaped fields. Suffix matching means purchase_order_no
            // reaches "no", and external_reference reaches "reference".
            'no' => ['strategy' => 'alphanumeric', 'length' => 10],
            'number' => ['strategy' => 'alphanumeric', 'length' => 10],
            'code' => ['strategy' => 'alphanumeric', 'length' => 8],
            'reference' => ['strategy' => 'alphanumeric', 'length' => 10],
            'price_cents' => ['strategy' => 'int', 'min' => 100, 'max' => 500000],
            'amount_cents' => ['strategy' => 'int', 'min' => 100, 'max' => 500000],
            'total_cents' => ['strategy' => 'int', 'min' => 100, 'max' => 500000],
            'quantity' => ['strategy' => 'int', 'min' => 1, 'max' => 5],
            'qty' => ['strategy' => 'int', 'min' => 1, 'max' => 5],
            'latitude' => ['strategy' => 'latitude', 'min' => -44, 'max' => -10],
            'longitude' => ['strategy' => 'longitude', 'min' => 112, 'max' => 154],
            'timezone' => ['strategy' => 'timezone'],
            'uuid' => ['strategy' => 'uuid'],
        ];
    }
}
