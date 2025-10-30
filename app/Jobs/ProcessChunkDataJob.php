<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Location;
use App\Models\Rate;
use App\Models\Transport;
use ProtoneMedia\Splade\Facades\Toast;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Illuminate\Bus\Batchable;


class ProcessChunkDataJob implements ShouldQueue
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
    public function handle(): void
    {
        
        foreach ($this->chunkData as $column) {
        
            // Retrieve the name for from and to locations from the column data
            $fromLocationName = trim(strtolower($column['from_location_id']));
            $toLocationName = trim(strtolower($column['to_location_id']));
            $transportName = trim(strtolower($column['transport_id']));

            // Check if the from and to locations exist based on the name
            $fromLocation = Location::where('name', $fromLocationName)->first();
            $toLocation = Location::where('name', $toLocationName)->first();

            // If from/to location is not found, skip the current row
            if (!$fromLocation || !$toLocation) {
                Toast::title('Invalid from_location or to_location! Please check the locations.')
                    ->danger()
                    ->rightBottom()
                    ->autoDismiss(5);
                continue; // Skip this iteration
            }

            // Create or retrieve the transport if all required fields are present
            if (!empty($column['vehicle_seating_capacity']) && !empty($column['vehicle_luggage_capacity']) && !empty($column['transport_id']) && !empty($column['vehicle_make'])) {
                $transport = Transport::firstOrCreate(
                    [
                        'vehicle_model' => trim(strtolower($column['transport_id'])),
                        'vehicle_make' => trim(strtolower($column['vehicle_make'])),
                    ],
                    [
                        'user_id' => $this->userId,
                        'vehicle_seating_capacity' => $column['vehicle_seating_capacity'],
                        'vehicle_luggage_capacity' => $column['vehicle_luggage_capacity'],
                    ]
                );
                // 'user_id' => $this->userId,
            } else {
                $transport = null;
            }

            // Convert hours from fraction of days to HH:MM format
            if (isset($column['hours'])) {
                $hours = (float) $column['hours'];
                $hoursDecimal = $hours * 24;
                $formattedHours = sprintf('%02d:%02d', floor($hoursDecimal), ($hoursDecimal - floor($hoursDecimal)) * 60);
            } else {
                $formattedHours = '00:00'; // Default if not set
            }

            // Determine the rate type
            if (empty($column['rate_type'])) {
                if (strpos($fromLocationName, 'airport') !== false || strpos($toLocationName, 'airport') !== false) {
                    $rate_type = 'airport_transfer';
                } elseif (strpos($fromLocationName, 'hotel') !== false || strpos($toLocationName, 'hotel') !== false) {
                    $rate_type = 'hotel_transfer';
                } else {
                    $rate_type = 'hotel_transfer';
                }
            }else{
                $rate_type = $column['rate_type'];
            }

            // Store the rate information
            // $rates = Rate::firstOrCreate(
            //     [
            //         'from_location_id' => $fromLocation->id,
            //         'to_location_id' => $toLocation->id,
            //         'transport_id' => $transport ? $transport->id : null,
            //         'vehicle_seating_capacity' => $column['vehicle_seating_capacity'],
            //         'vehicle_luggage_capacity' => $column['vehicle_luggage_capacity'],
            //         'rate' => $column['rate'] ? $column['rate'] : '0',
            //         'package' => $column['package'] ? $column['package'] : 'Package Here',
            //         'name' => $column['name'],
            //         'currency' => $column['currency'] ? $column['currency'] : 'Currency Here',
            //         'effective_date' => $this->convertDate($column['effective_date']) ? $this->convertDate($column['effective_date']) : '1970-01-01',
            //         'expiry_date' => $this->convertDate($column['expiry_date']),
            //         'route_type' => $column['route_type'] ? $column['route_type'] : 'one_way',
            //         'time_remarks' => $column['time_remarks'],
            //         'remarks' => $column['remarks'],
            //         'hours' => $formattedHours,
            //         'rate_type' => $rate_type,
            //     ]
            // );

            $rates = Rate::updateOrCreate(
                [
                    'from_location_id' => $fromLocation->id,
                    'to_location_id' => $toLocation->id,
                    'transport_id' => $transport ? $transport->id : null,
                    'vehicle_seating_capacity' => $column['vehicle_seating_capacity'],
                    'vehicle_luggage_capacity' => $column['vehicle_luggage_capacity'],
                    'name' => $column['name'],
                    'route_type' => $column['route_type'] ?? 'one_way',
                ],
                [
                    'rate' => $column['rate'] ?? '0',
                    'package' => $column['package'] ?? 'Package Here',
                    'currency' => $column['currency'] ?? 'Currency Here',
                    'effective_date' => $this->convertDate($column['effective_date']) ?? '1970-01-01',
                    'expiry_date' => $this->convertDate($column['expiry_date']),
                    'time_remarks' => $column['time_remarks'],
                    'remarks' => $column['remarks'],
                    'hours' => $formattedHours,
                    'rate_type' => $rate_type,
                ]
            );
            

            // Check for duplicate rate data
            if (!$rates->wasRecentlyCreated) {
                Toast::title('Duplicate rate data found for: ' . $column['name'])
                    ->danger()
                    ->rightBottom()
                    ->autoDismiss(5);
                continue; // Skip saving this rate as it already exists
            }

            // Attach transports to rates if transport exists
            if ($transport) {
                $rates->transport()->attach($transport->id); // Attach multiple transports via pivot
            }
        }
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
}
