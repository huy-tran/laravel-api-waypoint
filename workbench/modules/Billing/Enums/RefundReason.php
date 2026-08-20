<?php

declare(strict_types=1);

namespace Modules\Billing\Enums;

enum RefundReason: string
{
    case Duplicate = 'duplicate';
    case Fraudulent = 'fraudulent';
    case RequestedByCustomer = 'requested_by_customer';
    case GoodsReturned = 'goods_returned';
}
