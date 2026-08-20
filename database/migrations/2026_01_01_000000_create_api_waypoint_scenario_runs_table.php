<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records what each scenario run created, so DELETE /scenarios/{cleanup_token}
 * can undo it in reverse creation order.
 *
 * Without this, repeated scenario runs silt up the development database until
 * somebody wipes it, taking their hand-made fixtures with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_waypoint_scenario_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('cleanup_token')->unique();
            $table->string('scenario');
            $table->json('parameters')->nullable();
            $table->json('records');
            $table->string('actor')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('cleaned_up_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_waypoint_scenario_runs');
    }
};
