<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fda_products', function (Blueprint $table) {
            $table->id();
            $table->string('product_id', 150)->unique();
            $table->string('product_ndc', 50)->index();
            $table->text('generic_name')->nullable();
            $table->text('brand_name')->nullable();
            $table->text('brand_name_base')->nullable();
            $table->string('labeler_name')->nullable();
            $table->string('marketing_category', 100)->nullable();
            $table->string('application_number', 50)->nullable();
            $table->string('dosage_form', 100)->nullable();
            $table->string('product_type', 100)->nullable();
            $table->string('dea_schedule', 10)->nullable();
            $table->boolean('finished')->default(true);
            $table->date('marketing_start_date')->nullable();
            $table->date('listing_expiration_date')->nullable();
            $table->string('spl_id', 100)->nullable();
            $table->string('spl_set_id', 100)->nullable();
            $table->timestamps();
        });

        Schema::create('fda_product_active_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id_fk')->constrained('fda_products')->cascadeOnDelete();
            $table->text('name');
            $table->string('strength', 100)->nullable();
        });

        Schema::create('fda_product_packaging', function (Blueprint $table) {
            $table->string('package_ndc', 50)->primary();
            $table->foreignId('product_id_fk')->constrained('fda_products')->cascadeOnDelete();
            $table->text('description')->nullable();
            $table->date('marketing_start_date')->nullable();
            $table->boolean('is_sample')->default(false);
        });

        Schema::create('fda_product_pharm_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id_fk')->constrained('fda_products')->cascadeOnDelete();
            $table->string('class_name');
            $table->unique(['product_id_fk', 'class_name'], 'uq_prod_pharm');
        });

        Schema::create('fda_product_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id_fk')->constrained('fda_products')->cascadeOnDelete();
            $table->string('route_name', 100);
            $table->unique(['product_id_fk', 'route_name'], 'uq_prod_route');
        });

        Schema::create('fda_product_trading_partner', function (Blueprint $table) {
            $table->foreignId('fda_product_id')->constrained('fda_products')->cascadeOnDelete();
            $table->foreignId('trading_partner_id')->constrained('catalog_trading_partners')->cascadeOnDelete();
            $table->primary(['fda_product_id', 'trading_partner_id']);
        });

        Schema::create('fda_wdd_3pl_staging', function (Blueprint $table) {
            $table->id();
            $table->string('facility_name')->nullable();
            $table->string('alternate_name')->nullable();
            $table->string('street_address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 2)->nullable();
            $table->string('zip', 20)->nullable();
            $table->string('contact_person')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('facility_type', 50)->nullable();
            $table->string('license_number', 100)->nullable();
            $table->string('license_state', 2)->nullable();
            $table->string('expiration_date', 50)->nullable();
            $table->unsignedInteger('reporting_year')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fda_wdd_3pl_staging');
        Schema::dropIfExists('fda_product_trading_partner');
        Schema::dropIfExists('fda_product_routes');
        Schema::dropIfExists('fda_product_pharm_classes');
        Schema::dropIfExists('fda_product_packaging');
        Schema::dropIfExists('fda_product_active_ingredients');
        Schema::dropIfExists('fda_products');
    }
};
