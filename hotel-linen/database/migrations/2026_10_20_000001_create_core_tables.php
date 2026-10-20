<?php
// Структура ролей/прав и аудита вдохновлена Snipe-IT (AGPL-3.0, snipe/snipe-it):
// permissions role↔ability, action_logs как append-only журнал действий по единице.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();      // admin | manager | storekeeper | supervisor | maid | reception
            $t->string('title');               // отображаемое имя
            $t->boolean('is_mobile_only')->default(false); // горничная работает с телефона
            $t->timestamps();
        });

        Schema::add('users', ['role_id' => 'unsignedBigInteger null']);
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('role_id')->nullable()->constrained('roles');
            $t->boolean('is_active')->default(true);
            $t->timestamp('last_login_at')->nullable();
        });

        // права по действиям (проверка на сервере), как permissions в Snipe-IT
        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('role_name');   // связь по имени роли — гибко при переименовании
            $t->string('ability');     // напр. inventory.approve, stock.receive
            $t->unique(['role_name', 'ability']);
        });

        // append-only журнал: кто, когда, что, было, стало, почему
        Schema::create('action_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable();
            $t->string('role_name')->nullable();
            $t->string('device')->nullable();          // mobile | desktop
            $t->string('subject_type')->nullable();    // класс модели
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->string('event');                       // create/update/move/write_off/login/...
            $t->string('object_label')->nullable();    // человекочитаемо: «Полотенце 70×140 ×4»
            $t->json('old_values')->nullable();        // было
            $t->json('new_values')->nullable();        // стало
            $t->text('reason')->nullable();            // почему
            $t->string('reason_code')->nullable();     // ссылка на справочник причин
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index(['subject_type', 'subject_id']);
            $t->index(['user_id', 'created_at']);
        });

        Schema::create('settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->text('value')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('action_logs');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('users');
        Schema::dropIfExists('roles');
    }
};
