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
