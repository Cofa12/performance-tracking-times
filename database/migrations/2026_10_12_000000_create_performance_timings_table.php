<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('performance-tracking.connection');
    }

    public function up(): void
    {
        Schema::create(config('performance-tracking.table', 'performance_timings'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('method', 10);
            $table->string('path', 1024);
            $table->string('route_name')->nullable();
            $table->unsignedSmallInteger('status');
            $table->string('ip', 45)->nullable();
            $table->string('user_id')->nullable();

            // Server clock, epoch milliseconds.
            $table->decimal('server_received_ms', 16, 3); // t1
            $table->decimal('server_sent_ms', 16, 3);     // t2

            // Server-side segments, milliseconds.
            $table->float('delay_ms')->nullable();
            $table->float('boot_ms');
            $table->float('app_ms');
            $table->float('service_ms');
            $table->float('server_ms');
            $table->float('db_ms')->default(0);
            $table->unsignedInteger('db_count')->default(0);
            $table->json('queries')->nullable();
            $table->float('memory_peak_mb')->nullable();

            // Client-side segments, filled in by the client report.
            $table->float('calc_ms')->nullable();
            $table->float('connect_ms')->nullable();
            $table->float('network_out_ms')->nullable();
            $table->float('network_return_ms')->nullable();
            $table->float('clock_offset_ms')->nullable();
            $table->float('response_ms')->nullable();
            $table->string('network_split', 16)->nullable(); // "offset" | "symmetric"
            $table->timestamp('client_reported_at')->nullable();

            $table->timestamp('created_at', 6)->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('performance-tracking.table', 'performance_timings'));
    }
};
