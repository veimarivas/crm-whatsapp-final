<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('default_currency', 3)->default('BOB')->change();
        });

        DB::table('accounts')->where('default_currency', 'USD')->update(['default_currency' => 'BOB']);
    }

    public function down(): void
    {
        DB::table('accounts')->where('default_currency', 'BOB')->update(['default_currency' => 'USD']);

        Schema::table('accounts', function (Blueprint $table) {
            $table->string('default_currency', 3)->default('USD')->change();
        });
    }
};
