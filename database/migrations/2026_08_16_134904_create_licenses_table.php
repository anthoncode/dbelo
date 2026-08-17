<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licenses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('version', 20)->default('1.0');
            $table->text('summary');
            $table->longText('full_text')->nullable();

            $table->boolean('requires_attribution')->default(false);
            $table->boolean('allows_commercial')->default(true);
            $table->boolean('allows_derivatives')->default(true);

            $table->string('url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licenses');
    }
};
