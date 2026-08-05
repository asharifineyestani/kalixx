<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_logs', function (Blueprint $table): void {
            $table->id();

            $table->string('mobile', 11);
            $table->string('ip', 45)->nullable();
            $table->string('code', 4);
            $table->timestamp('used_at')->nullable();

            $table->timestamps();

            $table->index([
                'mobile',
                'created_at',
            ]);

            $table->index('used_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_logs');
    }
};