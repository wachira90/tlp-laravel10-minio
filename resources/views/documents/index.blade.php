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
