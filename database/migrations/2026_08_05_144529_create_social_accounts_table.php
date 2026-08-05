<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('provider', 50);
            $table->string('provider_user_id');
            $table->string('email')->nullable();
            $table->text('avatar_url')->nullable();

            $table->timestamps();

            $table->unique([
                'provider',
                'provider_user_id',
            ]);

            $table->unique([
                'user_id',
                'provider',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};