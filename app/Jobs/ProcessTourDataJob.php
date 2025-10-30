<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Location;
use App\Models\Rate;
use App\Models\Tour;
use App\Models\TourDestination;
use App\Models\TourRate;
use App\Models\Transport;
use ProtoneMedia\Splade\Facades\Toast;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Illuminate\Bus\Batchable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessTourDataJob implements ShouldQueue
{
    use Queueable, Batchable, SerializesModels;

    protected $chunkData;
    protected $userId;

    /**
     * Create a new job instance.
     */
    public function __construct(array $chunkData, $userId)
    {
        $this->chunkData = $chunkData;
        $this->userId = $userId;
    }

    /**
     * Execute the job.
     */
    public function handle(): bool
    {
        $processedRecords = []; // Array to track processed name-package combinations
        $errors = [];

        // ini_set('max_execution_time', 300);

        foreach ($this->chunkData as $index => $column) {
            $rowNumber = $index + 1; // Convert to 1-based indexing if needed
            // Skip rows with missing required fields
            if (empty($column['location_id']) || empty($column['name']) || empty($column['package'])) {

                Log::info('Missing required fields in the row.');
                return false; // Stop execution and notify about the issue
            }

            // Normalize location name for case-insensitive matching
            $locationName = trim(strtolower($column['location_id']));

            // Check if the location exists
            $city = Location::whereRaw('LOWER(TRIM(name)) = ?', [$locationName])->first();

            if (!$city) {
                Toast::title('Invalid location: ' . $locationName)
                    ->danger()
                    ->rightBottom()
                    ->autoDismiss(5);
                return false; // Stop execution and notify about the issue
            }

            // Normalize name and package for duplicate checking
            $recordKey = strtolower(trim($column['name'])) . '|' . strtolower(trim($column['package']));

            // Check for duplicates within the sheet
            if (in_array($recordKey, $processedRecords)) {
                Log::info('Duplicate in the sheet: ' . $column['name'] . ' | ' . $column['package']);
                continue; // Skip processing this record
            }

            // Add the current record to the processed list
            $processedRecords[] = $recordKey;

            // Initialize default formatted hours
            $formattedHours = '00:00'; // Default if not set
            if (!empty($column['hours'])) {
                $rawValue = $column['hours'];

                // Check if it's a valid time string (e.g., "08:00:00" or "08:00")
                if (preg_match('/^([01]?\d|2[0-3]):[0-5]\d(:[0-5]\d)?\s?(AM|PM)?$/i', $rawValue)) {
                    // Convert if in 12-hour format
                    $formattedHours = date('H:i', strtotime($rawValue));
                }
                // Check if it's a decimal value (e.g., "0.33333333333333")
                elseif (is_numeric($rawValue) && $rawValue >= 0 && $rawValue < 1) {
                    // Convert the decimal to hours and minutes
                    $totalMinutes = round($rawValue * 1440); // 1440 minutes in a day
                    $hours = floor($totalMinutes / 60);
                    $minutes = $totalMinutes % 60;
                    $formattedHours = sprintf('%02d:%02d', $hours, $minutes);
                } else {
                    // Log invalid format or handle the error
                    Log::warning("Invalid time format at row $rowNumber: {$rawValue}");
                    continue; // Skip this row
                }

                // Use $formattedHours for further processing
                Log::info("Formatted time for row $rowNumber: {$formattedHours}");
            }


            if (isset($column['highlights'])) {

                // Detect encoding and convert to UTF-8
                $encoding = mb_detect_encoding($column['highlights'], ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true) ?: 'UTF-8';
                $cleanedText = mb_convert_encoding($column['highlights'], 'UTF-8', $encoding);

                // Replace smart/curly apostrophes and quotes with normal characters
                $cleanedText = str_replace(['’', '‘'], "'", $cleanedText);  // Apostrophes
                $cleanedText = str_replace(['“', '”'], '"', $cleanedText);  // Curly quotes

                // Remove invalid UTF-8 characters and hidden control characters
                $cleanedText = preg_replace('/[^\P{C}\n]+/u', '', $cleanedText);

                // Normalize line breaks (convert all to `\n`)
                $cleanedText = preg_replace('/\r\n|\r/', "\n", $cleanedText);

                // Ensure only bullet points (`•`) trigger a split, while preserving words with apostrophes/hyphens/quotes
                $highlightsArray = preg_split('/\n?\s*[•]\s*/', $cleanedText, -1, PREG_SPLIT_NO_EMPTY);

                // Trim each item and remove extra spaces
                $highlightsArray = array_map('trim', $highlightsArray);

                // Remove empty items
                $highlightsArray = array_filter($highlightsArray, fn($item) => !empty($item) && trim($item) !== '');

                // Convert each item to UTF-8 again for safety
                $highlightsArray = array_map(fn($item) => mb_convert_encoding($item, 'UTF-8', $encoding), $highlightsArray);

                // Join array items with " || " (ensuring quotes and apostrophes remain intact)
                $highlights = implode(' || ', $highlightsArray);
            } else {
                $highlights = ''; // Default value if highlights are not set
            }



            if (isset($column['important_info'])) {

                // Detect encoding and convert to UTF-8
                $encoding = mb_detect_encoding($column['important_info'], ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true) ?: 'UTF-8';
                $cleanedText = mb_convert_encoding($column['important_info'], 'UTF-8', $encoding);

                // Replace smart/curly apostrophes and quotes with normal characters
                $cleanedText = str_replace(['’', '‘'], "'", $cleanedText);  // Apostrophes
                $cleanedText = str_replace(['“', '”'], '"', $cleanedText);  // Curly quotes

                // Remove invalid UTF-8 characters and hidden control characters
                $cleanedText = preg_replace('/[^\P{C}\n]+/u', '', $cleanedText);

                // Normalize line breaks (convert all to `\n`)
                $cleanedText = preg_replace('/\r\n|\r/', "\n", $cleanedText);

                // Ensure only bullet points (`•` or `-`) trigger a split but preserve date/time ranges
                $importantInfoArray = preg_split('/\n?\s*(?<!\w)[•](?!\s*\d)|\n?\s*(?<!\w)-\s+(?!\d)/', $cleanedText, -1, PREG_SPLIT_NO_EMPTY);

                // Trim each item and remove extra spaces
                $importantInfoArray = array_map('trim', $importantInfoArray);

                // Remove empty items and trailing "?" issues
                $importantInfoArray = array_filter($importantInfoArray, function ($item) {
                    return !empty($item) && trim($item) !== '' && trim($item) !== '?';
                });

                // Convert each item to UTF-8 again for safety
                $importantInfoArray = array_map(fn($item) => mb_convert_encoding($item, 'UTF-8', $encoding), $importantInfoArray);

                // Join array items with " || "
                $important_info = implode(' || ', $importantInfoArray);
            } else {
                $important_info = ''; // Default value if important_info is not set
            }




            $imagePaths = [];

            // Check if $column['images'] is a single URL or an array
            $imagesToProcess = is_array($column['images']) ? $column['images'] : [$column['images']];

            foreach ($imagesToProcess as $image) {
                // Debug the input
                Log::info("Processing image: $image");

                // Check if it's a valid URL
                if (filter_var($image, FILTER_VALIDATE_URL)) {
                    try {
                        $response = Http::get($image);
                        if ($response->successful()) {
                            // Generate a unique filename based on the URL
                            $filename = 'images/tours/' . uniqid() . '.' . pathinfo(parse_url($image, PHP_URL_PATH), PATHINFO_EXTENSION);

                            // Store the image
                            $stored = Storage::disk('public')->put($filename, $response->body());

                            if ($stored) {
                                $imagePaths[] = $filename;
                            } else {
                                Log::warning("Failed to store image: $filename");
                            }
                        } else {
                            Log::warning("Failed to fetch image: $image, Status: " . $response->status());
                        }
                    } catch (\Exception $e) {
                        Log::error("Error downloading image: $image, Error: " . $e->getMessage());
                    }
                } else {
                    Log::warning("Invalid image format: $image");
                }
            }



            $images = implode(',', $imagePaths);
            // Store the tour information
            try {

                // Insert or update TourDestination
                $destinationData = [
                    'location_id' => $city->id,
                    'name' => trim($column['name']),
                    'description' => $column['description'] ?? '',
                    'highlights' => $highlights,
                    'important_info' =>  $important_info,
                    'images' => $images,
                    'closing_day' => json_encode(array_filter(array_map('trim', explode(',', $column['closing_day'] ?? '')))),
                    'time_slots' => json_encode(array_filter(array_map('trim', explode(',', $column['time_slots'] ?? '')))),
                    'hours' => $formattedHours,
                    'adult' => $column['adult'] ?? 0,
                    'child' => $column['child'] ?? 0,
                    'ticket_currency' => $column['currency'] ?? '',
                    'on_request' => isset($column['on_request']) && strtolower($column['on_request']) === 'yes' ? 1 : 0,
                ];

                $normalizedName = strtolower(trim(preg_replace('/\s+/', ' ', $column['name'])));

                $tourDestination = TourDestination::where('location_id', $city->id)
                    ->whereRaw('LOWER(TRIM(REPLACE(name, "  ", " "))) = ?', [$normalizedName])
                    ->first();

                if ($tourDestination) {
                    $tourDestination->update($destinationData);
                } else {
                    TourDestination::create($destinationData);
                }
                // $tourDestination = TourDestination::updateOrCreate(
                //     [
                //         'location_id' => $city->id,
                //         'name' => trim($column['name']),
                //     ],
                //     $destinationData
                // );

                // Insert or update TourRate
                $rateData = [
                    'tour_destination_id' => $tourDestination->id,
                    'package' => $column['package'],
                    'currency' => $column['currency'] ?? '',
                    'price' => $column['price'] ?? 0,
                    'sharing' => isset($column['sharing']) && strtolower($column['sharing']) === 'yes' ? 1 : 0,
                    'seating_capacity' => $column['seating_capacity'] ?? null,
                    'effective_date' => $this->convertDate($column['effective_date'] ?? null),
                    'expiry_date' => $this->convertDate($column['expiry_date'] ?? null),
                ];

                TourRate::updateOrCreate(
                    [
                        'package' => $column['package'],
                        'tour_destination_id' => $tourDestination->id,
                        'currency' => $column['currency'] ?? '',
                    ],
                    $rateData
                );

                Log::info("Processed record for Tour: {$column['name']} in location: {$city->name}");

                Toast::title('Successfully added: ' . $column['name'] . ' | ' . $column['package'])
                    ->success()
                    ->rightBottom()
                    ->autoDismiss(5);
            } catch (\Exception $e) {

                Log::info('Error adding record: ' . $e);
                return false; // Stop execution and notify about the error
            }
        }

        return true; // Return true if all data is processed without critical errors
    }

    private function convertDate($dateValue)
    {
        // Check if dateValue is an object and is a valid date
        if ($dateValue instanceof \PhpOffice\PhpSpreadsheet\Cell\Coordinate) {
            return Date::excelToDateTimeObject($dateValue)->format('Y-m-d');
        }

        // Handle numeric value that represents an Excel date
        if (is_numeric($dateValue)) {
            return Date::excelToDateTimeObject($dateValue)->format('Y-m-d');
        }

        // If it's already a string, attempt to format it
        $date = \DateTime::createFromFormat('Y-m-d', $dateValue);
        if ($date) {
            return $date->format('Y-m-d');
        }

        // If no valid date, return null or handle as needed
        return null;
    }

    private function cleanText($text)
    {
        // Replace bullet points, tabs, and multiple dashes
        $text = str_replace(["•", "\t"], "-", $text);
        $text = preg_replace('/-+/', '-', $text);
        return trim($text, "- \t\n\r\0\x0B") ?: '-';
    }
}
