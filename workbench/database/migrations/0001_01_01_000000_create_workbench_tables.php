<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('email');
            $table->string('status')->default('active');
            // Present so the redaction tests have something real to try to leak.
            $table->string('password')->nullable();
            $table->string('remember_token')->nullable();
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->integer('price_cents');
            $table->boolean('is_active')->default(true);
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_id');
            $table->string('reference')->unique();
            $table->string('status')->default('draft');
            $table->string('channel')->default('web');
            $table->integer('total_cents')->default(0);
            $table->timestamp('placed_at')->nullable();
        });

        Schema::create('order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id');
            $table->foreignId('product_id');
            $table->integer('quantity');
            $table->integer('unit_price_cents')->nullable();
        });

        // "users" comes from Testbench's own default migrations; creating it here
        // as well would collide.
    }

    public function down(): void
    {
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('products');
        Schema::dropIfExists('customers');
    }
};
