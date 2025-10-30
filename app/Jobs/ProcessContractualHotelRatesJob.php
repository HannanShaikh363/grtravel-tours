<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\ContractualHotel;
use App\Models\ContractualHotelRate;
use ProtoneMedia\Splade\Facades\Toast;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Illuminate\Bus\Batchable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessContractualHotelRatesJob implements ShouldQueue
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

            if (empty($column['hotel_name'])) {
                     Log::info('Missing required fields in the row.', ['row_data' => $column]);


                Log::info('Missing required fields in the row.');
                return false;
            }

            $hotel_name = trim(strtolower($column['hotel_name']));
            $entitlements = !empty($column['entitlements']) ? explode('||', $column['entitlements']) : [];

            $hotel = ContractualHotel::whereRaw('LOWER(TRIM(hotel_name)) = ?', [$hotel_name])->first();

            if (!$hotel) {
                Log::info('Invalid Hotel Name: ' . $hotel_name);
                return false;
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
                            $filename = 'images/hotel/rates/' . uniqid() . '.' . pathinfo(parse_url($image, PHP_URL_PATH), PATHINFO_EXTENSION);

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

                $ratesData = [
                    'hotel_id' => $hotel->id,
                    'room_type' =>  $column['room_type'],
                    'images' => json_encode($imagePaths),
                    'weekdays_price' => $column['weekdays_price'],
                    'weekend_price' => $column['weekend_price'],
                    'currency' => $column['currency'],
                    'entitlements' => $column['entitlements'],
                    'no_of_beds' => $column['no_of_beds'],
                    'room_capacity' => $column['room_capacity'],
                    'effective_date' => $this->convertDate($column['effective_date']),
                    'expiry_date' => $this->convertDate($column['expiry_date']),
                    'expiry_date' => $this->convertDate($column['expiry_date']),
                ];

                

                $ContractualHotel = ContractualHotelRate::updateOrCreate(
                    [
                        'hotel_id' => $hotel->id,
                        'room_type' => $column['room_type'],
                        'room_capacity' => $column['room_capacity'],
                    ],
                    $ratesData
                );

                Log::info("Processed record for Genting Hotel: {$column['hotel_name']} in location: {$hotel->hotel_name}");
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

    private function convertDate($date)
    {
        return \Carbon\Carbon::createFromFormat('Y-m-d', '1899-12-30')->addDays($date)->format('Y-m-d');
    }
}
