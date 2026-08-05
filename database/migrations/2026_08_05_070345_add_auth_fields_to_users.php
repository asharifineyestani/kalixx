<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('mobile', 11)
                ->nullable()
                ->unique()
                ->after('id');

            $table->timestamp('mobile_verified_at')
                ->nullable()
                ->after('mobile');

            $table->string('email')
                ->nullable()
                ->change();

            $table->string('password')
                ->nullable()
                ->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_mobile_unique');

            $table->dropColumn([
                'mobile_verified_at',
                'mobile',
            ]);

            $table->string('email')
                ->nullable(false)
                ->change();

            $table->string('password')
                ->nullable(false)
                ->change();
        });
    }
};
