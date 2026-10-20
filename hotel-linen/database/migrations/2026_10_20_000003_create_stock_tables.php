<?php
// Склад и движение: остатки по локациям (количественный учёт), штучные вещи
// с инвентарным номером/QR (serialized, как assets в Snipe-IT), документы
// операций (приход/перемещение/прачечная/списание) со стадиями прачечной.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocks', function (Blueprint $t) {      // остаток = позиция×локация
            $t->id();
            $t->foreignId('item_id')->constrained()->cascadeOnDelete();
            $t->foreignId('location_id')->constrained()->cascadeOnDelete(); // склад/прачечная/списано; для номера — room_stocks
            $t->decimal('qty', 10, 2)->default(0);              // никогда не уходит в минус (проверка в сервисе)
            $t->unsignedBigInteger('version')->default(0);      // оптимистичная блокировка
            $t->unique(['item_id', 'location_id']);
            $t->timestamps();
        });

        Schema::create('room_stocks', function (Blueprint $t) { // остатки внутри номера
            $t->id();
            $t->foreignId('room_id')->constrained()->cascadeOnDelete();
            $t->foreignId('item_id')->constrained()->cascadeOnDelete();
            $t->decimal('qty', 10, 2)->default(0);
            $t->timestamps();
            $t->unique(['room_id', 'item_id']);
        });

        Schema::create('assets', function (Blueprint $t) {      // штучные вещи: ТВ, фен, сейф
            $t->id();
            $t->string('asset_tag')->unique();                  // инвентарный номер / QR payload
            $t->foreignId('item_id')->constrained();
            $t->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('location_id')->nullable()->constrained()->nullOnDelete(); // если вне номера
            $t->enum('status', ['in_use', 'laundry', 'repair', 'retired'])->default('in_use');
            $t->date('purchase_date')->nullable();
            $t->text('note')->nullable();
            $t->timestamps();
        });

        Schema::create('documents', function (Blueprint $t) {   // накладные/акты
            $t->id();
            $t->string('number')->unique();                     // ПН-2026-0001 ...
            $t->enum('kind', ['receipt', 'transfer', 'laundry_out', 'laundry_in', 'write_off',
                              'inventory', 'acceptance', 'readiness', 'issue']);
            $t->date('date');
            $t->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $t->string('doc_external')->nullable();             // входящий номер документа поставщика
            $t->foreignId('from_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $t->foreignId('to_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $t->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $t->enum('laundry_stage')->nullable()
              ->comment('dirty|washing|drying|control|clean');  // стадии прачечной
            $t->enum('status', ['draft', 'posted', 'approved', 'void'])->default('draft');
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->text('note')->nullable();
            $t->json('meta')->nullable();
            $t->timestamps();
        });

        Schema::create('document_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained()->cascadeOnDelete();
            $t->foreignId('item_id')->constrained();
            $t->decimal('qty', 10, 2);
            $t->decimal('price', 10, 2)->default(0);
            $t->foreignId('reason_id')->nullable()->constrained()->nullOnDelete(); // обязательна при списании
            $t->text('comment')->nullable();
        });

        Schema::create('asset_movements', function (Blueprint $t) { // история по каждой штучной единице
            $t->id();                                              // (аналог action_logs Snipe-IT для asset)
            $t->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $t->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->string('event');                    // checkout|checkin|transfer|laundry|write_off
            $t->string('from_label')->nullable();
            $t->string('to_label')->nullable();
            $t->string('reason_code')->nullable();
            $t->text('reason')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('checklist_results', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained()->cascadeOnDelete();
            $t->string('group');
            $t->string('text');
            $t->boolean('passed')->default(true);
            $t->text('comment')->nullable();
        });

        Schema::create('photos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained()->cascadeOnDelete();
            $t->string('path');
            $t->string('caption')->nullable();
            $t->foreignId('uploaded_by')->nullable()->constrained('users');
            $t->timestamps();
        });

        Schema::create('issues', function (Blueprint $t) {       // заявки по номерам
            $t->id();
            $t->foreignId('room_id')->constrained()->cascadeOnDelete();
            $t->enum('kind', ['linen', 'equipment', 'repair', 'cleaning', 'lost_item']);
            $t->string('title');
            $t->text('description')->nullable();
            $t->enum('status', ['open', 'in_progress', 'closed'])->default('open');
            $t->foreignId('reported_by')->constrained('users');
            $t->foreignId('closed_by')->nullable()->constrained('users');
            $t->text('close_comment')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
        });

        Schema::create('inventory_counts', function (Blueprint $t) { // строки инвентаризации: снимок vs факт
            $t->id();
            $t->foreignId('document_id')->constrained()->cascadeOnDelete();
            $t->foreignId('item_id')->constrained();
            $t->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('book_qty', 10, 2);        // учёт на момент создания (снимок)
            $t->decimal('fact_qty', 10, 2)->nullable(); // факт
            $t->decimal('diff_qty', 10, 2)->nullable(); // факт − снимок
            $t->foreignId('reason_id')->nullable()->constrained()->nullOnDelete(); // причина расхождения
            $t->boolean('applied')->default(false);// корректировка применена при утверждении
        });
    }

    public function down(): void
    {
        foreach (['inventory_counts', 'issues', 'photos', 'checklist_results',
                  'asset_movements', 'document_lines', 'documents', 'assets',
                  'room_stocks', 'stocks'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
