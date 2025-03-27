<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->string('payment_method')->nullable()->after('type');
        });
    }

    public function down()
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
    }
};
