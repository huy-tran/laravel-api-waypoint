<?php

declare(strict_types=1);

namespace Hygo\ApiWaypoint\Compiler\Response;

/**
 * The canonical error bodies every Laravel API produces, defined once under
 * components.responses and referenced from each endpoint.
 *
 * These are static because they are framework behaviour, not application
 * behaviour: the 422 shape is Laravel's, not the host app's.
 */
final class ErrorResponses
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            'ValidationError' => [
                'type' => 'object',
                'required' => ['message', 'errors'],
                'properties' => [
                    'message' => ['type' => 'string'],
                    'errors' => [
                        'type' => 'object',
                        'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
                'example' => [
                    'message' => 'The channel field must be one of: web, phone, in_store.',
                    'errors' => [
                        'channel' => ['The selected channel is invalid.'],
                        'lines.0.product_id' => ['The selected product id is invalid.'],
                    ],
                ],
            ],
            'Unauthenticated' => [
                'type' => 'object',
                'required' => ['message'],
                'properties' => ['message' => ['type' => 'string']],
                'example' => ['message' => 'Unauthenticated.'],
            ],
            'Forbidden' => [
                'type' => 'object',
                'required' => ['message'],
                'properties' => ['message' => ['type' => 'string']],
                'example' => ['message' => 'This action is unauthorized.'],
            ],
            'NotFound' => [
                'type' => 'object',
                'required' => ['message'],
                'properties' => ['message' => ['type' => 'string']],
                'example' => ['message' => 'Not Found.'],
            ],
            'DomainConflict' => [
                'type' => 'object',
                'required' => ['message'],
                'properties' => [
                    'message' => ['type' => 'string'],
                    'code' => ['type' => 'string'],
                ],
                'example' => ['message' => 'Order is not in a refundable state.', 'code' => 'order.not_refundable'],
            ],
            'QueryBuilderError' => [
                'type' => 'object',
                'required' => ['message'],
                'properties' => ['message' => ['type' => 'string']],
                'example' => [
                    'message' => 'Requested filter(s) `customer_email` are not allowed. '
                        .'Allowed filter(s) are `status, reference, customer.name`.',
                ],
            ],
        ];
    }
}
