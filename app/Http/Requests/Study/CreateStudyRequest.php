<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateStudyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Auth guard handles authorization
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'pdf'   => [
                'required',
                'file',
                'mimes:pdf',
                'max:20480', // 20 MB max
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Judul study wajib diisi.',
            'pdf.required'   => 'File PDF wajib diunggah.',
            'pdf.mimes'      => 'File harus berformat PDF.',
            'pdf.max'        => 'Ukuran file maksimal 20 MB.',
        ];
    }
}
