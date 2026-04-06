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
