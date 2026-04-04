<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add paid_at timestamp and enforce unique constraints on payments table.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Add paid_at timestamp if not already present
            if (! Schema::hasColumn('payments', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('last_synced_at');
            }
        });

        // Add unique index on merchant_order_id if not already present
        $this->addUniqueIfMissing('payments', 'merchant_order_id', 'payments_merchant_order_id_unique');

        // Add unique index on transaction_id if not already present
        $this->addUniqueIfMissing('payments', 'transaction_id', 'payments_transaction_id_unique');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Only drop indexes if they exist
            $sm       = Schema::getConnection()->getSchemaBuilder();
            $indexes  = $sm->getIndexListing('payments');

            if (in_array('payments_merchant_order_id_unique', $indexes)) {
                $table->dropUnique('payments_merchant_order_id_unique');
            }
            if (in_array('payments_transaction_id_unique', $indexes)) {
                $table->dropUnique('payments_transaction_id_unique');
            }
            if (Schema::hasColumn('payments', 'paid_at')) {
                $table->dropColumn('paid_at');
            }
        });
    }

    /**
     * Add a unique index only if it doesn't already exist.
     */
    private function addUniqueIfMissing(string $table, string $column, string $indexName): void
    {
        $indexes = Schema::getConnection()->getSchemaBuilder()->getIndexListing($table);

        if (! in_array($indexName, $indexes)) {
            Schema::table($table, fn (Blueprint $t) => $t->unique($column, $indexName));
        }
    }
};
