<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tgs_demos', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamp('provisional_at')->nullable();
            $table->timestamps();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('tgs_demos');
    }
};
