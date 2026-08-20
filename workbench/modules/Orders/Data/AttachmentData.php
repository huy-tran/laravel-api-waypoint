<?php

declare(strict_types=1);

namespace Modules\Orders\Data;

use Illuminate\Http\UploadedFile;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * A multipart body. v1 does not describe these: the endpoint must land in
 * diagnostics.unmapped_routes with reason "multipart" rather than being guessed at.
 */
#[MapInputName(SnakeCaseMapper::class)]
class AttachmentData extends Data
{
    public function __construct(
        public UploadedFile $document,
        public ?string $caption = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'document' => ['required', 'file', 'mimes:pdf,png,jpg', 'max:5120'],
            'caption' => ['nullable', 'string', 'max:120'],
        ];
    }
}
