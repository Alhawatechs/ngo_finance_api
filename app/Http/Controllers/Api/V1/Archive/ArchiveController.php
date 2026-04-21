<?php

namespace App\Http\Controllers\Api\V1\Archive;

use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ArchiveController extends Controller
{
    /**
     * List all documents (linked + standalone) for the organization with filters.
     */
    public function index(Request $request)
    {
        $query = Document::where('organization_id', $request->user()->organization_id)
            ->with(['documentable', 'uploader:id,name,email', 'office:id,name,code']);

        if ($request->filled('document_type')) {
            $query->where('document_type', $request->document_type);
        }

        if ($request->filled('archive_category')) {
            $query->where('archive_category', $request->archive_category);
        }

        if ($request->filled('source')) {
            $source = $request->source;
            if ($source === 'standalone') {
                $query->whereNull('documentable_id');
            } else {
                $typeMap = [
                    'grant' => 'App\Models\Grant',
                    'project' => 'App\Models\Project',
                    'voucher' => 'App\Models\Voucher',
                ];
                if (isset($typeMap[$source])) {
                    $query->where('documentable_type', $typeMap[$source]);
                }
            }
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('file_name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('retention_expired')) {
            if ($request->retention_expired === '1' || $request->retention_expired === 'true') {
                $query->whereNotNull('retention_until')->whereDate('retention_until', '<', now()->toDateString());
            }
        }

        if ($request->filled('retention_until_before')) {
            $query->where('retention_until', '<=', $request->retention_until_before);
        }

        if ($request->filled('office_id')) {
            $query->where('office_id', (int) $request->office_id);
        }

        $perPage = min((int) $request->input('per_page', 25), 100);
        $documents = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $items = $documents->getCollection()->map(function ($doc) {
            return $this->enrichDocument($doc);
        });

        $documents->setCollection($items);

        return $this->paginated($documents);
    }

    /**
     * Upload a standalone document.
     */
    public function store(Request $request)
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'max:51200',
                'mimetypes:application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,image/jpeg,image/png,image/gif,application/zip,application/x-zip-compressed',
            ],
            'title' => 'nullable|string|max:255',
            'document_type' => 'nullable|in:invoice,receipt,contract,amendment,budget,report,correspondence,other',
            'archive_category' => 'nullable|in:policy,template,compliance,other',
            'retention_until' => 'nullable|date',
            'office_id' => 'nullable|integer|exists:offices,id',
        ]);

        $file = $request->file('file');
        $orgId = $request->user()->organization_id;
        $dir = 'archive/' . $orgId . '/' . date('Y');
        $path = $file->store($dir, 'public');

        $document = Document::create([
            'organization_id' => $orgId,
            'documentable_type' => null,
            'documentable_id' => null,
            'title' => $request->input('title', $file->getClientOriginalName()),
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'file_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'document_type' => $request->input('document_type', 'other'),
            'archive_category' => $request->input('archive_category'),
            'retention_until' => $request->filled('retention_until') ? $request->retention_until : null,
            'office_id' => $request->filled('office_id') ? (int) $request->office_id : null,
            'uploaded_by' => $request->user()->id,
        ]);

        return $this->success([
            'document' => $this->enrichDocument($document->load('uploader:id,name,email')),
        ], 'Document uploaded successfully', 201);
    }

    /**
     * Show a single document's metadata.
     */
    public function show(Request $request, Document $document)
    {
        if ($document->organization_id !== $request->user()->organization_id) {
            return $this->error('Document not found', 404);
        }

        $document->load(['documentable', 'uploader:id,name,email', 'office:id,name,code']);

        return $this->success($this->enrichDocument($document));
    }

    /**
     * Download a document file.
     */
    public function download(Request $request, Document $document)
    {
        if ($document->organization_id !== $request->user()->organization_id) {
            return $this->error('Document not found', 404);
        }

        $path = Storage::disk('public')->path($document->file_path);
        if (!file_exists($path)) {
            return $this->error('File not found', 404);
        }

        return response()->download($path, $document->file_name, [
            'Content-Type' => $document->file_type,
        ]);
    }

    /**
     * Bulk download: zip multiple documents and return the archive.
     */
    public function bulkDownload(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:documents,id',
        ]);

        $ids = array_unique($request->ids);
        $documents = Document::where('organization_id', $request->user()->organization_id)
            ->whereIn('id', $ids)
            ->get();

        if ($documents->isEmpty()) {
            return $this->error('No documents found', 404);
        }

        $zip = new \ZipArchive();
        $zipPath = storage_path('app/temp/archive-' . uniqid() . '.zip');
        if (!is_dir(dirname($zipPath))) {
            mkdir(dirname($zipPath), 0755, true);
        }

        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return $this->error('Could not create archive', 500);
        }

        $usedNames = [];
        foreach ($documents as $doc) {
            $path = Storage::disk('public')->path($doc->file_path);
            if (file_exists($path)) {
                $name = $doc->file_name;
                if (isset($usedNames[$name])) {
                    $ext = pathinfo($name, PATHINFO_EXTENSION);
                    $base = pathinfo($name, PATHINFO_FILENAME);
                    $name = $base . '-' . $doc->id . ($ext ? '.' . $ext : '');
                }
                $usedNames[$name] = true;
                $zip->addFile($path, $name);
            }
        }

        $zip->close();

        $response = response()->file($zipPath, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="archive-documents.zip"',
        ]);

        register_shutdown_function(function () use ($zipPath) {
            if (file_exists($zipPath)) {
                @unlink($zipPath);
            }
        });

        return $response;
    }

    /**
     * Soft delete a document.
     */
    public function destroy(Request $request, Document $document)
    {
        if ($document->organization_id !== $request->user()->organization_id) {
            return $this->error('Document not found', 404);
        }

        $document->delete();

        return $this->success(null, 'Document deleted');
    }

    private function enrichDocument(Document $doc): array
    {
        $source = 'standalone';
        $source_label = 'Standalone';
        $source_link = null;

        if ($doc->documentable_type && $doc->documentable_id) {
            $model = $doc->documentable;
            if ($model) {
                if ($model instanceof \App\Models\Grant) {
                    $source = 'grant';
                    $source_label = 'Grant: ' . ($model->grant_code ?? '#' . $model->id);
                    $source_link = '/projects/grants?grant=' . $model->id;
                } elseif ($model instanceof \App\Models\Project) {
                    $source = 'project';
                    $source_label = 'Project: ' . ($model->project_code ?? '#' . $model->id);
                    $source_link = '/projects/' . $model->id;
                } elseif ($model instanceof \App\Models\Voucher) {
                    $source = 'voucher';
                    $source_label = 'Voucher: ' . ($model->voucher_number ?? '#' . $model->id);
                    $source_link = '/vouchers/' . $model->id;
                }
            }
        }

        return [
            'id' => $doc->id,
            'title' => $doc->title,
            'file_name' => $doc->file_name,
            'file_path' => $doc->file_path,
            'file_type' => $doc->file_type,
            'file_size' => $doc->file_size,
            'document_type' => $doc->document_type,
            'archive_category' => $doc->archive_category,
            'retention_until' => $doc->retention_until?->format('Y-m-d'),
            'office_id' => $doc->office_id,
            'office' => $doc->office ? ['id' => $doc->office->id, 'name' => $doc->office->name, 'code' => $doc->office->code] : null,
            'source' => $source,
            'source_label' => $source_label,
            'source_link' => $source_link,
            'uploaded_by' => $doc->uploader ? [
                'id' => $doc->uploader->id,
                'name' => $doc->uploader->name,
            ] : null,
            'created_at' => $doc->created_at?->toIso8601String(),
        ];
    }
}
