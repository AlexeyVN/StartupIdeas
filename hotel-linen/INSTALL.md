# LinenTrack (hotel-linen) — Инструкция по установке и настройке

Единая веб-система гостиницы: учёт белья и вещей номера, история изменений, причины расхождений.
Стек: **PHP 8.2+, Laravel 12, PostgreSQL** (для локального хостинга допускается SQLite), Nginx/Apache, PWA.

Роли: **Администратор** (пользователи, справочники, настройки, журнал), **Менеджер** (склад, приёмка, инвентаризации, отчёты; частично — роли «кладовщик» и «супервайзер»), **Горничная** (только назначенные номера).

---

## Содержание
1. [Требования](#1-требования)
2. [Вариант A — установка на Wamp/OpenServer (Windows, локальный хостинг)](#2-вариант-a--установка-na-wampopenserver-windows)
3. [Вариант B — установка на Linux-хостинг (Nginx + PHP-FPM + PostgreSQL)](#3-вариант-b--установка-na-linux-хостинг-nginx--php-fpm--postgresql)
4. [Вариант C — Docker](#4-вариант-c--docker)
5. [Настройка .env](#5-настройка-env)
6. [Первичная инициализация БД](#6-первичная-инициализация-бд)
7. [Права доступа к каталогам](#7-права-доступа-к-каталогам)
8. [Веб-сервер: Apache (Wamp/OpenServer) и Nginx](#8-веб-сервер)
9. [HTTPS](#9-https)
10. [PWA и работа горничной с телефона/планшета](#10-pwa-и-работа-горничной)
11. [Резервные копии (backup)](#11-резервные-копии-backup)
12. [Интеграции (PMS, штрихкоды/QR, мессенджер)](#12-интеграции)
13. [Тесты](#13-тесты)
14. [Обновление версии](#14-обновление-версии)
15. [Устранение неполадок](#15-устранение-неполадок)

---

## 1. Требования

| Компонент | Минимум | Проверка |
|---|---|---|
| PHP | 8.2+ (расширения: `pdo_pgsql`, `mbstring`, `openssl`, `gd` или `imagick`, `fileinfo`, `zip`, `intl`, `curl`) | `php -v`, `php -m` |
| Composer | 2.x | `composer -V` |
| PostgreSQL | 13+ (или SQLite для быстрого старта) | `psql --version` |
| Web-сервер | Apache 2.4+ (Wamp/OpenServer) или Nginx 1.20+ | — |
| Node.js | 18+ (только если пересобираете фронтенд через Vite) | `node -v` |

Для нагрузки до 100 номеров и 10 одновременных пользователей достаточно сервера 2 CPU / 4 GB RAM.

---

## 2. Вариант A — установка на Wamp/OpenServer (Windows)

Это основной сценарий: **локальный хостинг на Wamp или OpenServer**.

### 2.1 Подготовка Wamp/OpenServer
1. Установите **WampServer 3.3+** или **OpenServer Panel 6+** (оба включают Apache, PHP, MySQL/PostgreSQL).
2. В Wamp: левый клик по значку → **PHP → Version → 8.2** (или новее).
   В OpenServer: вкладка **Общие** → модуль **PHP 8.2**, затем **Дополнительно → Конфигурация PHP** включите расширения:
   `pdo_pgsql`, `mbstring`, `openssl`, `gd`, `fileinfo`, `zip`, `intl`, `curl`, `exif`.
3. Перезапустите сервер.

### 2.2 Расположение проекта
```bat
:: скопируйте проект в корень веб-сервера
xcopy /E hotel-linen C:\wamp64\www\hotel-linen\
:: или для OpenServer
xcopy /E hotel-linen C:\OpenServer\domains\hotel-linen\
```

### 2.3 Composer-зависимости
```bat
cd C:\wamp64\www\hotel-linen
composer install --no-dev --optimize-autoloader
```
Если Composer не в PATH: скачайте `Composer-Setup.exe` и укажите путь к `php.exe` из папки Wamp (`C:\wamp64\bin\php\php8.2.x\php.exe`).

### 2.4 База данных
**Рекомендуется PostgreSQL.** В Wamp его нет из коробки — два варианта:

*Вариант 1 (проще, для пилота): SQLite.* Файл БД создаётся автоматически, сервер не нужен:
```bat
type nul > database\database.sqlite
```
в `.env` оставьте `DB_CONNECTION=sqlite` (см. раздел 5).

*Вариант 2 (продакшн-конфигурация): PostgreSQL.* Установите PostgreSQL 16 отдельным инсталлятором, затем:
```bat
:: от имени postgres
createdb hotel_linen
psql -c "CREATE USER hotel WITH PASSWORD 'strong_password';"
psql -c "GRANT ALL PRIVILEGES ON DATABASE hotel_linen TO hotel;"
```

### 2.5 Виртуальный хост
**Wamp:** файл `C:\wamp64\scripts\hotel.local.virtualhost.txt`:
```apache
<VirtualHost *:80>
    DocumentRoot "C:/wamp64/www/hotel-linen/public"
    ServerName hotel.local
    <Directory "C:/wamp64/www/hotel-linen/public">
        Options +Indexes +FollowSymLinks +MultiViews
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```
Затем добавьте ссылку в `httpd-vhosts.conf` (меню Wamp → Apache → httpd-vhosts.conf) и строку в `C:\Windows\System32\drivers\etc\hosts`:
```
127.0.0.1   hotel.local
```

**OpenServer:** вкладка **Домены и поддомены** → тип «Файл» → домен `hotel.local` → корень `hotel-linen` → галочка **«public как корневая папка»** → сохранить и перезапустить.

### 2.6 Инициализация
```bat
copy .env.example .env
php artisan key:generate
php artisan migrate --seed     :: см. также раздел 6 про сидер ролей
php artisan storage:link
```
Откройте `http://hotel.local` в браузере.

---

## 3. Вариант B — установка на Linux-хостинг (Nginx + PHP-FPM + PostgreSQL)

```bash
# 1. Пакеты (Ubuntu 22.04/24.04)
sudo apt update
sudo apt install -y nginx postgresql php8.2-fpm php8.2-pgsql php8.2-mbstring \
  php8.2-gd php8.2-xml php8.2-zip php8.2-intl php8.2-curl php8.2-exif unzip

# 2. Composer
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# 3. Код проекта
sudo mkdir -p /var/www/hotel-linen
sudo rsync -a hotel-linen/ /var/www/hotel-linen/
cd /var/www/hotel-linen
sudo -u www-data composer install --no-dev --optimize-autoloader

# 4. PostgreSQL
sudo -u postgres createuser hotel -P          # задайте пароль
sudo -u postgres createdb -O hotel hotel_linen

# 5. Настройка .env и миграция — см. разделы 5–6
```

---

## 4. Вариант C — Docker

В корне проекта создайте файлы (при наличии Laravel Sail они уже есть: `vendor/bin/sail up`):

**Dockerfile**
```dockerfile
FROM php:8.2-fpm
RUN apt-get update && apt-get install -y libpq-dev libzip-dev libicu-dev \
    libfreetype6-dev libjpeg62-turbo-dev libpng-dev unzip git \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install pdo_pgsql zip intl gd exif opcache \
 && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
WORKDIR /var/www/html
COPY . .
RUN composer install --no-dev --optimize-autoloader
RUN php artisan package:discover --ansi || true
EXPOSE 9000
CMD ["php-fpm"]
```

**docker-compose.yml**
```yaml
services:
  app:
    build: .
    volumes: [".:/var/www/html"]
    environment:
      DB_CONNECTION: pgsql
      DB_HOST: db
      DB_DATABASE: hotel_linen
      DB_USERNAME: hotel
      DB_PASSWORD: secret
  db:
    image: postgres:16
    environment:
      POSTGRES_DB: hotel_linen
      POSTGRES_USER: hotel
      POSTGRES_PASSWORD: secret
    volumes: ["pgdata:/var/lib/postgresql/data"]
  web:
    image: nginx:1.27
    ports: ["80:80"]
    volumes:
      - ./:/var/www/html
      - ./docker/nginx.conf:/etc/nginx/conf.d/default.conf
volumes:
  pgdata:
```

Запуск:
```bash
docker compose up -d --build
docker compose exec app php artisan migrate --seed
docker compose exec app php artisan storage:link
```

---

## 5. Настройка .env

```bash
cp .env.example .env
php artisan key:generate
```

Ключевые параметры (`APP_KEY` уже заполнен командой выше):

```ini
APP_NAME="LinenTrack"
APP_ENV=production            # на проде только production!
APP_DEBUG=false               # НИКОГДА true на боевом сервере
APP_URL=https://hotel.local   # ваш домен/IP
APP_LOCALE=ru                 # русский интерфейс
APP_FAKER_LOCALE=ru_RU
APP_TIMEZONE=Europe/Moscow

# --- PostgreSQL (рекомендуется) ---
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=hotel_linen
DB_USERNAME=hotel
DB_PASSWORD=strong_password

# --- Или SQLite (быстрый старт / пилот без СУБД) ---
# DB_CONNECTION=sqlite
# DB_DATABASE=/absolute/path/to/hotel-linen/database/database.sqlite

SESSION_DRIVER=database       # или file
QUEUE_CONNECTION=sync         # при нагрузке 10 юзеров очередь не нужна
CACHE_STORE=file              # или redis

FILESYSTEM_DISK=public        # фото горничных доступны по /storage/...

# Безопасность входа
RATE_LIMITER_ENABLED=true     # throttling middleware на /login
LOG_LEVEL=warning
```

После правки `.env`:
```bash
php artisan config:clear && php artisan cache:clear
```

---

## 6. Первичная инициализация БД

```bash
php artisan migrate --force
php artisan db:seed --force
```

Что делает система при миграции (миграции `2026_10_20_0000xx_*`):
- справочники: корпуса/этажи/номера, шаблоны комплектации, номенклатура, поставщики, причины списания/расхождений, шаблоны чек-листов;
- склад: локации (склад, номер, прачечная, списано), остатки, движения (append-only журнал);
- пользователи/роли/права, журнал входов, акты инвентаризации, заявки, документы печатных форм.

После миграции создайте роли, права и учётную запись администратора (модель `User` связывается с ролью через `role_id`, права проверяются методом `canDo('ability')` на сервере):
```bash
php artisan tinker
```
```php
use App\Models\{Role, Permission, User};

// Роли (имена фиксированы: admin, manager, storer, supervisor, maid)
foreach (['admin','manager','storer','supervisor','maid'] as $r) {
    Role::firstOrCreate(['name' => $r]);
}

// Права по действиям (ability), пример для менеджера и кладовщика:
Permission::firstOrCreate(['role_name'=>'manager','ability'=>'stock.receive']);
Permission::firstOrCreate(['role_name'=>'manager','ability'=>'stock.issue']);
Permission::firstOrCreate(['role_name'=>'manager','ability'=>'inventory.approve']);
Permission::firstOrCreate(['role_name'=>'storer','ability'=>'stock.receive']);
Permission::firstOrCreate(['role_name'=>'supervisor','ability'=>'room.inspect']);
Permission::firstOrCreate(['role_name'=>'maid','ability'=>'room.clean']);
// Примечание: роль admin имеет все права автоматически (см. User::canDo).

// Администратор (пароль хэшируется кастом 'hashed' = bcrypt/argon2id)
User::create([
  'name' => 'Администратор', 'email' => 'admin@hotel.local',
  'password' => 'СменитеЭтотПароль!',
  'role_id' => Role::where('name','admin')->value('id'),
  'is_active' => true,
]);
```
(При подключении полноценного `RolePermissionSeeder`/`AdminSeeder` достаточно `php artisan db:seed` — он создаст те же роли и права автоматически.)

Дальше в интерфейсе администратора:
1. Заведите корпус, этажи, номера (или импорт CSV).
2. Номенклатуру белья (единица измерения, норма на номер, PAR, мин/макс, срок службы) и штучные вещи (ТВ, фен, сейф — учитываются по инвентарному номеру/QR).
3. Шаблоны комплектации номеров и чек-листов.
4. Пользователей горничных; назначьте им номера и смены.

---

## 7. Права доступа к каталогам

Linux:
```bash
sudo chown -R www-data:www-data /var/www/hotel-linen
sudo find /var/www/hotel-linen -type d -exec chmod 755 {} \;
sudo find /var/www/hotel-linen -type f -exec chmod 644 {} \;
chmod -R ug+rwx storage bootstrap/cache
```
Windows (Wamp/OpenServer): убедитесь, что пользователь Apache имеет Full Control на `storage/`, `bootstrap/cache/`, `public/storage/`.

---

## 8. Веб-сервер

### Apache (Wamp/OpenServer)
Корнем должна быть папка **`public/`**. Laravel кладёт туда готовый `.htaccess`. Проверьте, что в Apache включён `mod_rewrite` и `AllowOverride All` для каталога public (см. п. 2.5).

### Nginx
```nginx
server {
    listen 80;
    server_name hotel.local;
    root /var/www/hotel-linen/public;
    client_max_body_size 15M;          # загрузка фото горничными

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }
    location ~ /\.(?!well-known).* { deny all; }
}
```
```bash
sudo ln -s /etc/nginx/sites-available/hotel /etc/nginx/sites-enabled/ && sudo nginx -t && sudo systemctl reload nginx
```

---

## 9. HTTPS

Тестовая сеть отеля: самоподписанный сертификат достаточно, но для корректной работы PWA (Service Worker требует secure context) лучше доверенный.

Let's Encrypt (публичный домен):
```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d hotel.example.com
```

Локальная сеть без домена (Wamp/OpenServer/Nginx): выпустите внутренний сертификат и добавьте его в доверенные на устройствах горничных, либо используйте Tailscale/cloudflared. Без HTTPS PWA-режим (установка на домашний экран и офлайн-черновики) работать не будет — система продолжит функционировать как обычный адаптивный сайт.

В `.env` после включения HTTPS: `APP_URL=https://...`, в `config/app.php` включите `'asset_url'` по HTTPS, а в `AppServiceProvider`: `URL::forceScheme('https');`.

---

## 10. PWA и работа горничной

1. Соберите ассеты (если менялись ресурсы):
   ```bash
   npm ci && npm run build
   ```
2. Манифест `public/manifest.webmanifest` и Service Worker `public/sw.js` регистрируются автоматически; иконки 192/512 px лежат в `public/icons/`.
3. На телефоне/планшете горничной: откройте `https://hotel.local`, меню браузера → **«Установить приложение» / «На главный экран»**. Крупные кнопки интерфейса маляра рассчитаны на тач.
4. Черновики чек-листов при пропаже сети сохраняются в IndexedDB и досылаются при появлении соединения.
5. QR: распечатайте таблички номеров (форма №10) — сканирование камерой телефона открывает карточку номера.

---

## 11. Резервные копии (backup)

Ежедневный бэкап обязателен (требование безопасности).

**PostgreSQL** — скрипт `/usr/local/bin/backup-hotel.sh`:
```bash
#!/usr/bin/env bash
set -euo pipefail
DEST=/var/backups/hotel
KEEP=14
mkdir -p "$DEST"
PGPASSWORD='strong_password' pg_dump -h 127.0.0.1 -U hotel -Fc hotel_linen \
  > "$DEST/db_$(date +%F).dump"
tar czf "$DEST/storage_$(date +%F).tar.gz" -C /var/www/hotel-linen storage/app
find "$DEST" -mtime +$KEEP -delete
```
cron: `0 3 * * * /usr/local/bin/backup-hotel.sh >> /var/log/hotel-backup.log 2>&1`

**SQLite (Wamp/OpenServer)** — планировщик Windows или bat:
```bat
copy C:\wamp64\www\hotel-linen\database\database.sqlite D:\backups\hotel_%DATE:~0,4%%DATE:~5,2%%DATE:~8,2%.sqlite
```
(Laravel-команда `php artisan backup:run` доступна при подключении spatie/laravel-backup.)

Раз в месяц проверяйте восстановление из копии.

---

## 12. Интеграции

Архитектура заложена под интеграции (вне MVP):
- **PMS**: выезд гостя запускает приёмку номера. Подключается через API-эндпоинты `app/Http/Controllers/Api` (токен-аутентификация) или импорт CSV выезда по расписанию.
- **Штрихкоды/QR**: этикетки на единицах и табличках номеров; быстрое сканирование камерой (библиотека `endroid/qr-code` генерирует коды).
- **Мессенджер**: уведомления о заявках — через вебхуки в событиях Issue (Telegram Bot API / email).

---

## 13. Тесты

```bash
# Юнит-тесты правил движения, актов, прав доступа
php artisan test

# Сквозной сценарий приёмки (раздел 8 ТЗ):
php artisan test --filter=E2EAcceptanceScenario
```
Скрипт проверки перед боем:
1. создать пользователей и номенклатуру → 2. приход 100 шт. на склад → 3. выдача в номер → 4. приёмка после выезда ловит пропажу полотенца → 5. списание с причиной + заявка → 6. «готов» блокируется → 7. после восполнения «готов» проходит → 8. инвентаризация DRAFT→REVIEW→APPROVED, остатки скорректированы, акт read-only → 9. PDF акта печатается → 10. журнал восстанавливает цепочку.

---

## 14. Обновление версии

```bash
cd /var/www/hotel-linen
php artisan down
git pull                        # или замена файлов
composer install --no-dev -o
php artisan migrate --force
php artisan config:clear && php artisan cache:clear && php artisan view:clear
php artisan up
```

---

## 15. Устранение неполадок

| Симптом | Причина / решение |
|---|---|
| 500 ошибка после установки | Нет прав на `storage/` (п.7) или `APP_KEY` пуст → `php artisan key:generate`; смотрите `storage/logs/laravel.log` |
| «Class not found» | Не выполнен `composer dump-autoload -o` |
| Фото не загружаются / 413 | Увеличьте в php.ini `upload_max_filesize=10M, post_max_size=12M`; `client_max_body_size` в Nginx |
| Изображения 404 по `/storage/...` | Не выполнен `php artisan storage:link` |
| PWA не ставится | Нет HTTPS (п.9) |
| Ошибки входа после серии попыток | Сработал rate limiter — подождите минуту, проверьте `php artisan cache:clear` |
| Отрицательный остаток запрещён | Это ожидаемое поведение сервиса склада (правило «остаток не уходит в минус») |
| Горничная видит чужие номера | Проверьте назначение номеров в смене (админ → пользователи); права проверяются на сервере — клиентские ограничения не являются защитой |
| SQLite «database is locked» | Для более чем 1 пользователя переходите на PostgreSQL |

---

## Быстрая шпаргалка (Wamp, минимальный старт)

```bat
cd C:\wamp64\www\hotel-linen
composer install
copy .env.example .env
php artisan key:generate
echo.> database\database.sqlite
:: в .env: DB_CONNECTION=sqlite
php artisan migrate --seed
php artisan storage:link
:: открыть http://localhost/hotel-linen/public  (или vhost hotel.local)
```
