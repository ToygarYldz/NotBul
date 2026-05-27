# Not Bul

Not Bul, öğrencilerin ders notlarını **üniversite / bölüm / ders / konu** kırılımlarında keşfedip paylaşabilmesi için geliştirilen bir web platformudur. Proje şu an **Pre-Alpha** aşamasındadır.

## 🚀 Öne Çıkan Özellikler

- **Ana sayfa keşif deneyimi**
  - “Yeni Yüklenenler” alanı
  - Arama kutusu + hiyerarşik filtreler (üniversite, program türü, bölüm, ders, konu)
- **Detaylı not arama ekranı**
  - Gelişmiş filtre paneli
  - Dosya türüne göre süzme
  - Sonuç sayısı ve sayfalama
- **Not yükleme akışı**
  - Drag & drop dosya seçimi
  - Başlık, açıklama, etiket ve akademik hiyerarşi seçimi
  - Güvenlik odaklı yükleme kontrol listesi
- **Not detay sayfası**
  - Önizleme alanı, meta bilgiler, etiketler, yorum listesi
- **Kimlik doğrulama (backend bağlı)**
  - Kayıt ol, giriş yap, çıkış yap
  - Şifre hashleme ve PDO prepared statement kullanımı

---

## 🧱 Teknoloji Yığını

- **Backend:** PHP (procedural + PDO)
- **Veritabanı:** MySQL
- **Frontend:** HTML, Bootstrap 5, vanilla JavaScript
- **Veri Kaynakları:**
  - MySQL `users` ve `notes` tabloları
  - JSON dosyaları (`assets/data/*.json`) (filtre alanları için)

---

## 📁 Proje Yapısı

```text
.
├── assets/
│   ├── css/app.css
│   ├── data/
│   │   ├── universiteler.json
│   │   └── bolumler.json
│   ├── icons/favicon.svg
│   └── js/app.js
├── includes/
│   ├── db.php
│   ├── header.php
│   └── footer.php
├── index.php
├── search.php
├── upload.php
├── note-detail.php
├── register.php
├── login.php
├── logout.php
└── database.sql
```

---

## ⚙️ Kurulum (Local Development)

### 1) Gereksinimler

- PHP 8+
- MySQL / MariaDB
- Bir local web server (Apache, Nginx veya PHP built-in server)

### 2) Veritabanını oluştur

`database.sql` dosyasını içe aktar:

```bash
mysql -u root -p < database.sql
```

Bu script:

- `notbul` veritabanını oluşturur
- `users` ve `notes` tablolarını yaratır

### 3) Veritabanı bağlantısını düzenle

`includes/db.php` içinde ortamına göre bilgileri güncelle:

- `$host`
- `$db`
- `$user`
- `$pass`

### 4) Bot kayıt koruması

Kayıt formu Cloudflare Turnstile ve sunucu tarafı oran sınırlama ile korunur. `.env` veya ortam değişkenlerinde şu değerleri tanımla:

```bash
TURNSTILE_SITE_KEY=...
TURNSTILE_SECRET_KEY=...
TURNSTILE_EXPECTED_HOSTNAME=notbul.site
```

Uygulama bir güvenilir reverse proxy arkasında çalışıyorsa gerçek istemci IP’si için ayrıca `TRUST_PROXY_HEADERS=1` kullanılabilir.

### 5) Uygulamayı çalıştır

Örn. proje kökünde:

```bash
php -S localhost:8000
```

Ardından tarayıcıda aç:

- `http://localhost:8000`

---

## 🔐 Güvenlik Notları (Mevcut Durum)

Projede şu güvenlik pratikleri uygulanmış durumda:

- Kayıt/giriş tarafında **PDO prepared statement** kullanımı
- Şifrelerin **`password_hash`** ile saklanması
- Çıkışta metinlerin **`htmlspecialchars`** ile kaçışlanması

Dosya yükleme backend’i aktif olsa da aşağıdaki güvenlik adımlarının korunması üretimde zorunludur:

- MIME/type ve uzantı doğrulaması
- Maksimum dosya boyutu kontrolü
- Güvenli dosya adı üretimi
- Web root dışına saklama + kontrollü stream endpoint

---

## 🧪 Mevcut Kapsam ve Sınırlar

- Arama, filtreleme ve not listeleri veritabanındaki gerçek notlardan üretilir.
- Auth akışı (`register/login/logout`) gerçek veritabanına bağlıdır.
- Not yükleme ve not detay sayfaları backend'e bağlıdır; yorumlar henüz istemci tarafında tutulur.

---

## 🗺️ Önerilen Yol Haritası

1. **Gerçek not modeli ve tabloları** (notes, tags, comments, downloads, views)
2. **Dosya yükleme backend’i** (güvenli depolama + antivirüs/scan opsiyonları)
3. **Yetkilendirme** (kullanıcı rollerine göre işlem izinleri)
4. **Arama API’si + pagination/sorting** (server-side)
5. **Rate limiting & audit log**
6. **Test altyapısı** (PHPUnit + temel E2E)
7. **Containerization ve CI/CD**

---

## 📄 Lisans

Bu proje `LICENSE` dosyası altında lisanslanmıştır.

---

## 🤝 Katkı

İlk adım olarak issue açıp mevcut Pre-Alpha kapsamına uygun bir katkı planı paylaşmanız önerilir.
