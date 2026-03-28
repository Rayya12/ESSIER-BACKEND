<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Study\CreateStudyRequest;
use App\Jobs\ProcessPdfJob;
use App\Models\Study;
use App\Models\StudyDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class StudyController extends Controller
{
    /**
     * Create a new study and upload PDF document.
     * PDF processing runs asynchronously via queue.
     */
    public function store(CreateStudyRequest $request): JsonResponse
    {
        // 1. Create the Study record
        $study = Study::create([
            'user_id'    => Auth::id(),
            'title'      => $request->input('title'),
            'status'     => 'processing',
            'created_at' => now(),
        ]);

        // 2. Store PDF file to private disk
        $file     = $request->file('pdf');
        $path     = $file->store("studies/{$study->id}", 'private');
        $fileName = $file->getClientOriginalName();

        // 3. Create StudyDocument record
        $document = StudyDocument::create([
            'study_id'       => $study->id,
            'file_name'      => $fileName,
            'file_path'      => $path,
            'extracted_text' => null,
            'created_at'     => now(),
        ]);

        // 4. Dispatch async job for PDF processing
        ProcessPdfJob::dispatch($study, $document);

        return response()->json([
            'message'  => 'Study created. Processing your PDF...',
            'study_id' => $study->id,
            'status'   => $study->status,
        ], 201);
    }

    /**
     * Poll endpoint — Flutter can call this to check if processing is done.
     */
    public function status(string $studyId): JsonResponse
    {
        $study = Study::where('id', $studyId)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        return response()->json([
            'study_id' => $study->id,
            'status'   => $study->status, // processing | ready | failed
            'title'    => $study->title,
        ]);
    }

    /**
     * Return full study details including mnemonics and questions.
     * Only available when status = ready.
     */
    public function show(string $studyId): JsonResponse
    {
        $study = Study::with(['mnemonics', 'questions'])
            ->where('id', $studyId)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        if ($study->status !== 'ready') {
            return response()->json([
                'message' => 'Study is still being processed.',
                'status'  => $study->status,
            ], 202);
        }

        return response()->json([
            'study_id'   => $study->id,
            'title'      => $study->title,
            'status'     => $study->status,
            'mnemonics'  => $study->mnemonics,
            'questions'  => $study->questions->map(fn($q) => [
                'id'            => $q->id,
                'question_text' => $q->question_text,
                'order_index'   => $q->order_index,
            ]),
        ]);
    }

    /**
     * List all studies for authenticated user.
     */
    public function index(): JsonResponse
    {
        $studies = Study::where('user_id', Auth::id())
            ->orderByDesc('created_at')
            ->get(['id', 'title', 'status', 'created_at']);

        return response()->json($studies);
    }
}
