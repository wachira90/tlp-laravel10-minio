<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## ภาพรวม

ระบบอัปโหลดไฟล์มี 4 ส่วน:

* **Create** = อัปโหลดไฟล์เข้า MinIO + บันทึกข้อมูลลง DB
* **Read** = แสดงรายการไฟล์ + เปิด URL / ดาวน์โหลด
* **Update** = เปลี่ยนชื่อไฟล์หรืออัปโหลดไฟล์ใหม่แทนของเดิม
* **Delete** = ลบทั้ง metadata ใน DB และ object ใน MinIO

---

# 1) ติดตั้ง MinIO

ถ้ายังไม่มี MinIO ให้รันด้วย Docker:

```bash
docker run -d --name minio \
  -p 9000:9000 \
  -p 9001:9001 \
  -e MINIO_ROOT_USER=minioadmin \
  -e MINIO_ROOT_PASSWORD=minioadmin \
  quay.io/minio/minio:RELEASE.2025-09-07T16-13-09Z server /data --console-address ":9001"
```

จากนั้นเข้า console:

* API endpoint: `http://127.0.0.1:9000`
* Console: `http://127.0.0.1:9001`

สร้าง bucket เช่น `profile`

Laravel ใช้ S3 driver สำหรับ object storage และ MinIO รองรับ S3 API ติดตั้ง Package ด้านล่าง

```bash
composer require league/flysystem-aws-s3-v3 "^3.0"
```
---

# 2) ตั้งค่า `.env`

Laravel ใช้ environment variables สำหรับ S3 disk เช่น `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_BUCKET`, `AWS_ENDPOINT`, และ `AWS_USE_PATH_STYLE_ENDPOINT`

ในไฟล์ `.env`:

```env
FILESYSTEM_DISK=s3

AWS_ACCESS_KEY_ID=minioadmin
AWS_SECRET_ACCESS_KEY=minioadmin
AWS_DEFAULT_REGION=ap-southeast-7
AWS_BUCKET=profile
AWS_USE_PATH_STYLE_ENDPOINT=true
AWS_ENDPOINT=http://127.0.0.1:9000
AWS_URL=http://127.0.0.1:9000/profile
```

สำคัญ:

* `AWS_USE_PATH_STYLE_ENDPOINT=true` มักจำเป็นกับ MinIO
* `AWS_URL` เอาไว้ช่วย generate URL ของ object

---

# 3) ตรวจ `config/filesystems.php`

Laravel มี `s3` disk อยู่แล้วใน `config/filesystems.php` และจะอิงค่าจาก `.env`

เช็กให้มีประมาณนี้:

```php
'default' => env('FILESYSTEM_DISK', 'local'),

'disks' => [

    'local' => [
        'driver' => 'local',
        'root' => storage_path('app'),
    ],

    'public' => [
        'driver' => 'local',
        'root' => storage_path('app/public'),
        'url' => env('APP_URL').'/storage',
        'visibility' => 'public',
    ],

    's3' => [
        'driver' => 's3',
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION'),
        'bucket' => env('AWS_BUCKET'),
        'url' => env('AWS_URL'),
        'endpoint' => env('AWS_ENDPOINT'),
        'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
        'throw' => false,
    ],
],
```

หลังแก้ config:

```bash
php artisan config:clear
php artisan cache:clear
```

---

# 4) สร้าง Model + Migration

เราจะเก็บ metadata ของไฟล์ใน DB

```bash
php artisan make:model Document -m
```

แก้ migration:

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('file_name');
            $table->string('file_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
```

รัน migration:

```bash
php artisan migrate
```

---

# 5) ตั้งค่า Model

ไฟล์ `app/Models/Document.php`

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $fillable = [
        'title',
        'file_name',
        'file_path',
        'mime_type',
        'file_size',
    ];
}
```

---

# 6) สร้าง Controller

```bash
php artisan make:controller DocumentController --resource
```

แก้ไฟล์ `app/Http/Controllers/DocumentController.php`

```php
namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    public function index()
    {
        $documents = Document::latest()->get();

        return view('documents.index', compact('documents'));
    }

    public function create()
    {
        return view('documents.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'file'  => ['required', 'file', 'max:10240'], // 10MB
        ]);

        $file = $request->file('file');

        $fileName = time().'_'.Str::random(8).'_'.$file->getClientOriginalName();
        $filePath = Storage::disk('s3')->putFileAs('documents', $file, $fileName);

        Document::create([
            'title'     => $request->title,
            'file_name' => $fileName,
            'file_path' => $filePath,
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
        ]);

        return redirect()->route('documents.index')
            ->with('success', 'อัปโหลดไฟล์สำเร็จ');
    }

    public function show(Document $document)
    {
        // URL สำหรับเปิดไฟล์
        $url = Storage::disk('s3')->url($document->file_path);

        return view('documents.show', compact('document', 'url'));
    }

    public function edit(Document $document)
    {
        return view('documents.edit', compact('document'));
    }

    public function update(Request $request, Document $document)
    {
        $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'file'  => ['nullable', 'file', 'max:10240'],
        ]);

        $data = [
            'title' => $request->title,
        ];

        if ($request->hasFile('file')) {
            // ลบไฟล์เก่า
            if (Storage::disk('s3')->exists($document->file_path)) {
                Storage::disk('s3')->delete($document->file_path);
            }

            $file = $request->file('file');
            $fileName = time().'_'.Str::random(8).'_'.$file->getClientOriginalName();
            $filePath = Storage::disk('s3')->putFileAs('documents', $file, $fileName);

            $data['file_name'] = $fileName;
            $data['file_path'] = $filePath;
            $data['mime_type'] = $file->getClientMimeType();
            $data['file_size'] = $file->getSize();
        }

        $document->update($data);

        return redirect()->route('documents.index')
            ->with('success', 'แก้ไขข้อมูลสำเร็จ');
    }

    public function destroy(Document $document)
    {
        if (Storage::disk('s3')->exists($document->file_path)) {
            Storage::disk('s3')->delete($document->file_path);
        }

        $document->delete();

        return redirect()->route('documents.index')
            ->with('success', 'ลบข้อมูลสำเร็จ');
    }

    public function download(Document $document)
    {
        return Storage::disk('s3')->download(
            $document->file_path,
            $document->file_name
        );
    }
}
```

Laravel มี API สำหรับ `put`, `delete`, `url`, และการทำงานผ่าน `Storage::disk(...)` ตาม filesystem abstraction ของ framework ([Laravel][1])

---

# 7) สร้าง Route

ไฟล์ `routes/web.php`

```php
use App\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('documents.index');
});

Route::resource('documents', DocumentController::class);
Route::get('documents/{document}/download', [DocumentController::class, 'download'])
    ->name('documents.download');
```

---

# 8) สร้าง View

## `resources/views/documents/index.blade.php`

```php
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Documents</title>
</head>
<body>
    <h1>รายการเอกสาร</h1>

    @if(session('success'))
        <p style="color: green">{{ session('success') }}</p>
    @endif

    <a href="{{ route('documents.create') }}">+ เพิ่มเอกสาร</a>

    <table border="1" cellpadding="10" cellspacing="0" style="margin-top: 15px;">
        <thead>
            <tr>
                <th>ID</th>
                <th>Title</th>
                <th>File Name</th>
                <th>Size</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($documents as $doc)
                <tr>
                    <td>{{ $doc->id }}</td>
                    <td>{{ $doc->title }}</td>
                    <td>{{ $doc->file_name }}</td>
                    <td>{{ $doc->file_size }}</td>
                    <td>
                        <a href="{{ route('documents.show', $doc) }}">View</a>
                        <a href="{{ route('documents.edit', $doc) }}">Edit</a>
                        <a href="{{ route('documents.download', $doc) }}">Download</a>

                        <form action="{{ route('documents.destroy', $doc) }}" method="POST" style="display:inline">
                            @csrf
                            @method('DELETE')
                            <button type="submit" onclick="return confirm('ยืนยันการลบ?')">Delete</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">ไม่มีข้อมูล</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
```

---

## `resources/views/documents/create.blade.php`

```php
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Create Document</title>
</head>
<body>
    <h1>เพิ่มเอกสาร</h1>

    @if ($errors->any())
        <div style="color:red">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form action="{{ route('documents.store') }}" method="POST" enctype="multipart/form-data">
        @csrf

        <div>
            <label>Title:</label>
            <input type="text" name="title" value="{{ old('title') }}">
        </div>

        <div style="margin-top:10px">
            <label>File:</label>
            <input type="file" name="file">
        </div>

        <div style="margin-top:10px">
            <button type="submit">Upload</button>
        </div>
    </form>

    <p><a href="{{ route('documents.index') }}">กลับ</a></p>
</body>
</html>
```

---

## `resources/views/documents/show.blade.php`

```php
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Show Document</title>
</head>
<body>
    <h1>รายละเอียดเอกสาร</h1>

    <p><strong>Title:</strong> {{ $document->title }}</p>
    <p><strong>File Name:</strong> {{ $document->file_name }}</p>
    <p><strong>MIME:</strong> {{ $document->mime_type }}</p>
    <p><strong>Size:</strong> {{ $document->file_size }}</p>

    <p>
        <a href="{{ $url }}" target="_blank">เปิดไฟล์</a>
        |
        <a href="{{ route('documents.download', $document) }}">Download</a>
    </p>

    <p><a href="{{ route('documents.index') }}">กลับ</a></p>
</body>
</html>
```

---

## `resources/views/documents/edit.blade.php`

```php
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Edit Document</title>
</head>
<body>
    <h1>แก้ไขเอกสาร</h1>

    @if ($errors->any())
        <div style="color:red">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form action="{{ route('documents.update', $document) }}" method="POST" enctype="multipart/form-data">
        @csrf
        @method('PUT')

        <div>
            <label>Title:</label>
            <input type="text" name="title" value="{{ old('title', $document->title) }}">
        </div>

        <div style="margin-top:10px">
            <label>Replace File:</label>
            <input type="file" name="file">
        </div>

        <div style="margin-top:10px">
            <button type="submit">Update</button>
        </div>
    </form>

    <p><a href="{{ route('documents.index') }}">กลับ</a></p>
</body>
</html>
```

---

# 9) ทดสอบการทำงาน

สั่งรัน Laravel:

```bash
php artisan serve --host=0.0.0.0 --port=8080
```

เปิด:

```text
http://127.0.0.1:8080
```

แล้วลองทำตามนี้:

1. กด **เพิ่มเอกสาร**
2. ใส่ title และเลือกไฟล์
3. Upload
4. กลับมาที่รายการ
5. กด View / Download
6. กด Edit เพื่อเปลี่ยนชื่อหรือเปลี่ยนไฟล์
7. กด Delete เพื่อลบ

---

# 10) อธิบาย CRUD แบบตรง ๆ

## Create

ใช้:

```php
Storage::disk('s3')->putFileAs('documents', $file, $fileName);
```

Laravel จะส่งไฟล์ไปยัง S3 disk ที่เรา map ไป MinIO ไว้

---

## Read

ดึงข้อมูลจาก DB:

```php
$documents = Document::latest()->get();
```

สร้าง URL:

```php
Storage::disk('s3')->url($document->file_path);
```

ตัว URL จะอิงตามค่า `AWS_URL` / config ของ disk 

---

## Update

ถ้ามีไฟล์ใหม่:

1. ลบไฟล์เก่าใน MinIO
2. อัปโหลดไฟล์ใหม่
3. อัปเดต record ใน DB

```php
Storage::disk('s3')->delete($document->file_path);
```

---

## Delete

ลบ object ใน MinIO ก่อน แล้วค่อยลบแถวใน DB:

```php
Storage::disk('s3')->delete($document->file_path);
$document->delete();
```


## Docker Command

### MinIO

```sh
#!/bin/bash
docker stop minio
docker rm minio
sleep 2
docker run -d --name minio \
  -p 9000:9000 \
  -p 9001:9001 \
  -e MINIO_ROOT_USER=minioadmin \
  -e MINIO_ROOT_PASSWORD=minioadmin \
    quay.io/minio/minio:RELEASE.2025-09-07T16-13-09Z server /data --console-address ":9001"
```

### MYSQL

```sh
#!/bin/bash
docker run --name mysql \
    -p 3306:3306 \
    -v .\data\:/var/lib/mysql/:rw \
    -e MYSQL_ROOT_PASSWORD=iv99jTUT35Vwk8uR \
    -e TZ="Asia/Bangkok" \
    -d docker.io/library/mysql:8.0.42
```
