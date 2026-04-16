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
        // Update existing rows
        \Illuminate\Support\Facades\DB::table('users')->where('role', 'user')->update(['role' => 'staff']);

        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('staff')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('user')->change();
        });

        // Revert existing rows back to 'user'
        \Illuminate\Support\Facades\DB::table('users')->where('role', 'staff')->update(['role' => 'user']);
    }
};
