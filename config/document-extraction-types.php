<?php

/**
 * Define your document types here. Each key is a document type string
 * used throughout the package. Configure the template, folder, and
 * identifier field for each type.
 *
 * @return array<string, array{
 *     template_id: ?string,
 *     folder_id: ?string,
 *     identifier: string,
 * }>
 */
return [
    'car_license' => [
        'template_id' => env('KONCILE_AI_CAR_LICENSE_TEMPLATE_ID'),
        'folder_id' => env('KONCILE_AI_CAR_LICENSE_FOLDER_ID'),
        'identifier' => 'license_number',
    ],

    // Add more document types:
    // 'invoice' => [
    //     'template_id' => env('KONCILE_AI_INVOICE_TEMPLATE_ID'),
    //     'folder_id' => env('KONCILE_AI_INVOICE_FOLDER_ID'),
    //     'identifier' => 'invoice_number',
    // ],
];
