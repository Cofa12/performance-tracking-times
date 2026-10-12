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

            // Server-side segments, milliseconds. double, not float: Laravel 10
            // turns float() into double(8, 2) on MySQL, which rounds and overflows.
            $table->double('delay_ms')->nullable();
            $table->double('boot_ms');
            $table->double('app_ms');
            $table->double('service_ms');
            $table->double('server_ms');
            $table->double('db_ms')->default(0);
            $table->unsignedInteger('db_count')->default(0);
            $table->json('queries')->nullable();
            $table->double('memory_peak_mb')->nullable();

            // Client-side segments, filled in by the client report.
            $table->double('calc_ms')->nullable();
            $table->double('connect_ms')->nullable();
            $table->double('network_out_ms')->nullable();
            $table->double('network_return_ms')->nullable();
            $table->double('clock_offset_ms')->nullable();
            $table->double('response_ms')->nullable();
            $table->string('network_split', 16)->nullable(); // "offset" | "symmetric"
            $table->timestamp('client_reported_at')->nullable();

            // With a default: MySQL servers running explicit_defaults_for_timestamp=OFF
            // otherwise give this column an invalid zero-date default and refuse the table.
            $table->timestamp('created_at', 6)->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('performance-tracking.table', 'performance_timings'));
    }
};
