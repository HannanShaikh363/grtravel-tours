<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Location;
use App\Models\city;
use App\Models\Country;
use App\Models\ContractualHotel;
use ProtoneMedia\Splade\Facades\Toast;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Illuminate\Bus\Batchable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessContractualHotelDataJob implements ShouldQueue
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
        // ini_set('max_execution_time', '300');
        foreach ($this->chunkData as $column) {

            if (empty($column['hotel_name']) || empty($column['description'])) {

                Log::info('Missing required fields in the row.');
                return false;
            }
            $facilities = [];
            $countryName = trim(strtolower($column['country']));
            $cityName = trim(strtolower($column['city']));
            $amenities = !empty($column['amenities']) ? explode(',', $column['property_amenities']) : [];
            $room_features = !empty($column['room_features']) ? explode(',', $column['room_features']) : [];
            $room_types = !empty($column['room_types']) ? explode(',', $column['room_types']) : [];

            // $facilities = [
            //     'amenities' => $amenities,
            //     'room_features' => $room_features,
            //     'room_types' => $room_types
            // ];

            $Country = Country::whereRaw('LOWER(TRIM(name)) = ?', [$countryName])->first();

            if (!$Country) {
                Log::info('Invalid country: ' . $countryName);
                return false;
            }
             $City = City::whereRaw('LOWER(TRIM(name)) = ?', [$cityName])->first();

            if (!$City) {
                Log::info('Invalid city: ' . $cityName);
                return false;
            }

            if (isset($column['important_info'])) {

                $encoding = mb_detect_encoding($column['important_info'], ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true) ?: 'UTF-8';
                $cleanedText = mb_convert_encoding($column['important_info'], 'UTF-8', $encoding);

                $cleanedText = str_replace(['’', '‘'], "'", $cleanedText);  // Apostrophes
                $cleanedText = str_replace(['“', '”'], '"', $cleanedText);  // Curly quotes

                $cleanedText = preg_replace('/[^\P{C}\n]+/u', '', $cleanedText);

                $cleanedText = preg_replace('/\r\n|\r/', "\n", $cleanedText);

                $importantInfoArray = preg_split('/\n?\s*(?<!\w)[•](?!\s*\d)|\n?\s*(?<!\w)-\s+(?!\d)/', $cleanedText, -1, PREG_SPLIT_NO_EMPTY);

                $importantInfoArray = array_map('trim', $importantInfoArray);

                $importantInfoArray = array_filter($importantInfoArray, function ($item) {
                    return !empty($item) && trim($item) !== '' && trim($item) !== '?';
                });

                $importantInfoArray = array_map(fn($item) => mb_convert_encoding($item, 'UTF-8', $encoding), $importantInfoArray);

                $important_info = implode(' || ', $importantInfoArray);
            } else {
                $important_info = ''; // Default value if important_info is not set
            }

            $imagePaths = [];

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
                            $filename = 'images/hotel/' . uniqid() . '.' . pathinfo(parse_url($image, PHP_URL_PATH), PATHINFO_EXTENSION);

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



            // $images = implode(',', $imagePaths);
            try {

                $hotelData = [
                    'city_id' => $City->id,
                    'country_id' => $Country->id,
                    'hotel_name' => $column['hotel_name'],
                    'description' => $column['description'] ?? '',
                    'important_info' =>  $column['important_info'],
                    'images' => json_encode($imagePaths),
                    'property_amenities' => $column['property_amenities'],
                    'room_features' => $column['room_features'],
                    'room_types' => $column['room_types'],
                    'extra_bed_adult' => $column['extra_bed_adult'] ?? '',
                    'extra_bed_child' => $column['extra_bed_child'] ?? '',
                    'currency' => $column['currency'] ?? '',
                ];
                // log::info($hotelData);

                $contractualHotel = ContractualHotel::updateOrCreate(
                    [
                        'city_id' => $City->id,
                        'country_id' => $Country->id,
                        'hotel_name' => $column['hotel_name'],
                    ],
                    $hotelData
                );

                Log::info("Processed record for Contractual Hotel: {$column['hotel_name']} in location: {$City->name}");
            } catch (\Exception $e) {

                Log::info('Error adding record: ' . $e);
                return false;
            }
        }

        return true; // Return true if all data is processed without critical errors
    }

    private function cleanText($text)
    {
        // Replace bullet points, tabs, and multiple dashes
        $text = str_replace(["•", "\t"], "-", $text);
        $text = preg_replace('/-+/', '-', $text);
        return trim($text, "- \t\n\r\0\x0B") ?: '-';
    }
}
