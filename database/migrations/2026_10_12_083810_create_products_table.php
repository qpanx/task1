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
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description');
            $table->decimal('price', 12, 2);
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->boolean('available')->default(false);
            $table->timestamps();

            $table->index(['price', 'id']);
            $table->index(['created_at', 'id']);
            $table->index(['category_id', 'price', 'id']);
            $table->index(['category_id', 'created_at', 'id']);
            $table->index(['available', 'price', 'id']);
            $table->index(['available', 'created_at', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
