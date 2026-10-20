<?php
// Справочники: номера, номенклатура, шаблоны комплектации и чек-листов,
// поставщики, причины списания/расхождений. Часть полей — как в Snipe-IT
// (supplier, model, category), адаптирована под бельё отеля.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->enum('kind', ['warehouse', 'laundry', 'discard', 'other'])->default('other');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('buildings', function (Blueprint $t) {   // корпуса
            $t->id();
            $t->string('name');
            $t->timestamps();
        });

        Schema::create('rooms', function (Blueprint $t) {       // номера
            $t->id();
            $t->foreignId('building_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('floor');
            $t->string('number');                               // «1204»
            $t->string('category')->default('standard');        // стандарт / люкс ...
            $t->unsignedTinyInteger('capacity')->default(2);    // вместимость
            $t->enum('status', ['DIRTY', 'CLEANING', 'INSPECTION', 'READY', 'OOO'])
              ->default('DIRTY');                              // OOO = выведен из эксплуатации
            $t->boolean('linen_problem')->default(false);       // «проблема белья»
            $t->boolean('broken')->default(false);              // «неисправен»
            $t->foreignId('template_id')->nullable();           // шаблон комплектации
            $t->foreignId('maid_id')->nullable()->constrained('users')->nullOnDelete();
            $t->text('note')->nullable();
            $t->timestamps();
            $t->unique(['building_id', 'number']);
        });

        Schema::create('categories', function (Blueprint $t) {  // бельё/оборудование/мебель/расходники
            $t->id();
            $t->string('name');
            $t->enum('tracking', ['quantitative', 'serialized'])
              ->default('quantitative'); // бельё — количественно, ТВ/фен/сейф — по инв. номеру/QR
            $t->boolean('washable')->default(false);            // проходит через стадии прачечной
            $t->timestamps();
        });

        Schema::create('items', function (Blueprint $t) {       // номенклатура
            $t->id();
            $t->foreignId('category_id')->constrained();
            $t->string('name');
            $t->string('unit')->default('шт');                  // единица измерения
            $t->decimal('norm_per_room', 8, 2)->default(0);     // норма на номер
            $t->decimal('par_level', 8, 2)->default(3);         // PAR (циклы комплектов)
            $t->unsignedInteger('min_qty')->default(0);
            $t->unsignedInteger('max_qty')->default(0);
            $t->unsignedSmallInteger('life_months')->nullable();// срок службы, мес
            $t->decimal('price', 10, 2)->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index(['category_id', 'name']);
        });

        Schema::create('templates', function (Blueprint $t) {   // шаблоны комплектации номера
            $t->id();
            $t->string('name');
            $t->text('note')->nullable();
            $t->timestamps();
        });

        Schema::create('template_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('template_id')->constrained()->cascadeOnDelete();
            $t->foreignId('item_id')->constrained();
            $t->decimal('qty', 8, 2);
        });

        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('contact')->nullable();
            $t->string('phone')->nullable();
            $t->timestamps();
        });

        Schema::create('reasons', function (Blueprint $t) {     // причины списания и расхождений
            $t->id();
            $t->string('code')->unique();       // guest_took, torn, laundry_loss, acceptance_error, not_returned...
            $t->string('title');
            $t->enum('kind', ['write_off', 'discrepancy', 'both']);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('checklist_templates', function (Blueprint $t) { // шаблоны чек-листов
            $t->id();
            $t->string('name');
            $t->enum('kind', ['checkout_acceptance', 'pre_arrival_readiness']);
            $t->json('points');                 // [{group:'Бельё', text:'Простыни на месте'}]
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('shifts', function (Blueprint $t) {      // смены и назначение номеров
            $t->id();
            $t->date('date');
            $t->enum('period', ['morning', 'evening'])->default('morning');
            $t->foreignId('maid_id')->constrained('users')->cascadeOnDelete();
            $t->timestamps();
        });

        Schema::create('shift_rooms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $t->foreignId('room_id')->constrained()->cascadeOnDelete();
            $t->enum('task_kind', ['clean', 'checkout_acceptance', 'deep_clean'])->default('clean');
            $t->unique(['shift_id', 'room_id']);
        });
    }

    public function down(): void
    {
        foreach (['shift_rooms', 'shifts', 'checklist_templates', 'reasons', 'suppliers',
                  'template_lines', 'templates', 'items', 'categories', 'rooms',
                  'buildings', 'locations'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
