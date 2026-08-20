<?php

declare(strict_types=1);

namespace Modules\Billing\Transformers;

use League\Fractal\TransformerAbstract;

class RefundTransformer extends TransformerAbstract
{
    /** @var array<int, string> */
    protected array $availableIncludes = ['order'];

    /** @var array<int, string> */
    protected array $defaultIncludes = [];

    /**
     * @param array<string, mixed> $refund
     * @return array<string, mixed>
     */
    public function transform(array $refund): array
    {
        return $refund;
    }
}
