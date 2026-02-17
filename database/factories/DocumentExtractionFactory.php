<?php

namespace TamirRental\DocumentExtraction\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use TamirRental\DocumentExtraction\Enums\DocumentExtractionStatusEnum;
use TamirRental\DocumentExtraction\Models\DocumentExtraction;

/**
 * @extends Factory<DocumentExtraction>
 */
class DocumentExtractionFactory extends Factory
{
    protected $model = DocumentExtraction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => 'car_license',
            'filename' => fake()->uuid().'.pdf',
            'identifier' => '',
            'extracted_data' => (object) [],
            'metadata' => [],
            'status' => DocumentExtractionStatusEnum::Pending,
            'error_message' => null,
            'external_task_id' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => DocumentExtractionStatusEnum::Pending,
            'external_task_id' => fake()->uuid(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => DocumentExtractionStatusEnum::Completed,
            'identifier' => fake()->numerify('##-###-##'),
            'extracted_data' => (object) [
                'general_fields' => [
                    'license_number' => ['value' => fake()->numerify('##-###-##')],
                    'owner_name' => ['value' => fake()->name()],
                ],
            ],
            'external_task_id' => fake()->uuid(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => DocumentExtractionStatusEnum::Failed,
            'error_message' => 'Extraction failed: unable to process document.',
            'external_task_id' => fake()->uuid(),
        ]);
    }
}
