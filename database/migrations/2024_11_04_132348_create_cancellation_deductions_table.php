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

        Schema::create('cancellation_policies', function (Blueprint $table) {

            $table->id();
            $table->string('name');
            $table->text('description');
            $table->string('type')->comment("transfer,flight,tour,hotel");
            $table->text("cancellation_policies_meta");
            $table->unsignedInteger("user_id")->foreign(\App\Models\User::class)->constrained()->onDelete('cascade');
            $table->boolean('active')->default(1);
            $table->timestamps();
        });


        Schema::create('cancellation_deductions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger("cancellation_policy_id")->foreign(\App\Models\CancellationPolicies::class)->constrained()->onDelete('cascade');
            $table->decimal("deduction", 10, 2);
            $table->unsignedInteger("service_id");
            $table->string("service_type")->comment("1:transfer,2:flight,3:tour,4:hotel");
            $table->unsignedInteger("user_id")->foreign(\App\Models\User::class)->constrained()->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cancellation_policies');
        Schema::dropIfExists('cancellation_deductions');
    }
};
