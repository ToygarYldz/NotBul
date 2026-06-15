# Not Bul

Not Bul, öğrencilerin ders notlarını üniversite, bölüm, sınıf, ders ve konu kırılımlarında arayıp paylaşabilmesi için geliştirilen PHP tabanlı bir ders notu paylaşım platformudur.

Proje şu anda aktif geliştirme aşamasındadır. Kod tabanı klasik PHP sayfalarından oluşur; ayrı bir build adımı, framework, Composer bağımlılığı veya Node.js derleme süreci yoktur.

## İçindekiler

- [Özellikler](#özellikler)
- [Teknoloji Yığını](#teknoloji-yığını)
- [Proje Yapısı](#proje-yapısı)
- [Gereksinimler](#gereksinimler)
- [Kurulum](#kurulum)
- [Ortam Değişkenleri](#ortam-değişkenleri)
- [Dosya Yükleme ve Saklama](#dosya-yükleme-ve-saklama)
- [Admin Hesabı](#admin-hesabı)
- [Veritabanı Özeti](#veritabanı-özeti)
- [Geliştirme Notları](#geliştirme-notları)
- [Güvenlik Notları](#güvenlik-notları)
- [Bilinen Sınırlar](#bilinen-sınırlar)
- [Lisans](#lisans)

## Özellikler

- Ana sayfada popüler notlar, son yüklenenler ve hızlı filtreleme
- Ders notu arama ekranında üniversite, program türü, bölüm, sınıf, ders, konu ve dosya türü filtreleri
- JSON kaynaklı üniversite ve bölüm listeleri
- Giriş yapan kullanıcılar için PDF, DOCX, PPTX, PNG, JPG, JPEG ve WEBP not yükleme
- Dosya uzantısı, MIME type, boyut ve SHA-256 bütünlük kontrolü
- Dosyaları doğrudan web root altından sunmak yerine `view.php` stream endpoint'i üzerinden görüntüleme ve indirme
- Kullanıcı kaydı, e-posta doğrulama, giriş, çıkış ve şifre sıfırlama akışları
- Cloudflare Turnstile, CSRF token, honeypot, form zamanlaması ve oran sınırlama ile kayıt koruması
- Brevo SMTP API ile doğrulama, şifre sıfırlama, kullanıcı bildirimi ve admin bildirimi e-postaları
- Not detay sayfasında yorum ve 1-5 arası puanlama
- Kullanıcı profilinde yüklenen notlar, arşivlenen notlar, yorumlar ve hesap ayarları
- Admin panelinde kullanıcı, not ve yorum yönetimi
- Admin için rol değiştirme, doğrulama durumu yönetimi, bildirim tercihleri ve önerilen aksiyonlar
- Soft-delete destekli not arşivleme
- Açık Grafik, Twitter card ve canonical URL meta etiketleri
- Açık/koyu tema uyumu için sistem tercihine duyarlı arayüz

## Teknoloji Yığını

- Backend: PHP 8+, procedural PHP, PDO
- Veritabanı: MariaDB önerilir
- Web sunucusu: Nginx + PHP-FPM veya PHP built-in server
- Frontend: HTML, Bootstrap 5, Font Awesome, vanilla JavaScript
- Veri dosyaları: `assets/data/universiteler.json`, `assets/data/bolumler.json`
- E-posta: Brevo SMTP API
- Bot koruması: Cloudflare Turnstile

`database.sql` dosyası MariaDB dostu `IF NOT EXISTS` ifadeleri içerir. MySQL kullanacaksanız sürümünüzün bu ifadeleri desteklediğinden emin olun veya migration satırlarını uyarlayın.

## Proje Yapısı

```text
.
├── assets/
│   ├── css/app.css
│   ├── data/bolumler.json
│   ├── data/universiteler.json
│   ├── icons/
│   └── js/app.js
├── includes/
│   ├── admin_auth.php
│   ├── admin_notifications.php
│   ├── admin_suggested_actions.php
│   ├── brevo.php
│   ├── db.php
│   ├── env.php
│   ├── footer.php
│   ├── header.php
│   ├── ratings.php
│   ├── registration_security.php
│   ├── storage.php
│   ├── upload_config.php
│   └── user_notifications.php
├── ops/
│   └── nginx/upload-limits.conf
├── admin.php
├── admin-comment-edit.php
├── admin-note-edit.php
├── comment-edit.php
├── database.sql
├── forgot-password.php
├── index.php
├── login.php
├── logout.php
├── note-detail.php
├── profile.php
├── profile_edit.php
├── register.php
├── reset-password.php
├── search.php
├── upload.php
├── verify-email.php
├── view.php
├── .user.ini
└── README.md
```

Önemli dosyalar:

- `includes/db.php`: PDO bağlantısı.
- `includes/env.php`: Ortam değişkeni ve `.env` okuyucusu.
- `includes/storage.php`: Yüklenen notların saklandığı klasör ve güvenli dosya yolu çözümleme.
- `includes/registration_security.php`: Kayıt formu güvenlik kontrolleri.
- `includes/brevo.php`: Brevo e-posta gönderimi ve markalı e-posta şablonları.
- `assets/js/app.js`: Hiyerarşik filtreler, arama, sayfalama, tag input ve upload arayüzü.
- `database.sql`: Şema oluşturma ve mevcut kurulumlar için basit migration ifadeleri.
- `ops/nginx/upload-limits.conf`: Nginx upload limiti için include dosyası.

## Gereksinimler

- PHP 8.0 veya üzeri
- PHP-FPM, Nginx ve MariaDB
- PHP eklentileri:
  - `pdo_mysql`
  - `curl`
  - `fileinfo`
  - `mbstring`
  - `json`
  - `openssl`
- Cloudflare Turnstile site key ve secret key
- Brevo API key ve doğrulanmış sender e-posta adresi

## Kurulum

### 1. Depoyu hazırlayın

```bash
cd /var/www/NotBul
```

Bu proje için paket kurulumu gerekmez. Dosyalar doğrudan PHP tarafından çalıştırılır.

### 2. Veritabanını oluşturun

MariaDB shell içinde örnek kullanıcı ve veritabanı:

```sql
CREATE DATABASE IF NOT EXISTS notbul CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'notbul_user'@'localhost' IDENTIFIED BY 'güçlü-bir-parola';
GRANT ALL PRIVILEGES ON notbul.* TO 'notbul_user'@'localhost';
FLUSH PRIVILEGES;
```

Şemayı içe aktarın:

```bash
mariadb -u root -p < database.sql
```

`database.sql` şunları oluşturur:

- `users`
- `notes`
- `note_comments`
- `registration_attempts`
- `admin_suggested_actions`

Dosya ayrıca mevcut kurulumlarda eksik kolon ve indexleri eklemeye çalışan migration ifadeleri içerir.

### 3. Veritabanı bağlantısını ayarlayın

Şu an veritabanı bağlantısı `includes/db.php` içindeki değerlerden okunur:

```php
$host = '127.0.0.1';
$db   = 'notbul';
$user = 'notbul_user';
$pass = '...';
```

Local ortamınıza göre `$host`, `$db`, `$user` ve `$pass` değerlerini güncelleyin. Üretimde veritabanı parolasını repoda tutmak yerine ortam değişkenine taşımak daha güvenlidir.

### 4. Dosya saklama klasörünü hazırlayın

Varsayılan saklama klasörü:

```text
/var/lib/notbul/notes/
```

PHP-FPM kullanıcısına yazma izni verin:

```bash
sudo mkdir -p /var/lib/notbul/notes
sudo chown -R www-data:www-data /var/lib/notbul/notes
sudo chmod 750 /var/lib/notbul/notes
```

Farklı bir klasör kullanmak için `NOTBUL_NOTE_STORAGE_DIR` ortam değişkenini tanımlayın. Bu değer `includes/storage.php` tarafından doğrudan process environment üzerinden okunur.

### 5. Ortam dosyasını oluşturun

Gizli değerler için önerilen konum:

```text
/etc/notbul/.env
```

Örnek:

```dotenv
APP_BASE_URL=http://notbul.local
SITE_URL=http://notbul.local
APP_URL=http://notbul.local

TURNSTILE_SITE_KEY=...
TURNSTILE_SECRET_KEY=...
TURNSTILE_EXPECTED_HOSTNAME=notbul.local
REGISTRATION_MIN_FORM_SECONDS=3
TRUST_PROXY_HEADERS=0

BREVO_API_KEY=...
BREVO_SENDER_EMAIL=no-reply@example.com
BREVO_SENDER_NAME=Not Bul

ADMIN_NOTIFY_PRIMARY_EMAIL=admin@example.com
ADMIN_NOTIFY_PRIMARY_NAME=Not Bul Admin
ADMIN_NOTIFY_SENDER_EMAIL=system-notify@example.com
ADMIN_NOTIFY_SENDER_NAME=Not Bul Sistem Bildirimi

USER_NOTIFY_SENDER_EMAIL=no-reply@example.com
USER_NOTIFY_SENDER_NAME=Not Bul

STALE_UNVERIFIED_USER_HOURS=48
```

`includes/env.php` değerleri şu sırayla okur:

1. Process environment
2. `NOTBUL_ENV_FILE` ile verilen dosya
3. `/etc/notbul/.env`
4. Proje kökündeki `.env`

Not: `NOTBUL_NOTE_STORAGE_DIR` bu yardımcı okuyucudan değil, doğrudan `getenv()` ile okunur. PHP-FPM pool, systemd service veya shell environment içinde tanımlanmalıdır.

### 6. Upload limitlerini ayarlayın

Uygulama içi limit `includes/upload_config.php` içinde 25 MB olarak tanımlıdır.

PHP tarafındaki limitler `.user.ini` içinde:

```ini
upload_max_filesize = 25M
post_max_size = 26M
max_file_uploads = 20
max_input_time = 120
max_execution_time = 120
```

Nginx tarafında `ops/nginx/upload-limits.conf` dosyasını server bloğunuza dahil edin:

```nginx
include /var/www/NotBul/ops/nginx/upload-limits.conf;
```

Bu include şu değeri ayarlar:

```nginx
client_max_body_size 26m;
```

### 7. Nginx + PHP-FPM ile servis edin

Örnek local server bloğu:

```nginx
server {
    listen 80;
    server_name notbul.local;
    root /var/www/NotBul;
    index index.php;

    include /var/www/NotBul/ops/nginx/upload-limits.conf;

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
```

PHP-FPM socket yolu sunucunuzdaki PHP sürümüne göre değişebilir. Örneğin `php8.3-fpm.sock` veya TCP tabanlı `127.0.0.1:9000` kullanılıyor olabilir.

Local hostname kullanacaksanız `/etc/hosts` içine örnek kayıt:

```text
127.0.0.1 notbul.local
```

### 8. PHP built-in server ile hızlı çalıştırma

Nginx kullanmadan hızlı kontrol için:

```bash
mkdir -p /tmp/notbul-notes
NOTBUL_NOTE_STORAGE_DIR=/tmp/notbul-notes php -S 127.0.0.1:8000
```

Ardından:

```text
http://127.0.0.1:8000
```

Bu yöntem gerçek üretim davranışını birebir temsil etmez, ama arayüz ve temel PHP akışlarını hızlıca denemek için yeterlidir.

## Ortam Değişkenleri

| Değişken | Açıklama |
| --- | --- |
| `APP_BASE_URL` | E-posta linkleri ve e-posta içi asset URL'leri için ana adres. |
| `SITE_URL` | Header meta/canonical URL üretimi için tercih edilen site adresi. |
| `APP_URL` | `SITE_URL` yoksa meta/canonical URL için fallback. |
| `NOTBUL_ENV_FILE` | `.env` dosyasını özel bir konumdan okutmak için process environment değişkeni. |
| `NOTBUL_NOTE_STORAGE_DIR` | Yüklenen not dosyalarının saklanacağı klasör. Doğrudan `getenv()` ile okunur. |
| `TURNSTILE_SITE_KEY` | Kayıt formunda Turnstile widget'ı için site key. Boşsa kayıt butonu devre dışı kalır. |
| `TURNSTILE_SECRET_KEY` | Sunucu tarafı Turnstile doğrulaması için secret key. |
| `TURNSTILE_EXPECTED_HOSTNAME` | Turnstile yanıtında beklenen hostname. Local testte uygun hostname ile ayarlayın veya boş bırakın. |
| `REGISTRATION_MIN_FORM_SECONDS` | Kayıt formunun gönderilebilmesi için minimum bekleme süresi. Varsayılan `3`. |
| `TRUST_PROXY_HEADERS` | Güvenilir proxy arkasında gerçek istemci IP'sini `CF-Connecting-IP` veya `X-Forwarded-For` üzerinden okumak için `1`. |
| `BREVO_API_KEY` | Brevo SMTP API anahtarı. |
| `BREVO_SENDER_EMAIL` | Doğrulama ve şifre sıfırlama e-postalarının varsayılan gönderen adresi. |
| `BREVO_SENDER_NAME` | Varsayılan gönderen adı. |
| `ADMIN_NOTIFY_PRIMARY_EMAIL` | Admin bildirimlerinin varsayılan alıcısı. |
| `ADMIN_NOTIFY_PRIMARY_NAME` | Varsayılan admin alıcı adı. |
| `ADMIN_NOTIFY_SENDER_EMAIL` | Admin bildirimleri için sender adresi. |
| `ADMIN_NOTIFY_SENDER_NAME` | Admin bildirimleri için sender adı. |
| `USER_NOTIFY_SENDER_EMAIL` | Kullanıcı bildirimleri için sender adresi. |
| `USER_NOTIFY_SENDER_NAME` | Kullanıcı bildirimleri için sender adı. |
| `STALE_UNVERIFIED_USER_HOURS` | Admin önerilerinde eski doğrulanmamış hesap eşiği. Varsayılan `48`. |

## Dosya Yükleme ve Saklama

Yükleme akışı `upload.php` içinde çalışır.

Desteklenen dosya türleri:

- PDF
- DOCX
- PPTX
- PNG
- JPG / JPEG
- WEBP

Sunucu tarafında yapılan kontroller:

- PHP upload hata kodu kontrolü
- `is_uploaded_file()` doğrulaması
- Maksimum dosya boyutu kontrolü
- Dosya uzantısı allowlist kontrolü
- `finfo_file()` ile MIME type allowlist kontrolü
- SHA-256 hash üretimi
- Rastgele dosya adı üretimi
- Yıl/ay bazlı alt klasörleme

Dosyalar varsayılan olarak `/var/lib/notbul/notes/YYYY/MM/` altında saklanır. Veritabanında `storage_path`, `sha256`, `file_size`, `mime_type`, `upload_status`, `scan_status` ve `download_count` bilgileri tutulur.

Kullanıcı dosyaya doğrudan storage yolundan erişmez. `view.php?id=...` dosyayı inline sunar, `view.php?id=...&download=1` indirme olarak gönderir ve indirme sayısını artırır.

## Admin Hesabı

İlk admin hesabı için önerilen akış:

1. Uygulama üzerinden normal kullanıcı kaydı oluşturun.
2. E-posta doğrulamasını tamamlayın.
3. MariaDB üzerinde kullanıcıyı admin rolüne yükseltin.

```sql
UPDATE users
SET role = 'admin',
    verified = 1,
    verified_at = COALESCE(verified_at, NOW())
WHERE email = 'admin@example.com';
```

Admin paneli:

```text
/admin.php
```

Admin panelinde kullanıcı rolleri, doğrulama durumu, notlar, yorumlar, bildirim tercihleri ve önerilen aksiyonlar yönetilebilir.

## Veritabanı Özeti

`users`

- Kullanıcı kimlik bilgileri
- Şifre hash'i
- E-posta doğrulama token hash'i ve süresi
- Şifre sıfırlama token hash'i ve süresi
- Rol bilgisi: `user`, `admin`
- Bildirim tercihleri

`notes`

- Not başlığı, açıklaması ve akademik filtre alanları
- Dosya metadata bilgileri
- Storage bilgileri
- Upload ve scan durumu
- İndirme sayısı
- Soft-delete alanları

`note_comments`

- Not yorumları
- 1-5 arası puanlama
- Kullanıcı ve not ilişkisi

`registration_attempts`

- Kayıt denemeleri için IP ve e-posta hash kayıtları
- Oran sınırlama ve temizlik kontrolleri

`admin_suggested_actions`

- Admin panelinde gösterilen önerilen aksiyon kayıtları
- Şu an eski, doğrulanmamış ve içeriksiz kullanıcı temizliği için kullanılır

## Geliştirme Notları

Kod tabanı doğrudan PHP sayfalarıyla çalışır:

- Ana sayfa: `index.php`
- Arama: `search.php`
- Not yükleme: `upload.php`
- Not detayı: `note-detail.php`
- Dosya stream: `view.php`
- Kayıt: `register.php`
- Giriş: `login.php`
- Profil: `profile.php`
- Admin paneli: `admin.php`

Frontend davranışlarının çoğu `assets/js/app.js` içindedir. Stil katmanı `assets/css/app.css` içindedir. Üniversite ve bölüm seçenekleri `assets/data/*.json` dosyalarından okunur.

PHP sözdizimi kontrolü için:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

Veritabanını sıfırdan kurmak için:

```bash
mariadb -u root -p < database.sql
```

Şema değiştirirken `database.sql` içindeki mevcut migration yaklaşımını koruyun veya ayrı bir migration stratejisi ekleyin.

## Güvenlik Notları

Projede mevcut olan güvenlik pratikleri:

- PDO prepared statement kullanımı
- `password_hash()` ve `password_verify()` ile şifre yönetimi
- E-posta doğrulama ve şifre sıfırlama tokenlarının hashlenerek saklanması
- Kayıt formunda CSRF token, honeypot, minimum form süresi, Turnstile ve rate limit
- Admin işlemlerinde CSRF token kontrolleri
- Çıktılarda `htmlspecialchars()` kullanımı
- Dosya upload için uzantı ve MIME allowlist
- Dosyaların web root dışında saklanması
- Dosya sunumunda `X-Content-Type-Options: nosniff`
- Notlarda soft-delete, admin silmelerinde yetki kontrolü

Üretim için ayrıca önerilenler:

- `includes/db.php` içindeki veritabanı bilgilerini ortam değişkenlerine taşıyın.
- `/etc/notbul/.env` dosyasını sadece web server/PHP-FPM kullanıcısının okuyabileceği şekilde yetkilendirin.
- Proje kökündeki `.env` dosyasını üretimde tercih etmeyin.
- Nginx'te dotfile erişimini kapalı tutun.
- HTTPS zorunlu kullanın.
- PHP-FPM ve Nginx hata loglarını izleyin.
- Yüklenen dosyalar için gerçek antivirüs veya malware scan katmanı ekleyin. Şu an upload sırasında `scan_status` doğrudan `clean` olarak kaydediliyor.
- Veritabanı ve upload storage için düzenli yedekleme planı oluşturun.
- Admin hesabı için güçlü parola ve mümkünse ek MFA katmanı kullanın.

## Bilinen Sınırlar

- Otomatik test altyapısı bulunmuyor.
- Composer bağımlılık yönetimi yok.
- Arama sonuçları sunucudan JSON payload olarak sayfaya basılıyor ve filtreleme istemci tarafında yapılıyor. Veri büyüdükçe server-side arama/pagination gerekecektir.
- Upload dosyaları için gerçek dosya tarama servisi henüz entegre değil.
- Veritabanı bağlantısı henüz `.env` üzerinden yönetilmiyor.
- `database.sql` basit migration ifadeleri içeriyor; sürümlü migration aracı yok.

## Lisans

Bu proje MIT lisansı ile lisanslanmıştır. Ayrıntılar için `LICENSE` dosyasına bakın.
