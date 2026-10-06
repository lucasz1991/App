<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_interactions')) {
            Schema::create('customer_interactions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('customer_id');
                $table->unsignedBigInteger('contact_id')->nullable();
                $table->unsignedBigInteger('order_id')->nullable();
                $table->unsignedBigInteger('inquiry_id')->nullable();
                $table->string('channel', 16);
                $table->string('direction', 16);
                $table->dateTime('occurred_at');
                $table->string('timezone', 64);
                $table->string('subject', 180);
                $table->longText('body');
                $table->unsignedBigInteger('created_by');
                $table->timestamps();
                $table->foreign('customer_id', 'ci_customer_fk')->references('id')->on('customers')->restrictOnDelete();
                // Customer contacts are an optional module during staged rollout.
                if (Schema::hasTable('customer_contacts')) {
                    $table->foreign('contact_id', 'ci_contact_fk')->references('id')->on('customer_contacts')->restrictOnDelete();
                }
                $table->foreign('order_id', 'ci_order_fk')->references('id')->on('orders')->restrictOnDelete();
                if (Schema::hasTable('operation_inquiries')) {
                    $table->foreign('inquiry_id', 'ci_inquiry_fk')->references('id')->on('operation_inquiries')->restrictOnDelete();
                }
                $table->foreign('created_by', 'ci_creator_fk')->references('id')->on('users')->restrictOnDelete();
                $table->index(['customer_id', 'occurred_at', 'id'], 'ci_customer_occurred_idx');
                $table->index(['customer_id', 'channel'], 'ci_customer_channel_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('customer_interactions') && DB::table('customer_interactions')->exists()) {
            throw new RuntimeException('Kundenkommunikation bleibt erhalten; Rückbau benötigt einen geprüften Plan.');
        }
        Schema::dropIfExists('customer_interactions');
    }
};
