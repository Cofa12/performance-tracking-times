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
        Schema::table(config('performance-tracking.table', 'performance_timings'), function (Blueprint $table) {
            // Streamed and file responses: the body is produced after t2.
            $table->boolean('streamed')->default(false);
            $table->double('stream_ms')->nullable();

            // The client only knew when the headers arrived, not the body.
            $table->boolean('headers_only')->default(false);

            // Dashboard ordering and filters.
            $table->index('server_received_ms');
            $table->index(['status', 'server_received_ms']);
        });
    }

    public function down(): void
    {
        Schema::table(config('performance-tracking.table', 'performance_timings'), function (Blueprint $table) {
            $table->dropIndex(['status', 'server_received_ms']);
            $table->dropIndex(['server_received_ms']);
            $table->dropColumn(['streamed', 'stream_ms', 'headers_only']);
        });
    }
};
