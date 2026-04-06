<?php

namespace App\Http\Controllers;

// use Illuminate\Http\Request;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $documents = Document::latest()->get();
        return view('documents.index', compact('documents'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('documents.create');
    }

    /**
     * Store a newly created resource in storage.
     */
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

        return redirect()->route('documents.index')->with('success', 'อัปโหลดไฟล์สำเร็จ');
    }

    /**
     * Display the specified resource.
     */
    // public function show(string $id)
    public function show(Document $document)
    {
        $url = Storage::disk('s3')->url($document->file_path);
        return view('documents.show', compact('document', 'url'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    // public function edit(string $id)
    public function edit(Document $document)
    {
        return view('documents.edit', compact('document'));
    }

    /**
     * Update the specified resource in storage.
     */

    // public function update(Request $request, string $id)
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

        return redirect()->route('documents.index')->with('success', 'แก้ไขข้อมูลสำเร็จ');
    }

    /**
     * Remove the specified resource from storage.
     */
    // public function destroy(string $id)
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
