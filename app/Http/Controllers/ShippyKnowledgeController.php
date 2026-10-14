<?php

namespace App\Http\Controllers;

use App\Actions\Audit\AuditRecorder;
use App\Http\Requests\SaveShippyFaqRequest;
use App\Http\Requests\StoreShippyDocumentsRequest;
use App\Http\Requests\UpdateShippyDocumentRequest;
use App\Models\ShippyDocument;
use App\Models\ShippyFaq;
use App\Models\ShippySetting;
use App\Support\ShippyMarkdown;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Website → Shippy → Knowledge and Documents (v1.3.0): the Owner and Super Admin teach Shippy with FAQs and Markdown
 * files. Both are stored in PostgreSQL and read by Shippy from the cache, which every saved change drops (see the
 * models), so a change answers the next question. A document's text is never served back: no route returns it. Every
 * change is audited under `settings`, without the document text.
 */
class ShippyKnowledgeController extends Controller
{
    private const FAQ_FIELDS = ['question', 'answer', 'category', 'alternatives', 'is_enabled'];

    private const DOCUMENT_FIELDS = ['filename', 'bytes', 'sections', 'is_enabled'];

    /** Adds an FAQ. The very same question and answer sent again is a retry of one already saved, not a second FAQ. */
    public function storeFaq(SaveShippyFaqRequest $request, AuditRecorder $audit): JsonResponse
    {
        DB::transaction(function () use ($request, $audit): void {
            $values = $request->faq();
            if (ShippyFaq::query()->lockForUpdate()->where('question', $values['question'])->where('answer', $values['answer'])->exists()) {
                return;
            }
            if (ShippyFaq::query()->count() >= ShippyFaq::MAX_FAQS) {
                throw ValidationException::withMessages(['question' => 'Shippy already has '.ShippyFaq::MAX_FAQS.' FAQs. Delete one before adding another.']);
            }
            $faq = ShippyFaq::query()->create($values);
            $audit->record(null, $request->user(), 'settings', 'shippy.faq_created', ShippyFaq::class, (string) $faq->id, null, $faq->only(self::FAQ_FIELDS));
        });

        return response()->json(['saved' => true]);
    }

    public function updateFaq(SaveShippyFaqRequest $request, ShippyFaq $faq, AuditRecorder $audit): JsonResponse
    {
        DB::transaction(function () use ($request, $faq, $audit): void {
            $locked = ShippyFaq::query()->whereKey($faq->id)->lockForUpdate()->firstOrFail();
            $before = $locked->only(self::FAQ_FIELDS);
            $locked->update($request->faq());
            if ($before !== $locked->only(self::FAQ_FIELDS)) {
                $audit->record(null, $request->user(), 'settings', 'shippy.faq_updated', ShippyFaq::class, (string) $locked->id, $before, $locked->only(self::FAQ_FIELDS));
            }
        });

        return response()->json(['saved' => true]);
    }

    public function destroyFaq(Request $request, ShippyFaq $faq, AuditRecorder $audit): JsonResponse
    {
        Gate::authorize('update', ShippySetting::class);
        DB::transaction(function () use ($request, $faq, $audit): void {
            $faq->delete();
            $audit->record(null, $request->user(), 'settings', 'shippy.faq_deleted', ShippyFaq::class, (string) $faq->id, $faq->only(self::FAQ_FIELDS), null);
        });

        return response()->json(['saved' => true]);
    }

    /**
     * Adds one or more documents. Nothing is stored unless every file of the request can be used. Sending the same
     * file again (same name, same text) is a retry of an upload whose answer never arrived: it is already saved, so
     * it succeeds without a second copy.
     */
    public function storeDocuments(StoreShippyDocumentsRequest $request, AuditRecorder $audit): JsonResponse
    {
        $uploads = [];
        foreach (array_values((array) $request->file('files')) as $index => $file) {
            $uploads[] = $this->read($file, 'files.'.$index);
        }
        DB::transaction(function () use ($request, $uploads, $audit): void {
            $saved = ShippyDocument::query()->lockForUpdate()->pluck('content', 'filename')
                ->mapWithKeys(fn (string $content, string $name): array => [mb_strtolower($name) => hash('sha256', $content)]);
            $fresh = [];
            foreach ($uploads as $index => $upload) {
                $name = mb_strtolower($upload['filename']);
                if (isset($fresh[$name]) || ($saved->has($name) && $saved[$name] !== hash('sha256', $upload['content']))) {
                    throw ValidationException::withMessages(['files.'.$index => $upload['filename'].' is already uploaded. Use Replace on that document instead.']);
                }
                if (! $saved->has($name)) {
                    $fresh[$name] = $upload;
                }
            }
            if ($saved->count() + count($fresh) > ShippyDocument::MAX_DOCUMENTS) {
                throw ValidationException::withMessages(['files' => 'Shippy keeps at most '.ShippyDocument::MAX_DOCUMENTS.' documents. Delete one before adding another.']);
            }
            foreach ($fresh as $upload) {
                $document = ShippyDocument::query()->create([...$upload, 'is_enabled' => true]);
                $audit->record(null, $request->user(), 'settings', 'shippy.document_uploaded', ShippyDocument::class, (string) $document->id, null, $document->only(self::DOCUMENT_FIELDS));
            }
        });

        return response()->json(['saved' => true]);
    }

    public function updateDocument(UpdateShippyDocumentRequest $request, ShippyDocument $document, AuditRecorder $audit): JsonResponse
    {
        DB::transaction(function () use ($request, $document, $audit): void {
            $locked = ShippyDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $before = $locked->only(self::DOCUMENT_FIELDS);
            $locked->update($request->validated());
            if ($before !== $locked->only(self::DOCUMENT_FIELDS)) {
                $audit->record(null, $request->user(), 'settings', $locked->is_enabled ? 'shippy.document_enabled' : 'shippy.document_disabled', ShippyDocument::class, (string) $locked->id, $before, $locked->only(self::DOCUMENT_FIELDS));
            }
        });

        return response()->json(['saved' => true]);
    }

    /** Puts a new file's text in place of a document's; its name and switch stay as they are. */
    public function replaceDocument(Request $request, ShippyDocument $document, AuditRecorder $audit): JsonResponse
    {
        Gate::authorize('update', ShippySetting::class);
        $request->validate(['file' => StoreShippyDocumentsRequest::FILE], [
            'file.extensions' => 'Only Markdown (.md) files can be uploaded.',
            'file.max' => 'A document may be at most '.ShippyDocument::MAX_KILOBYTES.' KB.',
        ]);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);
        $upload = $this->read($file, 'file');
        DB::transaction(function () use ($request, $document, $upload, $audit): void {
            $locked = ShippyDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $before = $locked->only(self::DOCUMENT_FIELDS);
            /** A replacement with the very same text is still a new upload, so the date shown moves. */
            $locked->forceFill(['content' => $upload['content'], 'bytes' => $upload['bytes'], 'sections' => $upload['sections'], 'updated_at' => now()])->save();
            $audit->record(null, $request->user(), 'settings', 'shippy.document_replaced', ShippyDocument::class, (string) $locked->id, $before, $locked->only(self::DOCUMENT_FIELDS));
        });

        return response()->json(['saved' => true]);
    }

    public function destroyDocument(Request $request, ShippyDocument $document, AuditRecorder $audit): JsonResponse
    {
        Gate::authorize('update', ShippySetting::class);
        DB::transaction(function () use ($request, $document, $audit): void {
            $document->delete();
            $audit->record(null, $request->user(), 'settings', 'shippy.document_deleted', ShippyDocument::class, (string) $document->id, $document->only(self::DOCUMENT_FIELDS), null);
        });

        return response()->json(['saved' => true]);
    }

    /**
     * An uploaded file as a document, or a validation error naming what is wrong with it. The file is read as text
     * and never executed, moved or kept as a file.
     *
     * @return array{filename: string, content: string, bytes: int, sections: int}
     */
    private function read(UploadedFile $file, string $field): array
    {
        $filename = mb_substr(trim((string) preg_replace('/[^\p{L}\p{N} ._()-]+/u', ' ', basename(str_replace('\\', '/', $file->getClientOriginalName())))), -120);
        $content = (string) $file->get();
        $problem = ShippyMarkdown::problem($content);
        if ($problem !== null || $filename === '') {
            throw ValidationException::withMessages([$field => ($filename ?: 'This file').' '.($problem ?? 'has no usable name.')]);
        }

        return [
            'filename' => $filename, 'content' => $content, 'bytes' => strlen($content),
            'sections' => count(ShippyMarkdown::sections($content, $filename)),
        ];
    }
}
