<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('gift-vouchers.database.connection');
    }

    public function up(): void
    {
        Schema::create('gift_vouchers', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // The code is kept hashed (to find it) and encrypted (to show it
            // in emails and the Control Panel), never as plain text.
            $table->char('code_hash', 64)->unique();
            $table->text('code_encrypted');
            $table->string('code_hint', 8);

            $table->char('currency', 3);
            $table->unsignedBigInteger('initial_amount');
            $table->unsignedBigInteger('balance');

            $table->string('site')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('disabled_at')->nullable();

            $table->string('recipient_name')->nullable();
            $table->string('recipient_email')->nullable()->index();
            $table->string('sender_name')->nullable();
            $table->text('message')->nullable();
            $table->date('send_on')->nullable();
            $table->timestamp('sent_at')->nullable();

            // Where it came from: an order's line item, or the Control Panel.
            $table->string('order_id')->nullable()->index();
            $table->string('line_item_id')->nullable();
            $table->unsignedInteger('sequence')->nullable();
            $table->string('product_id')->nullable();
            $table->string('created_by')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            // One voucher per unit of a line item, however often an order is
            // marked as paid.
            $table->unique(['order_id', 'line_item_id', 'sequence']);
        });

        Schema::create('gift_voucher_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('voucher_id')->constrained('gift_vouchers')->cascadeOnDelete();
            $table->string('type', 20);
            $table->bigInteger('amount');
            $table->unsignedBigInteger('balance_after');
            $table->string('order_id')->nullable()->index();
            $table->string('user_id')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['voucher_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_voucher_transactions');
        Schema::dropIfExists('gift_vouchers');
    }
};
