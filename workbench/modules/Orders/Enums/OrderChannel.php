<?php

declare(strict_types=1);

namespace Modules\Orders\Enums;

enum OrderChannel: string
{
    case Web = 'web';
    case Phone = 'phone';
    case InStore = 'in_store';
}
