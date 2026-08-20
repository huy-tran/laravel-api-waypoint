<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Billing\Actions\RefundOrder;
use Modules\Orders\Actions\AttachDocument;
use Modules\Orders\Actions\CreateOrder;
use Modules\Orders\Actions\ListOrders;
use Modules\Orders\Actions\ShowOrder;
use Modules\Reporting\Actions\ExportReport;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum'])
    ->name('api.v1.')
    ->group(function (): void {
        Route::get('orders', ListOrders::class)->name('orders.index');
        Route::post('orders', CreateOrder::class)->name('orders.store');
        Route::get('orders/{order}', ShowOrder::class)->name('orders.show');

        Route::post('orders/{order}/refund', RefundOrder::class)
            ->middleware('can:refund,order')
            ->name('orders.refund');

        Route::post('orders/{order}/attachments', AttachDocument::class)->name('orders.attachments.store');

        Route::post('reports/export', ExportReport::class)->name('reports.export');

        // Unnamed on purpose: the compiler must derive an id and warn about it.
        Route::get('health/ping', fn (): array => ['ok' => true]);
    });
